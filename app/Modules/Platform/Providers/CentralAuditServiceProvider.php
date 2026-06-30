<?php

namespace App\Modules\Platform\Providers;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Platform\Listeners\RecordCentralAuditEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

class CentralAuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ($this->platformAuditableEvents() as $event) {
            Event::listen($event, RecordCentralAuditEvent::class);
        }
    }

    private function platformAuditableEvents(): array
    {
        $classes = [];
        $path = app_path('Modules/Platform/Events');

        if (! is_dir($path)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\Modules\\Platform\\Events\\'.$file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            try {
                $ref = new ReflectionClass($class);
                if (! $ref->isAbstract() && $ref->implementsInterface(ProvidesAuditData::class)) {
                    $classes[] = $class;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_unique($classes);
    }
}
