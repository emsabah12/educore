import { Form, Head } from '@inertiajs/react';
import { Check, Network, School } from 'lucide-react';
import { Button } from '@/components/ui/button';

type AssignmentOption = {
    id: string;
    label: string;
    path: string | null;
    is_functional: boolean;
};

type Props = {
    tenantName: string;
    assignments: AssignmentOption[];
    currentAssignmentId: string | null;
};

/**
 * Pilih lembaga kerja — muncul bila anggota punya lebih dari satu penugasan (PRD-000 §6, OD-08).
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
                        const Icon = assignment.is_functional
                            ? Network
                            : School;

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
                                                {assignment.is_functional && (
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
        'Anda bertugas di lebih dari satu lembaga. Pilih lembaga yang ingin dibuka.',
};
