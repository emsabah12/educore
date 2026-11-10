<?php

namespace Modules\Academic\Application\Subject;

use Illuminate\Database\Query\Builder;

/**
 * Urutan mapel di mana pun (PRD-002 §22.4 AC-8b): kelompok (urutan, nama) → mapel (urutan, nama);
 * mapel tanpa kelompok di akhir, mapel tanpa urutan sesudah yang berurutan.
 */
final class SubjectOrder
{
    /**
     * Tambahkan join kelompok (alias `sg`) dan urutan ke query yang memuat `ac_subjects` dengan
     * alias `s`.
     */
    public static function apply(Builder $query): Builder
    {
        return $query
            ->leftJoin('ac_subject_groups as sg', 'sg.id', '=', 's.group_id')
            ->orderByRaw('(sg.id IS NULL)')
            ->orderBy('sg.sort_order')
            ->orderByRaw('lower(sg.name)')
            ->orderByRaw('s.sort_order NULLS LAST')
            ->orderByRaw('lower(s.name)');
    }
}
