<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Models\Document;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'user_id', 'parent_comment_id', 'body'])]
class Comment extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'entity_id')
            ->where('entity_type', DocumentEntityType::Comment);
    }
}
