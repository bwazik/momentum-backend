<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

beforeEach(function () {
    $dbDir = dirname(__DIR__).'/database';
    $files = glob($dbDir.'/momentum_tenant_*');
    if ($files) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function cleanupTenantDatabase(?string $databaseName): void
{
    if ($databaseName) {
        $dbPath = dirname(__DIR__).'/database/'.$databaseName;
        if (file_exists($dbPath)) {
            @unlink($dbPath);
        }
    }
}

function cleanupTenantStorage(int|string $tenantId): void
{
    $suffixBase = config('tenancy.filesystem.suffix_base', 'tenant');
    $storageDir = dirname(__DIR__).'/storage/'.$suffixBase.$tenantId;
    if (is_dir($storageDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($storageDir);
    }
}

function cleanupAllTenantDatabases(): void
{
    $dbDir = dirname(__DIR__).'/database';
    $files = glob($dbDir.'/momentum_tenant_*');
    if ($files) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

cleanupAllTenantDatabases();
