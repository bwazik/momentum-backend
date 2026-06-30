<?php

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Data\AuditEventData;

interface ProvidesAuditData
{
    public function auditData(): AuditEventData;
}
