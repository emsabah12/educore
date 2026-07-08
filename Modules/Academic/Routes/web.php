<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Rute ini berada di dalam Sandbox Group yang diatur oleh Service Provider
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Welcome to EduCore Academic Module Sandbox Layer!',
        'timestamp' => now()->toIso8601String()
    ]);
})->name('index');

Route::get('/courses', function () {
    return response()->json([
        'module' => 'academic',
        'resource' => 'courses',
        'data' => [
            ['id' => 1, 'subject' => 'Advanced Software Architecture', 'credits' => 4],
            ['id' => 2, 'subject' => 'Database Engineering & Scaling', 'credits' => 3]
        ]
    ]);
})->name('courses.list');