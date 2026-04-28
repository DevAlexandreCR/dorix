<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function (): void {
    Route::get('/meta', MetaController::class);
});

