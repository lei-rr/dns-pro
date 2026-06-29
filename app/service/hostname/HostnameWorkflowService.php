<?php

declare(strict_types=1);

namespace app\service\hostname;

use app\exception\ApiException;
use app\support\SideEffectResult;

/**
 * Hostname 编排服务
 *
 * 把 controller 中的业务副作用编排（DNSPod 预检 / 同步 / 清理 / 状态跃迁判断）
 * 下沉到 service 层，controller 只保留参数解析与响应包装。
 */
class HostnameWorkflowService
{
    public function __construct(
        private readonly HostnameService $hostnames,
        private readonly HostnameSyncService $sync,
    ) {
    }

    public function hostnames(string $providerId, string $zoneName, int $page, int $perPage, bool $refresh = false): array
    {
        $result = $this->hostnames->hostnames($providerId, $zoneName, $page, $perPage, $refresh);
        if (!$refresh) {
            return $result;
        }

        $activeStates = ['active', 'active_renewing', 'moved'];
        $cleanup = [];

        foreach (($result['items'] ?? []) as $index => $item) {
            $previous = (string) ($item['previous_status'] ?? '');
            $current = (string) ($item['status'] ?? '');
            $fqdn = trim((string) ($item['hostname'] ?? ''));
            $transitioned = $previous !== ''
                && !in_array($previous, $activeStates, true)
                && in_array($current, $activeStates, true);

            unset($result['items'][$index]['previous_status']);

            if ($transitioned && $fqdn !== '') {
                $cleanup[$fqdn] = $this->safeSync(
                    fn () => $this->sync->cleanupStaleRecords($providerId, $zoneName, $fqdn),
                );
            }
        }

        if ($cleanup !== []) {
            $result += SideEffectResult::dns([
                'cleanup' => SideEffectResult::operation('completed', '列表刷新后已执行 DNS 清理检查', ['items' => $cleanup]),
            ]);
        }

        return $result;
    }

    public function createHostname(string $providerId, string $zoneName, array $data, bool $autoSync = false): array
    {
        if ($autoSync) {
            $this->sync->preflight($providerId, (string) ($data['hostname'] ?? ''));
        }

        $result = $this->hostnames->createHostname($providerId, $zoneName, $data);

        if ($autoSync && !empty($result['hostname'])) {
            $sync = $this->safeSync(
                fn () => $this->sync->sync($providerId, $zoneName, (string) $result['hostname']),
            );
            $result += SideEffectResult::dns([
                'sync' => $this->normalizeSyncOperation($sync, '已执行 DNS 同步'),
            ]);
        }

        return $result;
    }

    public function updateHostname(string $providerId, string $zoneName, string $hostnameFqdn, array $data, bool $autoSync = false): array
    {
        $beforeRecords = [];
        if ($autoSync) {
            $beforeRecords = $this->sync->collectRecordsFor($providerId, $zoneName, $hostnameFqdn)['records'] ?? [];
        }

        $result = $this->hostnames->updateHostname($providerId, $zoneName, $hostnameFqdn, $data);

        if ($autoSync) {
            $sync = $this->safeSync(
                fn () => $this->sync->resyncAfterUpdate($providerId, $zoneName, $hostnameFqdn, $beforeRecords),
            );
            $result += SideEffectResult::dns([
                'sync' => $this->normalizeSyncOperation($sync, '已执行 DNS 重同步'),
            ]);
        }

        return $result;
    }

    public function refreshHostname(string $providerId, string $zoneName, string $hostnameFqdn): array
    {
        $result = $this->hostnames->refreshHostname($providerId, $zoneName, $hostnameFqdn);

        $activeStates = ['active', 'active_renewing', 'moved'];
        $previous = (string) ($result['previous_status'] ?? '');
        $current = (string) ($result['status'] ?? '');
        $transitioned = $previous !== ''
            && !in_array($previous, $activeStates, true)
            && in_array($current, $activeStates, true);

        if ($transitioned) {
            $cleanup = $this->safeSync(
                fn () => $this->sync->cleanupStaleRecords($providerId, $zoneName, $hostnameFqdn),
            );
            $result += SideEffectResult::dns([
                'cleanup' => $this->normalizeCleanupOperation($cleanup, '已执行 DNS 清理'),
            ]);
        }

        unset($result['previous_status']);

        return $result;
    }

    public function deleteHostname(string $providerId, string $zoneName, string $hostnameFqdn, bool $autoCleanup = true): array
    {
        $collected = $autoCleanup ? $this->sync->collectRecordsFor($providerId, $zoneName, $hostnameFqdn) : null;
        $result = $this->hostnames->deleteHostname($providerId, $zoneName, $hostnameFqdn);

        if ($collected && !empty($collected['records']) && $collected['hostname_fqdn'] !== '') {
            $cleanup = $this->safeSync(
                fn () => $this->sync->cleanup($providerId, $collected['hostname_fqdn'], $collected['records']),
            );
            $result += SideEffectResult::dns([
                'cleanup' => $this->normalizeCleanupOperation($cleanup, '已执行 DNS 删除后清理'),
            ]);
        }

        return $result;
    }

    /**
     * 同步/清理调用包装：失败时返回 skipped 描述而不抛异常（不影响主流程响应）
     */
    private function safeSync(callable $fn): array
    {
        try {
            return $fn();
        } catch (ApiException $e) {
            return [
                'status' => 'skipped',
                'code' => $e->getErrorCode() ?: 'sync_skipped',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function normalizeSyncOperation(array $result, string $defaultMessage): array
    {
        $status = (string) ($result['status'] ?? '');
        if ($status === '') {
            $status = $this->deriveSyncStatus($result);
        }

        return SideEffectResult::operation($status, (string) ($result['message'] ?? $defaultMessage), $result);
    }

    private function normalizeCleanupOperation(array $result, string $defaultMessage): array
    {
        $cleaned = (int) ($result['cleaned'] ?? 0);
        $status = ($result['status'] ?? '') === 'skipped'
            ? 'skipped'
            : ($cleaned > 0 ? 'completed' : (($result['reason'] ?? '') !== '' ? 'skipped' : 'completed'));

        return SideEffectResult::operation($status, (string) ($result['message'] ?? $defaultMessage), $result);
    }

    private function deriveSyncStatus(array $result): string
    {
        $records = $result['records'] ?? [];
        if (!is_array($records) || $records === []) {
            return (($result['reason'] ?? '') !== '' || ($result['code'] ?? '') !== '') ? 'skipped' : 'completed';
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (($record['status'] ?? '') === 'failed') {
                return 'failed';
            }
        }

        return 'completed';
    }
}
