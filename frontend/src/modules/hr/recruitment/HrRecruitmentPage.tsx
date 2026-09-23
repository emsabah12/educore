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
    useCancelOnboardingCaseMutation,
    useStartOnboardingCaseMutation,
} from '@/modules/hr/api/use-onboarding-case-mutations';
import {
    useCompleteOnboardingTaskMutation,
    useWaiveOnboardingTaskMutation,
} from '@/modules/hr/api/use-onboarding-task-mutations';
import {
    useOnboardingTemplatesQuery,
} from '@/modules/hr/api/use-onboarding-templates-query';
import {
    useApproveForHiringRecruitmentApplicationMutation,
    useCreateOnboardingCaseMutation,
    useCreateRecruitmentApplicationMutation,
    useHireConversionMutation,
    useRejectRecruitmentApplicationMutation,
    useStartProcessingRecruitmentApplicationMutation,
    useWithdrawRecruitmentApplicationMutation,
    type OnboardingCaseResource,
} from '@/modules/hr/api/use-recruitment-application-mutations';
import {
    useRecruitmentApplicationsQuery,
    type RecruitmentApplicationResource,
} from '@/modules/hr/api/use-recruitment-applications-query';
import {
    useCreateRecruitmentCandidateMutation,
    useStoreRecruitmentCandidateIdentifierMutation,
} from '@/modules/hr/api/use-recruitment-candidate-mutations';
import {
    useRecruitmentCandidatesQuery,
} from '@/modules/hr/api/use-recruitment-candidates-query';
import {
    useEmploymentTypesQuery,
} from '@/modules/hr/api/use-employment-types-query';
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

const APPLICATION_STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary' | 'destructive'
> = {
    SUBMITTED: 'warning',
    IN_PROCESS: 'warning',
    HIRING_APPROVED: 'success',
    REJECTED: 'destructive',
    WITHDRAWN: 'secondary',
    HIRED: 'success',
};

const APPLICATION_STATUS_LABEL: Record<string, string> = {
    SUBMITTED: 'Diajukan',
    IN_PROCESS: 'Diproses',
    HIRING_APPROVED: 'Disetujui untuk Perekrutan',
    REJECTED: 'Ditolak',
    WITHDRAWN: 'Ditarik',
    HIRED: 'Direkrut',
};

function HireConversionForm({
    vacancyId,
    applicationId,
}: {
    vacancyId: string;
    applicationId: string;
}) {
    const employmentTypesQuery =
        useEmploymentTypesQuery();

    const hireConversionMutation =
        useHireConversionMutation(
            vacancyId,
        );

    const [
        employmentTypeId,
        setEmploymentTypeId,
    ] = useState('');

    const [
        startDate,
        setStartDate,
    ] = useState('');

    const [
        confirmCreateNewPerson,
        setConfirmCreateNewPerson,
    ] = useState(false);

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        hireConversionMutation.mutate(
            {
                applicationId,

                employmentTypeId,

                startDate,

                confirmCreateNewPerson,
            },
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="flex flex-wrap items-end gap-2 rounded-md border p-2"
        >
            <div className="space-y-1">
                <label
                    htmlFor={
                        `hire-employment-type-${applicationId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Jenis Pegawai
                </label>

                <Select
                    id={
                        `hire-employment-type-${applicationId}`
                    }
                    value={
                        employmentTypeId
                    }
                    required
                    disabled={
                        employmentTypesQuery.status !== 'success'
                    }
                    onChange={
                        (
                            event,
                        ) =>
                            setEmploymentTypeId(
                                event.target.value,
                            )
                    }
                >
                    <option value="">
                        Pilih…
                    </option>

                    {
                        employmentTypesQuery.status === 'success'
                            ? employmentTypesQuery.data.map(
                                (
                                    employmentType,
                                ) => (
                                    <option
                                        key={
                                            employmentType.id
                                        }
                                        value={
                                            employmentType.id
                                        }
                                    >
                                        {
                                            employmentType.name
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
                    htmlFor={
                        `hire-start-date-${applicationId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Tanggal Mulai
                </label>

                <Input
                    id={
                        `hire-start-date-${applicationId}`
                    }
                    type="date"
                    value={
                        startDate
                    }
                    required
                    onChange={
                        (
                            event,
                        ) =>
                            setStartDate(
                                event.target.value,
                            )
                    }
                />
            </div>

            <label
                htmlFor={
                    `hire-confirm-${applicationId}`
                }
                className="flex items-center gap-1 text-xs text-muted-foreground"
            >
                <input
                    id={
                        `hire-confirm-${applicationId}`
                    }
                    type="checkbox"
                    checked={
                        confirmCreateNewPerson
                    }
                    onChange={
                        (
                            event,
                        ) =>
                            setConfirmCreateNewPerson(
                                event.target.checked,
                            )
                    }
                />

                Konfirmasi buat Person baru bila identitas tidak cocok
            </label>

            <Button
                type="submit"
                size="sm"
                disabled={
                    hireConversionMutation.isPending
                }
            >
                {
                    hireConversionMutation.isPending
                        ? 'Memproses…'
                        : 'Proses Perekrutan'
                }
            </Button>

            {
                hireConversionMutation.isSuccess
                    ? (
                        <p className="w-full text-xs text-muted-foreground">
                            Status konversi: {hireConversionMutation.data.conversion_status}
                        </p>
                    )
                    : null
            }

            {
                hireConversionMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="w-full text-xs text-destructive"
                        >
                            Gagal memproses. Cek identitas Kandidat atau coba konfirmasi buat Person baru.
                        </p>
                    )
                    : null
            }
        </form>
    );
}

const ONBOARDING_CASE_STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary' | 'destructive'
> = {
    NOT_STARTED: 'secondary',
    IN_PROGRESS: 'warning',
    READY_FOR_ACTIVATION: 'success',
    COMPLETED: 'success',
    CANCELLED: 'destructive',
};

const ONBOARDING_CASE_STATUS_LABEL: Record<string, string> = {
    NOT_STARTED: 'Belum Dimulai',
    IN_PROGRESS: 'Berjalan',
    READY_FOR_ACTIVATION: 'Siap Diaktifkan',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
};

const ONBOARDING_TASK_STATUS_LABEL: Record<string, string> = {
    PENDING: 'Menunggu',
    COMPLETED: 'Selesai',
    WAIVED: 'Dikecualikan',
};

function OnboardingTaskRow({
    task,
    onTaskUpdated,
}: {
    task: OnboardingCaseResource['tasks'][number];
    onTaskUpdated: (
        task: OnboardingCaseResource['tasks'][number],
    ) => void;
}) {
    const completeMutation =
        useCompleteOnboardingTaskMutation();

    const waiveMutation =
        useWaiveOnboardingTaskMutation();

    const isPending =
        completeMutation.isPending
        || waiveMutation.isPending;

    return (
        <li className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background p-2 text-xs">
            <div className="flex items-center gap-2">
                <span className="font-medium">
                    {
                        task.title
                    }
                </span>

                <span className="text-muted-foreground">
                    (
                    {
                        task.category
                    }
                    {
                        task.is_required
                            ? ', wajib'
                            : ''
                    }
                    )
                </span>

                <Badge
                    variant={
                        task.status === 'PENDING'
                            ? 'warning'
                            : 'success'
                    }
                >
                    {
                        ONBOARDING_TASK_STATUS_LABEL[
                            task.status
                        ]
                        ?? task.status
                    }
                </Badge>
            </div>

            {
                task.status === 'PENDING'
                    ? (
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                disabled={isPending}
                                onClick={
                                    () =>
                                        completeMutation.mutate(
                                            {
                                                taskId:
                                                    task.id,
                                            },
                                            {
                                                onSuccess:
                                                    onTaskUpdated,
                                            },
                                        )
                                }
                            >
                                Selesaikan
                            </Button>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={isPending}
                                onClick={
                                    () =>
                                        waiveMutation.mutate(
                                            {
                                                taskId:
                                                    task.id,
                                            },
                                            {
                                                onSuccess:
                                                    onTaskUpdated,
                                            },
                                        )
                                }
                            >
                                Kecualikan
                            </Button>
                        </div>
                    )
                    : null
            }
        </li>
    );
}

function OnboardingCaseManager({
    initialCase,
}: {
    initialCase: OnboardingCaseResource;
}) {
    const [
        onboardingCase,
        setOnboardingCase,
    ] = useState(
        initialCase,
    );

    const [
        cancelReason,
        setCancelReason,
    ] = useState('');

    const startMutation =
        useStartOnboardingCaseMutation();

    const cancelMutation =
        useCancelOnboardingCaseMutation();

    function handleTaskUpdated(
        updatedTask: OnboardingCaseResource['tasks'][number],
    ) {
        setOnboardingCase(
            (
                current,
            ) => (
                {
                    ...current,

                    tasks:
                        current.tasks.map(
                            (
                                task,
                            ) =>
                                task.id === updatedTask.id
                                    ? updatedTask
                                    : task,
                        ),
                }
            ),
        );
    }

    function handleCancel(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        cancelMutation.mutate(
            {
                caseId:
                    onboardingCase.id,

                reason:
                    cancelReason,
            },
            {
                onSuccess:
                    setOnboardingCase,
            },
        );
    }

    return (
        <div className="space-y-2 rounded-md border p-2 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="font-medium">
                        Onboarding Case
                    </span>

                    <Badge
                        variant={
                            ONBOARDING_CASE_STATUS_VARIANT[
                                onboardingCase.status
                            ]
                        }
                    >
                        {
                            ONBOARDING_CASE_STATUS_LABEL[
                                onboardingCase.status
                            ]
                            ?? onboardingCase.status
                        }
                    </Badge>
                </div>

                {
                    onboardingCase.status === 'NOT_STARTED'
                        ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={
                                    startMutation.isPending
                                }
                                onClick={
                                    () =>
                                        startMutation.mutate(
                                            {
                                                caseId:
                                                    onboardingCase.id,
                                            },
                                            {
                                                onSuccess:
                                                    setOnboardingCase,
                                            },
                                        )
                                }
                            >
                                Mulai
                            </Button>
                        )
                        : null
                }
            </div>

            {
                onboardingCase.tasks.length > 0
                    ? (
                        <ul className="space-y-1">
                            {
                                onboardingCase.tasks.map(
                                    (
                                        task,
                                    ) => (
                                        <OnboardingTaskRow
                                            key={
                                                task.id
                                            }
                                            task={
                                                task
                                            }
                                            onTaskUpdated={
                                                handleTaskUpdated
                                            }
                                        />
                                    ),
                                )
                            }
                        </ul>
                    )
                    : (
                        <p className="text-muted-foreground">
                            Tidak ada tugas (Template kosong atau tanpa Template).
                        </p>
                    )
            }

            {
                onboardingCase.status !== 'COMPLETED'
                && onboardingCase.status !== 'CANCELLED'
                    ? (
                        <form
                            onSubmit={handleCancel}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <div className="space-y-1">
                                <label
                                    htmlFor={
                                        `onboarding-cancel-reason-${onboardingCase.id}`
                                    }
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Alasan Pembatalan
                                </label>

                                <Input
                                    id={
                                        `onboarding-cancel-reason-${onboardingCase.id}`
                                    }
                                    value={
                                        cancelReason
                                    }
                                    onChange={
                                        (
                                            event,
                                        ) =>
                                            setCancelReason(
                                                event.target.value,
                                            )
                                    }
                                />
                            </div>

                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={
                                    cancelMutation.isPending
                                    || cancelReason.trim() === ''
                                }
                            >
                                Batalkan Onboarding
                            </Button>
                        </form>
                    )
                    : null
            }
        </div>
    );
}

function OnboardingTriggerForm({
    vacancyId,
    applicationId,
}: {
    vacancyId: string;
    applicationId: string;
}) {
    const templatesQuery =
        useOnboardingTemplatesQuery();

    const createCaseMutation =
        useCreateOnboardingCaseMutation(
            vacancyId,
        );

    const [
        templateId,
        setTemplateId,
    ] = useState('');

    function handleClick() {
        createCaseMutation.mutate(
            {
                applicationId,

                templateId:
                    templateId === ''
                        ? null
                        : templateId,
            },
        );
    }

    if (createCaseMutation.isSuccess) {
        return (
            <OnboardingCaseManager
                initialCase={
                    createCaseMutation.data
                }
            />
        );
    }

    return (
        <div className="flex flex-wrap items-end gap-2">
            <div className="space-y-1">
                <label
                    htmlFor={
                        `onboarding-template-${applicationId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Template (opsional)
                </label>

                <Select
                    id={
                        `onboarding-template-${applicationId}`
                    }
                    value={
                        templateId
                    }
                    disabled={
                        templatesQuery.status !== 'success'
                    }
                    onChange={
                        (
                            event,
                        ) =>
                            setTemplateId(
                                event.target.value,
                            )
                    }
                >
                    <option value="">
                        Tanpa Template
                    </option>

                    {
                        templatesQuery.status === 'success'
                            ? templatesQuery.data.map(
                                (
                                    template,
                                ) => (
                                    <option
                                        key={
                                            template.id
                                        }
                                        value={
                                            template.id
                                        }
                                    >
                                        {
                                            template.name
                                        }
                                    </option>
                                ),
                            )
                            : null
                    }
                </Select>
            </div>

            <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={
                    createCaseMutation.isPending
                }
                onClick={
                    handleClick
                }
            >
                {
                    createCaseMutation.isPending
                        ? 'Membuat…'
                        : 'Mulai Onboarding'
                }
            </Button>

            {
                createCaseMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            Gagal membuat Onboarding Case.
                        </p>
                    )
                    : null
            }
        </div>
    );
}

function ApplicationActions({
    vacancyId,
    application,
}: {
    vacancyId: string;
    application: RecruitmentApplicationResource;
}) {
    const startProcessingMutation =
        useStartProcessingRecruitmentApplicationMutation(
            vacancyId,
        );

    const rejectMutation =
        useRejectRecruitmentApplicationMutation(
            vacancyId,
        );

    const withdrawMutation =
        useWithdrawRecruitmentApplicationMutation(
            vacancyId,
        );

    const approveForHiringMutation =
        useApproveForHiringRecruitmentApplicationMutation(
            vacancyId,
        );

    const isPending =
        startProcessingMutation.isPending
        || rejectMutation.isPending
        || withdrawMutation.isPending
        || approveForHiringMutation.isPending;

    if (
        application.status === 'SUBMITTED'
        || application.status === 'IN_PROCESS'
    ) {
        return (
            <div className="flex gap-2">
                {
                    application.status === 'SUBMITTED'
                        ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={isPending}
                                onClick={
                                    () =>
                                        startProcessingMutation.mutate(
                                            {
                                                applicationId:
                                                    application.id,
                                            },
                                        )
                                }
                            >
                                Proses
                            </Button>
                        )
                        : null
                }

                {
                    application.status === 'IN_PROCESS'
                        ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={isPending}
                                onClick={
                                    () =>
                                        approveForHiringMutation.mutate(
                                            {
                                                applicationId:
                                                    application.id,

                                                reason:
                                                    null,
                                            },
                                        )
                                }
                            >
                                Setujui untuk Rekrut
                            </Button>
                        )
                        : null
                }

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            rejectMutation.mutate(
                                {
                                    applicationId:
                                        application.id,

                                    reason:
                                        null,
                                },
                            )
                    }
                >
                    Tolak
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            withdrawMutation.mutate(
                                {
                                    applicationId:
                                        application.id,
                                },
                            )
                    }
                >
                    Tarik
                </Button>
            </div>
        );
    }

    if (application.status === 'HIRING_APPROVED') {
        return (
            <HireConversionForm
                vacancyId={
                    vacancyId
                }
                applicationId={
                    application.id
                }
            />
        );
    }

    if (application.status === 'HIRED') {
        return (
            <OnboardingTriggerForm
                vacancyId={
                    vacancyId
                }
                applicationId={
                    application.id
                }
            />
        );
    }

    return null;
}

function VacancyApplicationsPanel({
    vacancyId,
}: {
    vacancyId: string;
}) {
    const applicationsQuery =
        useRecruitmentApplicationsQuery(
            vacancyId,
        );

    const candidatesQuery =
        useRecruitmentCandidatesQuery();

    const createMutation =
        useCreateRecruitmentApplicationMutation(
            vacancyId,
        );

    const [
        candidateId,
        setCandidateId,
    ] = useState('');

    const candidateNameById =
        new Map(
            candidatesQuery.status === 'success'
                ? candidatesQuery.data.map(
                    (
                        candidate,
                    ) => [
                        candidate.id,
                        candidate.display_name,
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
                candidateId,
            },
            {
                onSuccess: () => {
                    setCandidateId('');
                },
            },
        );
    }

    return (
        <div className="space-y-3 rounded-md border bg-muted/30 p-3">
            <h4 className="text-sm font-semibold">
                Lamaran
            </h4>

            {
                applicationsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-xs text-muted-foreground"
                        >
                            Memuat…
                        </p>
                    )
                    : null
            }

            {
                applicationsQuery.status === 'success'
                    ? (
                        applicationsQuery.data.length === 0
                            ? (
                                <p className="text-xs text-muted-foreground">
                                    Belum ada Lamaran untuk Lowongan ini.
                                </p>
                            )
                            : (
                                <ul className="space-y-2">
                                    {
                                        applicationsQuery.data.map(
                                            (
                                                application,
                                            ) => (
                                                <li
                                                    key={
                                                        application.id
                                                    }
                                                    className="space-y-2 rounded-md border bg-background p-2 text-xs"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">
                                                                {
                                                                    candidateNameById.get(
                                                                        application.candidate_id,
                                                                    )
                                                                    ?? application.candidate_id
                                                                }
                                                            </span>

                                                            <Badge
                                                                variant={
                                                                    APPLICATION_STATUS_VARIANT[
                                                                        application.status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    APPLICATION_STATUS_LABEL[
                                                                        application.status
                                                                    ]
                                                                    ?? application.status
                                                                }
                                                            </Badge>
                                                        </div>
                                                    </div>

                                                    <ApplicationActions
                                                        vacancyId={
                                                            vacancyId
                                                        }
                                                        application={
                                                            application
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
                className="flex flex-wrap items-end gap-2"
            >
                <div className="space-y-1">
                    <label
                        htmlFor={
                            `application-candidate-${vacancyId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Ajukan Kandidat
                    </label>

                    <Select
                        id={
                            `application-candidate-${vacancyId}`
                        }
                        value={
                            candidateId
                        }
                        required
                        disabled={
                            candidatesQuery.status !== 'success'
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setCandidateId(
                                    event.target.value,
                                )
                        }
                    >
                        <option value="">
                            Pilih…
                        </option>

                        {
                            candidatesQuery.status === 'success'
                                ? candidatesQuery.data.map(
                                    (
                                        candidate,
                                    ) => (
                                        <option
                                            key={
                                                candidate.id
                                            }
                                            value={
                                                candidate.id
                                            }
                                        >
                                            {
                                                candidate.display_name
                                            }
                                        </option>
                                    ),
                                )
                                : null
                        }
                    </Select>
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
                            ? 'Mengajukan…'
                            : 'Ajukan'
                    }
                </Button>

                {
                    createMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="w-full text-xs text-destructive"
                            >
                                Gagal mengajukan. Pastikan Lowongan sedang Dibuka.
                            </p>
                        )
                        : null
                }
            </form>
        </div>
    );
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

    const [
        expandedVacancyId,
        setExpandedVacancyId,
    ] = useState<string | null>(
        null,
    );

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
                                                    className="space-y-3 rounded-md border p-3 text-sm"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
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

                                                        <div className="flex items-center gap-2">
                                                            <VacancyActions
                                                                vacancy={
                                                                    vacancy
                                                                }
                                                            />

                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={
                                                                    () =>
                                                                        setExpandedVacancyId(
                                                                            expandedVacancyId === vacancy.id
                                                                                ? null
                                                                                : vacancy.id,
                                                                        )
                                                                }
                                                            >
                                                                {
                                                                    expandedVacancyId === vacancy.id
                                                                        ? 'Tutup Lamaran'
                                                                        : 'Lihat Lamaran'
                                                                }
                                                            </Button>
                                                        </div>
                                                    </div>

                                                    {
                                                        expandedVacancyId === vacancy.id
                                                            ? (
                                                                <VacancyApplicationsPanel
                                                                    vacancyId={
                                                                        vacancy.id
                                                                    }
                                                                />
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

/**
 * §Melengkapi identitas kuat (NIK/Paspor) ke Candidate yang SUDAH
 * ADA — bukan cuma saat pembuatan kandidat baru. Menutup gap yang
 * membuat kandidat yang sudah terlanjur dibuat tanpa identitas
 * SELALU gagal di "Proses Perekrutan": pesan errornya generik
 * ("Cek identitas Kandidat...") dan tidak ada cara memperbaikinya
 * dari UI sebelum komponen ini ada.
 */
function CandidateIdentifierForm({
    candidateId,
}: {
    candidateId: string;
}) {
    const storeIdentifierMutation =
        useStoreRecruitmentCandidateIdentifierMutation();

    const [
        isExpanded,
        setIsExpanded,
    ] = useState(false);

    const [
        type,
        setType,
    ] = useState('NATIONAL_ID');

    const [
        value,
        setValue,
    ] = useState('');

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        storeIdentifierMutation.mutate(
            {
                candidateId,

                type,

                issuingCountryCode:
                    'ID',

                value,
            },
            {
                onSuccess: () => {
                    setValue('');
                },
            },
        );
    }

    if (! isExpanded) {
        return (
            <button
                type="button"
                className="ml-2 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                onClick={
                    () =>
                        setIsExpanded(true)
                }
            >
                + Tambah Identitas
            </button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="mt-2 flex flex-wrap items-end gap-2 border-t pt-2"
        >
            <div className="space-y-1">
                <label
                    htmlFor={
                        `candidate-identifier-type-${candidateId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Jenis
                </label>

                <Select
                    id={
                        `candidate-identifier-type-${candidateId}`
                    }
                    value={type}
                    onChange={
                        (
                            event,
                        ) =>
                            setType(
                                event.target.value,
                            )
                    }
                >
                    <option value="NATIONAL_ID">
                        NIK
                    </option>

                    <option value="PASSPORT">
                        Paspor
                    </option>
                </Select>
            </div>

            <div className="space-y-1">
                <label
                    htmlFor={
                        `candidate-identifier-value-${candidateId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Nomor
                </label>

                <Input
                    id={
                        `candidate-identifier-value-${candidateId}`
                    }
                    value={value}
                    required
                    onChange={
                        (
                            event,
                        ) =>
                            setValue(
                                event.target.value,
                            )
                    }
                />
            </div>

            <Button
                type="submit"
                size="sm"
                disabled={
                    storeIdentifierMutation.isPending
                }
            >
                {
                    storeIdentifierMutation.isPending
                        ? 'Menyimpan…'
                        : 'Simpan Identitas'
                }
            </Button>

            <button
                type="button"
                className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                onClick={
                    () =>
                        setIsExpanded(false)
                }
            >
                Batal
            </button>

            {
                storeIdentifierMutation.isSuccess
                    ? (
                        <p className="w-full text-xs text-muted-foreground">
                            Identitas tersimpan.
                        </p>
                    )
                    : null
            }

            {
                storeIdentifierMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="w-full text-xs text-destructive"
                        >
                            {
                                storeIdentifierMutation.error.kind === 'response'
                                    && storeIdentifierMutation.error.status === 409
                                    ? 'Identitas ini sudah dipakai Kandidat lain.'
                                    : 'Gagal menyimpan identitas. Coba lagi.'
                            }
                        </p>
                    )
                    : null
            }
        </form>
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
        identifierType: 'NATIONAL_ID',
        identifierValue: '',
    });

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        const trimmedIdentifierValue =
            form.identifierValue.trim();

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

                identifiers:
                    trimmedIdentifierValue === ''
                        ? null
                        : [
                            {
                                type:
                                    form.identifierType,

                                issuingCountryCode:
                                    'ID',

                                value:
                                    trimmedIdentifierValue,
                            },
                        ],
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
                            identifierType: 'NATIONAL_ID',
                            identifierValue: '',
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

                                                    <CandidateIdentifierForm
                                                        candidateId={
                                                            candidate.id
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

                    <div className="space-y-1">
                        <label
                            htmlFor="candidate-identifier-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis Identitas (opsional)
                        </label>

                        <Select
                            id="candidate-identifier-type"
                            value={
                                form.identifierType
                            }
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
                        >
                            <option value="NATIONAL_ID">
                                NIK
                            </option>

                            <option value="PASSPORT">
                                Paspor
                            </option>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="candidate-identifier-value"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nomor Identitas (opsional)
                        </label>

                        <Input
                            id="candidate-identifier-value"
                            value={
                                form.identifierValue
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            identifierValue:
                                                event.target.value,
                                        },
                                    )
                            }
                        />

                        <p className="text-xs text-muted-foreground">
                            Wajib diisi sebelum Kandidat bisa diproses
                            jadi Pegawai — boleh dilengkapi belakangan.
                        </p>
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
