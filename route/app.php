<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;
use app\controller\auth\SessionController;
use app\controller\Index;
use app\controller\system\HealthController;
use app\support\ApiResponse;
use app\support\ErrorMessages;

// 前端 SPA 入口路由(所有非 API 的页面都由前端路由接管)
Route::get('/', [Index::class, 'index']);
Route::get('login', [Index::class, 'index']);
Route::get('providers', [Index::class, 'index']);
Route::get('dnspod/<path>', [Index::class, 'index'])->pattern(['path' => '.*']);
Route::get('cloudflare/<path>', [Index::class, 'index'])->pattern(['path' => '.*']);
Route::get('edgeone/<path>', [Index::class, 'index'])->pattern(['path' => '.*']);
Route::get('saas/<path>', [Index::class, 'index'])->pattern(['path' => '.*']);
Route::get('cloudflared/<path>', [Index::class, 'index'])->pattern(['path' => '.*']);

// API 路由组
Route::group('api', function () {
    Route::get('health', [HealthController::class, 'show']);

    Route::post('session', [SessionController::class, 'store']);
    Route::get('session', [SessionController::class, 'show']);
    Route::delete('session', [SessionController::class, 'delete']);

    Route::group(function () {
        require __DIR__ . '/api/provider.php';
        require __DIR__ . '/api/dnspod.php';
        require __DIR__ . '/api/cloudflare.php';
        require __DIR__ . '/api/edgeone.php';
        require __DIR__ . '/api/saas.php';
        require __DIR__ . '/api/cloudflared.php';
    })->middleware('auth.required');

    Route::miss(fn () => ApiResponse::error(
        ErrorMessages::translate('not_found') ?? 'API endpoint not found',
        404,
        'not_found',
    ));
});
