<?php

declare(strict_types=1);

namespace app\controller\edgeone;

use app\controller\concerns\ResolvesQueryParams;
use app\controller\concerns\ValidatesInput;
use app\service\edgeone\EdgeOneService;
use app\service\edgeone\EdgeOneWorkflowService;
use app\support\ApiResponse;
use app\validate\EdgeOneRecordValidate;
use think\Response;

class EdgeOneAccelerationDomainController
{
    use ResolvesQueryParams;
    use ValidatesInput;

    public function __construct(
        private readonly EdgeOneService $edgeone,
        private readonly EdgeOneWorkflowService $workflow,
    ) {
    }

    public function zoneRecords(string $providerId, string $zoneId): Response
    {
        $filters = $this->queryInput(EdgeOneRecordValidate::class, 'index');

        return ApiResponse::data(
            $this->edgeone->accelerationDomains($providerId, $zoneId, $filters),
        );
    }

    public function storeZoneRecord(string $providerId, string $zoneId): Response
    {
        $data = $this->postInput(EdgeOneRecordValidate::class, 'record');

        return ApiResponse::data(
            $this->workflow->createAccelerationDomain(
                $providerId,
                $zoneId,
                $data,
                $this->boolQuery('auto_sync'),
            ),
            201,
        );
    }

    public function updateZoneRecord(string $providerId, string $zoneId, string $domainName): Response
    {
        $data = $this->putInput(EdgeOneRecordValidate::class, 'updateRecord');

        return ApiResponse::data(
            $this->edgeone->updateAccelerationDomain($providerId, $zoneId, $domainName, $data),
        );
    }

    public function deleteZoneRecord(string $providerId, string $zoneId, string $domainName): Response
    {
        return ApiResponse::data(
            $this->workflow->deleteAccelerationDomain(
                $providerId,
                $zoneId,
                $domainName,
                $this->boolQuery('auto_cleanup', true),
            ),
        );
    }

    public function updateZoneRecordStatus(string $providerId, string $zoneId, string $domainName): Response
    {
        $data = $this->putInput(EdgeOneRecordValidate::class, 'status');

        return ApiResponse::data(
            $this->edgeone->updateAccelerationDomainStatus(
                $providerId,
                $zoneId,
                $domainName,
                $data['status'],
            ),
        );
    }

    public function syncZoneRecordCname(string $providerId, string $zoneId, string $domainName): Response
    {
        return ApiResponse::data(
            $this->workflow->syncCname($providerId, $zoneId, $domainName),
        );
    }

    public function updateZoneRecordCertificate(string $providerId, string $zoneId, string $domainName): Response
    {
        $data = $this->putInput(EdgeOneRecordValidate::class, 'certificate');

        return ApiResponse::data(
            $this->edgeone->updateCertificate($providerId, $zoneId, $domainName, $data),
        );
    }

}
