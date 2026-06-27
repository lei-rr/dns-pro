<?php

declare(strict_types=1);

namespace app\controller\hostname;

use app\controller\concerns\ResolvesQueryParams;
use app\exception\ApiException;
use app\service\hostname\HostnameService;
use app\service\hostname\HostnameSyncService;
use app\support\ApiResponse;
use app\validate\HostnameValidate;
use think\Response;

/**
 * Hostname 控制器
 *
 * 路由参数:providerId / zoneName (Cloudflare zone) / hostnameFqdn (主机名 FQDN, 如 app.example.com)
 *
 * 行为约定:
 *   - 查询接口 ?refresh=1 透传给 service 触发缓存穿透
 *   - POST ?auto_sync=1:创建后自动同步到 DNSPod(需 dnspod_provider 已配置)
 *   - DELETE ?auto_cleanup=0:删除时跳过 DNSPod 记录清理(默认会清)
 */
class HostnameController
{
    use ResolvesQueryParams;

    public function __construct(
        private readonly HostnameService $hostnames,
        private readonly HostnameSyncService $sync,
    ) {
    }

    public function zones(string $providerId): Response
    {
        $query = $this->query('listZones');

        return ApiResponse::data($this->hostnames->zones(
            $providerId,
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 100),
            trim((string) ($query['name'] ?? '')),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function hostnames(string $providerId, string $zoneName): Response
    {
        $query = $this->query('listHostnames');
        $zone = trim(rawurldecode($zoneName));

        return ApiResponse::data($this->hostnames->hostnames(
            $providerId,
            $zone,
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 100),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function store(string $providerId, string $zoneName): Response
    {
        $data = $this->body('store', 'post');
        $zone = trim(rawurldecode($zoneName));
        $autoSync = $this->boolQuery('auto_sync');

        // autoSync 开启时先预检 DNSPod zone:不通过则不进入 Cloudflare 创建
        if ($autoSync) {
            $this->sync->preflight($providerId, (string) ($data['hostname'] ?? ''));
        }

        $result = $this->hostnames->createHostname($providerId, $zone, $data);

        if ($autoSync && !empty($result['hostname'])) {
            $result['dns_sync'] = $this->safeSync(fn () => $this->sync->sync($providerId, $zone, (string) $result['hostname']));
        }

        return ApiResponse::data($result, 201);
    }

    public function show(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $query = $this->query('show');

        return ApiResponse::data($this->hostnames->showHostname(
            $providerId,
            trim(rawurldecode($zoneName)),
            trim(rawurldecode($hostnameFqdn)),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function refresh(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $zone = trim(rawurldecode($zoneName));
        $fqdn = trim(rawurldecode($hostnameFqdn));

        $result = $this->hostnames->refreshHostname($providerId, $zone, $fqdn);

        // 仅在确认状态从"非 active 跃迁到 active"时,清理已经无用的所有权 TXT。
        // 旧状态未知(缓存空)则保守不操作 —— 同状态刷新不应该触碰 DNSPod。
        $activeStates = ['active', 'active_renewing', 'moved'];
        $previous = (string) ($result['previous_status'] ?? '');
        $current = (string) ($result['status'] ?? '');
        $transitioned = $previous !== ''
            && !in_array($previous, $activeStates, true)
            && in_array($current, $activeStates, true);
        if ($transitioned) {
            $result['dns_cleanup'] = $this->safeSync(
                fn () => $this->sync->cleanupStaleRecords($providerId, $zone, $fqdn),
            );
        }
        unset($result['previous_status']);

        return ApiResponse::data($result);
    }

    public function delete(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $zone = trim(rawurldecode($zoneName));
        $fqdn = trim(rawurldecode($hostnameFqdn));
        $autoCleanup = $this->boolQuery('auto_cleanup', true);

        // 删除前先收集要清理的记录(hostname 删除后无法再查 CF)
        $collected = $autoCleanup ? $this->sync->collectRecordsFor($providerId, $zone, $fqdn) : null;

        $result = $this->hostnames->deleteHostname($providerId, $zone, $fqdn);

        if ($collected && !empty($collected['records']) && $collected['hostname_fqdn'] !== '') {
            $result['dns_cleanup'] = $this->safeSync(
                fn () => $this->sync->cleanup($providerId, $collected['hostname_fqdn'], $collected['records']),
            );
        }

        return ApiResponse::data($result);
    }

    public function fallbackOriginShow(string $providerId, string $zoneName): Response
    {
        $query = $this->query('show');

        return ApiResponse::data($this->hostnames->fallbackOriginInfo(
            $providerId,
            trim(rawurldecode($zoneName)),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function fallbackOriginUpdate(string $providerId, string $zoneName): Response
    {
        $data = $this->body('fallbackOrigin', 'put');

        return ApiResponse::data($this->hostnames->setFallbackOrigin(
            $providerId,
            trim(rawurldecode($zoneName)),
            (string) $data['origin'],
        ));
    }

    public function fallbackOriginDelete(string $providerId, string $zoneName): Response
    {
        return ApiResponse::data($this->hostnames->deleteFallbackOrigin(
            $providerId,
            trim(rawurldecode($zoneName)),
        ));
    }

    // ---------- helpers ----------

    private function query(string $scene): array
    {
        return validate(HostnameValidate::class)->scene($scene)->checked(input('get.', []));
    }

    private function body(string $scene, string $verb): array
    {
        return validate(HostnameValidate::class)->scene($scene)->checked(input($verb . '.', []));
    }

    /**
     * 同步/清理调用包装:失败时返回 skipped 描述而不抛异常(不影响主流程响应)
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
}
