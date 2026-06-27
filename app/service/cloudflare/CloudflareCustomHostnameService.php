<?php

declare(strict_types=1);

namespace app\service\cloudflare;

use app\repository\ProviderRepository;
use app\service\concerns\PaginationMeta;
use app\service\concerns\ProviderServiceConcern;

/**
 * Cloudflare for SaaS 自定义主机名服务
 *
 * 封装所有 /zones/:zoneId/custom_hostnames/* 的 API 调用与缓存。
 * 上层（hostname 模块）只通过本服务访问 Cloudflare，不直接拼路径。
 */
class CloudflareCustomHostnameService
{
    use ProviderServiceConcern;
    use PaginationMeta;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CloudflareApiClient $client,
    ) {
    }

    public function list(string $cloudflareProviderId, string $zoneId, int $page, int $perPage, bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:custom_hostnames', [
            'provider_id' => $cloudflareProviderId,
            'zone_id' => $zoneId,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($cloudflareProviderId);
        $payload = $this->client->get($provider, 'zones/' . rawurlencode($zoneId) . '/custom_hostnames', [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $result = [
            'items' => array_map(fn (array $hostname) => $this->present($hostname), $payload['result'] ?? []),
            'pagination' => [
                'page' => $payload['result_info']['page'] ?? $page,
                'per_page' => $payload['result_info']['per_page'] ?? $perPage,
                'count' => $payload['result_info']['count'] ?? null,
                'total_count' => $payload['result_info']['total_count'] ?? null,
                'total_pages' => $payload['result_info']['total_pages'] ?? null,
            ],
        ];
        $result['meta'] = $this->pagePaginationMeta($result['pagination'], 100);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        ]);

        return $result;
    }

    public function show(string $cloudflareProviderId, string $zoneId, string $hostnameId, bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:custom_hostname', [
            'provider_id' => $cloudflareProviderId,
            'zone_id' => $zoneId,
            'hostname_id' => $hostnameId,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($cloudflareProviderId);
        $response = $this->client->get(
            $provider,
            'zones/' . rawurlencode($zoneId) . '/custom_hostnames/' . rawurlencode($hostnameId),
        );

        $result = $this->present($response['result'] ?? []);
        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        ]);

        return $result;
    }

    /**
     * 把 hostname FQDN 解析为 Cloudflare 内部 UUID
     *
     * 走 list 缓存(默认 3 天 TTL),找不到时强制 refresh 一次再找(容忍 CF 端外部新建)。
     * 仍找不到则抛 404,由 ExceptionHandle 统一返回 hostname_not_found。
     *
     * 缓存可靠性:
     *   - 自建/改/删触发 invalidate,本地操作命中率 100%
     *   - 仅当用户在 CF 控制台外部新建时需要 refresh fallback
     *   - 一次 list 最多 100 条;如果 zone 下 hostname >100 条会分页,理论需多次查询
     *
     * 注:Cloudflare 不允许同 zone 下 hostname FQDN 重复,所以 fqdn 是唯一 key。
     */
    public function idByHostname(string $cloudflareProviderId, string $zoneId, string $hostnameFqdn, bool $refresh = false): string
    {
        $normalized = strtolower(trim($hostnameFqdn));

        $found = $this->findIdInList($cloudflareProviderId, $zoneId, $normalized, $refresh);
        if ($found !== null) {
            return $found;
        }

        // 缓存里没有时强制刷新一次再找(应对 CF 端外部新建场景)
        if (!$refresh) {
            $found = $this->findIdInList($cloudflareProviderId, $zoneId, $normalized, true);
            if ($found !== null) {
                return $found;
            }
        }

        throw new \app\exception\ApiException(
            'Hostname not found',
            404,
            'hostname_not_found',
            ['hostname' => $hostnameFqdn],
        );
    }

    /**
     * 翻遍 list 各页查找 fqdn 对应的 hostname id
     */
    private function findIdInList(string $cloudflareProviderId, string $zoneId, string $fqdn, bool $refresh): ?string
    {
        $page = 1;
        do {
            $result = $this->list($cloudflareProviderId, $zoneId, $page, 100, $refresh && $page === 1);
            foreach ($result['items'] ?? [] as $item) {
                if (strtolower((string) ($item['hostname'] ?? '')) === $fqdn) {
                    return (string) ($item['id'] ?? '');
                }
            }
            $page++;
            $totalPages = (int) ($result['pagination']['total_pages'] ?? $result['meta']['total_pages'] ?? 1);
        } while ($page <= $totalPages);

        return null;
    }

    public function create(string $cloudflareProviderId, string $zoneId, array $data): array
    {
        $provider = $this->findProvider($cloudflareProviderId);

        $payload = ['hostname' => trim((string) ($data['hostname'] ?? ''))];

        $ssl = [];
        $method = trim((string) ($data['method'] ?? ''));
        if ($method !== '') {
            $ssl['method'] = $method;
        }

        $minTlsVersion = trim((string) ($data['min_tls_version'] ?? ''));
        if ($minTlsVersion !== '') {
            $ssl['settings'] = ['min_tls_version' => $minTlsVersion];
        }

        $ssl['type'] = 'dv';
        $payload['ssl'] = $ssl;

        $customOriginServer = trim((string) ($data['custom_origin_server'] ?? ''));
        if ($customOriginServer !== '') {
            $payload['custom_origin_server'] = $customOriginServer;
        }

        // 注意：Cloudflare custom_metadata 仅企业版可用，普通账号下发会被 API 拒绝。
        // hostname 模块的本地属性（如 preferred_domain）由 hostname_preferences 表存储，
        // 不通过 Cloudflare API 透传。如果未来升级到企业版，再恢复下发逻辑。

        $response = $this->client->post(
            $provider,
            'zones/' . rawurlencode($zoneId) . '/custom_hostnames',
            $payload,
        );
        $result = $this->present($response['result'] ?? []);

        $this->invalidateCache(
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        );

        return $result;
    }

    public function delete(string $cloudflareProviderId, string $zoneId, string $hostnameId): array
    {
        $provider = $this->findProvider($cloudflareProviderId);
        $response = $this->client->delete(
            $provider,
            'zones/' . rawurlencode($zoneId) . '/custom_hostnames/' . rawurlencode($hostnameId),
        );

        $this->invalidateCache(
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        );

        return [
            'id' => $response['result']['id'] ?? $hostnameId,
        ];
    }

    /**
     * 获取 zone fallback origin 的完整信息（SaaS zone 级别的默认回源主机名）
     *
     * 当 custom hostname 未设置 custom_origin_server 时，Cloudflare 用这个值作为实际回源。
     *
     * @return array{origin:?string, status:?string, created_at:?string, updated_at:?string}
     */
    public function fallbackOriginInfo(string $cloudflareProviderId, string $zoneId, bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('cloudflare:fallback_origin', [
            'provider_id' => $cloudflareProviderId,
            'zone_id' => $zoneId,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($cloudflareProviderId);

        try {
            $payload = $this->client->get(
                $provider,
                'zones/' . rawurlencode($zoneId) . '/custom_hostnames/fallback_origin',
            );
            $info = $this->presentFallbackOrigin($payload['result'] ?? []);
        } catch (\Throwable) {
            // Cloudflare 在 fallback_origin 未配置时返回 404
            $info = $this->presentFallbackOrigin([]);
        }

        $this->setCached($cacheKey, $info, [
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        ]);

        return $info;
    }

    public function setFallbackOrigin(string $cloudflareProviderId, string $zoneId, string $origin): array
    {
        $provider = $this->findProvider($cloudflareProviderId);

        $payload = $this->client->put(
            $provider,
            'zones/' . rawurlencode($zoneId) . '/custom_hostnames/fallback_origin',
            ['origin' => $origin],
        );

        $this->invalidateCache(
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        );

        return $this->presentFallbackOrigin($payload['result'] ?? []);
    }

    public function deleteFallbackOrigin(string $cloudflareProviderId, string $zoneId): array
    {
        $provider = $this->findProvider($cloudflareProviderId);

        $this->client->delete(
            $provider,
            'zones/' . rawurlencode($zoneId) . '/custom_hostnames/fallback_origin',
        );

        $this->invalidateCache(
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        );

        return $this->presentFallbackOrigin([]);
    }

    private function presentFallbackOrigin(array $result): array
    {
        $origin = (string) ($result['origin'] ?? '');

        return [
            'origin' => $origin !== '' ? $origin : null,
            'status' => $result['status'] ?? null,
            'errors' => $result['errors'] ?? [],
            'created_at' => $result['created_at'] ?? null,
            'updated_at' => $result['updated_at'] ?? null,
        ];
    }

    /**
     * 让上层显式失效缓存（hostname 模块在跨服务操作后需要主动清）
     */
    public function invalidate(string $cloudflareProviderId, string $zoneId): void
    {
        $this->invalidateCache(
            $this->providerCacheTag($cloudflareProviderId),
            $this->customHostnameCacheTag($cloudflareProviderId, $zoneId),
        );
    }

    private function findProvider(string $cloudflareProviderId): array
    {
        return $this->providers->requireType(
            $cloudflareProviderId,
            'cloudflare',
            'Cloudflare provider not found',
            'cloudflare_provider_not_found',
        );
    }

    private function customHostnameCacheTag(string $cloudflareProviderId, string $zoneId): string
    {
        return $this->buildCacheTag('cloudflare', 'custom_hostnames', $cloudflareProviderId, $zoneId);
    }

    /**
     * 标准化 Cloudflare 返回的 custom hostname 数据
     *
     * 注意：证书签发后，ssl.expires_on / ssl.issuer 等字段实际在 ssl.certificates[] 数组内，
     * 不是直接在 ssl 顶层。这里把 certificates[0] 的信息提到顶层方便前端使用。
     */
    private function present(array $hostname): array
    {
        $ssl = $hostname['ssl'] ?? [];
        $certificates = is_array($ssl['certificates'] ?? null) ? $ssl['certificates'] : [];
        $primaryCert = $certificates[0] ?? [];

        return [
            'id' => $hostname['id'] ?? null,
            'hostname' => $hostname['hostname'] ?? null,
            'status' => $hostname['status'] ?? null,
            'ownership_verification' => $hostname['ownership_verification'] ?? null,
            'ownership_verification_http' => $hostname['ownership_verification_http'] ?? null,
            'custom_origin_server' => $hostname['custom_origin_server'] ?? null,
            'custom_metadata' => $hostname['custom_metadata'] ?? null,
            'created_at' => $hostname['created_at'] ?? null,
            // hostname 顶层错误(如 "zone does not have a fallback origin set"),与 ssl.validation_errors 互补
            'verification_errors' => $hostname['verification_errors'] ?? [],
            'ssl' => [
                'id' => $ssl['id'] ?? null,
                'status' => $ssl['status'] ?? null,
                'method' => $ssl['method'] ?? null,
                'type' => $ssl['type'] ?? null,
                'wildcard' => $ssl['wildcard'] ?? null,
                // 证书已签发：优先用 certificates[0]，否则 fallback 到 ssl 顶层
                'expires_on' => $primaryCert['expires_on'] ?? $ssl['expires_on'] ?? null,
                'issued_on' => $primaryCert['issued_on'] ?? null,
                'issuer' => $primaryCert['issuer'] ?? $ssl['issuer'] ?? null,
                'serial_number' => $primaryCert['serial_number'] ?? $ssl['serial_number'] ?? null,
                'certificate_authority' => $ssl['certificate_authority'] ?? null,
                'settings' => $ssl['settings'] ?? [],
                'validation_errors' => $ssl['validation_errors'] ?? [],
                // 临时 ACME 验证记录（每次续期会变）
                'validation_records' => $ssl['validation_records'] ?? [],
                // 永久 DCV 委派 CNAME 记录（推荐使用）
                'dcv_delegation_records' => $ssl['dcv_delegation_records'] ?? [],
                'certificates' => $certificates,
            ],
        ];
    }
}
