<?php

/*
 * `/up` memeriksa database tanpa membocorkan detailnya (PRD-000 §9, F4).
 */

test('/up sehat saat database bisa dihubungi', function () {
    $this->get('/up')->assertOk();
});

test('/up menjawab 500 dengan pesan umum saat database tidak bisa dihubungi', function () {
    config([
        'app.debug' => false,
        'database.connections.rusak' => [
            ...config('database.connections.pgsql'),
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'database_rahasia',
            'connect_timeout' => 2,
        ],
        'database.default' => 'rusak',
    ]);

    try {
        $response = $this->get('/up');
    } finally {
        config(['database.default' => 'pgsql']);
    }

    $response->assertStatus(500)
        ->assertDontSee('database_rahasia')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('127.0.0.1');
});
