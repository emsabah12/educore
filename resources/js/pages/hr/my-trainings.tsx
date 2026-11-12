import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { TrainingSection } from '@/components/hr/employee-trainings';
import type {
    TrainingFormOptions,
    TrainingRecord,
} from '@/components/hr/employee-trainings';

type Props = {
    linked: boolean;
    trainings: TrainingRecord[];
    form: TrainingFormOptions;
};

/**
 * Pelatihan saya (PRD-001 §19.1, HR-8a; OD-HR-16): pegawai melihat riwayat pelatihannya, mengajukan pelatihan baru
 * (diverifikasi Admin SDM), dan menarik pengajuan yang masih menunggu.
 */
export default function MyTrainings({ linked, trainings, form }: Props) {
    return (
        <>
            <Head title="Pelatihan saya" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pelatihan saya"
                    description="Diklat, seminar, dan sertifikasi yang pernah Anda ikuti. Pengajuan baru diperiksa Admin SDM sebelum masuk riwayat resmi."
                />

                {linked ? (
                    <TrainingSection
                        records={trainings}
                        mode="self"
                        form={form}
                        storeUrl="/pelatihan-saya"
                        actionUrl={(record) => `/pelatihan-saya/${record.id}`}
                        downloadUrl={(record) =>
                            `/pelatihan-saya/${record.id}/unduh`
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
