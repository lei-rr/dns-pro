<?php

declare(strict_types=1);

namespace app\controller\saas;

use app\controller\concerns\ResolvesQueryParams;
use app\controller\concerns\ValidatesInput;
use app\service\saas\SaasService;
use app\service\saas\SaasWorkflowService;
use app\support\ApiResponse;
use app\validate\SaasValidate;
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
class SaasController
{
    use ResolvesQueryParams;
    use ValidatesInput;

    public function __construct(
        private readonly SaasService $hostnames,
        private readonly SaasWorkflowService $workflow,
    ) {
    }

    public function zones(string $providerId): Response
    {
        $query = $this->queryInput(SaasValidate::class, 'listZones');

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
        $query = $this->queryInput(SaasValidate::class, 'listHostnames');

        return ApiResponse::data($this->workflow->hostnames(
            $providerId,
            $this->zoneName($zoneName),
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 20),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function store(string $providerId, string $zoneName): Response
    {
        $data = $this->postInput(SaasValidate::class, 'store');

        return ApiResponse::data($this->workflow->createHostname(
            $providerId,
            $this->zoneName($zoneName),
            $data,
            $this->boolQuery('auto_sync'),
        ), 201);
    }

    public function show(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $query = $this->queryInput(SaasValidate::class, 'show');

        return ApiResponse::data($this->hostnames->showHostname(
            $providerId,
            $this->zoneName($zoneName),
            $this->hostnameFqdn($hostnameFqdn),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function update(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $data = $this->putInput(SaasValidate::class, 'update');

        return ApiResponse::data($this->workflow->updateHostname(
            $providerId,
            $this->zoneName($zoneName),
            $this->hostnameFqdn($hostnameFqdn),
            $data,
            $this->boolQuery('auto_sync'),
        ));
    }

    public function refresh(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        return ApiResponse::data($this->workflow->refreshHostname(
            $providerId,
            $this->zoneName($zoneName),
            $this->hostnameFqdn($hostnameFqdn),
        ));
    }

    public function delete(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        return ApiResponse::data($this->workflow->deleteHostname(
            $providerId,
            $this->zoneName($zoneName),
            $this->hostnameFqdn($hostnameFqdn),
            $this->boolQuery('auto_cleanup', true),
        ));
    }

    public function fallbackOriginShow(string $providerId, string $zoneName): Response
    {
        $query = $this->queryInput(SaasValidate::class, 'show');

        return ApiResponse::data($this->hostnames->fallbackOriginInfo(
            $providerId,
            $this->zoneName($zoneName),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function fallbackOriginUpdate(string $providerId, string $zoneName): Response
    {
        $data = $this->putInput(SaasValidate::class, 'fallbackOrigin');

        return ApiResponse::data($this->hostnames->setFallbackOrigin(
            $providerId,
            $this->zoneName($zoneName),
            (string) $data['origin'],
        ));
    }

    public function fallbackOriginDelete(string $providerId, string $zoneName): Response
    {
        return ApiResponse::data($this->hostnames->deleteFallbackOrigin(
            $providerId,
            $this->zoneName($zoneName),
        ));
    }

    private function zoneName(string $zoneName): string
    {
        return trim(rawurldecode($zoneName));
    }

    private function hostnameFqdn(string $hostnameFqdn): string
    {
        return trim(rawurldecode($hostnameFqdn));
    }
}
