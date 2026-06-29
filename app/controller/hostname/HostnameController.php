<?php

declare(strict_types=1);

namespace app\controller\hostname;

use app\controller\concerns\ResolvesQueryParams;
use app\controller\concerns\ValidatesInput;
use app\service\hostname\HostnameService;
use app\service\hostname\HostnameWorkflowService;
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
    use ValidatesInput;

    public function __construct(
        private readonly HostnameService $hostnames,
        private readonly HostnameWorkflowService $workflow,
    ) {
    }

    public function zones(string $providerId): Response
    {
        $query = $this->queryInput(HostnameValidate::class, 'listZones');

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
        $query = $this->queryInput(HostnameValidate::class, 'listHostnames');
        return ApiResponse::data($this->workflow->hostnames(
            $providerId,
            trim(rawurldecode($zoneName)),
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 100),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function store(string $providerId, string $zoneName): Response
    {
        $data = $this->postInput(HostnameValidate::class, 'store');
        return ApiResponse::data($this->workflow->createHostname(
            $providerId,
            trim(rawurldecode($zoneName)),
            $data,
            $this->boolQuery('auto_sync'),
        ), 201);
    }

    public function show(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $query = $this->queryInput(HostnameValidate::class, 'show');

        return ApiResponse::data($this->hostnames->showHostname(
            $providerId,
            trim(rawurldecode($zoneName)),
            trim(rawurldecode($hostnameFqdn)),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function update(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        $data = $this->putInput(HostnameValidate::class, 'update');
        return ApiResponse::data($this->workflow->updateHostname(
            $providerId,
            trim(rawurldecode($zoneName)),
            trim(rawurldecode($hostnameFqdn)),
            $data,
            $this->boolQuery('auto_sync'),
        ));
    }

    public function refresh(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        return ApiResponse::data($this->workflow->refreshHostname(
            $providerId,
            trim(rawurldecode($zoneName)),
            trim(rawurldecode($hostnameFqdn)),
        ));
    }

    public function delete(string $providerId, string $zoneName, string $hostnameFqdn): Response
    {
        return ApiResponse::data($this->workflow->deleteHostname(
            $providerId,
            trim(rawurldecode($zoneName)),
            trim(rawurldecode($hostnameFqdn)),
            $this->boolQuery('auto_cleanup', true),
        ));
    }

    public function fallbackOriginShow(string $providerId, string $zoneName): Response
    {
        $query = $this->queryInput(HostnameValidate::class, 'show');

        return ApiResponse::data($this->hostnames->fallbackOriginInfo(
            $providerId,
            trim(rawurldecode($zoneName)),
            (bool) ($query['refresh'] ?? false),
        ));
    }

    public function fallbackOriginUpdate(string $providerId, string $zoneName): Response
    {
        $data = $this->putInput(HostnameValidate::class, 'fallbackOrigin');

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
}
