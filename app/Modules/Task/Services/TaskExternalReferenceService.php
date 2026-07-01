<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Events\ExternalReferenceCreated;
use App\Modules\Task\Events\ExternalReferenceDeleted;
use App\Modules\Task\Events\ExternalReferenceUpdated;
use App\Modules\Task\Exceptions\ExternalEntityInactiveException;
use App\Modules\Task\Exceptions\ExternalEntityNotFoundException;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;

class TaskExternalReferenceService
{
    public function listForTask(Task $task, int $perPage = 15): CursorPaginator
    {
        try {
            return TaskExternalReference::where('task_id', $task->id)
                ->with('externalEntity')
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to list task external references', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.list',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function create(Task $task, array $data, User $user): TaskExternalReference
    {
        try {
            $entityId = $this->resolveActiveEntityId($data['external_entity_id'] ?? null);

            $reference = TaskExternalReference::create([
                'task_id' => $task->id,
                'reference_type' => $data['reference_type'],
                'reference_number' => $data['reference_number'],
                'external_entity_id' => $entityId,
                'notes' => $data['notes'] ?? null,
            ]);

            event(new ExternalReferenceCreated($reference->load('externalEntity'), $user));

            return $reference->fresh(['externalEntity']);
        } catch (ExternalEntityNotFoundException|ExternalEntityInactiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create external reference', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.create',
                'entity_type' => 'external_reference',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(TaskExternalReference $reference, array $data, User $user): TaskExternalReference
    {
        try {
            $reference->update([
                'reference_type' => $data['reference_type'] ?? $reference->reference_type,
                'reference_number' => $data['reference_number'] ?? $reference->reference_number,
                'external_entity_id' => array_key_exists('external_entity_id', $data)
                    ? $this->resolveActiveEntityId($data['external_entity_id'])
                    : $reference->external_entity_id,
                'notes' => $data['notes'] ?? $reference->notes,
            ]);

            event(new ExternalReferenceUpdated($reference->fresh(['externalEntity']), $user));

            return $reference->fresh(['externalEntity']);
        } catch (ExternalEntityNotFoundException|ExternalEntityInactiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to update external reference', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.update',
                'entity_type' => 'external_reference',
                'entity_id' => $reference->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(TaskExternalReference $reference, User $user): void
    {
        try {
            $reference->delete();
            event(new ExternalReferenceDeleted($reference, $user));
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to delete external reference', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.delete',
                'entity_type' => 'external_reference',
                'entity_id' => $reference->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function resolveActiveEntityId(?string $publicId): ?int
    {
        if ($publicId === null) {
            return null;
        }

        $entity = ExternalEntity::where('public_id', $publicId)->first();

        if (! $entity) {
            throw new ExternalEntityNotFoundException;
        }

        if (! $entity->is_active) {
            throw new ExternalEntityInactiveException;
        }

        return $entity->id;
    }
}
