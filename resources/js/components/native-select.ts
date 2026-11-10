/**
 * Kelas bersama untuk `<select>` bawaan peramban (UX-2): sama tinggi & gaya dengan `Input` — 40px, sudut 6px,
 * garis abu yang cukup kontras, fokus = garis ungu tanpa cahaya (gaya Materio).
 */
export const selectClass =
    'h-10 w-full min-w-0 rounded-md border border-input bg-transparent px-3.5 text-sm outline-none transition-colors hover:border-foreground/60 focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60 dark:[&>option]:bg-card';
