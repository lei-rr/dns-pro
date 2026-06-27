<?php

declare(strict_types=1);

namespace app\service\cloudflare;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\concerns\PaginationMeta;
use app\service\concerns\ProviderServiceConcern;

class CloudflareDnsRecordService
{
    use ProviderServiceConcern;
    use PaginationMeta;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareApiClient $client,
    ) {
    }

    public function list(string $providerId, string $zoneId, array $filters): array
    {
        $filters = $this->normalizeListFilters($filters);
        $refresh = $filters['refresh'];
        unset($filters['refresh']);

        $cacheKey = $this->buildCacheKey('cloudflare:records', [
            'provider_id' => $providerId,
            'zone_id' => $zoneId,
            'filters' => $filters,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($providerId);

        $query = [
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ];

        foreach (['type', 'search'] as $key) {
            if ($filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        $payload = $this->client->get($provider, 'zones/' . rawurlencode($zoneId) . '/dns_records', $query);

        $result = [
            'items' => array_map(fn (array $record) => $this->presentRecord($record), $payload['result'] ?? []),
            'pagination' => [
                'page' => $payload['result_info']['page'] ?? $filters['page'],
                'per_page' => $payload['result_info']['per_page'] ?? $filters['per_page'],
                'count' => $payload['result_info']['count'] ?? null,
                'total_count' => $payload['result_info']['total_count'] ?? null,
                'total_pages' => $payload['result_info']['total_pages'] ?? null,
            ],
        ];
        $result['meta'] = $this->pagePaginationMeta($result['pagination'], 100);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->recordCacheTag($providerId, $zoneId),
        ]);

        return $result;
    }

    public function create(string $providerId, string $zoneId, array $data): array
    {
        $data = $this->normalizeRecordData($data);
        $provider = $this->findProvider($providerId);
        $payload = $this->client->post($provider, 'zones/' . rawurlencode($zoneId) . '/dns_records', $this->recordPayload($data));

        $this->invalidateCache($this->recordCacheTag($providerId, $zoneId));

        return $this->presentRecord($payload['result'] ?? []);
    }

    public function update(string $providerId, string $zoneId, string $recordId, array $data): array
    {
        $data = $this->normalizeRecordData($data);
        $provider = $this->findProvider($providerId);
        $payload = $this->client->put(
            $provider,
            'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId),
            $this->recordPayload($data),
        );

        $this->invalidateCache($this->recordCacheTag($providerId, $zoneId));

        return $this->presentRecord($payload['result'] ?? []);
    }

    public function delete(string $providerId, string $zoneId, string $recordId): array
    {
        $provider = $this->findProvider($providerId);
        $payload = $this->client->delete($provider, 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId));

        $this->invalidateCache($this->recordCacheTag($providerId, $zoneId));

        return [
            'id' => $payload['result']['id'] ?? $recordId,
        ];
    }

    private function findProvider(string $providerId): array
    {
        return $this->providers->requireType($providerId, 'cloudflare', 'Cloudflare provider not found', 'cloudflare_provider_not_found');
    }

    private function recordCacheTag(string $providerId, string $zoneId): string
    {
        return $this->buildCacheTag('cloudflare', 'records', $providerId, $zoneId);
    }

    private function normalizeListFilters(array $filters): array
    {
        return [
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 100),
            'type' => strtoupper(trim((string) ($filters['type'] ?? ''))),
            'search' => trim((string) ($filters['search'] ?? '')),
            'refresh' => (bool) ($filters['refresh'] ?? false),
        ];
    }

    private function normalizeRecordData(array $data): array
    {
        $data['type'] = strtoupper(trim((string) ($data['type'] ?? '')));
        $data['ttl'] = (int) ($data['ttl'] ?? 1);

        if (isset($data['priority'])) {
            $data['priority'] = (int) $data['priority'];
        }

        if (array_key_exists('proxied', $data)) {
            $data['proxied'] = filter_var($data['proxied'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return $data;
    }

    private function recordPayload(array $data): array
    {
        $payload = [
            'type' => strtoupper((string) $data['type']),
            'name' => (string) $data['name'],
            'content' => (string) $data['content'],
            'ttl' => (int) $data['ttl'],
        ];

        foreach (['proxied', 'priority', 'comment'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        return $payload;
    }

    private function presentRecord(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'zone_id' => $record['zone_id'] ?? null,
            'zone_name' => $record['zone_name'] ?? null,
            'name' => $record['name'] ?? null,
            'type' => $record['type'] ?? null,
            'content' => $record['content'] ?? null,
            'ttl' => $record['ttl'] ?? null,
            'proxied' => $record['proxied'] ?? null,
            'proxiable' => $record['proxiable'] ?? null,
            'priority' => $record['priority'] ?? null,
            'comment' => $record['comment'] ?? null,
            'tags' => $record['tags'] ?? [],
            'created_on' => $record['created_on'] ?? null,
            'modified_on' => $record['modified_on'] ?? null,
        ];
    }
}
