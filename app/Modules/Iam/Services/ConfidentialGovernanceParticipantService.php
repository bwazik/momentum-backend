<?php

namespace App\Modules\Iam\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Events\ConfidentialGovernanceParticipantCreated;
use App\Modules\Iam\Events\ConfidentialGovernanceParticipantRevoked;
use App\Modules\Iam\Events\ConfidentialGovernanceParticipantUpdated;
use App\Modules\Iam\Exceptions\InvalidGovernanceScopeException;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfidentialGovernanceParticipantService
{
    private const CACHE_KEY = 'iam:confidential_governance_participants:all';

    public function list(int $perPage = 15): CursorPaginator
    {
        try {
            return ConfidentialGovernanceParticipant::with(['position', 'scopeDepartment', 'blueprintCategory', 'createdBy'])
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to list confidential governance participants', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.list',
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function allActive(): array
    {
        $key = $this->cacheKey();

        return Cache::remember($key, 300, function () {
            return ConfidentialGovernanceParticipant::with('position')
                ->whereNull('revoked_at')
                ->get()
                ->all();
        });
    }

    public function create(array $data, User $createdBy): ConfidentialGovernanceParticipant
    {
        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $this->validateScope($data);

                $position = Position::where('public_id', $data['position_id'])->firstOrFail();
                $scopeDepartmentId = null;
                if (! empty($data['scope_department_id'])) {
                    $scopeDepartmentId = Department::where('public_id', $data['scope_department_id'])->value('id');
                }

                $config = ConfidentialGovernanceParticipant::create([
                    'position_id' => $position->id,
                    'scope_type' => $data['scope_type'],
                    'scope_department_id' => $scopeDepartmentId,
                    'blueprint_category_id' => $data['blueprint_category_id'] ?? null,
                    'applies_to_classification_level' => $data['applies_to_classification_level'] ?? ClassificationLevel::Confidential->value,
                    'created_by_user_id' => $createdBy->id,
                    'created_at' => now(),
                ]);

                $config->load(['position', 'scopeDepartment', 'blueprintCategory']);
                $this->clearCache();
                event(new ConfidentialGovernanceParticipantCreated($config));

                return $config;
            });
        } catch (InvalidGovernanceScopeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to create confidential governance participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.create',
                'performed_by' => $createdBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(ConfidentialGovernanceParticipant $config, array $data, User $updatedBy): ConfidentialGovernanceParticipant
    {
        try {
            return DB::transaction(function () use ($config, $data, $updatedBy) {
                $this->validateScope($data);

                $update = [
                    'scope_type' => $data['scope_type'] ?? $config->scope_type,
                    'blueprint_category_id' => $data['blueprint_category_id'] ?? $config->blueprint_category_id,
                    'applies_to_classification_level' => $data['applies_to_classification_level'] ?? $config->applies_to_classification_level->value,
                ];

                if (array_key_exists('scope_department_id', $data)) {
                    $update['scope_department_id'] = empty($data['scope_department_id'])
                        ? null
                        : Department::where('public_id', $data['scope_department_id'])->value('id');
                }

                $config->update($update);
                $this->clearCache();
                event(new ConfidentialGovernanceParticipantUpdated($config->fresh(), $updatedBy));

                return $config->fresh(['position', 'scopeDepartment', 'blueprintCategory']);
            });
        } catch (InvalidGovernanceScopeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to update confidential governance participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.update',
                'entity_id' => $config->public_id,
                'performed_by' => $updatedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revoke(ConfidentialGovernanceParticipant $config, User $revokedBy): ConfidentialGovernanceParticipant
    {
        try {
            return DB::transaction(function () use ($config, $revokedBy) {
                $config->update(['revoked_at' => now()]);
                $this->clearCache();
                event(new ConfidentialGovernanceParticipantRevoked($config, $revokedBy));

                return $config->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke confidential governance participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.revoke',
                'entity_id' => $config->public_id,
                'performed_by' => $revokedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateScope(array $data): void
    {
        $scopeType = is_object($data['scope_type']) ? $data['scope_type']->value : (int) $data['scope_type'];

        if (in_array($scopeType, [ScopeType::OWN_DEPARTMENT->value, ScopeType::OWN_TASKS->value], true)) {
            throw new InvalidGovernanceScopeException;
        }

        $needsDepartment = in_array($scopeType, [ScopeType::SPECIFIC_DEPARTMENT->value, ScopeType::DEPARTMENT_TREE->value], true);
        if ($needsDepartment && empty($data['scope_department_id'])) {
            throw new InvalidGovernanceScopeException;
        }

        if ($scopeType === ScopeType::TENANT->value && ! empty($data['scope_department_id'])) {
            throw new InvalidGovernanceScopeException;
        }
    }

    private function cacheKey(): string
    {
        return tenant()?->slug.':'.self::CACHE_KEY;
    }

    private function clearCache(): void
    {
        Cache::forget($this->cacheKey());
    }
}
