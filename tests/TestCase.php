<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function refreshDatabase()
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        parent::refreshDatabase();
    }

    protected function refreshInMemoryDatabase()
    {
        $this->artisan('migrate:fresh', array_merge(
            ['--drop-views' => true, '--drop-types' => true, '--force' => true, '--seed' => false],
            $this->migrateUsing(),
        ));

        $this->app[Kernel::class]->setArtisan(null);
    }
}
