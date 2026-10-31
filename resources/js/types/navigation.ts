import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/** Kelompok menu samping. Tanpa judul = ditampilkan tanpa label (mis. Beranda). */
export type NavGroup = {
    key: string;
    title?: string;
    items: NavItem[];
};
