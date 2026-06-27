<?php

declare(strict_types=1);

namespace app\controller\edgeone;

use app\controller\concerns\ResolvesQueryParams;
use app\service\edgeone\EdgeOneService;
use app\support\ApiResponse;
use app\validate\EdgeOneRecordValidate;
use think\Response;

class EdgeOneAccelerationDomainController
{
    use ResolvesQueryParams;

    public function __construct(private readonly EdgeOneService $edgeone)
    {
    }

    public function zoneRecords(string $providerId, string $zoneName): Response
    {
        $filters = validate(EdgeOneRecordValidate::class)->scene('index')->checked(input('get.', []));

        return ApiResponse::data(
            $this->edgeone->accelerationDomains($providerId, $this->zoneId($providerId, $zoneName), $filters),
        );
    }

    public function storeZoneRecord(string $providerId, string $zoneName): Response
    {
        $data = validate(EdgeOneRecordValidate::class)->scene('record')->checked(input('post.', []));

        return ApiResponse::data(
            $this->edgeone->createAccelerationDomain(
                $providerId,
                $this->zoneId($providerId, $zoneName),
                $data,
                $this->boolQuery('auto_sync'),
            ),
            201,
        );
    }

    public function updateZoneRecord(string $providerId, string $zoneName, string $domainName): Response
    {
        $data = validate(EdgeOneRecordValidate::class)->scene('updateRecord')->checked(input('put.', []));

        return ApiResponse::data(
            $this->edgeone->updateAccelerationDomain($providerId, $this->zoneId($providerId, $zoneName), $domainName, $data),
        );
    }

    public function deleteZoneRecord(string $providerId, string $zoneName, string $domainName): Response
    {
        return ApiResponse::data(
            $this->edgeone->deleteAccelerationDomain(
                $providerId,
                $this->zoneId($providerId, $zoneName),
                $domainName,
                $this->boolQuery('auto_cleanup', true),
            ),
        );
    }

    public function updateZoneRecordStatus(string $providerId, string $zoneName, string $domainName): Response
    {
        $data = validate(EdgeOneRecordValidate::class)->scene('status')->checked(input('put.', []));

        return ApiResponse::data(
            $this->edgeone->updateAccelerationDomainStatus(
                $providerId,
                $this->zoneId($providerId, $zoneName),
                $domainName,
                $data['status'],
            ),
        );
    }

    public function syncZoneRecordCname(string $providerId, string $zoneName, string $domainName): Response
    {
        return ApiResponse::data(
            $this->edgeone->syncCname($providerId, $this->zoneId($providerId, $zoneName), $domainName),
        );
    }

    public function updateZoneRecordCertificate(string $providerId, string $zoneName, string $domainName): Response
    {
        $data = validate(EdgeOneRecordValidate::class)->scene('certificate')->checked(input('put.', []));

        return ApiResponse::data(
            $this->edgeone->updateCertificate($providerId, $this->zoneId($providerId, $zoneName), $domainName, $data),
        );
    }

    public function zoneRecordCnameStatus(string $providerId, string $zoneName, string $domainName): Response
    {
        return ApiResponse::data(
            $this->edgeone->cnameStatus($providerId, $this->zoneId($providerId, $zoneName), $domainName),
        );
    }

    private function zoneId(string $providerId, string $zoneName): string
    {
        return $this->edgeone->zoneIdByName($providerId, strtolower(trim($zoneName)));
    }
}
