import { Link, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown, Network, School } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

/**
 * Menampilkan yayasan & lembaga kerja aktif di sidebar, dengan tautan untuk
 * menggantinya bila pengguna punya lebih dari satu pilihan (PRD-000 §6).
 */
export function ContextSwitcher() {
    const { context } = usePage().props;

    if (!context) {
        return null;
    }

    const WorkspaceIcon =
        context.workspace.type === 'functional' ? Network : School;

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Konteks kerja</SidebarGroupLabel>
            <SidebarMenu>
                <ContextItem
                    href={context.can_switch_tenant ? '/konteks/yayasan' : null}
                    tooltip={`Yayasan: ${context.tenant.name}`}
                    icon={<Building2 />}
                    label={context.tenant.name}
                />
                <ContextItem
                    href={
                        context.can_switch_workspace ? '/konteks/lembaga' : null
                    }
                    tooltip={`Lembaga kerja: ${context.workspace.label}`}
                    icon={<WorkspaceIcon />}
                    label={context.workspace.label}
                />
            </SidebarMenu>
        </SidebarGroup>
    );
}

function ContextItem({
    href,
    tooltip,
    icon,
    label,
}: {
    href: string | null;
    tooltip: string;
    icon: ReactNode;
    label: string;
}) {
    const content = (
        <>
            {icon}
            <span className="truncate">{label}</span>
            {href && (
                <ChevronsUpDown
                    className="ml-auto size-4 opacity-60"
                    aria-label="Ganti"
                />
            )}
        </>
    );

    return (
        <SidebarMenuItem>
            {href ? (
                <SidebarMenuButton asChild tooltip={{ children: tooltip }}>
                    <Link href={href}>{content}</Link>
                </SidebarMenuButton>
            ) : (
                <SidebarMenuButton tooltip={{ children: tooltip }}>
                    {content}
                </SidebarMenuButton>
            )}
        </SidebarMenuItem>
    );
}
