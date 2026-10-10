import { Form, Head } from '@inertiajs/react';
import { Building2, Check, Network, School } from 'lucide-react';
import { Button } from '@/components/ui/button';

type WorkspaceOption = {
    /** ID penugasan, atau "tenant" untuk Seluruh Yayasan. */
    id: string;
    label: string;
    path: string | null;
    type: 'tenant' | 'organization' | 'functional';
};

const ICONS = {
    tenant: Building2,
    organization: School,
    functional: Network,
} as const;

type Props = {
    tenantName: string;
    assignments: WorkspaceOption[];
    currentAssignmentId: string | null;
};

/**
 * Pilih lembaga kerja — muncul bila anggota punya lebih dari satu pilihan (PRD-000 §6, OD-08):
 * penugasan-penugasannya, ditambah Seluruh Yayasan bila punya role tenant-wide.
 */
export default function SelectWorkspace({
    tenantName,
    assignments,
    currentAssignmentId,
}: Props) {
    return (
        <>
            <Head title="Pilih lembaga kerja" />

            <p className="mb-4 text-center text-sm text-muted-foreground">
                {tenantName}
            </p>

            {assignments.length === 0 ? (
                <p className="text-center text-sm text-muted-foreground">
                    Tidak ada lembaga kerja yang bisa dipilih.
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    {assignments.map((assignment) => {
                        const Icon = ICONS[assignment.type];

                        return (
                            <Form
                                key={assignment.id}
                                action="/konteks/lembaga"
                                method="post"
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="assignment_id"
                                            value={assignment.id}
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className="h-auto w-full justify-start gap-3 py-3 text-left whitespace-normal"
                                            disabled={processing}
                                        >
                                            <Icon className="size-5 shrink-0" />
                                            <span className="flex flex-1 flex-col">
                                                <span className="font-medium">
                                                    {assignment.label}
                                                </span>
                                                {assignment.path && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {assignment.path}
                                                    </span>
                                                )}
                                                {assignment.type ===
                                                    'functional' && (
                                                    <span className="text-xs text-muted-foreground">
                                                        Penugasan fungsional
                                                    </span>
                                                )}
                                            </span>
                                            {assignment.id ===
                                                currentAssignmentId && (
                                                <Check
                                                    className="size-4 shrink-0"
                                                    aria-label="Sedang dipilih"
                                                />
                                            )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        );
                    })}
                </div>
            )}
        </>
    );
}

SelectWorkspace.layout = {
    title: 'Pilih lembaga kerja',
    description:
        'Anda punya lebih dari satu lembaga kerja. Pilih lembaga yang ingin dibuka.',
};
