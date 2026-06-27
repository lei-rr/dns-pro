<?php

declare(strict_types=1);

use app\controller\cloudflared\CloudflaredTunnelController;
use think\facade\Route;

$pattern = [
    'tunnelId' => '[A-Za-z0-9-]+',
];

// CF zones 列表（供路由配置时下拉选择）
Route::get('cloudflared/providers/:providerId/zones', [CloudflaredTunnelController::class, 'zones'])->completeMatch();

// 隧道列表 / 创建
Route::get('cloudflared/providers/:providerId/tunnels', [CloudflaredTunnelController::class, 'index'])->completeMatch();
Route::post('cloudflared/providers/:providerId/tunnels', [CloudflaredTunnelController::class, 'store'])->completeMatch();

// 单隧道：详情（轮询用）/ 删除
Route::get('cloudflared/providers/:providerId/tunnels/:tunnelId', [CloudflaredTunnelController::class, 'show'])->pattern($pattern)->completeMatch();
Route::delete('cloudflared/providers/:providerId/tunnels/:tunnelId', [CloudflaredTunnelController::class, 'delete'])->pattern($pattern);

// 隧道 token + 安装命令
Route::get('cloudflared/providers/:providerId/tunnels/:tunnelId/token', [CloudflaredTunnelController::class, 'token'])->pattern($pattern);
// 轮换令牌（使旧 token 失效）
Route::post('cloudflared/providers/:providerId/tunnels/:tunnelId/token/rotate', [CloudflaredTunnelController::class, 'rotateToken'])->pattern($pattern);

// 隧道路由（ingress 配置）
Route::get('cloudflared/providers/:providerId/tunnels/:tunnelId/routes', [CloudflaredTunnelController::class, 'configShow'])->pattern($pattern);
Route::post('cloudflared/providers/:providerId/tunnels/:tunnelId/routes', [CloudflaredTunnelController::class, 'addRoute'])->pattern($pattern);
Route::put('cloudflared/providers/:providerId/tunnels/:tunnelId/routes', [CloudflaredTunnelController::class, 'updateRoute'])->pattern($pattern);
Route::delete('cloudflared/providers/:providerId/tunnels/:tunnelId/routes', [CloudflaredTunnelController::class, 'deleteRoute'])->pattern($pattern);
