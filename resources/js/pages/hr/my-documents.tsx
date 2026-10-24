import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { DocumentSection } from '@/components/hr/employee-documents';
import type { EmployeeDocument } from '@/components/hr/employee-documents';

type Props = {
    linked: boolean;
    documents: EmployeeDocument[];
};

/**
 * Dokumen saya (PRD-001 §15.2, HR-2b): pegawai melihat & mengunduh dokumen/SK miliknya yang
 * berlaku. Menambah atau memperbaiki dokumen tetap lewat Admin SDM.
 */
export default function MyDocuments({ linked, documents }: Props) {
    return (
        <>
            <Head title="Dokumen saya" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Dokumen saya"
                    description="SK, kontrak, ijazah, dan dokumen kepegawaian lain milik Anda. Bila ada yang kurang atau salah, hubungi Admin SDM."
                />

                {linked ? (
                    <DocumentSection
                        employeeId=""
                        documents={documents}
                        manage={null}
                        downloadUrl={(document) =>
                            `/dokumen-saya/${document.id}/unduh`
                        }
                    />
                ) : (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Akun ini belum tertaut ke data pegawai. Hubungi Admin
                        SDM bila Anda pegawai yayasan.
                    </p>
                )}
            </div>
        </>
    );
}
