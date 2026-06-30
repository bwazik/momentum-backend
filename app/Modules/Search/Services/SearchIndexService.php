<?php

namespace App\Modules\Search\Services;

use App\Modules\Search\Models\TaskSearchIndex;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Support\Facades\Log;

class SearchIndexService
{
    public function upsertForTask(Task $task): void
    {
        try {
            $notesAr = TaskStageAssignment::where('task_id', $task->id)
                ->where('is_completed', true)
                ->whereNotNull('completion_note_ar')
                ->orderBy('completed_at')
                ->pluck('completion_note_ar')
                ->implode("\n");

            $notesEn = TaskStageAssignment::where('task_id', $task->id)
                ->where('is_completed', true)
                ->whereNotNull('completion_note_en')
                ->orderBy('completed_at')
                ->pluck('completion_note_en')
                ->implode("\n");

            $commentContent = Comment::where('task_id', $task->id)
                ->whereNull('deleted_at')
                ->pluck('body')
                ->implode("\n");

            TaskSearchIndex::updateOrCreate(
                ['task_id' => $task->id],
                [
                    'notes_ar' => $notesAr ?: null,
                    'notes_en' => $notesEn ?: null,
                    'comment_content_ar' => $commentContent ?: null,
                    'comment_content_en' => $commentContent ?: null,
                ]
            );
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to update search index', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'search.index_update',
                'entity_type' => 'task_search_index',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
