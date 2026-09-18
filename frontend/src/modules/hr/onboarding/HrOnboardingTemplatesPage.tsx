import {
    useState,
} from 'react';

import {
    useCreateOnboardingTemplateMutation,
    type CreateOnboardingTemplateTaskInput,
} from '@/modules/hr/api/use-onboarding-template-mutations';
import {
    useOnboardingTemplatesQuery,
} from '@/modules/hr/api/use-onboarding-templates-query';
import {
    Badge,
    Button,
    Input,
    Select,
} from '@/shared/ui';

const CATEGORY_OPTIONS = [
    {
        value: 'DOCUMENT',
        label: 'Dokumen',
    },
    {
        value: 'ORIENTATION',
        label: 'Orientasi',
    },
    {
        value: 'CONTRACT',
        label: 'Kontrak',
    },
    {
        value: 'ADMIN',
        label: 'Administrasi',
    },
] as const;

function emptyTask(
    sequence: number,
): CreateOnboardingTemplateTaskInput {
    return {
        code: '',
        title: '',
        category: 'DOCUMENT',
        sequence,
        isRequired: true,
        requiresEvidence: false,
    };
}

export function HrOnboardingTemplatesPage() {
    const templatesQuery =
        useOnboardingTemplatesQuery();

    const createMutation =
        useCreateOnboardingTemplateMutation();

    const [
        form,
        setForm,
    ] = useState({
        code: '',
        name: '',
    });

    const [
        tasks,
        setTasks,
    ] = useState<
        readonly CreateOnboardingTemplateTaskInput[]
    >(
        [
            emptyTask(1),
        ],
    );

    function updateTask(
        index: number,
        patch: Partial<CreateOnboardingTemplateTaskInput>,
    ) {
        setTasks(
            tasks.map(
                (
                    task,
                    taskIndex,
                ) =>
                    taskIndex === index
                        ? {
                            ...task,
                            ...patch,
                        }
                        : task,
            ),
        );
    }

    function removeTask(
        index: number,
    ) {
        setTasks(
            tasks.filter(
                (
                    _task,
                    taskIndex,
                ) =>
                    taskIndex !== index,
            ),
        );
    }

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                code:
                    form.code,

                name:
                    form.name,

                tasks,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            code: '',
                            name: '',
                        },
                    );

                    setTasks(
                        [
                            emptyTask(1),
                        ],
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="onboarding-templates-heading"
            className="space-y-6"
        >
            <div>
                <h1
                    id="onboarding-templates-heading"
                    className="text-xl font-semibold"
                >
                    Template Onboarding
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Kelola daftar tugas standar untuk proses onboarding pegawai baru.
                </p>
            </div>

            {
                templatesQuery.status === 'pending'
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
                templatesQuery.status === 'success'
                    ? (
                        templatesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Template Onboarding.
                                </p>
                            )
                            : (
                                <ul className="space-y-2">
                                    {
                                        templatesQuery.data.map(
                                            (
                                                template,
                                            ) => (
                                                <li
                                                    key={
                                                        template.id
                                                    }
                                                    className="space-y-2 rounded-md border p-3 text-sm"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">
                                                            {
                                                                template.name
                                                            }
                                                        </span>

                                                        <span className="text-muted-foreground">
                                                            (
                                                            {
                                                                template.code
                                                            }
                                                            )
                                                        </span>

                                                        <Badge
                                                            variant={
                                                                template.is_active
                                                                    ? 'success'
                                                                    : 'secondary'
                                                            }
                                                        >
                                                            {
                                                                template.is_active
                                                                    ? 'Aktif'
                                                                    : 'Nonaktif'
                                                            }
                                                        </Badge>
                                                    </div>

                                                    {
                                                        template.tasks.length > 0
                                                            ? (
                                                                <ul className="space-y-1 pl-4 text-xs text-muted-foreground">
                                                                    {
                                                                        template.tasks.map(
                                                                            (
                                                                                task,
                                                                            ) => (
                                                                                <li
                                                                                    key={
                                                                                        task.id
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        task.sequence
                                                                                    }
                                                                                    {
                                                                                        '. '
                                                                                    }
                                                                                    {
                                                                                        task.title
                                                                                    }
                                                                                    {
                                                                                        ' ('
                                                                                    }
                                                                                    {
                                                                                        task.category
                                                                                    }
                                                                                    {
                                                                                        task.is_required
                                                                                            ? ', wajib'
                                                                                            : ''
                                                                                    }
                                                                                    {
                                                                                        ')'
                                                                                    }
                                                                                </li>
                                                                            ),
                                                                        )
                                                                    }
                                                                </ul>
                                                            )
                                                            : (
                                                                <p className="pl-4 text-xs text-muted-foreground">
                                                                    Tidak ada tugas.
                                                                </p>
                                                            )
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
                className="space-y-4 rounded-md border p-4"
            >
                <h2 className="text-sm font-semibold">
                    Buat Template Baru
                </h2>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1">
                        <label
                            htmlFor="onboarding-template-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode
                        </label>

                        <Input
                            id="onboarding-template-code"
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

                    <div className="space-y-1">
                        <label
                            htmlFor="onboarding-template-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="onboarding-template-name"
                            value={
                                form.name
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            name:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>
                </div>

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h3 className="text-xs font-semibold text-muted-foreground">
                            Daftar Tugas
                        </h3>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={
                                () =>
                                    setTasks(
                                        [
                                            ...tasks,
                                            emptyTask(
                                                tasks.length + 1,
                                            ),
                                        ],
                                    )
                            }
                        >
                            + Tambah Tugas
                        </Button>
                    </div>

                    {
                        tasks.map(
                            (
                                task,
                                index,
                            ) => (
                                <div
                                    key={
                                        index
                                    }
                                    className="grid gap-2 rounded-md border p-2 sm:grid-cols-6"
                                >
                                    <div className="space-y-1 sm:col-span-1">
                                        <label
                                            htmlFor={
                                                `onboarding-task-code-${index}`
                                            }
                                            className="text-xs font-medium text-muted-foreground"
                                        >
                                            Kode
                                        </label>

                                        <Input
                                            id={
                                                `onboarding-task-code-${index}`
                                            }
                                            value={
                                                task.code
                                            }
                                            required
                                            onChange={
                                                (
                                                    event,
                                                ) =>
                                                    updateTask(
                                                        index,
                                                        {
                                                            code:
                                                                event.target.value,
                                                        },
                                                    )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1 sm:col-span-2">
                                        <label
                                            htmlFor={
                                                `onboarding-task-title-${index}`
                                            }
                                            className="text-xs font-medium text-muted-foreground"
                                        >
                                            Judul
                                        </label>

                                        <Input
                                            id={
                                                `onboarding-task-title-${index}`
                                            }
                                            value={
                                                task.title
                                            }
                                            required
                                            onChange={
                                                (
                                                    event,
                                                ) =>
                                                    updateTask(
                                                        index,
                                                        {
                                                            title:
                                                                event.target.value,
                                                        },
                                                    )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1 sm:col-span-1">
                                        <label
                                            htmlFor={
                                                `onboarding-task-category-${index}`
                                            }
                                            className="text-xs font-medium text-muted-foreground"
                                        >
                                            Kategori
                                        </label>

                                        <Select
                                            id={
                                                `onboarding-task-category-${index}`
                                            }
                                            value={
                                                task.category
                                            }
                                            onChange={
                                                (
                                                    event,
                                                ) =>
                                                    updateTask(
                                                        index,
                                                        {
                                                            category:
                                                                event.target.value as CreateOnboardingTemplateTaskInput['category'],
                                                        },
                                                    )
                                            }
                                        >
                                            {
                                                CATEGORY_OPTIONS.map(
                                                    (
                                                        option,
                                                    ) => (
                                                        <option
                                                            key={
                                                                option.value
                                                            }
                                                            value={
                                                                option.value
                                                            }
                                                        >
                                                            {
                                                                option.label
                                                            }
                                                        </option>
                                                    ),
                                                )
                                            }
                                        </Select>
                                    </div>

                                    <div className="space-y-1 sm:col-span-1">
                                        <label
                                            htmlFor={
                                                `onboarding-task-sequence-${index}`
                                            }
                                            className="text-xs font-medium text-muted-foreground"
                                        >
                                            Urutan
                                        </label>

                                        <Input
                                            id={
                                                `onboarding-task-sequence-${index}`
                                            }
                                            type="number"
                                            min={1}
                                            step={1}
                                            value={
                                                task.sequence
                                            }
                                            required
                                            onChange={
                                                (
                                                    event,
                                                ) =>
                                                    updateTask(
                                                        index,
                                                        {
                                                            sequence:
                                                                Number(
                                                                    event.target.value,
                                                                ),
                                                        },
                                                    )
                                            }
                                        />
                                    </div>

                                    <div className="flex items-end justify-between gap-2 sm:col-span-1">
                                        <label className="flex items-center gap-1 text-xs text-muted-foreground">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    task.isRequired
                                                }
                                                onChange={
                                                    (
                                                        event,
                                                    ) =>
                                                        updateTask(
                                                            index,
                                                            {
                                                                isRequired:
                                                                    event.target.checked,
                                                            },
                                                        )
                                                }
                                            />

                                            Wajib
                                        </label>

                                        {
                                            tasks.length > 1
                                                ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={
                                                            () =>
                                                                removeTask(
                                                                    index,
                                                                )
                                                        }
                                                    >
                                                        Hapus
                                                    </Button>
                                                )
                                                : null
                                        }
                                    </div>
                                </div>
                            ),
                        )
                    }
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
                            : 'Simpan Template'
                    }
                </Button>
            </form>
        </section>
    );
}
