<?php

namespace Database\Factories;

use App\Modules\Organization\Models\AuthorityGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorityGrade>
 */
class AuthorityGradeFactory extends Factory
{
    protected $model = AuthorityGrade::class;

    private static int $rankCounter = 1;

    public function definition(): array
    {
        $rank = static::$rankCounter++;

        return [
            'rank' => $rank,
            'name_ar' => 'الرتبة '.$rank,
            'name_en' => 'Grade '.$rank,
        ];
    }
}
