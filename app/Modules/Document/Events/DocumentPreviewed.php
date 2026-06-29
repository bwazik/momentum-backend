<?php

namespace App\Modules\Document\Events;

use App\Models\User;
use App\Modules\Document\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentPreviewed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Document $document,
        public User $user,
    ) {}
}
