<?php

use App\Modules\Search\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('search')->group(function () {
    Route::get('/', [SearchController::class, 'search']);
    Route::get('tasks', [SearchController::class, 'tasks']);
    Route::get('recent', [SearchController::class, 'recent']);
});
