/**
 * Konteks kerja aktif yang dibagikan server lewat props Inertia `context`
 * (WorkContext::toArray() di modul Core). Null di halaman tanpa konteks kerja.
 */
export type WorkContext = {
    tenant: {
        id: string;
        name: string;
    };
    workspace: {
        type: 'tenant' | 'organization' | 'functional';
        assignment_id: string | null;
        organization_id: string | null;
        label: string;
    };
    can_switch_tenant: boolean;
    can_switch_workspace: boolean;
};
