<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Events\AuthorityGradeCreated;
use App\Modules\Organization\Events\AuthorityGradeUpdated;
use App\Modules\Organization\Exceptions\AuthorityGradeHasActivePositionsException;
use App\Modules\Organization\Models\AuthorityGrade;
use Illuminate\Database\Eloquent\Collection;

class AuthorityGradeService
{
    public function create(array $data): AuthorityGrade
    {
        $grade = AuthorityGrade::create([
            'rank' => $data['rank'],
            'name_ar' => $data['name_ar'],
            'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
            'description' => $data['description'] ?? null,
        ]);

        event(new AuthorityGradeCreated($grade));

        return $grade;
    }

    public function update(AuthorityGrade $grade, array $data): AuthorityGrade
    {
        if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
            $data['name_en'] = $data['name_ar'] ?? $grade->name_ar;
        }

        $grade->update($data);

        event(new AuthorityGradeUpdated($grade));

        return $grade->fresh();
    }

    public function delete(AuthorityGrade $grade): void
    {
        if ($grade->positions()->where('is_active', true)->exists()) {
            throw new AuthorityGradeHasActivePositionsException;
        }

        $grade->delete();
    }

    public function listAll(): Collection
    {
        return AuthorityGrade::orderBy('rank')->get();
    }
}
