<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        /** @var Model $model */
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
