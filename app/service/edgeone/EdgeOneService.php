<?php

declare(strict_types=1);

namespace app\service\edgeone;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\concerns\PaginationMeta;
use app\service\concerns\ProviderServiceConcern;
use app\service\concerns\TencentSdkExceptionConcern;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Teo\V20220901\Models\AccelerationDomain;
use TencentCloud\Teo\V20220901\Models\CreateAccelerationDomainRequest;
use TencentCloud\Teo\V20220901\Models\DeleteAccelerationDomainsRequest;
use TencentCloud\Teo\V20220901\Models\DescribeAccelerationDomainsRequest;
use TencentCloud\Teo\V20220901\Models\DescribeZonesRequest;
use TencentCloud\Teo\V20220901\Models\ModifyAccelerationDomainRequest;
use TencentCloud\Teo\V20220901\Models\ModifyAccelerationDomainStatusesRequest;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\Models\OriginInfo;
use TencentCloud\Teo\V20220901\Models\Zone;

/**
 * EdgeOne 服务
 *
 * 领域服务：封装 EdgeOne zone / acceleration domain / status / certificate / CNAME 状态查询等领域能力。
 * DNSPod 同步与清理编排由 EdgeOneWorkflowService 负责。
 *
 * 模块边界：通过关联的 DNSPod provider 鉴权（共用密钥）；DNSPod 写入委托给 EdgeOneSyncService。
 */
class EdgeOneService
{
    use ProviderServiceConcern;
    use PaginationMeta;
    use TencentSdkExceptionConcern;

    /** 请求级 provider 凭据缓存（避免一次请求多次 DB 查询） */
    private array $credentialCache = [];

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly EdgeOneClientFactory $clients,
        private readonly EdgeOneMapper $mapper,
    ) {
    }

    // ---------- Zones ----------

    public function zones(string $providerId, array $filters): array
    {
        $filters = $this->mapper->normalizeZoneFilters($filters);
        $refresh = $filters['refresh'];
        unset($filters['refresh']);

        $cacheKey = $this->buildCacheKey('edgeone:zones', [
            'provider_id' => $providerId,
            'filters' => $filters,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->credentialProvider($providerId);
        $request = new DescribeZonesRequest();
        $request->Offset = $filters['offset'];
        $request->Limit = $filters['limit'];

        try {
            $response = $this->clients->make($provider)->DescribeZones($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne zone list failed', 'edgeone_zone_list_failed', $providerId, $exception);
        }

        $result = [
            'items' => array_map(fn (Zone $zone) => $this->mapper->presentZone($zone), $response->Zones ?? []),
            'pagination' => [
                'offset' => $filters['offset'],
                'limit' => $filters['limit'],
                'total' => $response->TotalCount,
            ],
            'request_id' => $response->RequestId,
        ];
        $result['meta'] = $this->offsetPaginationMeta($result['pagination'], 20);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->zoneCacheTag($providerId),
        ]);

        return $result;
    }

    public function zoneById(string $providerId, string $zoneId): array
    {
        $zone = $this->findFirstMatchedZone(
            $providerId,
            fn (array $zone) => (string) ($zone['id'] ?? '') === $zoneId,
        );

        if ($zone === null) {
            throw new ApiException('EdgeOne zone not found', 404, 'edgeone_zone_not_found', [
                'provider_id' => $providerId,
                'zone_id' => $zoneId,
            ]);
        }

        return $zone;
    }

    // ---------- Acceleration Domains ----------

    public function accelerationDomains(string $providerId, string $zoneId, array $filters): array
    {
        $filters = $this->mapper->normalizeDomainFilters($filters);
        $refresh = $filters['refresh'];
        unset($filters['refresh']);

        $cacheKey = $this->buildCacheKey('edgeone:domains', [
            'provider_id' => $providerId,
            'zone_id' => $zoneId,
            'filters' => $filters,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->credentialProvider($providerId);
        $request = new DescribeAccelerationDomainsRequest();
        $request->ZoneId = $zoneId;
        $request->Offset = $filters['offset'];
        $request->Limit = $filters['limit'];

        try {
            $response = $this->clients->make($provider)->DescribeAccelerationDomains($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne acceleration domain list failed', 'edgeone_acceleration_domain_list_failed', $providerId, $exception, ['zone_id' => $zoneId]);
        }

        $result = [
            'items' => array_map(fn (AccelerationDomain $domain) => $this->mapper->presentAccelerationDomain($domain, $zoneId), $response->AccelerationDomains ?? []),
            'pagination' => [
                'offset' => $filters['offset'],
                'limit' => $filters['limit'],
                'total' => $response->TotalCount,
            ],
            'request_id' => $response->RequestId,
        ];
        $result['meta'] = $this->offsetPaginationMeta($result['pagination'], 20);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->domainCacheTag($providerId, $zoneId),
        ]);

        return $result;
    }

    public function createAccelerationDomain(string $providerId, string $zoneId, array $data): array
    {
        $data = $this->mapper->normalizeAccelerationDomainData($data);
        $domainName = (string) $data['domain_name'];

        $provider = $this->credentialProvider($providerId);
        $request = new CreateAccelerationDomainRequest();
        $this->applyAccelerationDomainPayload($request, $zoneId, $data);

        try {
            $response = $this->clients->make($provider)->CreateAccelerationDomain($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne acceleration domain create failed', 'edgeone_acceleration_domain_create_failed', $providerId, $exception, ['zone_id' => $zoneId]);
        }

        $this->invalidateCache($this->domainCacheTag($providerId, $zoneId));

        $result = [
            'name' => $domainName,
            'request_id' => $response->RequestId,
            'ownership_verification' => $response->OwnershipVerification ?? null,
        ];

        return $result;
    }

    public function updateAccelerationDomain(string $providerId, string $zoneId, string $domainName, array $data): array
    {
        $data = $this->mapper->normalizeAccelerationDomainData($data + ['domain_name' => $domainName]);
        $provider = $this->credentialProvider($providerId);
        $request = new ModifyAccelerationDomainRequest();
        $this->applyAccelerationDomainPayload($request, $zoneId, $data);

        try {
            $response = $this->clients->make($provider)->ModifyAccelerationDomain($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne acceleration domain update failed', 'edgeone_acceleration_domain_update_failed', $providerId, $exception, ['zone_id' => $zoneId, 'domain_name' => $domainName]);
        }

        $this->invalidateCache($this->domainCacheTag($providerId, $zoneId));

        return ['name' => $domainName, 'request_id' => $response->RequestId];
    }

    public function deleteAccelerationDomain(string $providerId, string $zoneId, string $domainName): array
    {
        $provider = $this->credentialProvider($providerId);
        $request = new DeleteAccelerationDomainsRequest();
        $request->ZoneId = $zoneId;
        $request->DomainNames = [$domainName];
        $request->Force = false;

        try {
            $response = $this->clients->make($provider)->DeleteAccelerationDomains($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne acceleration domain delete failed', 'edgeone_acceleration_domain_delete_failed', $providerId, $exception, ['zone_id' => $zoneId, 'domain_name' => $domainName]);
        }

        $this->invalidateCache($this->domainCacheTag($providerId, $zoneId));

        return ['name' => $domainName, 'request_id' => $response->RequestId];
    }

    public function updateAccelerationDomainStatus(string $providerId, string $zoneId, string $domainName, string $status): array
    {
        $provider = $this->credentialProvider($providerId);
        $request = new ModifyAccelerationDomainStatusesRequest();
        $request->ZoneId = $zoneId;
        $request->DomainNames = [$domainName];
        $request->Status = $status;
        $request->Force = false;

        try {
            $response = $this->clients->make($provider)->ModifyAccelerationDomainStatuses($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne acceleration domain status update failed', 'edgeone_acceleration_domain_status_update_failed', $providerId, $exception, ['zone_id' => $zoneId, 'domain_name' => $domainName]);
        }

        $this->invalidateCache($this->domainCacheTag($providerId, $zoneId));

        return ['name' => $domainName, 'status' => $status, 'request_id' => $response->RequestId];
    }

    public function updateCertificate(string $providerId, string $zoneId, string $domainName, array $data): array
    {
        $data = $this->mapper->normalizeCertificateData($data);
        $provider = $this->credentialProvider($providerId);
        $request = new ModifyHostsCertificateRequest();
        $request->ZoneId = $zoneId;
        $request->Hosts = [$domainName];
        $request->Mode = $data['https_mode'];

        if ($data['https_mode'] === 'sslcert') {
            $request->ServerCertInfo = [['CertId' => $data['cert_id']]];
        }

        try {
            $response = $this->clients->make($provider)->ModifyHostsCertificate($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne certificate update failed', 'edgeone_certificate_update_failed', $providerId, $exception, [
                'zone_id' => $zoneId,
                'domain_name' => $domainName,
            ]);
        }

        $this->invalidateCache($this->domainCacheTag($providerId, $zoneId));

        return ['name' => $domainName, 'https_mode' => $data['https_mode'], 'request_id' => $response->RequestId];
    }

    // ---------- CNAME Sync to DNSPod ----------

    /**
     * 把 EdgeOne 加速域名的 CNAME 自动同步到关联的 DNSPod zone（手动触发，失败抛异常）
     */
    public function assignedCname(string $providerId, string $zoneId, string $domainName): string
    {
        $domain = $this->findAccelerationDomain($providerId, $zoneId, $domainName);
        return (string) ($domain['cname'] ?? '');
    }

    // ---------- private ----------

    private function credentialProvider(string $providerId): array
    {
        if (isset($this->credentialCache[$providerId])) {
            return $this->credentialCache[$providerId];
        }

        $edgeoneProvider = $this->edgeoneProvider($providerId);
        $credential = $this->providers->requireType(
            (string) ($edgeoneProvider['dnspod_provider'] ?? ''),
            'dnspod',
            'DNSPod provider for EdgeOne not found',
            'edgeone_dnspod_provider_not_found',
        );

        return $this->credentialCache[$providerId] = $credential;
    }

    private function edgeoneProvider(string $providerId): array
    {
        return $this->providers->requireType($providerId, 'edgeone', 'EdgeOne provider not found', 'edgeone_provider_not_found');
    }

    private function zoneCacheTag(string $providerId): string
    {
        return $this->buildCacheTag('edgeone', 'zones', $providerId);
    }

    private function domainCacheTag(string $providerId, string $zoneId): string
    {
        return $this->buildCacheTag('edgeone', 'domains', $providerId, $zoneId);
    }

    private function findFirstMatchedZone(string $providerId, callable $predicate): ?array
    {
        $offset = 0;

        do {
            $zones = $this->zones($providerId, ['offset' => $offset, 'limit' => 100]);

            foreach ($zones['items'] as $zone) {
                if ($predicate($zone)) {
                    return $zone;
                }
            }

            $count = count($zones['items']);
            $offset += 100;
        } while ($count === 100);

        return null;
    }

    private function findAccelerationDomain(string $providerId, string $zoneId, string $domainName): array
    {
        $offset = 0;

        do {
            $domains = $this->accelerationDomains($providerId, $zoneId, ['offset' => $offset, 'limit' => 200]);

            foreach ($domains['items'] as $domain) {
                if (($domain['name'] ?? '') === $domainName) {
                    return $domain;
                }
            }

            $count = count($domains['items']);
            $offset += 200;
        } while ($count === 200);

        throw new ApiException('EdgeOne acceleration domain not found', 404, 'edgeone_acceleration_domain_not_found', [
            'provider_id' => $providerId,
            'zone_id' => $zoneId,
            'domain_name' => $domainName,
        ]);
    }

    private function applyAccelerationDomainPayload(CreateAccelerationDomainRequest|ModifyAccelerationDomainRequest $request, string $zoneId, array $data): void
    {
        $origin = new OriginInfo();
        $origin->OriginType = $data['origin_type'];
        $origin->Origin = $data['origin'];

        if (($data['host_header'] ?? '') !== '') {
            $origin->HostHeader = $data['host_header'];
        }

        $request->ZoneId = $zoneId;
        $request->DomainName = $data['domain_name'];
        $request->OriginInfo = $origin;
        $request->OriginProtocol = $data['origin_protocol'];
        $request->IPv6Status = $data['ipv6_status'];

        if (in_array($data['origin_protocol'], ['FOLLOW', 'HTTP'], true)) {
            $request->HttpOriginPort = $data['http_origin_port'];
        }

        if (in_array($data['origin_protocol'], ['FOLLOW', 'HTTPS'], true)) {
            $request->HttpsOriginPort = $data['https_origin_port'];
        }
    }
}
