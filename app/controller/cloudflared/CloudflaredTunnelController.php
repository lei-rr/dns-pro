<?php

declare(strict_types=1);

namespace app\controller\cloudflared;

use app\controller\concerns\ResolvesQueryParams;
use app\service\cloudflared\CloudflaredMapper;
use app\service\cloudflared\CloudflaredTunnelService;
use app\support\ApiResponse;
use app\validate\CloudflaredValidate;
use think\Response;

/**
 * Cloudflared 隧道控制器
 *
 * 路由参数:providerId / tunnelId
 *
 * 行为约定:
 *   - GET ?refresh=1:  透传给 service 触发缓存穿透
 *   - POST 路由:        body 含 hostname / protocol / address / zone_id / path
 *   - PUT 路由:         body 同上；query original_hostname / original_path 定位待改路由
 *   - DELETE 路由:      query 含 hostname / path / zone_id
 */
class CloudflaredTunnelController
{
    use ResolvesQueryParams;

    public function __construct(
        private readonly CloudflaredTunnelService $tunnels,
        private readonly CloudflaredMapper $mapper,
    ) {
    }

    public function index(string $providerId): Response
    {
        $refresh = $this->boolQuery('refresh');

        return ApiResponse::data($this->tunnels->list($providerId, $refresh));
    }

    public function show(string $providerId, string $tunnelId): Response
    {
        return ApiResponse::data($this->tunnels->show($providerId, $tunnelId, $this->boolQuery('refresh')));
    }

    public function store(string $providerId): Response
    {
        $data = validate(CloudflaredValidate::class)
            ->scene('create')
            ->checked(input('post.', []));

        return ApiResponse::data(
            $this->tunnels->create($providerId, trim((string) $data['name'])),
            201,
        );
    }

    public function delete(string $providerId, string $tunnelId): Response
    {
        return ApiResponse::data($this->tunnels->delete($providerId, $tunnelId));
    }

    public function token(string $providerId, string $tunnelId): Response
    {
        return ApiResponse::data($this->tunnels->token($providerId, $tunnelId));
    }

    public function rotateToken(string $providerId, string $tunnelId): Response
    {
        return ApiResponse::data($this->tunnels->rotateToken($providerId, $tunnelId));
    }

    public function configShow(string $providerId, string $tunnelId): Response
    {
        return ApiResponse::data($this->tunnels->getConfig($providerId, $tunnelId, $this->boolQuery('refresh')));
    }

    public function addRoute(string $providerId, string $tunnelId): Response
    {
        $data = validate(CloudflaredValidate::class)
            ->scene('route')
            ->checked(input('post.', []));

        return ApiResponse::data(
            $this->tunnels->addRoute($providerId, $tunnelId, $this->routePayload($data)),
            201,
        );
    }

    public function updateRoute(string $providerId, string $tunnelId): Response
    {
        $data = validate(CloudflaredValidate::class)
            ->scene('route')
            ->checked(input('put.', []));

        $originalHostname = trim((string) input('get.original_hostname', ''));
        $originalPath = (string) input('get.original_path', '');

        if ($originalHostname === '') {
            $originalHostname = (string) $data['hostname'];
            $originalPath = (string) ($data['path'] ?? '');
        }

        return ApiResponse::data(
            $this->tunnels->updateRoute(
                $providerId,
                $tunnelId,
                $originalHostname,
                $originalPath,
                $this->routePayload($data),
            ),
        );
    }

    public function deleteRoute(string $providerId, string $tunnelId): Response
    {
        $hostname = trim((string) input('get.hostname', ''));
        $path = (string) input('get.path', '');
        $zoneId = trim((string) input('get.zone_id', ''));

        return ApiResponse::data($this->tunnels->deleteRoute($providerId, $tunnelId, $hostname, $path, $zoneId));
    }

    public function zones(string $providerId): Response
    {
        return ApiResponse::data($this->tunnels->zones($providerId, $this->boolQuery('refresh')));
    }

    /**
     * 把 validate 通过的入参组装成 service 需要的 route 结构
     */
    private function routePayload(array $data): array
    {
        return [
            'hostname' => (string) $data['hostname'],
            'service' => $this->mapper->buildServiceUrl(
                (string) ($data['protocol'] ?? 'http'),
                (string) $data['address'],
            ),
            'zone_id' => (string) $data['zone_id'],
            'path' => (string) ($data['path'] ?? ''),
        ];
    }
}
