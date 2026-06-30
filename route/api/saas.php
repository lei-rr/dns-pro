<?php

declare(strict_types=1);

use app\controller\saas\SaasController;
use app\controller\saas\PreferredDomainController;
use think\facade\Route;

$zonePattern = ['zoneName' => '[A-Za-z0-9.-]+'];
$hostnamePattern = array_merge($zonePattern, ['hostnameFqdn' => '[A-Za-z0-9.-]+']);

// 优选域名（saas 创建/编辑时的"境内优选 CNAME"候选）
Route::get('saas/preferred-domains', [PreferredDomainController::class, 'index']);
Route::post('saas/preferred-domains', [PreferredDomainController::class, 'store']);
Route::put('saas/preferred-domains/sort', [PreferredDomainController::class, 'sort']);
Route::put('saas/preferred-domains/:domain', [PreferredDomainController::class, 'update'])
    ->pattern(['domain' => '[A-Za-z0-9.%-]+']);
Route::delete('saas/preferred-domains/:domain', [PreferredDomainController::class, 'delete'])
    ->pattern(['domain' => '[A-Za-z0-9.%-]+']);

// Zone 列表（saas 关联的 cloudflare zones）
Route::get('saas/providers/:providerId/zones', [SaasController::class, 'zones'])->completeMatch();

// SaaS 主机名列表与新建
Route::get('saas/providers/:providerId/zones/:zoneName/hostnames', [SaasController::class, 'hostnames'])->pattern($zonePattern)->completeMatch();
Route::post('saas/providers/:providerId/zones/:zoneName/hostnames', [SaasController::class, 'store'])->pattern($zonePattern)->completeMatch();

// 单个 saas 主机名（详情/删除）
Route::get('saas/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn', [SaasController::class, 'show'])->pattern($hostnamePattern)->completeMatch();
Route::put('saas/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn', [SaasController::class, 'update'])->pattern($hostnamePattern)->completeMatch();
Route::delete('saas/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn', [SaasController::class, 'delete'])->pattern($hostnamePattern)->completeMatch();

// saas 主机名状态刷新 / 同步
Route::post('saas/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn/refresh', [SaasController::class, 'refresh'])->pattern($hostnamePattern)->completeMatch();
Route::post('saas/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn/sync', [SaasController::class, 'sync'])->pattern($hostnamePattern)->completeMatch();
Route::post('saas/providers/:providerId/zones/:zoneName/hostnames/:hostnameFqdn/sync-check', [SaasController::class, 'checkSync'])->pattern($hostnamePattern)->completeMatch();

// Zone 级默认回源域名(fallback origin)
Route::get('saas/providers/:providerId/zones/:zoneName/fallback-origin', [SaasController::class, 'fallbackOriginShow'])->pattern($zonePattern)->completeMatch();
Route::put('saas/providers/:providerId/zones/:zoneName/fallback-origin', [SaasController::class, 'fallbackOriginUpdate'])->pattern($zonePattern)->completeMatch();
Route::delete('saas/providers/:providerId/zones/:zoneName/fallback-origin', [SaasController::class, 'fallbackOriginDelete'])->pattern($zonePattern)->completeMatch();

// DNSPod 同步
