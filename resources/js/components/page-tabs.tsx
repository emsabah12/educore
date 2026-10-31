import { useState } from 'react';
import { cn } from '@/lib/utils';

export type TabOption = { value: string; label: string; badge?: number };

function readParam(param: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return new URLSearchParams(window.location.search).get(param);
}

/**
 * Tab halaman yang tercatat di alamat (`?tab=`), jadi tetap di tab yang sama setelah muat ulang / kembali
 * (pedoman tampilan UI-1: satu tampilan = satu fungsi utama). Mengganti tab tidak memuat ulang data.
 */
export function usePageTab<T extends string>(
    values: readonly T[],
    fallback: T,
    param = 'tab',
): [T, (value: T) => void] {
    const [tab, setTab] = useState<T>(() => {
        const requested = readParam(param);

        return requested !== null &&
            (values as readonly string[]).includes(requested)
            ? (requested as T)
            : fallback;
    });

    const change = (value: T) => {
        setTab(value);

        try {
            const url = new URL(window.location.href);

            if (value === fallback) {
                url.searchParams.delete(param);
            } else {
                url.searchParams.set(param, value);
            }

            // Simpan state riwayat Inertia apa adanya; hanya alamat yang berubah.
            window.history.replaceState(
                window.history.state,
                '',
                url.toString(),
            );
        } catch {
            // Alamat tidak bisa diubah (mis. pratinjau): tab tetap berganti.
        }
    };

    return [tab, change];
}

/**
 * Deret tab (role="tablist"), bisa digeser ke samping di layar sempit.
 */
export function PageTabs<T extends string>({
    tabs,
    value,
    onChange,
    label,
}: {
    tabs: readonly (TabOption & { value: T })[];
    value: T;
    onChange: (value: T) => void;
    label: string;
}) {
    return (
        <div
            role="tablist"
            aria-label={label}
            className="-mx-4 flex gap-1 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0"
        >
            {tabs.map((tab) => {
                const active = tab.value === value;

                return (
                    <button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(tab.value)}
                        className={cn(
                            '-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                            active
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                        {tab.badge !== undefined && tab.badge > 0 && (
                            <span className="rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums">
                                {tab.badge}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
