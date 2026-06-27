<?php

declare(strict_types=1);

namespace app\controller\cloudflare;

use app\service\cloudflare\CloudflareDnsRecordService;
use app\service\cloudflare\CloudflareZoneService;
use app\support\ApiResponse;
use app\validate\CloudflareRecordValidate;
use think\Response;

class CloudflareDnsRecordController
{
    public function __construct(
        private readonly CloudflareDnsRecordService $records,
        private readonly CloudflareZoneService $zones,
    ) {
    }

    public function zoneIndex(string $providerId, string $zone): Response
    {
        $filters = validate(CloudflareRecordValidate::class)
            ->scene('index')
            ->checked(input('get.', []));

        return ApiResponse::data($this->records->list(
            $providerId,
            $this->zones->idByName($providerId, $zone),
            $filters,
        ));
    }

    public function zoneStore(string $providerId, string $zone): Response
    {
        $data = validate(CloudflareRecordValidate::class)
            ->scene('record')
            ->checked(input('post.', []));

        return ApiResponse::data(
            $this->records->create($providerId, $this->zones->idByName($providerId, $zone), $data),
            201,
        );
    }

    public function zoneUpdate(string $providerId, string $zone, string $recordId): Response
    {
        $data = validate(CloudflareRecordValidate::class)
            ->scene('record')
            ->checked(input('put.', []));

        return ApiResponse::data(
            $this->records->update($providerId, $this->zones->idByName($providerId, $zone), $recordId, $data),
        );
    }

    public function zoneDelete(string $providerId, string $zone, string $recordId): Response
    {
        return ApiResponse::data(
            $this->records->delete($providerId, $this->zones->idByName($providerId, $zone), $recordId),
        );
    }
}
