<?php

declare(strict_types=1);

use app\controller\cloudflare\CloudflareDnsRecordController;
use app\controller\cloudflare\CloudflareZoneController;
use think\facade\Route;

Route::put('cloudflare/providers/:providerId/zones/:zone/records/:recordId', [CloudflareDnsRecordController::class, 'zoneUpdate']);
Route::delete('cloudflare/providers/:providerId/zones/:zone/records/:recordId', [CloudflareDnsRecordController::class, 'zoneDelete']);
Route::get('cloudflare/providers/:providerId/zones/:zone/records', [CloudflareDnsRecordController::class, 'zoneIndex'])->completeMatch();
Route::post('cloudflare/providers/:providerId/zones/:zone/records', [CloudflareDnsRecordController::class, 'zoneStore'])->completeMatch();
Route::delete('cloudflare/providers/:providerId/zones/:zone', [CloudflareZoneController::class, 'delete']);
Route::get('cloudflare/providers/:providerId/zones', [CloudflareZoneController::class, 'index'])->completeMatch();
Route::post('cloudflare/providers/:providerId/zones', [CloudflareZoneController::class, 'store'])->completeMatch();
