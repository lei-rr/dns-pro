<?php

declare(strict_types=1);

namespace app\controller\cloudflare;

use app\controller\concerns\ValidatesInput;
use app\service\cloudflare\CloudflareDnsRecordGateway;
use app\service\cloudflare\CloudflareZoneGateway;
use app\support\ApiResponse;
use app\validate\CloudflareRecordValidate;
use think\Response;

class CloudflareDnsRecordController
{
    use ValidatesInput;

    public function __construct(
        private readonly CloudflareDnsRecordGateway $records,
        private readonly CloudflareZoneGateway $zones,
    ) {
    }

    public function zoneIndex(string $providerId, string $zone): Response
    {
        $filters = $this->queryInput(CloudflareRecordValidate::class, 'index');

        return ApiResponse::data($this->records->list(
            $providerId,
            $this->zones->idByName($providerId, $zone),
            $filters,
        ));
    }

    public function zoneStore(string $providerId, string $zone): Response
    {
        $data = $this->postInput(CloudflareRecordValidate::class, 'record');

        return ApiResponse::data(
            $this->records->create($providerId, $this->zones->idByName($providerId, $zone), $data),
            201,
        );
    }

    public function zoneUpdate(string $providerId, string $zone, string $recordId): Response
    {
        $data = $this->putInput(CloudflareRecordValidate::class, 'record');

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
