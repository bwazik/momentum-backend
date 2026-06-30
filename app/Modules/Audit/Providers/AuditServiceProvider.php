<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Listeners\RecordAuditEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ($this->auditableEventClasses() as $event) {
            Event::listen($event, RecordAuditEvent::class);
        }
    }

    private function auditableEventClasses(): array
    {
        $classes = [];
        $modulesPath = app_path('Modules');

        if (! is_dir($modulesPath)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulesPath)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(
                str_replace([$modulesPath, '.php', '/'], ['', '', '\\'], $file->getPathname()),
                '\\'
            );

            if (str_starts_with($relative, 'Platform\\') || str_starts_with($relative, 'Audit\\')) {
                continue;
            }

            $class = 'App\\Modules\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                if ($reflection->implementsInterface(ProvidesAuditData::class)) {
                    $classes[] = $class;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_unique($classes);
    }
}
