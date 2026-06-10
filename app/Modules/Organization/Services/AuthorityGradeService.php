<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Events\AuthorityGradeCreated;
use App\Modules\Organization\Events\AuthorityGradeUpdated;
use App\Modules\Organization\Exceptions\AuthorityGradeHasActivePositionsException;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Traits\AuthenticatedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class AuthorityGradeService
{
    use AuthenticatedUser;

    public function create(array $data): AuthorityGrade
    {
        try {
            $grade = AuthorityGrade::create([
                'rank' => $data['rank'],
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'description' => $data['description'] ?? null,
            ]);

            event(new AuthorityGradeCreated($grade));

            return $grade;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to create authority grade', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'authority_grade.create',
                'entity_type' => 'authority_grade',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(AuthorityGrade $grade, array $data): AuthorityGrade
    {
        try {
            if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
                $data['name_en'] = $data['name_ar'] ?? $grade->name_ar;
            }

            $grade->update($data);

            event(new AuthorityGradeUpdated($grade));

            return $grade->fresh();
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to update authority grade', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'authority_grade.update',
                'entity_type' => 'authority_grade',
                'entity_id' => $grade->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(AuthorityGrade $grade): void
    {
        try {
            if ($grade->positions()->where('is_active', true)->exists()) {
                throw new AuthorityGradeHasActivePositionsException;
            }

            $grade->delete();
        } catch (AuthorityGradeHasActivePositionsException $e) {
            Log::channel('organization')->warning('Attempted to delete authority grade with active positions', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'authority_grade.delete',
                'entity_type' => 'authority_grade',
                'entity_id' => $grade->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to delete authority grade', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'authority_grade.delete',
                'entity_type' => 'authority_grade',
                'entity_id' => $grade->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function listAll(): Collection
    {
        return AuthorityGrade::orderBy('rank')->get();
    }
}
