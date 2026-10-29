import { Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    BookOpen,
    Calculator,
    CalendarCog,
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    ClipboardList,
    ClipboardPen,
    Coins,
    CreditCard,
    Clock,
    FileClock,
    FileText,
    HandCoins,
    Heart,
    GraduationCap,
    LayoutGrid,
    Network,
    Percent,
    Plane,
    Receipt,
    School,
    ScrollText,
    ShieldCheck,
    Tags,
    UserCheck,
    Users,
    Wallet,
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

const leaveSettingsNavItem: NavItem = {
    title: 'Pengaturan cuti & presensi',
    href: '/pegawai/cuti/pengaturan',
    icon: CalendarCog,
};

const leavesNavItem: NavItem = {
    title: 'Kelola cuti',
    href: '/pegawai/cuti',
    icon: CalendarRange,
};

const myAttendanceNavItem: NavItem = {
    title: 'Presensi saya',
    href: '/presensi-saya',
    icon: Clock,
};

const attendanceSheetNavItem: NavItem = {
    title: 'Presensi pegawai',
    href: '/pegawai/presensi',
    icon: UserCheck,
};

const myLeaveNavItem: NavItem = {
    title: 'Cuti saya',
    href: '/cuti-saya',
    icon: Plane,
};

const myDocumentsNavItem: NavItem = {
    title: 'Dokumen saya',
    href: '/dokumen-saya',
    icon: FileText,
};

const myPayNavItem: NavItem = {
    title: 'Gaji saya',
    href: '/gaji-saya',
    icon: HandCoins,
};

const expiringDocumentsNavItem: NavItem = {
    title: 'Dokumen akan habis',
    href: '/pegawai/dokumen/akan-habis',
    icon: FileClock,
};

const payrollEmployeesNavItem: NavItem = {
    title: 'Gaji pegawai',
    href: '/pegawai/gaji',
    icon: Banknote,
};

const payrollRecapNavItem: NavItem = {
    title: 'Rekap gaji',
    href: '/pegawai/gaji/rekap',
    icon: Calculator,
};

const payrollSettingsNavItem: NavItem = {
    title: 'Pengaturan gaji',
    href: '/pegawai/gaji/pengaturan',
    icon: Wallet,
};

const leaveApprovalsNavItem: NavItem = {
    title: 'Persetujuan cuti',
    href: '/persetujuan-cuti',
    icon: ClipboardCheck,
};

const studentsNavItem: NavItem = {
    title: 'Siswa',
    href: '/siswa',
    icon: GraduationCap,
};

const classesNavItem: NavItem = {
    title: 'Kelas',
    href: '/kelas',
    icon: School,
};

const subjectsNavItem: NavItem = {
    title: 'Mata pelajaran',
    href: '/akademik/mapel',
    icon: BookOpen,
};

const myClassesNavItem: NavItem = {
    title: 'Kelas saya',
    href: '/kelas-saya',
    icon: ClipboardList,
};

const myChildrenNavItem: NavItem = {
    title: 'Anak saya',
    href: '/anak-saya',
    icon: Heart,
};

const teachingNavItem: NavItem = {
    title: 'Presensi mengajar',
    href: '/akademik/presensi-mengajar',
    icon: ClipboardPen,
};

const academicCalendarNavItem: NavItem = {
    title: 'Kalender akademik',
    href: '/akademik/kalender',
    icon: CalendarDays,
};

const cashierNavItem: NavItem = {
    title: 'Kasir',
    href: '/keuangan/kasir',
    icon: CreditCard,
};

const invoicesNavItem: NavItem = {
    title: 'Tagihan',
    href: '/keuangan/tagihan',
    icon: Receipt,
};

const discountsNavItem: NavItem = {
    title: 'Potongan siswa',
    href: '/keuangan/potongan',
    icon: Percent,
};

const financeSettingsNavItem: NavItem = {
    title: 'Pengaturan keuangan',
    href: '/keuangan/pengaturan',
    icon: Coins,
};

const platformNavItem: NavItem = {
    title: 'Panel platform',
    href: '/platform',
    icon: ShieldCheck,
};

export function AppSidebar() {
    const { auth, permissions, context, academic, hr } = usePage().props;
    // Halaman tingkat Yayasan (Katalog SDM, Kalender akademik, Log audit) hanya bisa dibuka dari lembaga kerja
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
        ...(permissions.includes('academic.students.view')
            ? [studentsNavItem, classesNavItem, subjectsNavItem]
            : []),
        ...(academic?.myClasses ? [myClassesNavItem] : []),
        ...(academic?.isGuardian ? [myChildrenNavItem] : []),
        ...(permissions.includes('academic.teaching.manage') ||
        academic?.teachingDuty
            ? [teachingNavItem]
            : []),
        ...(hr?.isEmployee
            ? [
                  myAttendanceNavItem,
                  myLeaveNavItem,
                  myDocumentsNavItem,
                  myPayNavItem,
              ]
            : []),
        ...(permissions.includes('hr.attendance.manage')
            ? [attendanceSheetNavItem]
            : []),
        ...(permissions.includes('hr.leave.approve') ||
        (atTenantLevel && permissions.includes('hr.leave.manage'))
            ? [leaveApprovalsNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('hr.leave.manage')
            ? [leavesNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('hr.employees.manage')
            ? [expiringDocumentsNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('hr.catalog.manage')
            ? [hrCatalogNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('hr.leave.manage')
            ? [leaveSettingsNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('hr.payroll.manage')
            ? [
                  payrollEmployeesNavItem,
                  payrollRecapNavItem,
                  payrollSettingsNavItem,
              ]
            : []),
        ...(permissions.includes('finance.billing.view')
            ? [cashierNavItem, invoicesNavItem, discountsNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('finance.settings.manage')
            ? [financeSettingsNavItem]
            : []),
        ...(atTenantLevel && permissions.includes('academic.calendar.manage')
            ? [academicCalendarNavItem]
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
