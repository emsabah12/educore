<?php

/*
 * Penjaga batas modul (ADR-001 §2.2).
 *
 * Arah dependency yang sah:
 *   Core     → (tidak ada)
 *   HR       → Core
 *   Academic → Core, HR (hanya kontrak publik: Contracts, DTO)
 *
 * Test ini gagal otomatis bila ada kode yang melanggar,
 * sehingga batas modul tidak bergantung pada ingatan developer.
 */

arch('Core tidak boleh bergantung pada modul bisnis')
    ->expect('Modules\Core')
    ->not->toUse(['Modules\HR', 'Modules\Academic']);

arch('HR tidak boleh bergantung pada Academic')
    ->expect('Modules\HR')
    ->not->toUse('Modules\Academic');

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
