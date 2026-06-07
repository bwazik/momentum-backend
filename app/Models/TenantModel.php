<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

abstract class TenantModel extends Model
{
    use SoftDeletes;

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = Str::uuid()->toString();
            }
        });
    }
}
