/*
 * Hitungan nilai di layar (PRD-002 §19 AC-5b, aspek §22 AC-8a, skala predikat AC-8b), sama persis
 * dengan server (ManageGrades/GradingRules): nilai aspek = Σ nilai × bobot ÷ 100 dibulatkan bulat;
 * nilai akhir = rata-rata aspek, 1 desimal; predikat = tingkat tertinggi yang nilai minimalnya
 * terpenuhi. Server tetap menghitung ulang dan memeriksa semua aturan.
 */

export type GradingComponent = { code: string; name: string; weight: number };

export type GradingAspect = {
    code: string;
    name: string;
    components: GradingComponent[];
};

/** Satu tingkat skala predikat, urut dari tertinggi; tingkat terakhir min 0. */
export type PredicateLevel = {
    letter: string;
    label: string | null;
    min: number;
};

export type GradingRules = {
    kkm: number;
    predicates: PredicateLevel[];
    aspects: GradingAspect[];
};

/** Jenis nilai mapel (AC-8b). */
export type GradeType = 'NUMBER' | 'LETTER';

/** Kode aspek tunggal aturan lama/bawaan (namanya tidak ditampilkan). */
export const DEFAULT_ASPECT_CODE = 'NILAI';

const SCORE_PATTERN = /^\d{1,3}([.,]\d{1,2})?$/;

/** "82,5" atau "82.5" → 82.5; kosong/tidak sah → null. */
export function parseScore(value: string): number | null {
    const text = value.trim();

    if (text === '' || !SCORE_PATTERN.test(text)) {
        return null;
    }

    const score = Number(text.replace(',', '.'));

    return score <= 100 ? score : null;
}

export function allComponents(rules: GradingRules): GradingComponent[] {
    return rules.aspects.flatMap((aspect) => aspect.components);
}

/** Nama aspek ditampilkan bila lebih dari satu aspek atau aspeknya bukan aspek bawaan. */
export function showsAspects(rules: GradingRules): boolean {
    return (
        rules.aspects.length > 1 ||
        rules.aspects[0]?.code !== DEFAULT_ASPECT_CODE
    );
}

/** Nilai satu aspek dari isian (teks); null bila ada komponen kosong/tidak sah. */
export function aspectScore(
    aspect: GradingAspect,
    scores: Record<string, string>,
): number | null {
    let sum = 0;

    for (const component of aspect.components) {
        const score = parseScore(scores[component.code] ?? '');

        if (score === null) {
            return null;
        }

        sum += Math.round(score * 100) * component.weight;
    }

    return Math.floor((sum + 5000) / 10000);
}

/** Rata-rata nilai aspek, 1 desimal (setengah ke atas); null bila ada aspek kosong. */
export function finalScore(
    rules: GradingRules,
    scores: Record<string, string>,
): number | null {
    const values = rules.aspects.map((aspect) => aspectScore(aspect, scores));

    if (values.length === 0 || values.some((value) => value === null)) {
        return null;
    }

    const sum = (values as number[]).reduce((total, value) => total + value, 0);
    const count = values.length;

    return Math.floor((sum * 20 + count) / (2 * count)) / 10;
}

export function predicate(rules: GradingRules, score: number): string {
    const level =
        rules.predicates.find((item) => score >= item.min) ??
        rules.predicates[rules.predicates.length - 1];

    return level?.letter ?? '';
}

/** Sebutan huruf skala (mis. "Sangat Baik"), atau null. */
export function labelOf(rules: GradingRules, letter: string): string | null {
    return (
        rules.predicates.find((item) => item.letter === letter)?.label ?? null
    );
}

/** "A Sangat Baik" atau "A" bila tanpa sebutan. */
export function describePredicate(rules: GradingRules, letter: string): string {
    const label = labelOf(rules, letter);

    return label ? `${letter} ${label}` : letter;
}

/** Ringkasan skala: "A ≥ 90, B ≥ 80, C ≥ 75, D di bawahnya". */
export function scaleText(rules: GradingRules): string {
    return rules.predicates
        .map((level, index) => {
            const name = describePredicate(rules, level.letter);

            return index === rules.predicates.length - 1
                ? `${name} di bawahnya`
                : `${name} ≥ ${level.min}`;
        })
        .join(', ');
}

/** 86.5 → "86,5"; `decimals` 1 → selalu satu angka di belakang koma (75 → "75,0"). */
export function formatScore(
    value: number | null | undefined,
    decimals: 0 | 1 = 0,
): string {
    if (value === null || value === undefined) {
        return '';
    }

    return value.toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals === 1 ? 1 : 2,
    });
}
