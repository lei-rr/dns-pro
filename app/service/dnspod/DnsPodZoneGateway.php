<?php

declare(strict_types=1);

namespace app\service\dnspod;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\concerns\PaginationMeta;
use app\service\concerns\ProviderServiceConcern;
use app\service\concerns\TencentSdkExceptionConcern;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Dnspod\V20210323\Models\CreateDomainRequest;
use TencentCloud\Dnspod\V20210323\Models\DeleteDomainRequest;
use TencentCloud\Dnspod\V20210323\Models\DescribeDomainListRequest;
use TencentCloud\Dnspod\V20210323\Models\DomainListItem;

class DnsPodZoneGateway
{
    use ProviderServiceConcern;
    use PaginationMeta;
    use TencentSdkExceptionConcern;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly DnsPodClientFactory $clients,
    ) {
    }

    public function list(string $providerId, int $offset, int $limit, string $keyword = '', bool $refresh = false): array
    {
        $cacheKey = $this->buildCacheKey('dnspod:zones', [
            'provider_id' => $providerId,
            'offset' => $offset,
            'limit' => $limit,
            'keyword' => $keyword,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($providerId);
        $request = new DescribeDomainListRequest();
        $request->Offset = $offset;
        $request->Limit = $limit;

        if ($keyword !== '') {
            $request->Keyword = $keyword;
        }

        try {
            $response = $this->clients->make($provider)->DescribeDomainList($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod zone list failed', 'dnspod_zone_list_failed', $providerId, $exception);
        }

        $result = [
            'items' => array_map(fn (DomainListItem $zone) => $this->presentZone($zone), $response->DomainList ?? []),
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => $response->DomainCountInfo?->DomainTotal,
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

    public function create(string $providerId, string $zone): array
    {
        $provider = $this->findProvider($providerId);
        $request = new CreateDomainRequest();
        $request->Domain = $zone;

        try {
            $response = $this->clients->make($provider)->CreateDomain($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod zone create failed', 'dnspod_zone_create_failed', $providerId, $exception, [
                'zone' => $zone,
            ]);
        }

        // 清除zone和record缓存
        $this->invalidateCache(
            $this->zoneCacheTag($providerId),
            $this->recordCacheTag($providerId, $zone)
        );

        return [
            'id' => $response->DomainInfo?->Id,
            'name' => $response->DomainInfo?->Domain ?? $zone,
            'name_servers' => $response->DomainInfo?->GradeNsList ?? [],
            'request_id' => $response->RequestId,
        ];
    }

    public function delete(string $providerId, string $zone): array
    {
        $provider = $this->findProvider($providerId);
        $request = new DeleteDomainRequest();
        $request->Domain = $zone;

        try {
            $response = $this->clients->make($provider)->DeleteDomain($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod zone delete failed', 'dnspod_zone_delete_failed', $providerId, $exception, [
                'zone' => $zone,
            ]);
        }

        // 清除zone和record缓存
        $this->invalidateCache(
            $this->zoneCacheTag($providerId),
            $this->recordCacheTag($providerId, $zone)
        );

        return [
            'name' => $zone,
            'request_id' => $response->RequestId,
        ];
    }

    private function findProvider(string $providerId): array
    {
        return $this->providers->requireType($providerId, 'dnspod', 'DNSPod provider not found', 'dnspod_provider_not_found');
    }

    private function zoneCacheTag(string $providerId): string
    {
        return $this->buildCacheTag('dnspod', 'zones', $providerId);
    }

    private function recordCacheTag(string $providerId, string $zone): string
    {
        return $this->buildCacheTag('dnspod', 'records', $providerId, $zone);
    }

    private function presentZone(DomainListItem $zone): array
    {
        return [
            'id' => $zone->DomainId,
            'name' => $zone->Name,
            'punycode' => $zone->Punycode,
            'status' => $zone->Status,
            'dns_status' => property_exists($zone, 'DnsStatus')
                ? $zone->DnsStatus
                : (property_exists($zone, 'DNSStatus') ? $zone->DNSStatus : null),
            'grade' => $zone->Grade,
            'grade_title' => $zone->GradeTitle,
            'group_id' => $zone->GroupId,
            'record_count' => $zone->RecordCount,
            'ttl' => $zone->TTL,
            'remark' => $zone->Remark,
            'effective_dns' => $zone->EffectiveDNS ?? [],
            'created_on' => $zone->CreatedOn,
            'updated_on' => $zone->UpdatedOn,
        ];
    }
}
