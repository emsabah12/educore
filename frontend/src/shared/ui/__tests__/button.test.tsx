import {
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    Button,
} from '@/shared/ui/button';

describe(
    'Button',
    () => {
        it(
            'renders its children as a native button element',
            () => {
                render(
                    <Button>
                        Simpan
                    </Button>,
                );

                const button =
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    );

                expect(
                    button.tagName,
                ).toBe(
                    'BUTTON',
                );
            },
        );

        it(
            'applies the destructive variant class when requested',
            () => {
                render(
                    <Button variant="destructive">
                        Hapus
                    </Button>,
                );

                const button =
                    screen.getByRole(
                        'button',
                        {
                            name: 'Hapus',
                        },
                    );

                expect(
                    button.className,
                ).toContain(
                    'bg-destructive',
                );
            },
        );

        it(
            'renders as the child element instead of a button when asChild is set',
            () => {
                render(
                    <Button asChild>
                        <a href="/hr/workforce">
                            Buka Daftar Pegawai
                        </a>
                    </Button>,
                );

                const link =
                    screen.getByRole(
                        'link',
                        {
                            name: 'Buka Daftar Pegawai',
                        },
                    );

                expect(
                    link.tagName,
                ).toBe(
                    'A',
                );
                expect(
                    link.className,
                ).toContain(
                    'inline-flex',
                );
            },
        );

        it(
            'disables the button and applies disabled styling',
            () => {
                render(
                    <Button disabled>
                        Kirim
                    </Button>,
                );

                const button =
                    screen.getByRole(
                        'button',
                        {
                            name: 'Kirim',
                        },
                    );

                expect(
                    button,
                ).toBeDisabled();
            },
        );
    },
);