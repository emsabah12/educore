import type {
    ComponentProps,
} from 'react';

import {
    cn,
} from '@/shared/lib/utils';

export function Select(
    {
        className,
        ...props
    }: ComponentProps<'select'>,
) {
    return (
        <select
            data-slot="select"
            className={
                cn(
                    'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors outline-none',
                    'focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50',
                    'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                    className,
                )
            }
            {...props}
        />
    );
}