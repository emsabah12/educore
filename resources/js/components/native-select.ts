/**
 * Kelas bersama untuk `<select>` bawaan peramban (UX-1): sama tinggi & gaya dengan `Input` — 48px, sudut 8px,
 * garis abu yang cukup kontras, fokus = garis tinta 2px tanpa cahaya (DESIGN-airbnb `text-input`).
 */
export const selectClass =
    'h-12 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none transition-colors focus-visible:border-foreground focus-visible:ring-1 focus-visible:ring-foreground disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60';
