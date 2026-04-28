<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'service' => 'backend',
        'docs' => [
            'health' => '/api/health',
            'meta' => '/api/v1/meta',
        ],
    ]);
});
