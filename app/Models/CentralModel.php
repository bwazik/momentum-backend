<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class CentralModel extends Model
{
    /**
     * Ensure central models always use the central connection,
     * even when tenancy is initialized and the default connection is switched.
     */
    public function getConnectionName()
    {
        return config('tenancy.database.central_connection');
    }
}
