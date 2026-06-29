<?php

declare(strict_types=1);

namespace app\service\edgeone;

use app\exception\ApiException;
use app\support\SideEffectResult;

/**
 * EdgeOne 编排服务
 *
 * 把 EdgeOne 领域操作与 DNSPod 同步/清理副作用编排拆开：
 *   - EdgeOneService 负责腾讯云 EdgeOne 领域 API 操作
 *   - EdgeOneWorkflowService 负责 auto_sync / auto_cleanup / DNS 降级语义
 */
class EdgeOneWorkflowService
{
    public function __construct(
        private readonly EdgeOneService $edgeone,
        private readonly EdgeOneSyncService $sync,
        private readonly EdgeOneMapper $mapper,
    ) {
    }

    public function createAccelerationDomain(string $providerId, string $zoneId, array $data, bool $autoSync = false): array
    {
        if ($autoSync) {
            $this->sync->preflight($providerId, (string) ($data['domain_name'] ?? ''));
        }

        $result = $this->edgeone->createAccelerationDomain($providerId, $zoneId, $data);

        if ($autoSync && !empty($result['name'])) {
            $sync = $this->safeSyncCname($providerId, $zoneId, (string) $result['name']);
            $result += SideEffectResult::dns([
                'sync' => SideEffectResult::operation(
                    ($sync['synced'] ?? false) ? 'completed' : (($sync['action'] ?? '') === 'failed' ? 'failed' : 'skipped'),
                    (string) ($sync['message'] ?? '已执行 DNS 同步'),
                    $sync,
                ),
            ]);
        }

        return $result;
    }

    public function deleteAccelerationDomain(string $providerId, string $zoneId, string $domainName, bool $autoCleanup = false): array
    {
        $cname = '';
        if ($autoCleanup) {
            try {
                $cname = $this->edgeone->assignedCname($providerId, $zoneId, $domainName);
            } catch (\Throwable) {
                $cname = '';
            }
        }

        $result = $this->edgeone->deleteAccelerationDomain($providerId, $zoneId, $domainName);

        if ($autoCleanup) {
            $cleanup = $this->safeCleanupCname($providerId, $domainName, $cname);
            $result += SideEffectResult::dns([
                'cleanup' => SideEffectResult::operation(
                    ((int) ($cleanup['cleaned'] ?? 0)) > 0 ? 'completed' : ((($cleanup['reason'] ?? '') !== '' ? 'skipped' : 'completed')),
                    (string) ($cleanup['message'] ?? '已执行 DNS 清理'),
                    $cleanup,
                ),
            ]);
        }

        return $result;
    }

    public function syncCname(string $providerId, string $zoneId, string $domainName): array
    {
        $cname = $this->edgeone->assignedCname($providerId, $zoneId, $domainName);

        if ($cname === '') {
            throw new ApiException('EdgeOne CNAME not found', 502, 'edgeone_cname_empty', [
                'provider_id' => $providerId,
                'zone_id' => $zoneId,
                'domain_name' => $domainName,
            ]);
        }

        $sync = $this->presentCnameSync($this->sync->sync($providerId, $domainName, $cname));

        return $sync + SideEffectResult::dns([
            'sync' => SideEffectResult::operation(
                ($sync['synced'] ?? false) ? 'completed' : (($sync['action'] ?? '') === 'failed' ? 'failed' : 'skipped'),
                (string) ($sync['message'] ?? '已执行 DNS 同步'),
                $sync,
            ),
        ]);
    }

    private function safeSyncCname(string $providerId, string $zoneId, string $domainName): array
    {
        try {
            $cname = $this->edgeone->assignedCname($providerId, $zoneId, $domainName);
            if ($cname === '') {
                return $this->mapper->syncResult(false, 'skipped', 'EdgeOne CNAME 尚未分配,稍后可手动同步', '');
            }

            return $this->presentCnameSync($this->sync->sync($providerId, $domainName, $cname));
        } catch (ApiException $e) {
            return $this->mapper->syncResult(false, 'skipped', $e->getMessage(), '');
        } catch (\Throwable $e) {
            return $this->mapper->syncResult(false, 'failed', $e->getMessage(), '');
        }
    }

    private function safeCleanupCname(string $providerId, string $domainName, string $cname): array
    {
        try {
            return $this->sync->cleanup($providerId, $domainName, $cname);
        } catch (ApiException $e) {
            return ['cleaned' => 0, 'records' => [], 'reason' => $e->getErrorCode() ?: 'cleanup_skipped', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['cleaned' => 0, 'records' => [], 'reason' => 'cleanup_failed', 'message' => $e->getMessage()];
        }
    }

    private function presentCnameSync(array $syncResult): array
    {
        $status = (string) ($syncResult['record']['status'] ?? '');

        return $this->mapper->syncResult(
            $status !== 'failed',
            $status ?: 'unknown',
            $this->syncActionMessage($status),
            (string) ($syncResult['record']['record_id'] ?? ''),
        );
    }

    private function syncActionMessage(string $status): string
    {
        return match ($status) {
            'created' => 'DNSPod CNAME 已创建',
            'updated' => 'DNSPod CNAME 已更新',
            'unchanged' => 'DNSPod CNAME 已是最新',
            'failed' => 'DNSPod CNAME 同步失败',
            default => 'DNSPod CNAME 同步完成',
        };
    }
}
