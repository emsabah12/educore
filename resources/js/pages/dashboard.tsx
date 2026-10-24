import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { dashboard } from '@/routes';

type DashboardCard = {
    key: string;
    title: string;
    value: number;
    description: string;
    href: string;
    tone: 'default' | 'warning' | 'danger';
};

type Props = {
    cards: DashboardCard[];
};

const TONE_CLASS: Record<DashboardCard['tone'], string> = {
    default: '',
    warning: 'border-amber-400/60 bg-amber-50 dark:bg-amber-950/30',
    danger: 'border-red-400/60 bg-red-50 dark:bg-red-950/30',
};

/**
 * Beranda: sambutan + kartu ringkas yang didaftarkan modul (mis. HR "Dokumen akan habis",
 * PRD-001 §15.2). Kartu hanya muncul bagi yang berhak; server yang menentukan.
 */
export default function Dashboard({ cards }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Beranda" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">
                        Selamat datang{auth.user ? `, ${auth.user.name}` : ''}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pilih menu di samping untuk mulai bekerja.
                    </p>
                </div>

                {cards.length > 0 && (
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(16rem,1fr))] gap-4">
                        {cards.map((card) => (
                            <Link
                                key={card.key}
                                href={card.href}
                                className={
                                    'group flex flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/50 ' +
                                    TONE_CLASS[card.tone]
                                }
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-medium">
                                        {card.title}
                                    </span>
                                    <ChevronRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                </div>
                                <span className="text-3xl font-semibold tabular-nums">
                                    {card.value}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {card.description}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Beranda',
            href: dashboard(),
        },
    ],
};
