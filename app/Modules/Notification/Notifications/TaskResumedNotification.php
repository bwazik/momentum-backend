<?php

namespace App\Modules\Notification\Notifications;

use App\Enums\PreferredLanguage;
use App\Modules\Notification\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskResumedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $taskPublicId,
        public string $taskTitleAr,
        public ?string $taskTitleEn,
        public string $dedupeKey,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => NotificationType::TaskResumed->value,
            'dedupe_key' => $this->dedupeKey,
            'title_ar' => __('notifications.task_resumed.title', [], 'ar'),
            'title_en' => __('notifications.task_resumed.title', [], 'en'),
            'body_ar' => __('notifications.task_resumed.body', ['task' => $this->taskTitleAr], 'ar'),
            'body_en' => __('notifications.task_resumed.body', ['task' => $this->taskTitleEn ?? $this->taskTitleAr], 'en'),
            'task_public_id' => $this->taskPublicId,
            'action_url' => "/tasks/{$this->taskPublicId}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language === PreferredLanguage::ENGLISH ? 'en' : 'ar';
        $title = $locale === 'en' ? ($this->taskTitleEn ?? $this->taskTitleAr) : $this->taskTitleAr;

        return (new MailMessage)
            ->subject(__('notifications.task_resumed.subject', [], $locale))
            ->greeting(__('notifications.task_resumed.title', [], $locale))
            ->line(__('notifications.task_resumed.body', ['task' => $title], $locale))
            ->action(__('notifications.view_task', [], $locale), url("/tasks/{$this->taskPublicId}"));
    }
}
