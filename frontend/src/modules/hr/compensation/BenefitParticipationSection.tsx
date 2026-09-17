import {
    Fragment,
    useState,
} from 'react';

import {
    useCreateBenefitIdentifierMutation,
} from '@/modules/hr/compensation/api/use-benefit-identifier-mutations';
import {
    useBenefitIdentifiersQuery,
} from '@/modules/hr/compensation/api/use-benefit-identifiers-query';
import {
    useCreateBenefitParticipationMutation,
    useEndBenefitParticipationMutation,
    useEnrollBenefitParticipationMutation,
    useReinstateBenefitParticipationMutation,
    useSuspendBenefitParticipationMutation,
} from '@/modules/hr/compensation/api/use-benefit-participation-mutations';
import {
    useBenefitParticipationsQuery,
    type EmployeeBenefitParticipationResource,
} from '@/modules/hr/compensation/api/use-benefit-participations-query';
import {
    useBenefitProgramsQuery,
} from '@/modules/hr/compensation/api/use-benefit-programs-query';
import {
    Badge,
    Button,
    Input,
    Select,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

const STATUS_LABEL: Record<string, string> = {
    ELIGIBLE: 'Berhak',
    ENROLLED: 'Terdaftar',
    SUSPENDED: 'Ditangguhkan',
    ENDED: 'Berakhir',
    INELIGIBLE: 'Tidak Berhak',
};

const STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary'
> = {
    ELIGIBLE: 'warning',
    ENROLLED: 'success',
    SUSPENDED: 'warning',
    ENDED: 'secondary',
    INELIGIBLE: 'secondary',
};

const EMPTY_DRAFT_FORM = {
    benefitProgramId: '',
    effectiveFrom: '',
    effectiveTo: '',
    notes: '',
};

const EMPTY_IDENTIFIER_FORM = {
    identifierType: '',
    value: '',
    issuer: '',
};

/*
 * §HR-006 §7.7 — data sensitif (mis. nomor BPJS). Panel ini SENGAJA
 * hanya fetch (`shouldFetch`) saat pengguna eksplisit membuka baris
 * ini, bukan auto-fetch semua identifier untuk semua partisipasi
 * saat halaman dimuat.
 */
function BenefitIdentifiersPanel({
    participationId,
}: {
    participationId: string;
}) {
    const identifiersQuery =
        useBenefitIdentifiersQuery(
            participationId,
            true,
        );

    const createMutation =
        useCreateBenefitIdentifierMutation();

    const [
        form,
        setForm,
    ] = useState(
        EMPTY_IDENTIFIER_FORM,
    );

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                participationId,

                identifier_type:
                    form.identifierType,

                value:
                    form.value,

                issuer:
                    form.issuer.trim() === ''
                        ? null
                        : form.issuer,
            },
            {
                onSuccess: () => {
                    setForm(
                        EMPTY_IDENTIFIER_FORM,
                    );
                },
            },
        );
    }

    return (
        <div className="space-y-3 rounded-md bg-muted/30 p-3">
            {
                identifiersQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-xs text-muted-foreground"
                        >
                            Memuat nomor identitas…
                        </p>
                    )
                    : null
            }

            {
                identifiersQuery.status === 'error'
                    ? (
                        <p
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            Gagal memuat nomor identitas.
                        </p>
                    )
                    : null
            }

            {
                identifiersQuery.status === 'success'
                    ? (
                        identifiersQuery.data.length === 0
                            ? (
                                <p className="text-xs text-muted-foreground">
                                    Belum ada nomor identitas terdaftar.
                                </p>
                            )
                            : (
                                <ul className="space-y-1 text-sm">
                                    {
                                        identifiersQuery.data.map(
                                            (
                                                identifier,
                                                index,
                                            ) => (
                                            <li
                                                key={
                                                    `${identifier.identifier_type}-${index}`
                                                }
                                            >
                                                    <span className="font-medium">
                                                        {
                                                            identifier.identifier_type
                                                        }
                                                        :
                                                    </span>
                                                    {
                                                        ' '
                                                    }
                                                    {
                                                        identifier.value
                                                    }
                                                    {
                                                        identifier.issuer !== null
                                                            ? ` (${identifier.issuer})`
                                                            : ''
                                                    }
                                                </li>
                                            ),
                                        )
                                    }
                                </ul>
                            )
                    )
                    : null
            }

            <form
                onSubmit={handleSubmit}
                className="flex flex-wrap items-end gap-2"
            >
                <div className="space-y-1">
                    <label
                        htmlFor={
                            `identifier-type-${participationId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Jenis
                    </label>

                    <Input
                        id={
                            `identifier-type-${participationId}`
                        }
                        placeholder="BPJS_KESEHATAN"
                        value={
                            form.identifierType
                        }
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setForm(
                                    {
                                        ...form,

                                        identifierType:
                                            event.target.value,
                                    },
                                )
                        }
                    />
                </div>

                <div className="space-y-1">
                    <label
                        htmlFor={
                            `identifier-value-${participationId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Nomor
                    </label>

                    <Input
                        id={
                            `identifier-value-${participationId}`
                        }
                        value={
                            form.value
                        }
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setForm(
                                    {
                                        ...form,

                                        value:
                                            event.target.value,
                                    },
                                )
                        }
                    />
                </div>

                <div className="space-y-1">
                    <label
                        htmlFor={
                            `identifier-issuer-${participationId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Penerbit (opsional)
                    </label>

                    <Input
                        id={
                            `identifier-issuer-${participationId}`
                        }
                        value={
                            form.issuer
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setForm(
                                    {
                                        ...form,

                                        issuer:
                                            event.target.value,
                                    },
                                )
                        }
                    />
                </div>

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        createMutation.isPending
                    }
                >
                    {
                        createMutation.isPending
                            ? 'Menyimpan…'
                            : 'Tambah Nomor'
                    }
                </Button>
            </form>

            {
                createMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            {
                                createMutation.error.kind === 'response'
                                && createMutation.error.status === 409
                                    ? 'Nomor ini sudah terdaftar di tenant Anda.'
                                    : 'Gagal menambah nomor identitas. Coba lagi.'
                            }
                        </p>
                    )
                    : null
            }
        </div>
    );
}

function EndParticipationInlineForm({
    participationId,
    onCancel,
    mutation,
}: {
    participationId: string;
    onCancel: () => void;
    mutation: ReturnType<
        typeof useEndBenefitParticipationMutation
    >;
}) {
    const [
        endDate,
        setEndDate,
    ] = useState('');

    return (
        <div className="flex items-center gap-2">
            <Input
                type="date"
                aria-label="Tanggal berakhir"
                value={endDate}
                onChange={
                    (
                        event,
                    ) =>
                        setEndDate(
                            event.target.value,
                        )
                }
            />

            <Button
                type="button"
                size="sm"
                disabled={
                    mutation.isPending
                    || endDate === ''
                }
                onClick={
                    () =>
                        mutation.mutate(
                            {
                                participationId,
                                endDate,
                            },
                            {
                                onSuccess:
                                    onCancel,
                            },
                        )
                }
            >
                {
                    mutation.isPending
                        ? 'Menyimpan…'
                        : 'Konfirmasi'
                }
            </Button>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onCancel}
            >
                Batal
            </Button>
        </div>
    );
}

export function BenefitParticipationSection({
    employmentId,
}: {
    employmentId: string;
}) {
    const participationsQuery =
        useBenefitParticipationsQuery(
            employmentId,
        );

    const programsQuery =
        useBenefitProgramsQuery();

    const createMutation =
        useCreateBenefitParticipationMutation(
            employmentId,
        );

    const enrollMutation =
        useEnrollBenefitParticipationMutation(
            employmentId,
        );

    const suspendMutation =
        useSuspendBenefitParticipationMutation(
            employmentId,
        );

    const reinstateMutation =
        useReinstateBenefitParticipationMutation(
            employmentId,
        );

    const endMutation =
        useEndBenefitParticipationMutation(
            employmentId,
        );

    const [
        form,
        setForm,
    ] = useState(
        EMPTY_DRAFT_FORM,
    );

    const [
        endingParticipationId,
        setEndingParticipationId,
    ] = useState<string | null>(
        null,
    );

    const [
        expandedParticipationId,
        setExpandedParticipationId,
    ] = useState<string | null>(
        null,
    );

    const programNameById =
        new Map(
            programsQuery.status === 'success'
                ? programsQuery.data.map(
                    (
                        program,
                    ) => [
                        program.id,
                        program.name,
                    ] as const,
                )
                : [],
        );

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                benefit_program_id:
                    form.benefitProgramId,

                effective_from:
                    form.effectiveFrom,

                effective_to:
                    form.effectiveTo.trim() === ''
                        ? null
                        : form.effectiveTo,

                notes:
                    form.notes.trim() === ''
                        ? null
                        : form.notes,
            },
            {
                onSuccess: () => {
                    setForm(
                        EMPTY_DRAFT_FORM,
                    );
                },
            },
        );
    }

    function renderActions(
        participation: EmployeeBenefitParticipationResource,
    ) {
        if (endingParticipationId === participation.id) {
            return (
                <EndParticipationInlineForm
                    participationId={
                        participation.id
                    }
                    mutation={
                        endMutation
                    }
                    onCancel={
                        () =>
                            setEndingParticipationId(
                                null,
                            )
                    }
                />
            );
        }

        return (
            <div className="flex flex-wrap gap-2">
                {
                    participation.status === 'ELIGIBLE'
                        ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={
                                    enrollMutation.isPending
                                }
                                onClick={
                                    () =>
                                        enrollMutation.mutate(
                                            participation.id,
                                        )
                                }
                            >
                                Verifikasi
                            </Button>
                        )
                        : null
                }

                {
                    participation.status === 'ENROLLED'
                        ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    suspendMutation.isPending
                                }
                                onClick={
                                    () =>
                                        suspendMutation.mutate(
                                            participation.id,
                                        )
                                }
                            >
                                Tangguhkan
                            </Button>
                        )
                        : null
                }

                {
                    participation.status === 'SUSPENDED'
                        ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    reinstateMutation.isPending
                                }
                                onClick={
                                    () =>
                                        reinstateMutation.mutate(
                                            participation.id,
                                        )
                                }
                            >
                                Aktifkan Kembali
                            </Button>
                        )
                        : null
                }

                {
                    participation.status === 'ENROLLED'
                    || participation.status === 'SUSPENDED'
                        ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={
                                    () =>
                                        setEndingParticipationId(
                                            participation.id,
                                        )
                                }
                            >
                                Akhiri
                            </Button>
                        )
                        : null
                }

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={
                        () =>
                            setExpandedParticipationId(
                                expandedParticipationId === participation.id
                                    ? null
                                    : participation.id,
                            )
                    }
                >
                    {
                        expandedParticipationId === participation.id
                            ? 'Tutup Nomor'
                            : 'Kelola Nomor'
                    }
                </Button>
            </div>
        );
    }

    return (
        <section
            aria-labelledby="benefit-participation-heading"
            className="space-y-4"
        >
            <h2
                id="benefit-participation-heading"
                className="text-lg font-semibold"
            >
                Kepesertaan Benefit
            </h2>

            {
                participationsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat kepesertaan…
                        </p>
                    )
                    : null
            }

            {
                participationsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat kepesertaan benefit.
                        </div>
                    )
                    : null
            }

            {
                participationsQuery.status === 'success'
                    ? (
                        participationsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada kepesertaan benefit.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Program
                                            </TableHead>
                                            <TableHead>
                                                Status
                                            </TableHead>
                                            <TableHead>
                                                Periode
                                            </TableHead>
                                            <TableHead>
                                                Aksi
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            participationsQuery.data.map(
                                                (
                                                    participation,
                                                ) => (
                                                    <Fragment
                                                        key={
                                                            participation.id
                                                        }
                                                    >
                                                        <TableRow
                                                            key={
                                                                participation.id
                                                            }
                                                        >
                                                            <TableCell>
                                                                {
                                                                    programNameById.get(
                                                                        participation.benefit_program_id,
                                                                    )
                                                                    ?? participation.benefit_program_id
                                                                }
                                                            </TableCell>
                                                            <TableCell>
                                                                <Badge
                                                                    variant={
                                                                        STATUS_VARIANT[
                                                                            participation.status
                                                                        ]
                                                                    }
                                                                >
                                                                    {
                                                                        STATUS_LABEL[
                                                                            participation.status
                                                                        ]
                                                                        ?? participation.status
                                                                    }
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell>
                                                                {
                                                                    participation.effective_from
                                                                }
                                                                {
                                                                    ' – '
                                                                }
                                                                {
                                                                    participation.effective_to
                                                                    ?? 'sekarang'
                                                                }
                                                            </TableCell>
                                                            <TableCell>
                                                                {
                                                                    renderActions(
                                                                        participation,
                                                                    )
                                                                }
                                                            </TableCell>
                                                        </TableRow>

                                                        {
                                                            expandedParticipationId === participation.id
                                                                ? (
                                                                    <TableRow
                                                                        key={
                                                                            `${participation.id}-identifiers`
                                                                        }
                                                                    >
                                                                        <TableCell
                                                                            colSpan={4}
                                                                        >
                                                                            <BenefitIdentifiersPanel
                                                                                participationId={
                                                                                    participation.id
                                                                                }
                                                                            />
                                                                        </TableCell>
                                                                    </TableRow>
                                                                )
                                                                : null
                                                        }
                                                    </Fragment>
                                                ),
                                            )
                                        }
                                    </TableBody>
                                </Table>
                            )
                    )
                    : null
            }

            <form
                onSubmit={handleSubmit}
                className="space-y-3 rounded-md border p-4"
            >
                <h3 className="text-sm font-semibold">
                    Daftarkan Kepesertaan Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="participation-program"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Program
                        </label>

                        <Select
                            id="participation-program"
                            value={
                                form.benefitProgramId
                            }
                            required
                            disabled={
                                programsQuery.status !== 'success'
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            benefitProgramId:
                                                event.target.value,
                                        },
                                    )
                            }
                        >
                            <option value="">
                                {
                                    programsQuery.status === 'pending'
                                        ? 'Memuat…'
                                        : 'Pilih program'
                                }
                            </option>

                            {
                                programsQuery.status === 'success'
                                    ? programsQuery.data.map(
                                        (
                                            program,
                                        ) => (
                                            <option
                                                key={
                                                    program.id
                                                }
                                                value={
                                                    program.id
                                                }
                                            >
                                                {
                                                    program.name
                                                }
                                            </option>
                                        ),
                                    )
                                    : null
                            }
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="participation-effective-from"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Berlaku Sejak
                        </label>

                        <Input
                            id="participation-effective-from"
                            type="date"
                            value={
                                form.effectiveFrom
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            effectiveFrom:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="participation-effective-to"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Berlaku Sampai (opsional)
                        </label>

                        <Input
                            id="participation-effective-to"
                            type="date"
                            value={
                                form.effectiveTo
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            effectiveTo:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="participation-notes"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Catatan (opsional)
                        </label>

                        <Input
                            id="participation-notes"
                            value={
                                form.notes
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            notes:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>
                </div>

                {
                    createMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {
                                    createMutation.error.kind === 'response'
                                    && createMutation.error.status === 409
                                        ? 'Ditolak: periksa status Employment atau kombinasi periode.'
                                        : 'Gagal menyimpan. Coba lagi.'
                                }
                            </p>
                        )
                        : null
                }

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        createMutation.isPending
                    }
                >
                    {
                        createMutation.isPending
                            ? 'Menyimpan…'
                            : 'Daftarkan'
                    }
                </Button>
            </form>
        </section>
    );
}
