<?php

namespace App\Modules\Organization\Events;

use App\Modules\Organization\Models\AuthorityGrade;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuthorityGradeUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public AuthorityGrade $authorityGrade) {}
}
