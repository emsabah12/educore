import {
    type ClassValue,
    clsx,
} from 'clsx';
import {
    twMerge,
} from 'tailwind-merge';

/*
 * Canonical classname composition helper.
 *
 * `clsx` resolves conditional/array/object class inputs into
 * a single string; `twMerge` then resolves conflicting
 * Tailwind utility classes (e.g. a caller-supplied `p-4`
 * overriding a component-default `p-2`) by keeping only the
 * last conflicting utility rather than emitting both.
 *
 * Every shared UI component accepts an optional `className`
 * prop and composes it through this helper so callers can
 * always override presentation without fighting specificity.
 */
export function cn(
    ...inputs: ClassValue[]
): string {
    return twMerge(
        clsx(
            ...inputs,
        ),
    );
}