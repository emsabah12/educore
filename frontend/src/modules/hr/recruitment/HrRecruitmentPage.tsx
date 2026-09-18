import {
    useState,
} from 'react';

import {
    useApproveRecruitmentVacancyMutation,
    useCancelRecruitmentVacancyMutation,
    useCloseRecruitmentVacancyMutation,
    useCreateRecruitmentVacancyMutation,
    useOpenRecruitmentVacancyMutation,
    useRejectRecruitmentVacancyMutation,
    useSubmitRecruitmentVacancyMutation,
} from '@/modules/hr/api/use-recruitment-vacancy-mutations';
import {
    useRecruitmentVacanciesQuery,
    type RecruitmentVacancyResource,
} from '@/modules/hr/api/use-recruitment-vacancies-query';
import {
    useCreateRecruitmentCandidateMutation,
} from '@/modules/hr/api/use-recruitment-candidate-mutations';
import {
    useRecruitmentCandidatesQuery,
} from '@/modules/hr/api/use-recruitment-candidates-query';
import {
    usePositionsQuery,
} from '@/modules/hr/api/use-positions-query';
import {
    useOrganizationsQuery,
} from '@/modules/settings/organizations/api/use-organizations-query';
import {
    useOrganizationUnitsQuery,
} from '@/modules/settings/organizations/api/use-organization-units-query';
import {
    Badge,
    Button,
    Input,
    Select,
} from '@/shared/ui';

const VACANCY_STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary' | 'destructive'
> = {
    DRAFT: 'secondary',
    PENDING_APPROVAL: 'warning',
    APPROVED: 'success',
    OPEN: 'success',
    CLOSED: 'secondary',
    CANCELLED: 'destructive',
};

const VACANCY_STATUS_LABEL: Record<string, string> = {
    DRAFT: 'Draf',
    PENDING_APPROVAL: 'Menunggu Persetujuan',
    APPROVED: 'Disetujui',
    OPEN: 'Dibuka',
    CLOSED: 'Ditutup',
    CANCELLED: 'Dibatalkan',
};

function VacancyActions({
    vacancy,
}: {
    vacancy: RecruitmentVacancyResource;
}) {
    const submitMutation =
        useSubmitRecruitmentVacancyMutation();

    const approveMutation =
        useApproveRecruitmentVacancyMutation();

    const rejectMutation =
        useRejectRecruitmentVacancyMutation();

    const openMutation =
        useOpenRecruitmentVacancyMutation();

    const closeMutation =
        useCloseRecruitmentVacancyMutation();

    const cancelMutation =
        useCancelRecruitmentVacancyMutation();

    const isPending =
        submitMutation.isPending
        || approveMutation.isPending
        || rejectMutation.isPending
        || openMutation.isPending
        || closeMutation.isPending
        || cancelMutation.isPending;

    const cancelButton =
        vacancy.status === 'DRAFT'
        || vacancy.status === 'PENDING_APPROVAL'
        || vacancy.status === 'APPROVED'
        || vacancy.status === 'OPEN'
            ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            cancelMutation.mutate(
                                {
                                    vacancyId:
                                        vacancy.id,
                                },
                            )
                    }
                >
                    Batalkan
                </Button>
            )
            : null;

    if (vacancy.status === 'DRAFT') {
        return (
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            submitMutation.mutate(
                                {
                                    vacancyId:
                                        vacancy.id,
                                },
                            )
                    }
                >
                    Ajukan
                </Button>

                {cancelButton}
            </div>
        );
    }

    if (vacancy.status === 'PENDING_APPROVAL') {
        return (
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            approveMutation.mutate(
                                {
                                    vacancyId:
                                        vacancy.id,

                                    reason:
                                        null,
                                },
                            )
                    }
                >
                    Setujui
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            rejectMutation.mutate(
                                {
                                    vacancyId:
                                        vacancy.id,

                                    reason:
                                        null,
                                },
                            )
                    }
                >
                    Tolak
                </Button>

                {cancelButton}
            </div>
        );
    }

    if (vacancy.status === 'APPROVED') {
        return (
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            openMutation.mutate(
                                {
                                    vacancyId:
                                        vacancy.id,
                                },
                            )
                    }
                >
                    Buka Lowongan
                </Button>

                {cancelButton}
            </div>
        );
    }

    if (vacancy.status === 'OPEN') {
        return (
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            closeMutation.mutate(
                                {
                                    vacancyId:
                                        vacancy.id,
                                },
                            )
                    }
                >
                    Tutup
                </Button>

                {cancelButton}
            </div>
        );
    }

    return null;
}

function VacancySection() {
    const vacanciesQuery =
        useRecruitmentVacanciesQuery();

    const positionsQuery =
        usePositionsQuery();

    const organizationsQuery =
        useOrganizationsQuery();

    const createMutation =
        useCreateRecruitmentVacancyMutation();

    const [
        form,
        setForm,
    ] = useState({
        code: '',
        title: '',
        positionId: '',
        organizationId: '',
        organizationUnitId: '',
        requestedHeadcount: '',
        description: '',
    });

    const unitsQuery =
        useOrganizationUnitsQuery(
            form.organizationId === ''
                ? null
                : form.organizationId,
        );

    const positionNameById =
        new Map(
            positionsQuery.status === 'success'
                ? positionsQuery.data.map(
                    (
                        position,
                    ) => [
                        position.id,
                        position.name,
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
                code:
                    form.code,

                title:
                    form.title,

                positionId:
                    form.positionId,

                organizationId:
                    form.organizationId,

                organizationUnitId:
                    form.organizationUnitId === ''
                        ? null
                        : form.organizationUnitId,

                requestedHeadcount:
                    Number(
                        form.requestedHeadcount,
                    ),

                description:
                    form.description.trim() === ''
                        ? null
                        : form.description,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            code: '',
                            title: '',
                            positionId: '',
                            organizationId: '',
                            organizationUnitId: '',
                            requestedHeadcount: '',
                            description: '',
                        },
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="recruitment-vacancy-heading"
            className="space-y-4"
        >
            <h2
                id="recruitment-vacancy-heading"
                className="text-lg font-semibold"
            >
                Lowongan
            </h2>

            {
                vacanciesQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat…
                        </p>
                    )
                    : null
            }

            {
                vacanciesQuery.status === 'success'
                    ? (
                        vacanciesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Lowongan.
                                </p>
                            )
                            : (
                                <ul className="space-y-2">
                                    {
                                        vacanciesQuery.data.map(
                                            (
                                                vacancy,
                                            ) => (
                                                <li
                                                    key={
                                                        vacancy.id
                                                    }
                                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm"
                                                >
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">
                                                                {
                                                                    vacancy.title
                                                                }
                                                            </span>

                                                            <span className="text-muted-foreground">
                                                                (
                                                                {
                                                                    vacancy.code
                                                                }
                                                                )
                                                            </span>

                                                            <Badge
                                                                variant={
                                                                    VACANCY_STATUS_VARIANT[
                                                                        vacancy.status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    VACANCY_STATUS_LABEL[
                                                                        vacancy.status
                                                                    ]
                                                                    ?? vacancy.status
                                                                }
                                                            </Badge>
                                                        </div>

                                                        <p className="text-muted-foreground">
                                                            {
                                                                positionNameById.get(
                                                                    vacancy.position_id,
                                                                )
                                                                ?? vacancy.position_id
                                                            }
                                                            {
                                                                ' · '
                                                            }
                                                            {
                                                                vacancy.requested_headcount
                                                            }
                                                            {
                                                                ' formasi'
                                                            }
                                                        </p>
                                                    </div>

                                                    <VacancyActions
                                                        vacancy={
                                                            vacancy
                                                        }
                                                    />
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
                className="space-y-3 rounded-md border p-4"
            >
                <h3 className="text-sm font-semibold">
                    Buat Lowongan Baru (Draf)
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="vacancy-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode
                        </label>

                        <Input
                            id="vacancy-code"
                            value={
                                form.code
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            code:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-2">
                        <label
                            htmlFor="vacancy-title"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Judul
                        </label>

                        <Input
                            id="vacancy-title"
                            value={
                                form.title
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            title:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="vacancy-position"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jabatan
                        </label>

                        <Select
                            id="vacancy-position"
                            value={
                                form.positionId
                            }
                            required
                            disabled={
                                positionsQuery.status !== 'success'
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            positionId:
                                                event.target.value,
                                        },
                                    )
                            }
                        >
                            <option value="">
                                Pilih…
                            </option>

                            {
                                positionsQuery.status === 'success'
                                    ? positionsQuery.data.map(
                                        (
                                            position,
                                        ) => (
                                            <option
                                                key={
                                                    position.id
                                                }
                                                value={
                                                    position.id
                                                }
                                            >
                                                {
                                                    position.name
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
                            htmlFor="vacancy-organization"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Organisasi
                        </label>

                        <Select
                            id="vacancy-organization"
                            value={
                                form.organizationId
                            }
                            required
                            disabled={
                                organizationsQuery.status !== 'success'
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            organizationId:
                                                event.target.value,

                                            organizationUnitId: '',
                                        },
                                    )
                            }
                        >
                            <option value="">
                                Pilih…
                            </option>

                            {
                                organizationsQuery.status === 'success'
                                    ? organizationsQuery.data.map(
                                        (
                                            organization,
                                        ) => (
                                            <option
                                                key={
                                                    organization.id
                                                }
                                                value={
                                                    organization.id
                                                }
                                            >
                                                {
                                                    organization.name
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
                            htmlFor="vacancy-unit"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Unit (opsional)
                        </label>

                        <Select
                            id="vacancy-unit"
                            value={
                                form.organizationUnitId
                            }
                            disabled={
                                form.organizationId === ''
                                || unitsQuery.status !== 'success'
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            organizationUnitId:
                                                event.target.value,
                                        },
                                    )
                            }
                        >
                            <option value="">
                                Tingkat Organisasi
                            </option>

                            {
                                unitsQuery.status === 'success'
                                    ? unitsQuery.data.map(
                                        (
                                            unit,
                                        ) => (
                                            <option
                                                key={
                                                    unit.id
                                                }
                                                value={
                                                    unit.id
                                                }
                                            >
                                                {
                                                    unit.name
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
                            htmlFor="vacancy-headcount"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jumlah Formasi
                        </label>

                        <Input
                            id="vacancy-headcount"
                            type="number"
                            min={1}
                            step={1}
                            value={
                                form.requestedHeadcount
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            requestedHeadcount:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="vacancy-description"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Deskripsi (opsional)
                        </label>

                        <Input
                            id="vacancy-description"
                            value={
                                form.description
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            description:
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
                                Gagal menyimpan. Coba lagi.
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
                            : 'Simpan sebagai Draf'
                    }
                </Button>
            </form>
        </section>
    );
}

function CandidateSection() {
    const candidatesQuery =
        useRecruitmentCandidatesQuery();

    const createMutation =
        useCreateRecruitmentCandidateMutation();

    const [
        form,
        setForm,
    ] = useState({
        displayName: '',
        birthDate: '',
        primaryEmail: '',
        primaryPhone: '',
        source: '',
    });

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                displayName:
                    form.displayName,

                birthDate:
                    form.birthDate === ''
                        ? null
                        : form.birthDate,

                primaryEmail:
                    form.primaryEmail.trim() === ''
                        ? null
                        : form.primaryEmail,

                primaryPhone:
                    form.primaryPhone.trim() === ''
                        ? null
                        : form.primaryPhone,

                source:
                    form.source.trim() === ''
                        ? null
                        : form.source,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            displayName: '',
                            birthDate: '',
                            primaryEmail: '',
                            primaryPhone: '',
                            source: '',
                        },
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="recruitment-candidate-heading"
            className="space-y-4"
        >
            <h2
                id="recruitment-candidate-heading"
                className="text-lg font-semibold"
            >
                Kandidat
            </h2>

            {
                candidatesQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat…
                        </p>
                    )
                    : null
            }

            {
                candidatesQuery.status === 'success'
                    ? (
                        candidatesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Kandidat.
                                </p>
                            )
                            : (
                                <ul className="space-y-2">
                                    {
                                        candidatesQuery.data.map(
                                            (
                                                candidate,
                                            ) => (
                                                <li
                                                    key={
                                                        candidate.id
                                                    }
                                                    className="rounded-md border p-3 text-sm"
                                                >
                                                    <span className="font-medium">
                                                        {
                                                            candidate.display_name
                                                        }
                                                    </span>

                                                    {
                                                        candidate.primary_email !== null
                                                            ? (
                                                                <span className="ml-2 text-muted-foreground">
                                                                    {
                                                                        candidate.primary_email
                                                                    }
                                                                </span>
                                                            )
                                                            : null
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
                className="space-y-3 rounded-md border p-4"
            >
                <h3 className="text-sm font-semibold">
                    Tambah Kandidat Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1 sm:col-span-2">
                        <label
                            htmlFor="candidate-display-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="candidate-display-name"
                            value={
                                form.displayName
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            displayName:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="candidate-birth-date"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Tanggal Lahir (opsional)
                        </label>

                        <Input
                            id="candidate-birth-date"
                            type="date"
                            value={
                                form.birthDate
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            birthDate:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="candidate-email"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Email (opsional)
                        </label>

                        <Input
                            id="candidate-email"
                            type="email"
                            value={
                                form.primaryEmail
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            primaryEmail:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="candidate-phone"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Telepon (opsional)
                        </label>

                        <Input
                            id="candidate-phone"
                            value={
                                form.primaryPhone
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            primaryPhone:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="candidate-source"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Sumber (opsional)
                        </label>

                        <Input
                            id="candidate-source"
                            value={
                                form.source
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            source:
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
                                Gagal menyimpan. Coba lagi.
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
                            : 'Simpan'
                    }
                </Button>
            </form>
        </section>
    );
}

export function HrRecruitmentPage() {
    return (
        <div className="space-y-8">
            <div>
                <h1 className="text-xl font-semibold">
                    Rekrutmen
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Kelola Lowongan dan Kandidat.
                </p>
            </div>

            <VacancySection />

            <CandidateSection />
        </div>
    );
}
