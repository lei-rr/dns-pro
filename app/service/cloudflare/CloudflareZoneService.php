<?php

declare(strict_types=1);

namespace app\service\cloudflare;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\concerns\PaginationMeta;
use app\service\concerns\ProviderServiceConcern;

class CloudflareZoneService
{
    use ProviderServiceConcern;
    use PaginationMeta;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareApiClient $client,
    ) {
    }

    public function list(string $providerId, int $page, int $perPage, string $name = '', bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:zones', [
            'provider_id' => $providerId,
            'page' => $page,
            'per_page' => $perPage,
            'name' => $name,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($providerId);

        $query = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($name !== '') {
            $query['name'] = $name;
        }

        $payload = $this->client->get($provider, 'zones', $query);

        $result = [
            'items' => array_map(fn (array $zone) => $this->presentZone($zone), $payload['result'] ?? []),
            'pagination' => [
                'page' => $payload['result_info']['page'] ?? $page,
                'per_page' => $payload['result_info']['per_page'] ?? $perPage,
                'count' => $payload['result_info']['count'] ?? null,
                'total_count' => $payload['result_info']['total_count'] ?? null,
                'total_pages' => $payload['result_info']['total_pages'] ?? null,
            ],
        ];
        $result['meta'] = $this->pagePaginationMeta($result['pagination'], 20);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->zoneCacheTag($providerId),
        ]);

        return $result;
    }

    public function create(string $providerId, string $name, string $type = 'full'): array
    {
        $provider = $this->findProvider($providerId);
        $accountId = trim((string) ($provider['account_id'] ?? ''));

        if ($accountId === '') {
            throw new ApiException('Cloudflare account_id is required', 422, 'cloudflare_account_id_required');
        }

        $payload = $this->client->post($provider, 'zones', [
            'name' => $name,
            'account' => ['id' => $accountId],
            'type' => $type,
        ]);

        $this->invalidateCache($this->zoneCacheTag($providerId));

        return $this->presentZone($payload['result'] ?? []);
    }

    public function delete(string $providerId, string $zoneId): array
    {
        $provider = $this->findProvider($providerId);
        $payload = $this->client->delete($provider, 'zones/' . rawurlencode($zoneId));

        // 清除zone和record缓存
        $this->invalidateCache(
            $this->zoneCacheTag($providerId),
            $this->recordCacheTag($providerId, $zoneId)
        );

        return [
            'id' => $payload['result']['id'] ?? $zoneId,
        ];
    }

    public function idByName(string $providerId, string $name, bool $refresh = false): string
    {
        $zones = $this->list($providerId, 1, 1, $name, $refresh);

        foreach ($zones['items'] as $zone) {
            if (strcasecmp((string) ($zone['name'] ?? ''), $name) === 0 && (string) ($zone['id'] ?? '') !== '') {
                return (string) $zone['id'];
            }
        }

        throw new ApiException('Cloudflare zone not found', 404, 'cloudflare_zone_not_found', [
            'provider_id' => $providerId,
            'name' => $name,
        ]);
    }

    /**
     * 获取 zone 的 DCV 委派 UUID（zone 级别的常量，用于拼接 _acme-challenge CNAME 目标）
     */
    public function dcvDelegationUuid(string $providerId, string $zoneId, bool $refresh = false): string
    {
        $cacheKey = $this->buildCacheKey('cloudflare:dcv_delegation', [
            'provider_id' => $providerId,
            'zone_id' => $zoneId,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return (string) ($cached['uuid'] ?? '');
        }

        $provider = $this->findProvider($providerId);

        try {
            $payload = $this->client->get($provider, 'zones/' . rawurlencode($zoneId) . '/dcv_delegation/uuid');
        } catch (\Throwable) {
            return '';
        }

        $uuid = (string) ($payload['result']['uuid'] ?? '');

        $this->setCached($cacheKey, ['uuid' => $uuid], [
            $this->providerCacheTag($providerId),
            $this->zoneCacheTag($providerId),
        ]);

        return $uuid;
    }

    private function findProvider(string $providerId): array
    {
        return $this->providers->requireType($providerId, 'cloudflare', 'Cloudflare provider not found', 'cloudflare_provider_not_found');
    }

    private function zoneCacheTag(string $providerId): string
    {
        return $this->buildCacheTag('cloudflare', 'zones', $providerId);
    }

    private function recordCacheTag(string $providerId, string $zoneId): string
    {
        return $this->buildCacheTag('cloudflare', 'records', $providerId, $zoneId);
    }

    private function presentZone(array $zone): array
    {
        return [
            'id' => $zone['id'] ?? null,
            'name' => $zone['name'] ?? null,
            'status' => $zone['status'] ?? null,
            'type' => $zone['type'] ?? null,
            'paused' => $zone['paused'] ?? null,
            'account' => $zone['account'] ?? null,
            'name_servers' => $zone['name_servers'] ?? [],
            'original_name_servers' => $zone['original_name_servers'] ?? [],
            'created_on' => $zone['created_on'] ?? null,
            'modified_on' => $zone['modified_on'] ?? null,
            'activated_on' => $zone['activated_on'] ?? null,
        ];
    }
}
