<?php

namespace Tests\Feature;

test('laravel liveness endpoint is healthy', function () {
    $this->get('/up')->assertOk();
});

test('api health readiness returns ok payload', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => ['app', 'database'],
        ]);

    expect($response->json('checks.app'))->toBeTrue();
    expect($response->json('checks.database'))->toBeTrue();
});
