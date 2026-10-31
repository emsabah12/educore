import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import type { NavGroup, NavItem } from '@/types';

const STORAGE_KEY = 'educore.nav.closed';

function readClosed(): string[] {
    try {
        const value = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '[]',
        );

        return Array.isArray(value)
            ? value.filter((key) => typeof key === 'string')
            : [];
    } catch {
        return [];
    }
}

function writeClosed(keys: string[]): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(keys));
    } catch {
        // Penyimpanan browser tidak tersedia: kelompok tetap bisa dibuka/tutup selama halaman terbuka.
    }
}

function pathOf(href: NavItem['href']): string {
    const url = toUrl(href);

    try {
        return new URL(url, 'http://localhost').pathname;
    } catch {
        return url;
    }
}

/**
 * Menu samping berkelompok (pedoman tampilan UI-1): tiap kelompok bisa dibuka/tutup; kelompok yang memuat halaman
 * aktif selalu terbuka. Pilihan tutup diingat di browser ini. Hanya satu menu yang ditandai aktif: yang alamatnya
 * paling cocok dengan halaman sekarang (mis. /pegawai/123 → "Pegawai").
 */
export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { currentUrl } = useCurrentUrl();
    const { state, isMobile } = useSidebar();
    const [closed, setClosed] = useState<string[]>(() =>
        typeof window === 'undefined' ? [] : readClosed(),
    );

    let activeHref: string | null = null;

    for (const group of groups) {
        for (const item of group.items) {
            const path = pathOf(item.href);
            const matches =
                currentUrl === path || currentUrl.startsWith(`${path}/`);

            if (
                matches &&
                (activeHref === null || path.length > activeHref.length)
            ) {
                activeHref = path;
            }
        }
    }

    const toggle = (key: string, open: boolean) => {
        const next = open
            ? closed.filter((closedKey) => closedKey !== key)
            : [...closed.filter((closedKey) => closedKey !== key), key];
        setClosed(next);
        writeClosed(next);
    };

    // Sidebar diciutkan jadi ikon: tampilkan semua ikon tanpa judul kelompok.
    const iconOnly = state === 'collapsed' && !isMobile;

    return (
        <>
            {groups.map((group) => {
                const hasActive = group.items.some(
                    (item) => pathOf(item.href) === activeHref,
                );
                const open =
                    iconOnly ||
                    !group.title ||
                    hasActive ||
                    !closed.includes(group.key);
                const menu = (
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={`${group.key}-${item.title}`}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={pathOf(item.href) === activeHref}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link href={item.href}>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                );

                if (!group.title) {
                    return (
                        <SidebarGroup key={group.key} className="px-2 py-0">
                            {menu}
                        </SidebarGroup>
                    );
                }

                return (
                    <Collapsible
                        key={group.key}
                        open={open}
                        onOpenChange={(value) => toggle(group.key, value)}
                        className="group/collapsible"
                    >
                        <SidebarGroup className="px-2 py-0">
                            <SidebarGroupLabel asChild>
                                <CollapsibleTrigger
                                    className="flex w-full items-center justify-between"
                                    disabled={hasActive}
                                >
                                    {group.title}
                                    <ChevronRight className="size-4 transition-transform group-data-[state=open]/collapsible:rotate-90" />
                                </CollapsibleTrigger>
                            </SidebarGroupLabel>
                            <CollapsibleContent>
                                <SidebarGroupContent>
                                    {menu}
                                </SidebarGroupContent>
                            </CollapsibleContent>
                        </SidebarGroup>
                    </Collapsible>
                );
            })}
        </>
    );
}
