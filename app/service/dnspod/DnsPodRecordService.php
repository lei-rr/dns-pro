<?php

declare(strict_types=1);

namespace app\service\dnspod;

use app\exception\ApiException;
use app\repository\ProviderRepository;
use app\service\concerns\PaginationMeta;
use app\service\concerns\ProviderServiceConcern;
use app\service\concerns\TencentSdkExceptionConcern;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Dnspod\V20210323\Models\CreateRecordRequest;
use TencentCloud\Dnspod\V20210323\Models\DeleteRecordRequest;
use TencentCloud\Dnspod\V20210323\Models\DescribeRecordListRequest;
use TencentCloud\Dnspod\V20210323\Models\ModifyRecordRequest;
use TencentCloud\Dnspod\V20210323\Models\RecordListItem;

class DnsPodRecordService
{
    use ProviderServiceConcern;
    use PaginationMeta;
    use TencentSdkExceptionConcern;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly DnsPodClientFactory $clients,
    ) {
    }

    public function list(string $providerId, string $domain, array $filters): array
    {
        $filters = $this->normalizeListFilters($filters);
        $refresh = $filters['refresh'];
        unset($filters['refresh']);

        $cacheKey = $this->buildCacheKey('dnspod:records', [
            'provider_id' => $providerId,
            'domain' => $domain,
            'filters' => $filters,
        ]);

        $cached = $this->getCached($cacheKey, $refresh);
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->findProvider($providerId);

        $request = new DescribeRecordListRequest();
        $request->Domain = $domain;
        $request->Offset = $filters['offset'];
        $request->Limit = $filters['limit'];
        $request->ErrorOnEmpty = 'no';

        foreach (['subdomain' => 'Subdomain', 'record_type' => 'RecordType', 'keyword' => 'Keyword'] as $key => $property) {
            if ($filters[$key] !== '') {
                $request->{$property} = $filters[$key];
            }
        }

        try {
            $response = $this->clients->make($provider)->DescribeRecordList($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod record list failed', 'dnspod_record_list_failed', $providerId, $exception, [
                'domain' => $domain,
            ]);
        }

        $result = [
            'items' => array_map(fn (RecordListItem $record) => $this->presentRecord($record), $response->RecordList ?? []),
            'pagination' => [
                'offset' => $filters['offset'],
                'limit' => $filters['limit'],
                'count' => $response->RecordCountInfo?->ListCount,
                'total' => $response->RecordCountInfo?->TotalCount,
            ],
            'request_id' => $response->RequestId,
        ];
        $result['meta'] = $this->offsetPaginationMeta($result['pagination'], 100);

        $this->setCached($cacheKey, $result, [
            $this->providerCacheTag($providerId),
            $this->recordCacheTag($providerId, $domain),
        ]);

        return $result;
    }

    public function create(string $providerId, string $domain, array $data): array
    {
        $data = $this->normalizeRecordData($data);
        $provider = $this->findProvider($providerId);
        $request = new CreateRecordRequest();
        $this->applyRecordPayload($request, $domain, $data);

        try {
            $response = $this->clients->make($provider)->CreateRecord($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod record create failed', 'dnspod_record_create_failed', $providerId, $exception, [
                'domain' => $domain,
            ]);
        }

        $this->invalidateCache($this->recordCacheTag($providerId, $domain));

        return [
            'id' => $response->RecordId,
            'request_id' => $response->RequestId,
        ];
    }

    public function update(string $providerId, string $domain, string $recordId, array $data): array
    {
        $data = $this->normalizeRecordData($data);
        $provider = $this->findProvider($providerId);
        $request = new ModifyRecordRequest();
        $request->RecordId = (int) $recordId;
        $this->applyRecordPayload($request, $domain, $data);

        try {
            $response = $this->clients->make($provider)->ModifyRecord($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod record update failed', 'dnspod_record_update_failed', $providerId, $exception, [
                'domain' => $domain,
            ]);
        }

        $this->invalidateCache($this->recordCacheTag($providerId, $domain));

        return [
            'id' => $response->RecordId,
            'request_id' => $response->RequestId,
        ];
    }

    public function delete(string $providerId, string $domain, string $recordId): array
    {
        $provider = $this->findProvider($providerId);
        $request = new DeleteRecordRequest();
        $request->Domain = $domain;
        $request->RecordId = (int) $recordId;

        try {
            $response = $this->clients->make($provider)->DeleteRecord($request);
        } catch (TencentCloudSDKException $exception) {
            throw $this->wrapSdkException('DNSPod record delete failed', 'dnspod_record_delete_failed', $providerId, $exception, [
                'domain' => $domain,
            ]);
        }

        $this->invalidateCache($this->recordCacheTag($providerId, $domain));

        return [
            'id' => (int) $recordId,
            'request_id' => $response->RequestId,
        ];
    }

    private function findProvider(string $providerId): array
    {
        return $this->providers->requireType($providerId, 'dnspod', 'DNSPod provider not found', 'dnspod_provider_not_found');
    }

    private function recordCacheTag(string $providerId, string $domain): string
    {
        return $this->buildCacheTag('dnspod', 'records', $providerId, $domain);
    }

    private function applyRecordPayload(CreateRecordRequest|ModifyRecordRequest $request, string $domain, array $data): void
    {
        $request->Domain = $domain;
        $request->RecordType = strtoupper((string) $data['record_type']);
        $request->RecordLine = (string) $data['record_line'];
        $request->Value = (string) $data['value'];

        foreach ([
            'subdomain' => 'SubDomain',
            'record_line_id' => 'RecordLineId',
            'mx' => 'MX',
            'ttl' => 'TTL',
            'weight' => 'Weight',
            'status' => 'Status',
            'remark' => 'Remark',
        ] as $key => $property) {
            if (array_key_exists($key, $data)) {
                $request->{$property} = $data[$key];
            }
        }
    }

    private function normalizeListFilters(array $filters): array
    {
        return [
            'offset' => (int) ($filters['offset'] ?? 0),
            'limit' => (int) ($filters['limit'] ?? 100),
            'subdomain' => trim((string) ($filters['subdomain'] ?? '')),
            'record_type' => strtoupper(trim((string) ($filters['record_type'] ?? ''))),
            'keyword' => trim((string) ($filters['keyword'] ?? '')),
            'refresh' => (bool) ($filters['refresh'] ?? false),
        ];
    }

    private function normalizeRecordData(array $data): array
    {
        $data['record_type'] = strtoupper(trim((string) ($data['record_type'] ?? '')));

        foreach (['mx', 'ttl', 'weight'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $data;
    }

    private function presentRecord(RecordListItem $record): array
    {
        return [
            'id' => $record->RecordId,
            'name' => $record->Name,
            'type' => $record->Type,
            'value' => $record->Value,
            'line' => $record->Line,
            'line_id' => $record->LineId,
            'status' => $record->Status,
            'ttl' => $record->TTL,
            'mx' => $record->MX,
            'weight' => $record->Weight,
            'monitor_status' => $record->MonitorStatus,
            'remark' => $record->Remark,
            'default_ns' => $record->DefaultNS,
            'updated_on' => $record->UpdatedOn,
        ];
    }
}
