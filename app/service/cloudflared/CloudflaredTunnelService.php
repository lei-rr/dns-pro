<?php

declare(strict_types=1);

namespace app\service\cloudflared;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\cloudflare\CloudflareApiClient;
use app\service\concerns\ProviderServiceConcern;

/**
 * Cloudflare Tunnel 服务
 *
 * 领域服务：封装隧道生命周期相关能力（列表、详情、创建、删除、token 获取与轮换）。
 * ingress 配置与 DNS 路由编排由 CloudflaredRouteService 负责。
 *
 * 模块边界：
 *   - 依赖 cloudflare 模块的 ApiClient（单向）
 *   - 通过 cloudflared provider 的 cloudflare_provider 字段找到关联的 CF 凭据
 *   - 不依赖 dnspod / edgeone / saas 模块
 */
class CloudflaredTunnelService
{
    use ProviderServiceConcern;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareApiClient $client,
        private readonly CloudflaredMapper $mapper,
    ) {
    }

    // ---------- Tunnels ----------

    public function list(string $providerId, bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:tunnels', [
            'provider_id' => $providerId,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        // 翻页拉全量，避免 >100 隧道丢失
        $items = [];
        $page = 1;
        do {
            $payload = $this->client->get($provider, "accounts/{$accountId}/cfd_tunnel", [
                'is_deleted' => 'false',
                'page' => $page,
                'per_page' => 100,
            ]);
            $batch = $payload['result'] ?? [];
            foreach ($batch as $tunnel) {
                $items[] = $this->mapper->presentTunnel($tunnel);
            }
            $count = count($batch);
            $page++;
        } while ($count === 100);

        $result = ['items' => $items];

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->tunnelCacheTag($providerId),
        ]);

        return $result;
    }

    /**
     * 查询单个隧道（详情页轮询用，命中单隧道接口，避免拉全量列表）
     */
    public function show(string $providerId, string $tunnelId, bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:tunnels', [
            'provider_id' => $providerId,
            'tunnel_id' => $tunnelId,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        $payload = $this->client->get($provider, "accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        $result = $this->mapper->presentTunnel($payload['result'] ?? []);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->tunnelCacheTag($providerId),
        ]);

        return $result;
    }

    public function create(string $providerId, string $name): array
    {
        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        $payload = $this->client->post($provider, "accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'config_src' => 'cloudflare',
            'tunnel_secret' => base64_encode(random_bytes(32)),
        ]);

        $this->invalidateCache($this->tunnelCacheTag($providerId));

        $tunnel = $this->mapper->presentTunnel($payload['result'] ?? []);

        // 获取 token
        $tunnelId = (string) $tunnel['id'];
        $token = $this->fetchToken($provider, $accountId, $tunnelId);

        return [
            'tunnel' => $tunnel,
            'token' => $token,
        ];
    }

    public function delete(string $providerId, string $tunnelId): array
    {
        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        // CF 要求删除前先清理连接
        try {
            $this->client->delete($provider, "accounts/{$accountId}/cfd_tunnel/{$tunnelId}/connections");
        } catch (ApiException $e) {
            if (!$this->isTunnelConnectionNotFound($e)) {
                throw $e;
            }
        }

        $this->client->delete($provider, "accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        $this->invalidateCache($this->tunnelCacheTag($providerId));

        return ['id' => $tunnelId];
    }

    public function token(string $providerId, string $tunnelId): array
    {
        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);
        $token = $this->fetchToken($provider, $accountId, $tunnelId);

        return [
            'token' => $token,
        ];
    }

    /**
     * 轮换令牌：更新 tunnel_secret 使旧 token 失效，返回新 token
     *
     * 轮换后所有副本需用新 token 重新连接，旧 token 立即失效。
     */
    public function rotateToken(string $providerId, string $tunnelId): array
    {
        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        $this->client->patch($provider, "accounts/{$accountId}/cfd_tunnel/{$tunnelId}", [
            'tunnel_secret' => base64_encode(random_bytes(32)),
        ]);

        // secret 变更后隧道状态/连接会变化，失效相关缓存
        $this->invalidateCache($this->tunnelCacheTag($providerId));

        $token = $this->fetchToken($provider, $accountId, $tunnelId);

        return [
            'token' => $token,
        ];
    }

    // ---------- private ----------

    /**
     * 取关联的 cloudflare provider（完整记录，含 api_token / account_id）
     */
    private function cfProvider(string $providerId): array
    {
        return $this->providers->requireType(
            $this->cfProviderIdOf($providerId),
            'cloudflare',
            'Cloudflare provider not found',
            'cloudflare_provider_not_found',
        );
    }

    /**
     * 取 cloudflared provider 关联的 cloudflare provider id
     */
    private function cfProviderIdOf(string $providerId): string
    {
        $cloudflaredProvider = $this->providers->requireType(
            $providerId,
            'cloudflared',
            'Cloudflare Tunnel provider not found',
            'cloudflared_provider_not_found',
        );

        $cfProviderId = trim((string) ($cloudflaredProvider['cloudflare_provider'] ?? ''));
        if ($cfProviderId === '') {
            throw new ApiException(
                'Cloudflare Tunnel provider is not linked to a Cloudflare provider',
                422,
                'cloudflared_cloudflare_provider_missing',
                ['provider_id' => $providerId],
            );
        }

        return $cfProviderId;
    }

    private function requireAccountId(array $provider): string
    {
        $accountId = trim((string) ($provider['account_id'] ?? ''));
        if ($accountId === '') {
            throw new ApiException(
                'Cloudflare account_id is required for tunnel operations',
                422,
                'cloudflared_account_id_required',
            );
        }

        return $accountId;
    }

    private function fetchToken(array $provider, string $accountId, string $tunnelId): string
    {
        $payload = $this->client->get($provider, "accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token");

        return (string) ($payload['result'] ?? '');
    }

    private function isTunnelConnectionNotFound(ApiException $e): bool
    {
        return (int) ($e->getDetails()['http_status'] ?? 0) === 404;
    }

    private function tunnelCacheTag(string $providerId): string
    {
        return $this->buildCacheTag('cloudflare', 'tunnels', $providerId);
    }
}
