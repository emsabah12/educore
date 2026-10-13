import { Link, usePage } from '@inertiajs/react';
import {
    LayoutGrid,
    Network,
    ScrollText,
    ShieldCheck,
    Tags,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { ContextSwitcher } from '@/components/context-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Beranda',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Menu hanya petunjuk; setiap halaman tetap dicek ulang di server (PRD-000 §7.1).
const organizationsNavItem: NavItem = {
    title: 'Lembaga',
    href: '/lembaga',
    icon: Network,
};

const auditNavItem: NavItem = {
    title: 'Log audit',
    href: '/audit',
    icon: ScrollText,
};

const employeesNavItem: NavItem = {
    title: 'Pegawai',
    href: '/pegawai',
    icon: Users,
};

const hrCatalogNavItem: NavItem = {
    title: 'Katalog SDM',
    href: '/pegawai/katalog',
    icon: Tags,
};

const platformNavItem: NavItem = {
    title: 'Panel platform',
    href: '/platform',
    icon: ShieldCheck,
};

export function AppSidebar() {
    const { auth, permissions, context } = usePage().props;
    // Halaman tingkat Yayasan (Katalog SDM, Log audit) hanya bisa dibuka dari lembaga kerja
    // "Seluruh Yayasan" (PRD-000 §6, §4.7). Di lembaga kerja lain menunya disembunyikan,
    // walaupun role tingkat Yayasan-nya terpasang.
    const atTenantLevel = context?.workspace.type === 'tenant';
    const navItems = [
        ...mainNavItems,
        ...(permissions.includes('core.organizations.view')
            ? [organizationsNavItem]
            : []),
        ...(permissions.includes('hr.employees.view')
            ? [employeesNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('hr.catalog.manage')
            ? [hrCatalogNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('core.audit.view')
            ? [auditNavItem]
            : []),
        ...(auth.user?.is_superadmin ? [platformNavItem] : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <ContextSwitcher />
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
