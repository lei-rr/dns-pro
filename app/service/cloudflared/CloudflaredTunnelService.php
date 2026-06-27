<?php

declare(strict_types=1);

namespace app\service\cloudflared;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\cloudflare\CloudflareApiClient;
use app\service\cloudflare\CloudflareDnsRecordService;
use app\service\cloudflare\CloudflareZoneService;
use app\service\concerns\ProviderServiceConcern;

/**
 * Cloudflare Tunnel 服务
 *
 * 封装隧道 CRUD、token 获取、ingress 配置、DNS 路由（在 CF zone 建 CNAME）。
 *
 * 模块边界：
 *   - 依赖 cloudflare 模块的 ApiClient / ZoneService / DnsRecordService（单向）
 *   - 通过 cloudflared provider 的 cloudflare_provider 字段找到关联的 CF 凭据
 *   - 不依赖 dnspod / edgeone / hostname 模块
 */
class CloudflaredTunnelService
{
    use ProviderServiceConcern;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareApiClient $client,
        private readonly CloudflareZoneService $cfZones,
        private readonly CloudflareDnsRecordService $cfDns,
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
        } catch (\Throwable) {
            // 无连接时会 404，忽略
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

    // ---------- Configurations (ingress) ----------

    public function getConfig(string $providerId, string $tunnelId, bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:tunnel_config', [
            'provider_id' => $providerId,
            'tunnel_id' => $tunnelId,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        $payload = $this->client->get($provider, "accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations");
        $result = $this->mapper->presentConfig($payload['result'] ?? []);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->tunnelConfigCacheTag($providerId, $tunnelId),
        ]);

        return $result;
    }

    /**
     * 添加一条路由（追加到现有 ingress，自动建 CNAME）
     *
     * ingress 是主流程；DNS（CNAME）失败降级为告警，不阻断、不回滚，
     * 返回 dns.action=failed 供前端提示用户手动处理。
     *
     * @param array{hostname:string, service:string, zone_id:string, path?:string} $route
     */
    public function addRoute(string $providerId, string $tunnelId, array $route): array
    {
        $route = $this->normalizeRoute($route);
        $current = $this->fetchRoutes($providerId, $tunnelId);

        // 检查 hostname + path 是否已存在
        foreach ($current as $existing) {
            if ($this->isSameRouteKey($existing, $route)) {
                throw new ApiException('Route already exists', 409, 'cloudflared_route_exists', [
                    'hostname' => $route['hostname'],
                    'path' => $route['path'],
                ]);
            }
        }

        $newRoutes = [...$current, [
            'hostname' => $route['hostname'],
            'service' => $route['service'],
            'path' => $route['path'],
        ]];

        $this->writeIngress($providerId, $tunnelId, $newRoutes);
        $dnsResult = $this->safeEnsureCname($this->cfProviderIdOf($providerId), $route['zone_id'], $route['hostname'], $tunnelId);
        $this->invalidateCache($this->tunnelConfigCacheTag($providerId, $tunnelId));

        return [
            'hostname' => $route['hostname'],
            'service' => $route['service'],
            'path' => $route['path'],
            'dns' => $dnsResult,
        ];
    }

    /**
     * 编辑一条路由（按原 hostname + path 定位）
     *
     * @param string $originalHostname 原 hostname
     * @param string $originalPath 原 path
     * @param array{hostname:string, service:string, zone_id:string, path?:string} $route 新的路由数据
     */
    public function updateRoute(string $providerId, string $tunnelId, string $originalHostname, string $originalPath, array $route): array
    {
        $route = $this->normalizeRoute($route);
        $cfProviderId = $this->cfProviderIdOf($providerId);
        $current = $this->fetchRoutes($providerId, $tunnelId);

        $found = false;
        $newRoutes = [];
        foreach ($current as $existing) {
            if (!$found && ($existing['hostname'] ?? '') === strtolower($originalHostname) && ($existing['path'] ?? '') === $originalPath) {
                $newRoutes[] = [
                    'hostname' => $route['hostname'],
                    'service' => $route['service'],
                    'path' => $route['path'],
                ];
                $found = true;
            } else {
                $newRoutes[] = $existing;
            }
        }

        if (!$found) {
            throw new ApiException('Route not found', 404, 'cloudflared_route_not_found', [
                'hostname' => $originalHostname,
                'path' => $originalPath,
            ]);
        }

        // 改动后若与其他路由 hostname+path 撞键 → 冲突
        $sameKeyCount = 0;
        foreach ($newRoutes as $r) {
            if ($this->isSameRouteKey($r, $route)) {
                $sameKeyCount++;
            }
        }
        if ($sameKeyCount > 1) {
            throw new ApiException('Route conflict', 409, 'cloudflared_route_exists', [
                'hostname' => $route['hostname'],
                'path' => $route['path'],
            ]);
        }

        $this->writeIngress($providerId, $tunnelId, $newRoutes);

        // DNS 处理：hostname 变了 → 删旧 CNAME；总是确保新 hostname 的 CNAME 存在
        $hostnameChanged = strtolower($originalHostname) !== $route['hostname'];
        if ($hostnameChanged) {
            // 检查旧 hostname 是否还被其他路由使用，没有才删 CNAME
            $stillUsed = array_filter($newRoutes, fn (array $r) => ($r['hostname'] ?? '') === strtolower($originalHostname));
            if (count($stillUsed) === 0) {
                $this->removeCnameBestEffort($cfProviderId, $originalHostname, $tunnelId);
            }
        }
        $dnsResult = $this->safeEnsureCname($cfProviderId, $route['zone_id'], $route['hostname'], $tunnelId);
        $this->invalidateCache($this->tunnelConfigCacheTag($providerId, $tunnelId));

        return [
            'hostname' => $route['hostname'],
            'service' => $route['service'],
            'path' => $route['path'],
            'dns' => $dnsResult,
        ];
    }

    /**
     * 删除一条路由（按 hostname + path 定位）
     */
    public function deleteRoute(string $providerId, string $tunnelId, string $hostname, string $path, string $zoneId): array
    {
        $cfProviderId = $this->cfProviderIdOf($providerId);
        $current = $this->fetchRoutes($providerId, $tunnelId);
        $normalizedHostname = strtolower(trim($hostname));

        $found = false;
        $newRoutes = [];
        foreach ($current as $existing) {
            if (!$found && ($existing['hostname'] ?? '') === $normalizedHostname && ($existing['path'] ?? '') === $path) {
                $found = true;
                continue;
            }
            $newRoutes[] = $existing;
        }

        if (!$found) {
            throw new ApiException('Route not found', 404, 'cloudflared_route_not_found', [
                'hostname' => $hostname,
                'path' => $path,
            ]);
        }

        $this->writeIngress($providerId, $tunnelId, $newRoutes);

        // 同 hostname 还被其他路由使用 → 保留 CNAME；否则删
        $stillUsed = array_filter($newRoutes, fn (array $r) => ($r['hostname'] ?? '') === $normalizedHostname);
        if (count($stillUsed) > 0) {
            $dnsResult = ['action' => 'kept', 'reason' => 'hostname_still_used'];
        } else {
            // zone_id 缺失时后端兜底解析（最长后缀匹配）
            $resolvedZoneId = $zoneId !== '' ? $zoneId : $this->resolveZoneId($cfProviderId, $normalizedHostname);
            $dnsResult = $resolvedZoneId !== ''
                ? $this->removeCname($cfProviderId, $resolvedZoneId, $normalizedHostname, $tunnelId)
                : ['action' => 'skipped', 'reason' => 'zone_not_found'];
        }

        $this->invalidateCache($this->tunnelConfigCacheTag($providerId, $tunnelId));

        return ['hostname' => $normalizedHostname, 'path' => $path, 'dns' => $dnsResult];
    }

    // ---------- CF Zone 列表（供前端下拉选择） ----------

    public function zones(string $providerId, bool $refresh = false): array
    {
        $cfProviderId = $this->cfProviderIdOf($providerId);

        return $this->cfZones->list($cfProviderId, 1, 100, '', $refresh);
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

    /**
     * 归一化路由入参并校验必填
     *
     * @return array{hostname:string, service:string, zone_id:string, path:string}
     */
    private function normalizeRoute(array $route): array
    {
        $hostname = strtolower(trim((string) ($route['hostname'] ?? '')));
        $service = trim((string) ($route['service'] ?? ''));
        $zoneId = trim((string) ($route['zone_id'] ?? ''));
        $path = trim((string) ($route['path'] ?? ''));

        if ($hostname === '' || $service === '' || $zoneId === '') {
            throw new ApiException('hostname, service, zone_id are required', 422, 'cloudflared_route_invalid');
        }

        return ['hostname' => $hostname, 'service' => $service, 'zone_id' => $zoneId, 'path' => $path];
    }

    /**
     * 拉取当前隧道的路由列表（不含 catch-all）
     *
     * @return array<int, array{hostname:string, service:string, path:string}>
     */
    private function fetchRoutes(string $providerId, string $tunnelId): array
    {
        // 写操作前必须拿最新数据，绕过缓存
        return $this->getConfig($providerId, $tunnelId, true)['routes'] ?? [];
    }

    /**
     * 把路由列表写回 ingress（序列化委托 Mapper）
     *
     * @param array<int, array{hostname:string, service:string, path?:string}> $routes
     */
    private function writeIngress(string $providerId, string $tunnelId, array $routes): void
    {
        $provider = $this->cfProvider($providerId);
        $accountId = $this->requireAccountId($provider);

        $this->client->put(
            $provider,
            "accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations",
            $this->mapper->buildIngress($routes),
        );
    }

    /**
     * 路由唯一键判定：hostname + path 相同视为同一条
     */
    private function isSameRouteKey(array $a, array $b): bool
    {
        return ($a['hostname'] ?? '') === ($b['hostname'] ?? '')
            && ($a['path'] ?? '') === ($b['path'] ?? '');
    }

    /**
     * ensureCname 的降级包装：DNS 失败不抛异常，返回 action=failed 供前端提示
     */
    private function safeEnsureCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        try {
            return $this->ensureCname($cfProviderId, $zoneId, $hostname, $tunnelId);
        } catch (\Throwable $e) {
            return ['action' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * 确保 CF zone 中存在指向隧道的 CNAME（幂等）
     */
    private function ensureCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        $cnameTarget = "{$tunnelId}.cfargotunnel.com";

        $existing = $this->cfDns->list($cfProviderId, $zoneId, [
            'type' => 'CNAME',
            'search' => $hostname,
            'page' => 1,
            'per_page' => 10,
        ]);

        foreach ($existing['items'] ?? [] as $record) {
            if (($record['name'] ?? '') !== $hostname) {
                continue;
            }
            if (($record['type'] ?? '') === 'CNAME' && ($record['content'] ?? '') === $cnameTarget) {
                return ['action' => 'unchanged', 'record_id' => $record['id'] ?? ''];
            }
            if (($record['type'] ?? '') === 'CNAME') {
                $updated = $this->cfDns->update($cfProviderId, $zoneId, (string) $record['id'], [
                    'type' => 'CNAME',
                    'name' => $hostname,
                    'content' => $cnameTarget,
                    'proxied' => true,
                    'ttl' => 1,
                ]);
                return ['action' => 'updated', 'record_id' => $updated['id'] ?? ''];
            }
        }

        $created = $this->cfDns->create($cfProviderId, $zoneId, [
            'type' => 'CNAME',
            'name' => $hostname,
            'content' => $cnameTarget,
            'proxied' => true,
            'ttl' => 1,
        ]);

        return ['action' => 'created', 'record_id' => $created['id'] ?? ''];
    }

    /**
     * 删除 CF zone 中指向隧道的 CNAME
     */
    private function removeCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        $cnameTarget = "{$tunnelId}.cfargotunnel.com";

        $existing = $this->cfDns->list($cfProviderId, $zoneId, [
            'type' => 'CNAME',
            'search' => $hostname,
            'page' => 1,
            'per_page' => 10,
        ]);

        foreach ($existing['items'] ?? [] as $record) {
            if (($record['name'] ?? '') === $hostname && ($record['content'] ?? '') === $cnameTarget) {
                $this->cfDns->delete($cfProviderId, $zoneId, (string) $record['id']);
                return ['action' => 'deleted', 'record_id' => $record['id']];
            }
        }

        return ['action' => 'not_found'];
    }

    /**
     * 编辑改名场景下删旧 hostname 的 CNAME；zone 未知时最长后缀匹配解析
     */
    private function removeCnameBestEffort(string $cfProviderId, string $hostname, string $tunnelId): void
    {
        $normalized = strtolower(trim($hostname));
        $zoneId = $this->resolveZoneId($cfProviderId, $normalized);
        if ($zoneId === '') {
            return;
        }

        try {
            $this->removeCname($cfProviderId, $zoneId, $normalized, $tunnelId);
        } catch (\Throwable) {
            // 尽力而为，失败忽略
        }
    }

    /**
     * 用 CF zone 列表对 FQDN 做最长后缀匹配，返回 zone id；找不到返回空串
     *
     * 比机械取末两段更健壮（正确处理 a.b.co.uk 这类多级 TLD）。
     */
    private function resolveZoneId(string $cfProviderId, string $fqdn): string
    {
        $fqdn = strtolower(rtrim(trim($fqdn), '.'));
        if ($fqdn === '') {
            return '';
        }

        try {
            $zones = $this->cfZones->list($cfProviderId, 1, 100);
        } catch (\Throwable) {
            return '';
        }

        $bestName = '';
        $bestId = '';
        foreach ($zones['items'] ?? [] as $zone) {
            $name = strtolower((string) ($zone['name'] ?? ''));
            $id = (string) ($zone['id'] ?? '');
            if ($name === '' || $id === '') {
                continue;
            }
            if (($fqdn === $name || str_ends_with($fqdn, '.' . $name)) && strlen($name) > strlen($bestName)) {
                $bestName = $name;
                $bestId = $id;
            }
        }

        return $bestId;
    }

    private function tunnelCacheTag(string $providerId): string
    {
        return $this->buildCacheTag('cloudflare', 'tunnels', $providerId);
    }

    private function tunnelConfigCacheTag(string $providerId, string $tunnelId): string
    {
        return $this->buildCacheTag('cloudflare', 'tunnel_config', $providerId, $tunnelId);
    }
}
