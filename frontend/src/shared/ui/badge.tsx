import {
    Slot,
} from '@radix-ui/react-slot';
import {
    type VariantProps,
    cva,
} from 'class-variance-authority';
import type {
    ComponentProps,
} from 'react';

import {
    cn,
} from '@/shared/lib/utils';

export const badgeVariants = cva(
    'inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 [&_svg]:pointer-events-none [&_svg]:size-3',
    {
        variants: {
            variant: {
                default:
                    'border-transparent bg-primary text-primary-foreground',
                secondary:
                    'border-transparent bg-secondary text-secondary-foreground',
                destructive:
                    'border-transparent bg-destructive text-destructive-foreground',
                outline:
                    'text-foreground',
                success:
                    'border-transparent bg-emerald-100 text-emerald-800',
                warning:
                    'border-transparent bg-amber-100 text-amber-800',
            },
        },
        defaultVariants: {
            variant:
                'default',
        },
    },
);

export interface BadgeProps
    extends
        ComponentProps<'span'>,
        VariantProps<typeof badgeVariants>
{
    asChild?: boolean;
}

export function Badge(
    {
        className,
        variant,
        asChild = false,
        ...props
    }: BadgeProps,
) {
    const Component =
        asChild
            ? Slot
            : 'span';

    return (
        <Component
            data-slot="badge"
            className={
                cn(
                    badgeVariants(
                        {
                            variant,
                        },
                    ),
                    className,
                )
            }
            {...props}
        />
    );
}