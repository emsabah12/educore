<?php

/*
 * Penjaga batas modul (ADR-001 §2.2).
 *
 * Arah dependency yang sah:
 *   Core     → (tidak ada)
 *   HR       → Core
 *   Academic → Core, HR (hanya kontrak publik: Contracts, DTO)
 *   Finance  → Core, Academic (hanya kontrak publik: Contracts) — PRD-003 §7
 *
 * Test ini gagal otomatis bila ada kode yang melanggar,
 * sehingga batas modul tidak bergantung pada ingatan developer.
 */

arch('Core tidak boleh bergantung pada modul bisnis')
    ->expect('Modules\Core')
    ->not->toUse(['Modules\HR', 'Modules\Academic', 'Modules\Finance']);

arch('HR tidak boleh bergantung pada Academic atau Finance')
    ->expect('Modules\HR')
    ->not->toUse(['Modules\Academic', 'Modules\Finance']);

arch('Academic tidak boleh bergantung pada Finance')
    ->expect('Modules\Academic')
    ->not->toUse('Modules\Finance');

arch('Finance hanya boleh memakai kontrak publik Academic, tanpa HR')
    ->expect('Modules\Finance')
    ->not->toUse([
        'Modules\HR',
        'Modules\Academic\Domain',
        'Modules\Academic\Application',
        'Modules\Academic\Http',
        'Modules\Academic\Database',
    ]);

arch('Academic hanya boleh memakai kontrak publik HR')
    ->expect('Modules\Academic')
    ->not->toUse([
        'Modules\HR\Domain',
        'Modules\HR\Application',
        'Modules\HR\Http',
        'Modules\HR\Database',
    ]);

arch('Kode modul tidak boleh bergantung pada folder app/')
    ->expect('Modules')
    ->not->toUse('App');

arch('Tidak ada fungsi debug yang tertinggal')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
