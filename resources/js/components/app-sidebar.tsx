import { Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    BookOpen,
    Calculator,
    CalendarCog,
    CalendarDays,
    CalendarRange,
    ChartColumn,
    ChartPie,
    CircleAlert,
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
    Landmark,
    FolderTree,
    LayoutGrid,
    Network,
    Percent,
    Plane,
    Receipt,
    ReceiptText,
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
import type { NavGroup, NavItem } from '@/types';

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

const arrearsNavItem: NavItem = {
    title: 'Tunggakan',
    href: '/keuangan/tunggakan',
    icon: CircleAlert,
};

const receiptRecapNavItem: NavItem = {
    title: 'Rekap penerimaan',
    href: '/keuangan/rekap-penerimaan',
    icon: ChartColumn,
};

const cashNavItem: NavItem = {
    title: 'Kas',
    href: '/keuangan/kas',
    icon: Landmark,
};

const expensesNavItem: NavItem = {
    title: 'Pengeluaran',
    href: '/keuangan/pengeluaran',
    icon: ReceiptText,
};

const cashReportNavItem: NavItem = {
    title: 'Laporan kas',
    href: '/keuangan/laporan-kas',
    icon: ChartPie,
};

const cashCategoriesNavItem: NavItem = {
    title: 'Kategori kas',
    href: '/keuangan/kategori-kas',
    icon: FolderTree,
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
    const when = (
        condition: boolean | undefined,
        items: NavItem[],
    ): NavItem[] => (condition ? items : []);

    // Menu berkelompok (pedoman tampilan UI-1); kelompok kosong tidak ditampilkan.
    const groups: NavGroup[] = [
        { key: 'home', items: mainNavItems },
        {
            key: 'mine',
            title: 'Untuk saya',
            items: [
                ...when(academic?.isGuardian, [myChildrenNavItem]),
                ...when(academic?.myClasses, [myClassesNavItem]),
                ...when(hr?.isEmployee, [
                    myAttendanceNavItem,
                    myLeaveNavItem,
                    myDocumentsNavItem,
                    myPayNavItem,
                ]),
            ],
        },
        {
            key: 'academic',
            title: 'Akademik',
            items: [
                ...when(permissions.includes('academic.students.view'), [
                    studentsNavItem,
                    classesNavItem,
                    subjectsNavItem,
                ]),
                ...when(
                    permissions.includes('academic.teaching.manage') ||
                        academic?.teachingDuty,
                    [teachingNavItem],
                ),
            ],
        },
        {
            key: 'hr',
            title: 'Kepegawaian',
            items: [
                ...when(permissions.includes('hr.employees.view'), [
                    employeesNavItem,
                ]),
                ...when(permissions.includes('hr.attendance.manage'), [
                    attendanceSheetNavItem,
                ]),
                ...when(
                    permissions.includes('hr.leave.approve') ||
                        (atTenantLevel &&
                            permissions.includes('hr.leave.manage')),
                    [leaveApprovalsNavItem],
                ),
                ...when(
                    atTenantLevel && permissions.includes('hr.leave.manage'),
                    [leavesNavItem],
                ),
                ...when(
                    atTenantLevel &&
                        permissions.includes('hr.employees.manage'),
                    [expiringDocumentsNavItem],
                ),
                ...when(
                    atTenantLevel && permissions.includes('hr.payroll.manage'),
                    [payrollEmployeesNavItem, payrollRecapNavItem],
                ),
            ],
        },
        {
            key: 'billing',
            title: 'Keuangan siswa',
            items: when(permissions.includes('finance.billing.view'), [
                cashierNavItem,
                invoicesNavItem,
                arrearsNavItem,
                receiptRecapNavItem,
                discountsNavItem,
            ]),
        },
        {
            key: 'cash',
            title: 'Kas & pengeluaran',
            items: when(permissions.includes('finance.cash.view'), [
                cashNavItem,
                expensesNavItem,
                cashReportNavItem,
            ]),
        },
        {
            key: 'settings',
            title: 'Pengaturan',
            items: [
                ...when(permissions.includes('core.organizations.view'), [
                    organizationsNavItem,
                ]),
                ...when(
                    atTenantLevel && permissions.includes('hr.catalog.manage'),
                    [hrCatalogNavItem],
                ),
                ...when(
                    atTenantLevel && permissions.includes('hr.leave.manage'),
                    [leaveSettingsNavItem],
                ),
                ...when(
                    atTenantLevel && permissions.includes('hr.payroll.manage'),
                    [payrollSettingsNavItem],
                ),
                ...when(
                    atTenantLevel &&
                        permissions.includes('finance.settings.manage'),
                    [financeSettingsNavItem, cashCategoriesNavItem],
                ),
                ...when(
                    atTenantLevel &&
                        permissions.includes('academic.calendar.manage'),
                    [academicCalendarNavItem],
                ),
                ...when(
                    atTenantLevel && permissions.includes('core.audit.view'),
                    [auditNavItem],
                ),
                ...when(auth.user?.is_superadmin, [platformNavItem]),
            ],
        },
    ].filter((group) => group.items.length > 0);

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
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
