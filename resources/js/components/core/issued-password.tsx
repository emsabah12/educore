import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/*
 * Kata sandi sementara (PRD-000 §4.7 F5a; dipakai juga akun orang tua/wali, PRD-002 §21.1).
 * Datang lewat flash Inertia (sekali kirim, tidak tersimpan di riwayat browser) dan hanya disimpan di
 * memori komponen sampai dialog ditutup.
 */

export type IssuedPassword = { login: string; password: string };

/** Bahan kartu akun yang bisa dicetak (opsional). */
export type AccountCard = {
    /** Judul kartu, mis. "Kartu akun orang tua/wali". */
    title: string;
    /** Nama pemilik akun. */
    owner: string;
    /** Keterangan tambahan, mis. "Orang tua/wali dari Ahmad Fauzi". */
    note?: string;
};

export function useIssuedPassword(): {
    value: IssuedPassword | null;
    clear: () => void;
} {
    const [value, setValue] = useState<IssuedPassword | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.issuedPassword as IssuedPassword | undefined;

            if (data?.login && data.password) {
                setValue(data);
            }
        });
    }, []);

    return { value, clear: () => setValue(null) };
}

/**
 * Cetak kartu akun di jendela baru. Isi dibangun lewat DOM (textContent), bukan string HTML,
 * agar nama/username tidak bisa menyisipkan markup; kata sandi tidak pernah masuk URL.
 */
function printAccountCard(issued: IssuedPassword, card: AccountCard): boolean {
    const popup = window.open('', '_blank', 'width=480,height=640');

    if (!popup) {
        return false;
    }

    const doc = popup.document;
    doc.title = card.title;

    const style = doc.createElement('style');
    style.textContent = `
        body { font-family: system-ui, sans-serif; margin: 24px; color: #111; }
        .card { border: 1px solid #999; border-radius: 12px; padding: 20px; max-width: 360px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p { margin: 0 0 12px; font-size: 13px; color: #444; }
        dt { font-size: 12px; color: #555; margin-top: 10px; }
        dd { margin: 2px 0 0; font-size: 16px; font-family: ui-monospace, monospace; word-break: break-all; }
        .pw { font-size: 20px; letter-spacing: 1px; }
        .foot { margin-top: 16px; font-size: 12px; color: #555; }
    `;
    doc.head.appendChild(style);

    const box = doc.createElement('div');
    box.className = 'card';

    const add = (tag: string, text: string, className?: string) => {
        const element = doc.createElement(tag);
        element.textContent = text;

        if (className) {
            element.className = className;
        }

        return element;
    };

    box.appendChild(add('h1', card.title));
    box.appendChild(
        add('p', card.note ? `${card.owner} — ${card.note}` : card.owner),
    );

    const list = doc.createElement('dl');
    list.appendChild(add('dt', 'Alamat aplikasi'));
    list.appendChild(add('dd', window.location.origin));
    list.appendChild(add('dt', 'Username'));
    list.appendChild(add('dd', issued.login));
    list.appendChild(add('dt', 'Kata sandi sementara'));
    list.appendChild(add('dd', issued.password, 'pw'));
    box.appendChild(list);

    box.appendChild(
        add(
            'div',
            'Saat pertama masuk Anda wajib mengganti kata sandi. Simpan kartu ini dengan aman dan jangan bagikan kepada orang lain.',
            'foot',
        ),
    );

    doc.body.appendChild(box);
    popup.focus();
    popup.print();

    return true;
}

export function IssuedPasswordDialog({
    issued,
    onClose,
    card,
}: {
    issued: IssuedPassword | null;
    onClose: () => void;
    /** Bila diisi, muncul tombol "Cetak kartu akun". */
    card?: AccountCard;
}) {
    const [copy, setCopy] = useState<'idle' | 'done' | 'failed'>('idle');
    const [printBlocked, setPrintBlocked] = useState(false);

    const reset = () => {
        setCopy('idle');
        setPrintBlocked(false);
    };

    const copyToClipboard = async () => {
        if (!issued) {
            return;
        }

        try {
            // Clipboard hanya tersedia di HTTPS/localhost; selain itu tampilkan petunjuk salin manual.
            await navigator.clipboard.writeText(
                `Username: ${issued.login}\nKata sandi sementara: ${issued.password}`,
            );
            setCopy('done');
        } catch {
            setCopy('failed');
        }
    };

    return (
        <Dialog
            open={issued !== null}
            onOpenChange={(open) => {
                if (!open) {
                    reset();
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Kata sandi sementara</DialogTitle>
                    <DialogDescription>
                        Catat, salin, atau cetak sekarang, lalu serahkan
                        langsung kepada pemilik akun. Kata sandi ini hanya
                        tampil satu kali dan wajib diganti saat pertama masuk.
                    </DialogDescription>
                </DialogHeader>

                {issued && (
                    <dl className="grid gap-3 rounded-lg border bg-muted/30 p-4">
                        <div className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                Username
                            </dt>
                            <dd className="font-mono break-all select-all">
                                {issued.login}
                            </dd>
                        </div>
                        <div className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                Kata sandi sementara
                            </dt>
                            <dd className="font-mono text-lg tracking-wider break-all select-all">
                                {issued.password}
                            </dd>
                        </div>
                    </dl>
                )}

                {copy === 'done' && (
                    <p className="text-sm text-muted-foreground">
                        Tersalin ke clipboard.
                    </p>
                )}
                {copy === 'failed' && (
                    <p className="text-sm text-muted-foreground">
                        Tidak bisa menyalin otomatis di sini. Pilih teksnya lalu
                        salin manual.
                    </p>
                )}
                {printBlocked && (
                    <p className="text-sm text-muted-foreground">
                        Jendela cetak diblokir browser. Izinkan pop-up untuk
                        situs ini, lalu coba lagi.
                    </p>
                )}

                <DialogFooter className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={copyToClipboard}
                    >
                        Salin
                    </Button>
                    {card && issued && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                setPrintBlocked(!printAccountCard(issued, card))
                            }
                        >
                            Cetak kartu akun
                        </Button>
                    )}
                    <Button
                        type="button"
                        onClick={() => {
                            reset();
                            onClose();
                        }}
                    >
                        Sudah saya catat
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
