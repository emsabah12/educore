import { Form, Head } from '@inertiajs/react';
import { Building2, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';

type MembershipOption = {
    id: string;
    tenant_name: string;
};

type Props = {
    memberships: MembershipOption[];
    currentMembershipId: string | null;
};

/**
 * Pilih yayasan — muncul bila akun terdaftar di lebih dari satu yayasan (PRD-000 §6).
 */
export default function SelectTenant({
    memberships,
    currentMembershipId,
}: Props) {
    return (
        <>
            <Head title="Pilih yayasan" />

            {memberships.length === 0 ? (
                <p className="text-center text-sm text-muted-foreground">
                    Tidak ada yayasan yang bisa dipilih.
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    {memberships.map((membership) => (
                        <Form
                            key={membership.id}
                            action="/konteks/yayasan"
                            method="post"
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="membership_id"
                                        value={membership.id}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="h-auto w-full justify-start gap-3 py-3 text-left whitespace-normal"
                                        disabled={processing}
                                    >
                                        <Building2 className="size-5 shrink-0" />
                                        <span className="flex-1 font-medium">
                                            {membership.tenant_name}
                                        </span>
                                        {membership.id ===
                                            currentMembershipId && (
                                            <Check
                                                className="size-4 shrink-0"
                                                aria-label="Sedang dipilih"
                                            />
                                        )}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ))}
                </div>
            )}
        </>
    );
}

SelectTenant.layout = {
    title: 'Pilih yayasan',
    description:
        'Akun Anda terdaftar di lebih dari satu yayasan. Pilih yayasan yang ingin dibuka.',
};
