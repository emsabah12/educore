import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Pencil } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Day = {
    day: number;
    name: string;
    employees: { id: string; name: string }[];
};

type Props = {
    organizations: Option[];
    organizationId: string;
    years: Option[];
    yearId: string | null;
    days: Day[];
    employees: Option[];
    max: number;
};

const BASE_URL = '/akademik/jadwal-piket';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Jadwal piket per lembaga & tahun ajaran (PRD-002 §20.2, OD-AC-13): guru piket tiap hari, yang
 * disarankan pertama sebagai guru pengganti dan boleh mencatat presensi mengajar hari itu.
 */
export default function DutyRoster(props: Props) {
    const go = (query: { lembaga?: string; tahun?: string }) =>
        router.get(
            BASE_URL,
            {
                lembaga: query.lembaga ?? props.organizationId,
                ...(query.lembaga
                    ? {}
                    : { tahun: query.tahun ?? props.yearId ?? '' }),
            },
            { preserveState: false, replace: true },
        );

    return (
        <>
            <Head title="Jadwal piket" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link
                        href={`/akademik/presensi-mengajar?lembaga=${props.organizationId}`}
                    >
                        <ArrowLeft />
                        Presensi mengajar
                    </Link>
                </Button>

                <Heading
                    title="Jadwal piket"
                    description="Guru piket tiap hari di lembaga ini. Guru piket hari itu bisa mencatat presensi mengajar dan disarankan pertama sebagai guru pengganti."
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            className={selectClass}
                            value={props.organizationId}
                            onChange={(event) =>
                                go({ lembaga: event.target.value })
                            }
                        >
                            {props.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="tahun">Tahun ajaran</Label>
                        <select
                            id="tahun"
                            className={selectClass}
                            value={props.yearId ?? ''}
                            disabled={props.years.length === 0}
                            onChange={(event) =>
                                go({ tahun: event.target.value })
                            }
                        >
                            {props.years.length === 0 && (
                                <option value="">Belum ada</option>
                            )}
                            {props.years.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {props.yearId === null ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Lembaga ini belum punya tahun ajaran yang berjalan atau
                        akan datang. Tambahkan di Kalender akademik.
                    </p>
                ) : props.employees.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada pegawai aktif yang ditempatkan di lembaga ini.
                    </p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                        {props.days.map((day) => (
                            <DayCard
                                key={day.day}
                                day={day}
                                organizationId={props.organizationId}
                                yearId={props.yearId as string}
                                employees={props.employees}
                                max={props.max}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function DayCard({
    day,
    organizationId,
    yearId,
    employees,
    max,
}: {
    day: Day;
    organizationId: string;
    yearId: string;
    employees: Option[];
    max: number;
}) {
    const [editing, setEditing] = useState(false);
    const selected = new Set(day.employees.map((item) => item.id));

    return (
        <li className="space-y-2 rounded-xl border p-4 text-sm">
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-medium">{day.name}</h2>
                {!editing && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Atur
                    </Button>
                )}
            </div>

            {!editing ? (
                day.employees.length === 0 ? (
                    <p className="text-muted-foreground">
                        Belum ada guru piket.
                    </p>
                ) : (
                    <ul className="list-inside list-disc">
                        {day.employees.map((employee) => (
                            <li key={employee.id}>{employee.name}</li>
                        ))}
                    </ul>
                )
            ) : (
                <Form
                    action={BASE_URL}
                    method="post"
                    errorBag={`duty-${day.day}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="organization_id"
                                value={organizationId}
                            />
                            <input
                                type="hidden"
                                name="academic_year_id"
                                value={yearId}
                            />
                            <input type="hidden" name="day" value={day.day} />
                            <div className="max-h-64 space-y-1 overflow-y-auto rounded-md border p-2">
                                {employees.map((employee) => (
                                    <label
                                        key={employee.value}
                                        className="flex cursor-pointer items-center gap-2 rounded px-1 py-1 hover:bg-muted"
                                    >
                                        <input
                                            type="checkbox"
                                            name="employee_ids[]"
                                            value={employee.value}
                                            defaultChecked={selected.has(
                                                employee.value,
                                            )}
                                            className="size-4 accent-primary"
                                        />
                                        {employee.label}
                                    </label>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Paling banyak {max} guru per hari. Kosongkan
                                semua untuk menghapus piket hari ini.
                            </p>
                            <InputError
                                message={
                                    errors.employee_ids ??
                                    errors['employee_ids.0'] ??
                                    errors.academic_year_id ??
                                    errors.form
                                }
                            />
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2">
                                <Button type="submit" disabled={processing}>
                                    Simpan
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditing(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}
