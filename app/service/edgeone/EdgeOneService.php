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
use TencentCloud\Teo\V20220901\Models\CheckCnameStatusRequest;
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
 * 单一对外入口，封装：zone 列表、加速域名 CRUD、状态变更、证书更新、CNAME 同步到 DNSPod、CNAME 解析状态查询、连接测试。
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
        private readonly EdgeOneSyncService $syncService,
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
        $result['meta'] = $this->offsetPaginationMeta($result['pagination'], 100);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->zoneCacheTag($providerId),
        ]);

        return $result;
    }

    public function zoneIdByName(string $providerId, string $zoneName): string
    {
        $zone = $this->findFirstMatchedZone(
            $providerId,
            fn (array $zone) => strcasecmp((string) ($zone['name'] ?? ''), $zoneName) === 0 && (string) ($zone['id'] ?? '') !== '',
        );

        if ($zone === null) {
            throw new ApiException('EdgeOne zone not found', 404, 'edgeone_zone_not_found', [
                'provider_id' => $providerId,
                'zone_name' => $zoneName,
            ]);
        }

        return (string) $zone['id'];
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
        $result['meta'] = $this->offsetPaginationMeta($result['pagination'], 100);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->domainCacheTag($providerId, $zoneId),
        ]);

        return $result;
    }

    public function createAccelerationDomain(string $providerId, string $zoneId, array $data, bool $autoSync = false): array
    {
        $data = $this->mapper->normalizeAccelerationDomainData($data);
        $domainName = (string) $data['domain_name'];

        // autoSync 开启时先预检 DNSPod zone:不通过则不进入 EdgeOne 创建
        if ($autoSync) {
            $this->syncService->preflight($providerId, $domainName);
        }

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

        // 创建后自动同步 CNAME 到 DNSPod(失败不影响创建结果,降级为 dns_record.synced=false)
        if ($autoSync) {
            $result['dns_record'] = $this->safeSyncCname($providerId, $zoneId, $domainName);
        }

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

    public function deleteAccelerationDomain(string $providerId, string $zoneId, string $domainName, bool $autoCleanup = false): array
    {
        // 删除前先收集 CNAME(删除后无法再查 EdgeOne)
        $cname = '';
        if ($autoCleanup) {
            try {
                $domain = $this->findAccelerationDomain($providerId, $zoneId, $domainName);
                $cname = (string) ($domain['cname'] ?? '');
            } catch (\Throwable) {
                $cname = '';
            }
        }

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

        $result = ['name' => $domainName, 'request_id' => $response->RequestId];

        if ($autoCleanup) {
            $result['dns_cleanup'] = $this->safeCleanupCname($providerId, $domainName, $cname);
        }

        return $result;
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
    public function syncCname(string $providerId, string $zoneId, string $domainName): array
    {
        $domain = $this->findAccelerationDomain($providerId, $zoneId, $domainName);
        $cname = (string) ($domain['cname'] ?? '');

        if ($cname === '') {
            throw new ApiException('EdgeOne CNAME not found', 502, 'edgeone_cname_empty', [
                'provider_id' => $providerId,
                'zone_id' => $zoneId,
                'domain_name' => $domainName,
            ]);
        }

        return $this->presentCnameSync($this->syncService->sync($providerId, $domainName, $cname));
    }

    /**
     * 查询 EdgeOne 加速域名当前 CNAME 解析状态
     */
    public function cnameStatus(string $providerId, string $zoneId, string $domainName): array
    {
        $provider = $this->credentialProvider($providerId);
        $request = new CheckCnameStatusRequest();
        $request->ZoneId = $zoneId;
        $request->RecordNames = [$domainName];

        try {
            $response = $this->clients->make($provider)->CheckCnameStatus($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('EdgeOne CNAME status check failed', 'edgeone_cname_status_failed', $providerId, $exception, [
                'zone_id' => $zoneId,
                'domain_name' => $domainName,
            ]);
        }

        $list = $response->StatusList ?? [];
        if (empty($list)) {
            return [
                'record_name' => $domainName,
                'cname' => null,
                'status' => null,
                'request_id' => $response->RequestId,
            ];
        }

        return $this->mapper->presentCnameStatus($list[0]) + ['request_id' => $response->RequestId];
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

    /**
     * 创建后调用:同步 CNAME 到 DNSPod,失败时降级返回 synced=false(不阻断主流程)
     *
     * EdgeOne 创建加速域名后,CNAME 在响应中不返回,需要二次拉详情;
     * 域名刚创建时也可能尚未分配 CNAME,此处优雅降级。
     */
    private function safeSyncCname(string $providerId, string $zoneId, string $domainName): array
    {
        try {
            $domain = $this->findAccelerationDomain($providerId, $zoneId, $domainName);
            $cname = (string) ($domain['cname'] ?? '');
            if ($cname === '') {
                return $this->mapper->syncResult(false, 'skipped', 'EdgeOne CNAME 尚未分配,稍后可手动同步', '');
            }

            return $this->presentCnameSync($this->syncService->sync($providerId, $domainName, $cname));
        } catch (ApiException $e) {
            return $this->mapper->syncResult(false, 'skipped', $e->getMessage(), '');
        } catch (\Throwable $e) {
            return $this->mapper->syncResult(false, 'failed', $e->getMessage(), '');
        }
    }

    /**
     * 把 EdgeOneSyncService::sync 的结果包装成前端约定的 syncResult 形态
     */
    private function presentCnameSync(array $syncResult): array
    {
        $status = (string) ($syncResult['record']['status'] ?? '');

        return $this->mapper->syncResult(
            $status !== 'failed',
            $status ?: 'unknown',
            $this->syncActionMessage($status),
            (string) ($syncResult['record']['record_id'] ?? ''),
        );
    }

    /**
     * 删除后调用:清理 DNSPod 中的 CNAME,失败时降级返回 cleaned=0(不阻断主流程)
     */
    private function safeCleanupCname(string $providerId, string $domainName, string $cname): array
    {
        try {
            return $this->syncService->cleanup($providerId, $domainName, $cname);
        } catch (ApiException $e) {
            return ['cleaned' => 0, 'records' => [], 'reason' => $e->getErrorCode() ?: 'cleanup_skipped', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['cleaned' => 0, 'records' => [], 'reason' => 'cleanup_failed', 'message' => $e->getMessage()];
        }
    }

    private function syncActionMessage(string $status): string
    {
        return match ($status) {
            'created' => 'DNSPod CNAME 已创建',
            'updated' => 'DNSPod CNAME 已更新',
            'unchanged' => 'DNSPod CNAME 已是最新',
            'failed' => 'DNSPod CNAME 同步失败',
            default => 'DNSPod CNAME 同步完成',
        };
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
