<?php

namespace App\Modules\Audit\Events;

use App\Modules\Audit\Models\AuditEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuditEventRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public AuditEvent $auditEvent) {}
}
