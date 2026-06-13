<?php

use App\Modules\Notification\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('notifications')->group(function () {
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('{notification}/read', [NotificationController::class, 'markRead']);
});
