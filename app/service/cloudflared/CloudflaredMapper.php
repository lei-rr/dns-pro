<?php

declare(strict_types=1);

namespace app\service\cloudflared;

/**
 * Cloudflare Tunnel 数据映射 + 安装命令生成
 */
class CloudflaredMapper
{
    /** 支持的服务协议 */
    public const SERVICE_PROTOCOLS = ['http', 'https', 'tcp', 'ssh', 'rdp', 'smb'];

    public function presentTunnel(array $tunnel): array
    {
        return [
            'id' => $tunnel['id'] ?? null,
            'name' => $tunnel['name'] ?? null,
            'status' => $tunnel['status'] ?? 'inactive',
            'config_src' => $tunnel['config_src'] ?? null,
            'remote_config' => $tunnel['remote_config'] ?? false,
            'connections' => array_map(
                fn (array $conn) => $this->presentConnection($conn),
                $tunnel['connections'] ?? [],
            ),
            'conns_active_at' => $tunnel['conns_active_at'] ?? null,
            'conns_inactive_at' => $tunnel['conns_inactive_at'] ?? null,
            'created_at' => $tunnel['created_at'] ?? null,
        ];
    }

    public function presentConnection(array $conn): array
    {
        return [
            'id' => $conn['id'] ?? null,
            'client_id' => $conn['client_id'] ?? null,
            'client_version' => $conn['client_version'] ?? null,
            'colo_name' => $conn['colo_name'] ?? null,
            'is_pending_reconnect' => $conn['is_pending_reconnect'] ?? false,
            'opened_at' => $conn['opened_at'] ?? null,
            'origin_ip' => $conn['origin_ip'] ?? null,
        ];
    }

    public function presentConfig(array $config): array
    {
        $ingress = $config['config']['ingress'] ?? [];

        // 最后一条无 hostname 的是 catch-all，分开
        $routes = [];
        $catchAll = null;

        foreach ($ingress as $rule) {
            if (($rule['hostname'] ?? '') === '') {
                $catchAll = $rule['service'] ?? 'http_status:404';
            } else {
                $routes[] = [
                    'hostname' => $rule['hostname'],
                    'service' => $rule['service'],
                    'path' => $rule['path'] ?? '',
                ];
            }
        }

        return [
            'routes' => $routes,
            'catch_all' => $catchAll ?? 'http_status:404',
            'version' => $config['version'] ?? 0,
        ];
    }

    /**
     * 把路由列表反序列化为 CF ingress 配置数组（自动补 catch-all）
     *
     * @param array<int, array{hostname:string, service:string, path?:string}> $routes
     * @param string $catchAll catch-all 服务，默认 404
     */
    public function buildIngress(array $routes, string $catchAll = 'http_status:404'): array
    {
        $ingress = [];
        foreach ($routes as $route) {
            $entry = [
                'hostname' => (string) ($route['hostname'] ?? ''),
                'service' => (string) ($route['service'] ?? ''),
            ];
            if (($route['path'] ?? '') !== '') {
                $entry['path'] = (string) $route['path'];
            }
            $ingress[] = $entry;
        }
        // CF 要求最后必须有一个无 hostname 的 catch-all
        $ingress[] = ['service' => $catchAll];

        return ['config' => ['ingress' => $ingress]];
    }

    /**
     * 构造 service URL
     */
    public function buildServiceUrl(string $protocol, string $address): string
    {
        $protocol = strtolower(trim($protocol));
        $address = trim($address);

        if (!in_array($protocol, self::SERVICE_PROTOCOLS, true)) {
            $protocol = 'http';
        }

        return "{$protocol}://{$address}";
    }
}
