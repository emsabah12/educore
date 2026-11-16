<?php

/*
 * Penjaga batas modul (ADR-001 §2.2).
 *
 * Arah dependency yang sah:
 *   Core     → (tidak ada)
 *   HR       → Core
 *   Academic → Core, HR (hanya kontrak publik: Contracts, DTO)
 *   Finance  → Core, Academic & Dormitory (hanya kontrak publik: Contracts) — PRD-003 §7, §20
 *   Dormitory → Core, Academic & HR (hanya kontrak publik: Contracts) — PRD-008 §0, §15
 *
 * Test ini gagal otomatis bila ada kode yang melanggar,
 * sehingga batas modul tidak bergantung pada ingatan developer.
 */

arch('Core tidak boleh bergantung pada modul bisnis')
    ->expect('Modules\Core')
    ->not->toUse(['Modules\HR', 'Modules\Academic', 'Modules\Finance', 'Modules\Dormitory']);

arch('HR & Academic tidak boleh bergantung pada Dormitory')
    ->expect(['Modules\HR', 'Modules\Academic'])
    ->not->toUse('Modules\Dormitory');

arch('Kontrak publik Dormitory tidak boleh membuka isi modul Dormitory')
    ->expect('Modules\Dormitory\Contracts')
    ->not->toUse([
        'Modules\Dormitory\Domain',
        'Modules\Dormitory\Application',
        'Modules\Dormitory\Http',
        'Modules\Dormitory\Database',
    ]);

arch('Dormitory hanya boleh memakai kontrak publik Academic & HR, tanpa Finance')
    ->expect('Modules\Dormitory')
    ->not->toUse([
        'Modules\Finance',
        'Modules\Academic\Domain',
        'Modules\Academic\Application',
        'Modules\Academic\Http',
        'Modules\Academic\Database',
        'Modules\HR\Domain',
        'Modules\HR\Application',
        'Modules\HR\Http',
        'Modules\HR\Database',
    ]);

arch('HR tidak boleh bergantung pada Academic atau Finance')
    ->expect('Modules\HR')
    ->not->toUse(['Modules\Academic', 'Modules\Finance']);

arch('Academic tidak boleh bergantung pada Finance')
    ->expect('Modules\Academic')
    ->not->toUse('Modules\Finance');

arch('Finance hanya boleh memakai kontrak publik Academic & Dormitory, tanpa HR')
    ->expect('Modules\Finance')
    ->not->toUse([
        'Modules\HR',
        'Modules\Academic\Domain',
        'Modules\Academic\Application',
        'Modules\Academic\Http',
        'Modules\Academic\Database',
        'Modules\Dormitory\Domain',
        'Modules\Dormitory\Application',
        'Modules\Dormitory\Http',
        'Modules\Dormitory\Database',
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
