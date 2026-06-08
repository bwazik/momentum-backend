<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;
use Dedoc\Scramble\ScrambleServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ScrambleServiceProvider::class,
];
