import { Head, Link, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    ClipboardCheck,
    CreditCard,
    FileClock,
    LayoutGrid,
    PiggyBank,
    ReceiptText,
    Scale,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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

/** Ikon per kartu (kunci dari server); kartu baru tanpa ikon khusus memakai ikon umum. */
const CARD_ICON: Record<string, LucideIcon> = {
    'hr.expiring_documents': FileClock,
    'finance.arrears': ReceiptText,
    'finance.billing': ReceiptText,
    'finance.expenses_pending': ClipboardCheck,
    'finance.expenses_approved': Wallet,
    'finance.savings': PiggyBank,
    'finance.online_overpaid': CreditCard,
    'finance.ledger_mismatch': Scale,
};

/** Kotak ikon berwarna (UX-2, gaya Materio): ungu = biasa, kuning = perlu perhatian, merah = mendesak. */
const TONE_ICON: Record<DashboardCard['tone'], string> = {
    default: 'bg-brand text-white shadow-primary',
    warning: 'bg-amber-500 text-white shadow-[0_2px_6px_rgba(255,180,0,0.4)]',
    danger: 'bg-red-500 text-white shadow-[0_2px_6px_rgba(255,76,81,0.4)]',
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
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-[1.375rem] leading-tight font-medium">
                        Selamat datang{auth.user ? `, ${auth.user.name}` : ''}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pilih menu di samping untuk mulai bekerja.
                    </p>
                </div>

                {cards.length > 0 && (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-6">
                        {cards.map((card) => {
                            const Icon = CARD_ICON[card.key] ?? LayoutGrid;

                            return (
                                <Link
                                    key={card.key}
                                    href={card.href}
                                    className="group flex flex-col gap-3 rounded-xl bg-card p-5 shadow-card transition-shadow hover:shadow-float focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span
                                            className={
                                                'flex size-11 shrink-0 items-center justify-center rounded-lg ' +
                                                TONE_ICON[card.tone]
                                            }
                                        >
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </span>
                                        <ChevronRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                    </div>
                                    <div className="space-y-0.5">
                                        <span className="block text-sm text-muted-foreground">
                                            {card.title}
                                        </span>
                                        <span className="block text-[1.75rem] leading-tight font-medium tabular-nums">
                                            {card.value}
                                        </span>
                                    </div>
                                    <span className="text-sm text-muted-foreground">
                                        {card.description}
                                    </span>
                                </Link>
                            );
                        })}
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
