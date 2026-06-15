<?php

namespace App\Modules\Search\Models;

use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'notes_ar', 'notes_en'])]
class TaskSearchIndex extends Model
{
    protected $table = 'task_search_index';

    public const CREATED_AT = 'updated_at';

    public const UPDATED_AT = 'updated_at';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
