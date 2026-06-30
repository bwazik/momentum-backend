<?php

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Platform\Providers\CentralAuditServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;
use Dedoc\Scramble\ScrambleServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ScrambleServiceProvider::class,
    AuditServiceProvider::class,
    CentralAuditServiceProvider::class,
];
