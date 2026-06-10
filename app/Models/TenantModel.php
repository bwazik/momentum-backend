<?php

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use HasPublicId;
}
