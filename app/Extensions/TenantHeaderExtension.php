<?php

namespace App\Extensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

class TenantHeaderExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $tenantHeader = Parameter::make('X-Tenant', 'header')
            ->required(true)
            ->setSchema(Schema::fromType(new StringType))
            ->description('Tenant slug or public ID for multi-tenant resolution.');

        $operation->addParameters([$tenantHeader]);
    }
}
