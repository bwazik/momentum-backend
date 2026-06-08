<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class TenantModel extends Model
{
    protected static function booted()
    {
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
