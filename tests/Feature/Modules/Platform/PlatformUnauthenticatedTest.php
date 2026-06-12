<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cannot access platform admin endpoints without authentication', function () {
    $response = $this->getJson('/v1/platform/admins');
    $response->assertStatus(401);
});

it('cannot access platform tenant endpoints without authentication', function () {
    $response = $this->getJson('/v1/platform/tenants');
    $response->assertStatus(401);
});

it('cannot access platform audit events without authentication', function () {
    $response = $this->getJson('/v1/platform/audit-events');
    $response->assertStatus(401);
});

it('cannot access impersonation sessions without authentication', function () {
    $response = $this->getJson('/v1/platform/impersonation-sessions');
    $response->assertStatus(401);
});
