<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Events\CommentCreated;
use App\Modules\Task\Exceptions\InvalidCommentParentException;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;

class CommentService
{
    public function create(Task $task, array $data, User $user): Comment
    {
        try {
            $parentId = null;

            if (! empty($data['parent_comment_id'])) {
                $parent = Comment::where('public_id', $data['parent_comment_id'])->first();

                if (! $parent || $parent->task_id !== $task->id || $parent->parent_comment_id !== null) {
                    throw new InvalidCommentParentException;
                }

                $parentId = $parent->id;
            }

            $comment = Comment::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'parent_comment_id' => $parentId,
                'body' => $data['body'],
            ]);

            event(new CommentCreated($comment->load('task'), $user));

            return $comment->fresh(['user']);
        } catch (InvalidCommentParentException $e) {
            Log::channel('task')->warning('Invalid comment parent', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'comment.create',
                'entity_type' => 'comment',
                'entity_id' => $data['parent_comment_id'] ?? null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create comment', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'comment.create',
                'entity_type' => 'comment',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function listForTask(Task $task, int $perPage = 15): CursorPaginator
    {
        try {
            return Comment::where('task_id', $task->id)
                ->whereNull('parent_comment_id')
                ->with(['task', 'user', 'replies.user'])
                ->withCount('documents')
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to list comments', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'comment.list',
                'entity_type' => 'comment',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
