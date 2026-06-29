<?php

declare(strict_types=1);

use app\controller\hostname\HostnameController;
use app\controller\hostname\PreferredDomainController;
use think\facade\Route;

$zonePattern = ['zoneName' => '[A-Za-z0-9.-]+'];
$hostnamePattern = array_merge($zonePattern, ['hostnameFqdn' => '[A-Za-z0-9.-]+']);

// 优选域名(hostname 创建/编辑时的"境内优选 CNAME"候选)
Route::get('hostname/preferred-domains', [PreferredDomainController::class, 'index']);
Route::post('hostname/preferred-domains', [PreferredDomainController::class, 'store']);
Route::put('hostname/preferred-domains/sort', [PreferredDomainController::class, 'sort']);
Route::put('hostname/preferred-domains/:domain', [PreferredDomainController::class, 'update'])
    ->pattern(['domain' => '[A-Za-z0-9.%-]+']);
Route::delete('hostname/preferred-domains/:domain', [PreferredDomainController::class, 'delete'])
    ->pattern(['domain' => '[A-Za-z0-9.%-]+']);

// Zone 列表(hostname 关联的 cloudflare zones)
Route::get('hostname/providers/:providerId/zones', [HostnameController::class, 'zones'])->completeMatch();

// Hostname 列表与新建
Route::get('hostname/providers/:providerId/zones/:zoneName/hostnames', [HostnameController::class, 'hostnames'])->pattern($zonePattern)->completeMatch();
Route::post('hostname/providers/:providerId/zones/:zoneName/hostnames', [HostnameController::class, 'store'])->pattern($zonePattern)->completeMatch();

// 单个 hostname （详情/删除）
Route::get('hostname/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn', [HostnameController::class, 'show'])->pattern($hostnamePattern)->completeMatch();
Route::put('hostname/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn', [HostnameController::class, 'update'])->pattern($hostnamePattern)->completeMatch();
Route::delete('hostname/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn', [HostnameController::class, 'delete'])->pattern($hostnamePattern)->completeMatch();

// hostname 状态刷新
Route::post('hostname/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn/refresh', [HostnameController::class, 'refresh'])->pattern($hostnamePattern)->completeMatch();

// Zone 级默认回源域名(fallback origin)
Route::get('hostname/providers/:providerId/zones/:zoneName/fallback-origin', [HostnameController::class, 'fallbackOriginShow'])->pattern($zonePattern)->completeMatch();
Route::put('hostname/providers/:providerId/zones/:zoneName/fallback-origin', [HostnameController::class, 'fallbackOriginUpdate'])->pattern($zonePattern)->completeMatch();
Route::delete('hostname/providers/:providerId/zones/:zoneName/fallback-origin', [HostnameController::class, 'fallbackOriginDelete'])->pattern($zonePattern)->completeMatch();

// DNSPod 同步
