<?php

declare(strict_types=1);

use app\controller\provider\ProviderController;
use think\facade\Route;

Route::get('providers/definitions', [ProviderController::class, 'definitions']);
Route::put('providers/sort-order', [ProviderController::class, 'sort']);
Route::get('providers', [ProviderController::class, 'index'])->completeMatch();
Route::post('providers', [ProviderController::class, 'store']);
Route::get('providers/:id', [ProviderController::class, 'show']);
Route::put('providers/:id', [ProviderController::class, 'update']);
Route::delete('providers/:id', [ProviderController::class, 'delete']);
