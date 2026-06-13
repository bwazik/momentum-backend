<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationReadService
{
    public function unreadCount(User $user): int
    {
        $key = (tenant()?->slug ?? 'central').':notification:unread_count:'.$user->public_id;

        return Cache::remember($key, 60, fn () => $user->unreadNotifications()->count());
    }

    public function markRead(User $user, string $id): void
    {
        try {
            $notification = $user->notifications()->whereKey($id)->firstOrFail();
            $notification->markAsRead();

            $this->invalidateUnreadCount($user);

            Log::channel('notification')->info('Notification marked as read', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.read',
                'entity_type' => 'notification',
                'entity_id' => $id,
                'performed_by' => $user->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to mark notification as read', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.read',
                'entity_type' => 'notification',
                'entity_id' => $id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markAllRead(User $user): void
    {
        try {
            DB::transaction(function () use ($user) {
                $user->unreadNotifications->markAsRead();
            });

            $this->invalidateUnreadCount($user);

            Log::channel('notification')->info('All notifications marked as read', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.read_all',
                'entity_type' => 'notification',
                'performed_by' => $user->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to mark all notifications as read', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.read_all',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function invalidateUnreadCount(User $user): void
    {
        $key = (tenant()?->slug ?? 'central').':notification:unread_count:'.$user->public_id;
        Cache::forget($key);
    }
}
