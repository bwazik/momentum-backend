<?php

namespace App\Modules\FollowUp\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\FollowUp\Enums\FollowUpActionType;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'user_id', 'action_type', 'note_ar', 'note_en', 'contact_name'])]
class FollowUpAction extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action_type' => FollowUpActionType::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
