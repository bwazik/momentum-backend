<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['rank', 'name_ar', 'name_en', 'description'])]
class AuthorityGrade extends TenantModel
{
    use HasFactory;

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    protected function casts(): array
    {
        return [];
    }
}
