import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import Heading from '@/components/heading';

type Child = {
    id: string;
    name: string;
    relation: string;
    organizations: string[];
    classes: string[];
};

/**
 * Anak saya (PRD-002 §21.2, AC-7a): daftar anak orang tua/wali yang login. Hanya baca.
 */
export default function MyChildren({ children }: { children: Child[] }) {
    return (
        <>
            <Head title="Anak saya" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Anak saya"
                    description="Data sekolah anak Anda: kelas, wali kelas, jadwal pelajaran, dan kalender akademik. Bila ada yang salah, hubungi operator lembaga."
                />

                {children.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Akun ini belum tercatat sebagai orang tua/wali siswa
                        mana pun.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {children.map((child) => (
                            <li key={child.id}>
                                <Link
                                    href={`/anak-saya/${child.id}`}
                                    className="flex items-center justify-between gap-3 p-4 hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-medium break-words">
                                            {child.name}
                                        </p>
                                        <p className="text-sm break-words text-muted-foreground">
                                            {child.classes.length > 0
                                                ? child.classes.join(', ')
                                                : child.organizations.length > 0
                                                  ? `${child.organizations.join(', ')} · belum masuk kelas`
                                                  : 'Tidak terdaftar di lembaga mana pun saat ini'}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Anda: {child.relation}
                                        </p>
                                    </div>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
