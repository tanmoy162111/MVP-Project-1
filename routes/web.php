<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name'    => config('app.name'),
        'version' => '1.0.0',
        'api'     => url('/api/v1/health'),
    ]);
});
