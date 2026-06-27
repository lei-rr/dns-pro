<?php

declare(strict_types=1);

use app\controller\edgeone\EdgeOneAccelerationDomainController;
use app\controller\edgeone\EdgeOneZoneController;
use think\facade\Route;

$edgeonePattern = [
    'zoneName' => '[A-Za-z0-9.-]+',
    'domainName' => '[A-Za-z0-9.*-]+(\.[A-Za-z0-9-]+)+',
];

Route::post('edgeone/providers/:providerId/zones/:zoneName/records/:domainName/cname-sync', [EdgeOneAccelerationDomainController::class, 'syncZoneRecordCname'])->pattern($edgeonePattern);
Route::put('edgeone/providers/:providerId/zones/:zoneName/records/:domainName/certificate', [EdgeOneAccelerationDomainController::class, 'updateZoneRecordCertificate'])->pattern($edgeonePattern);
Route::get('edgeone/providers/:providerId/zones/:zoneName/records/:domainName/cname-status', [EdgeOneAccelerationDomainController::class, 'zoneRecordCnameStatus'])->pattern($edgeonePattern);
Route::put('edgeone/providers/:providerId/zones/:zoneName/records/:domainName/status', [EdgeOneAccelerationDomainController::class, 'updateZoneRecordStatus'])->pattern($edgeonePattern);
Route::put('edgeone/providers/:providerId/zones/:zoneName/records/:domainName', [EdgeOneAccelerationDomainController::class, 'updateZoneRecord'])->pattern($edgeonePattern);
Route::delete('edgeone/providers/:providerId/zones/:zoneName/records/:domainName', [EdgeOneAccelerationDomainController::class, 'deleteZoneRecord'])->pattern($edgeonePattern);
Route::get('edgeone/providers/:providerId/zones/:zoneName/records', [EdgeOneAccelerationDomainController::class, 'zoneRecords'])->pattern(['zoneName' => $edgeonePattern['zoneName']])->completeMatch();
Route::post('edgeone/providers/:providerId/zones/:zoneName/records', [EdgeOneAccelerationDomainController::class, 'storeZoneRecord'])->pattern(['zoneName' => $edgeonePattern['zoneName']])->completeMatch();
Route::get('edgeone/providers/:providerId/zones', [EdgeOneZoneController::class, 'index'])->completeMatch();
