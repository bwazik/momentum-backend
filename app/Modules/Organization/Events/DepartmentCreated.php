<?php

namespace App\Modules\Organization\Events;

use App\Modules\Organization\Models\Department;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DepartmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Department $department) {}
}
