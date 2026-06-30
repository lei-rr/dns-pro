<?php

declare(strict_types=1);

namespace app\service\cloudflared;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\cloudflare\CloudflareApiClient;
use app\service\cloudflare\CloudflareDnsRecordGateway;
use app\service\cloudflare\CloudflareZoneGateway;
use app\service\concerns\ProviderServiceConcern;
use app\support\SideEffectResult;

/**
 * Cloudflared 路由服务
 *
 * 专注隧道 ingress 配置、公共主机名路由以及 Cloudflare DNS CNAME 编排。
 * 与隧道生命周期（list/show/create/delete/token）拆分，避免单个 service 同时承担两类职责。
 */
class CloudflaredRouteService
{
    use ProviderServiceConcern;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareApiClient $client,
        private readonly CloudflareZoneGateway $cfZones,
        private readonly CloudflareDnsRecordGateway $cfDns,
        private readonly CloudflaredMapper $mapper,
    ) {
    }

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
        ] + SideEffectResult::dns([
            'sync' => $this->normalizeDnsOperation($dnsResult, '已执行 Cloudflare DNS 同步'),
        ]);
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

        $hostnameChanged = strtolower($originalHostname) !== $route['hostname'];
        if ($hostnameChanged) {
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
        ] + SideEffectResult::dns([
            'sync' => $this->normalizeDnsOperation($dnsResult, '已执行 Cloudflare DNS 同步'),
        ]);
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

        $stillUsed = array_filter($newRoutes, fn (array $r) => ($r['hostname'] ?? '') === $normalizedHostname);
        if (count($stillUsed) > 0) {
            $dnsResult = ['action' => 'kept', 'reason' => 'hostname_still_used'];
        } else {
            $dnsResult = $this->safeRemoveCname($cfProviderId, $zoneId, $normalizedHostname, $tunnelId);
        }

        $this->invalidateCache($this->tunnelConfigCacheTag($providerId, $tunnelId));

        return ['hostname' => $normalizedHostname, 'path' => $path] + SideEffectResult::dns([
            'cleanup' => $this->normalizeDnsOperation($dnsResult, '已执行 Cloudflare DNS 清理'),
        ]);
    }

    public function zones(string $providerId, bool $refresh = false): array
    {
        $cfProviderId = $this->cfProviderIdOf($providerId);

        return $this->cfZones->list($cfProviderId, 1, 100, '', $refresh);
    }

    private function cfProvider(string $providerId): array
    {
        return $this->providers->requireType(
            $this->cfProviderIdOf($providerId),
            'cloudflare',
            'Cloudflare provider not found',
            'cloudflare_provider_not_found',
        );
    }

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

    private function isSameRouteKey(array $a, array $b): bool
    {
        return ($a['hostname'] ?? '') === ($b['hostname'] ?? '')
            && ($a['path'] ?? '') === ($b['path'] ?? '');
    }

    private function safeEnsureCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        try {
            return $this->ensureCname($cfProviderId, $zoneId, $hostname, $tunnelId);
        } catch (\Throwable $e) {
            return ['action' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function safeRemoveCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        try {
            $resolvedZoneId = $zoneId !== '' ? $zoneId : $this->resolveZoneId($cfProviderId, $hostname);

            return $resolvedZoneId !== ''
                ? $this->removeCname($cfProviderId, $resolvedZoneId, $hostname, $tunnelId)
                : ['action' => 'skipped', 'reason' => 'zone_not_found'];
        } catch (\Throwable $e) {
            return ['action' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function normalizeDnsOperation(array $result, string $defaultMessage): array
    {
        $action = (string) ($result['action'] ?? 'unknown');
        $status = match ($action) {
            'failed' => 'failed',
            'skipped', 'kept', 'not_found' => 'skipped',
            default => 'completed',
        };

        $message = (string) ($result['message'] ?? $result['error'] ?? $defaultMessage);

        return SideEffectResult::operation($status, $message, $result);
    }

    private function ensureCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        $cnameTarget = "{$tunnelId}.cfargotunnel.com";

        foreach ($this->exactCnameMatches($cfProviderId, $zoneId, $hostname) as $record) {
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

    private function removeCname(string $cfProviderId, string $zoneId, string $hostname, string $tunnelId): array
    {
        $cnameTarget = "{$tunnelId}.cfargotunnel.com";

        foreach ($this->exactCnameMatches($cfProviderId, $zoneId, $hostname) as $record) {
            if (($record['name'] ?? '') === $hostname && ($record['content'] ?? '') === $cnameTarget) {
                $this->cfDns->delete($cfProviderId, $zoneId, (string) $record['id']);
                return ['action' => 'deleted', 'record_id' => $record['id']];
            }
        }

        return ['action' => 'not_found'];
    }

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
     * Cloudflare 的 search 是模糊匹配，必须翻页过滤出 name 精确命中的 CNAME。
     * 否则记录较多时，精确记录可能不在第一页结果里。
     *
     * @return array<int, array<string, mixed>>
     */
    private function exactCnameMatches(string $cfProviderId, string $zoneId, string $hostname): array
    {
        $page = 1;
        $matches = [];

        do {
            $result = $this->cfDns->list($cfProviderId, $zoneId, [
                'type' => 'CNAME',
                'search' => $hostname,
                'page' => $page,
                'per_page' => 100,
            ]);

            foreach ($result['items'] ?? [] as $record) {
                if (($record['name'] ?? '') === $hostname) {
                    $matches[] = $record;
                }
            }

            $page++;
            $totalPages = (int) ($result['pagination']['total_pages'] ?? $result['meta']['total_pages'] ?? 1);
        } while ($page <= $totalPages);

        return $matches;
    }

    private function resolveZoneId(string $cfProviderId, string $fqdn): string
    {
        $fqdn = strtolower(rtrim(trim($fqdn), '.'));
        if ($fqdn === '') {
            return '';
        }

        $zones = $this->cfZones->list($cfProviderId, 1, 100);

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

    private function tunnelConfigCacheTag(string $providerId, string $tunnelId): string
    {
        return $this->buildCacheTag('cloudflare', 'tunnel_config', $providerId, $tunnelId);
    }
}
