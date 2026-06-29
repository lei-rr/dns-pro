<?php

declare(strict_types=1);

use app\controller\edgeone\EdgeOneAccelerationDomainController;
use app\controller\edgeone\EdgeOneZoneController;
use think\facade\Route;

$edgeonePattern = [
    'zoneId' => '[A-Za-z0-9-]+',
    'domainName' => '[A-Za-z0-9.*-]+(\.[A-Za-z0-9-]+)+',
];

Route::post('edgeone/providers/:providerId/zones/:zoneId/records/:domainName/cname-sync', [EdgeOneAccelerationDomainController::class, 'syncZoneRecordCname'])->pattern($edgeonePattern);
Route::put('edgeone/providers/:providerId/zones/:zoneId/records/:domainName/certificate', [EdgeOneAccelerationDomainController::class, 'updateZoneRecordCertificate'])->pattern($edgeonePattern);
Route::put('edgeone/providers/:providerId/zones/:zoneId/records/:domainName/status', [EdgeOneAccelerationDomainController::class, 'updateZoneRecordStatus'])->pattern($edgeonePattern);
Route::put('edgeone/providers/:providerId/zones/:zoneId/records/:domainName', [EdgeOneAccelerationDomainController::class, 'updateZoneRecord'])->pattern($edgeonePattern);
Route::delete('edgeone/providers/:providerId/zones/:zoneId/records/:domainName', [EdgeOneAccelerationDomainController::class, 'deleteZoneRecord'])->pattern($edgeonePattern);
Route::get('edgeone/providers/:providerId/zones/:zoneId/records', [EdgeOneAccelerationDomainController::class, 'zoneRecords'])->pattern(['zoneId' => $edgeonePattern['zoneId']])->completeMatch();
Route::post('edgeone/providers/:providerId/zones/:zoneId/records', [EdgeOneAccelerationDomainController::class, 'storeZoneRecord'])->pattern(['zoneId' => $edgeonePattern['zoneId']])->completeMatch();
Route::get('edgeone/providers/:providerId/zones/:zoneId', [EdgeOneZoneController::class, 'show'])->pattern(['zoneId' => $edgeonePattern['zoneId']])->completeMatch();
Route::get('edgeone/providers/:providerId/zones', [EdgeOneZoneController::class, 'index'])->completeMatch();
