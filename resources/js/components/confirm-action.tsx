import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Konfirmasi aksi hapus / batal / cabut / tolak lewat pop-up (pedoman tampilan #3, UX-3): tombol pemicu membuka
 * jendela berisi penjelasan, isian alasan bila diminta, dan tombol konfirmasi. Server tetap memeriksa ulang.
 */
export function ConfirmAction({
    trigger,
    title,
    description,
    action,
    method = 'post',
    confirmLabel,
    destructive = true,
    reason,
    errorBag,
    hidden,
    onConfirm,
    onSuccess,
}: {
    /** Tombol pemicu (mis. `<Button variant="outline">Hapus</Button>`). */
    trigger: ReactNode;
    title: string;
    description?: ReactNode;
    action: string;
    method?: 'post' | 'patch' | 'delete';
    confirmLabel: string;
    destructive?: boolean;
    /** Isian alasan (opsional): nama field, label, batas panjang, contoh isi. */
    reason?: {
        name?: string;
        label: string;
        max: number;
        placeholder?: string;
        required?: boolean;
    };
    errorBag?: string;
    /** Isian tersembunyi tambahan. */
    hidden?: Record<string, string>;
    /** Dipanggil saat tombol konfirmasi ditekan, sebelum dikirim. */
    onConfirm?: () => void;
    onSuccess?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const reasonName = reason?.name ?? 'reason';
    const id = `confirm-${errorBag ?? action}`.replace(/[^a-zA-Z0-9-]/g, '-');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>
                <Form
                    action={action}
                    method={method}
                    errorBag={errorBag}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setOpen(false);
                        onSuccess?.();
                    }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => {
                        const messages = errors as Record<
                            string,
                            string | undefined
                        >;
                        const firstError =
                            messages[reasonName] ??
                            messages.form ??
                            Object.values(messages).find(Boolean);

                        return (
                            <>
                                {hidden &&
                                    Object.entries(hidden).map(
                                        ([name, value]) => (
                                            <input
                                                key={name}
                                                type="hidden"
                                                name={name}
                                                value={value}
                                            />
                                        ),
                                    )}
                                {reason && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`${id}-reason`}>
                                            {reason.label}
                                        </Label>
                                        <Input
                                            id={`${id}-reason`}
                                            name={reasonName}
                                            maxLength={reason.max}
                                            placeholder={reason.placeholder}
                                            required={reason.required ?? true}
                                            autoFocus
                                        />
                                    </div>
                                )}
                                <InputError message={firstError} />
                                <DialogFooter className="gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setOpen(false)}
                                    >
                                        Batal
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant={
                                            destructive
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        disabled={processing}
                                        onClick={onConfirm}
                                    >
                                        {processing
                                            ? 'Memproses…'
                                            : confirmLabel}
                                    </Button>
                                </DialogFooter>
                            </>
                        );
                    }}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
