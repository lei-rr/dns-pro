<?php

declare(strict_types=1);

use app\controller\dnspod\DnsPodRecordController;
use app\controller\dnspod\DnsPodZoneController;
use think\facade\Route;

$zonePattern = ['zone' => '[A-Za-z0-9.-]+'];

Route::put('dnspod/providers/:providerId/zones/:zone/records/:recordId', [DnsPodRecordController::class, 'update'])
    ->pattern($zonePattern + ['recordId' => '\d+']);
Route::delete('dnspod/providers/:providerId/zones/:zone/records/:recordId', [DnsPodRecordController::class, 'delete'])
    ->pattern($zonePattern + ['recordId' => '\d+']);
Route::get('dnspod/providers/:providerId/zones/:zone/records', [DnsPodRecordController::class, 'index'])->pattern($zonePattern)->completeMatch();
Route::post('dnspod/providers/:providerId/zones/:zone/records', [DnsPodRecordController::class, 'store'])->pattern($zonePattern)->completeMatch();
Route::delete('dnspod/providers/:providerId/zones/:zone', [DnsPodZoneController::class, 'delete'])->pattern($zonePattern);
Route::get('dnspod/providers/:providerId/zones', [DnsPodZoneController::class, 'index'])->completeMatch();
Route::post('dnspod/providers/:providerId/zones', [DnsPodZoneController::class, 'store'])->completeMatch();
