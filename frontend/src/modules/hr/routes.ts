import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

/*
 * Canonical HR Workforce route policy.
 *
 * This is static access metadata, not current user authority.
 * CapabilityRuntime remains the owner of runtime permission
 * projection and ProtectedRouteBoundary remains the owner of
 * route-access evaluation.
 *
 * contextRequirement: 'organizational' — HR-013 §6 Target
 * Employee Scope Rule requires an organizational workspace to
 * already be selected; this route never renders under a
 * TENANT-scoped workspace.
 */
export const hrWorkforceRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'hr.workforce.index',

        contextRequirement:
            'organizational',

        authorizationScope:
            'workspace',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'hr.employees.view',
        },
    });

/*
 * Same policy shape as the listing above — the detail
 * endpoint filters from the identical visibility query, so
 * anyone allowed to see the list is allowed to open a
 * detail within it.
 */
export const hrWorkforceDetailRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'hr.workforce.show',

        contextRequirement:
            'organizational',

        authorizationScope:
            'workspace',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'hr.employees.view',
        },
    });

/*
 * Canonical HR Compensation & Benefit search route policy.
 *
 * contextRequirement: 'tenant' — BEDA dengan hrWorkforceRoutePolicy
 * di atas. CompensationAdjustmentController dkk. didaftarkan di
 * backend sebagai TENANT-wide concern (Route::middleware([...])
 * tanpa prefix('v1/hr/workspace')), bukan Organizational Workspace
 * scope — HR-officer tidak perlu memilih Workspace organisasi dulu
 * untuk membuka fitur ini, sama seperti Kelola Organisasi.
 *
 * Permission REUSE hr.employees.view — endpoint yang benar-benar
 * dipanggil halaman pencarian ini (GET /v1/hr/employees) memang
 * digerbang permission itu di backend; sub-halaman employment yang
 * dijangkau dari sini akan punya permission Compensation/Benefit
 * spesifik masing-masing saat dibangun.
 */
export const hrCompensationSearchRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'hr.compensation.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'hr.employees.view',
        },
    });

/*
 * Sub-halaman dari hr.compensation.index — dijangkau lewat tombol
 * "Pilih" di baris pegawai HrCompensationEmployeeSearchPage, BUKAN
 * entri menu navigasi top-level tersendiri. Sama seperti pola
 * settings.organizations.units.index (lihat catatan arsitektur di
 * Modules settings/routes.ts) — TIDAK perlu didaftarkan silang ke
 * application-route-access.ts karena tidak muncul di
 * navigation-definition.ts.
 *
 * Permission reuse hr.employments.view — sama persis dengan yang
 * menggate endpoint backend-nya.
 */
export const hrCompensationEmployeeEmploymentsRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'hr.compensation.employee-employments.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'hr.employments.view',
        },
    });

/*
 * Sub-halaman dari hr.compensation.employee-employments.index —
 * shell Compensation & Benefit untuk satu Employment terpilih
 * (M1 bagian 2). Sub-resource-nya (Compensation Assignment, Benefit
 * Participation/Identifier, Compensation Adjustment) dibangun
 * bertahap di M3–M5; permission masing-masing baru ditambahkan
 * saat sub-resource itu benar-benar ada.
 *
 * Permission reuse hr.employments.view untuk sekarang — shell ini
 * belum memanggil endpoint Compensation/Benefit apa pun, cuma
 * menampilkan konteks Employment yang sudah dipilih di halaman
 * sebelumnya.
 */
export const hrCompensationEmploymentShellRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'hr.compensation.employment-shell.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'hr.employments.view',
        },
    });

/*
 * Public route contribution owned by the HR module.
 *
 * The application composes this structural contract without
 * the module depending on app/router.tsx.
 */
export const hrRouteContributions = [
    {
        routeId:
            'hr.workforce.index',

        /*
         * Nested beneath the authenticated application shell,
         * therefore this is intentionally relative.
         */
        path:
            'hr/workforce',

        accessPolicy:
            hrWorkforceRoutePolicy,

        /*
         * Keep the page implementation outside the initial
         * application bundle. Static route and policy metadata
         * remain available during router composition.
         */
        lazy:
            async () => {
                const {
                    HrWorkforcePage,
                } =
                    await import(
                        '@/modules/hr/workforce/HrWorkforcePage'
                    );

                return {
                    Component:
                        HrWorkforcePage,
                };
            },
    },

    {
        routeId:
            'hr.workforce.show',

        path:
            'hr/workforce/:employeeId',

        accessPolicy:
            hrWorkforceDetailRoutePolicy,

        lazy:
            async () => {
                const {
                    HrEmployeeDetailPage,
                } =
                    await import(
                        '@/modules/hr/workforce/HrEmployeeDetailPage'
                    );

                return {
                    Component:
                        HrEmployeeDetailPage,
                };
            },
    },

    {
        routeId:
            'hr.compensation.index',

        path:
            'hr/compensation',

        accessPolicy:
            hrCompensationSearchRoutePolicy,

        lazy:
            async () => {
                const {
                    HrCompensationEmployeeSearchPage,
                } =
                    await import(
                        '@/modules/hr/compensation/HrCompensationEmployeeSearchPage'
                    );

                return {
                    Component:
                        HrCompensationEmployeeSearchPage,
                };
            },
    },

    {
        routeId:
            'hr.compensation.employee-employments.index',

        path:
            'hr/compensation/employees/:employeeId/employments',

        accessPolicy:
            hrCompensationEmployeeEmploymentsRoutePolicy,

        lazy:
            async () => {
                const {
                    HrCompensationEmployeeEmploymentsPage,
                } =
                    await import(
                        '@/modules/hr/compensation/HrCompensationEmployeeEmploymentsPage'
                    );

                return {
                    Component:
                        HrCompensationEmployeeEmploymentsPage,
                };
            },
    },

    {
        routeId:
            'hr.compensation.employment-shell.index',

        path:
            'hr/compensation/employments/:employmentId',

        accessPolicy:
            hrCompensationEmploymentShellRoutePolicy,

        lazy:
            async () => {
                const {
                    HrCompensationEmploymentShellPage,
                } =
                    await import(
                        '@/modules/hr/compensation/HrCompensationEmploymentShellPage'
                    );

                return {
                    Component:
                        HrCompensationEmploymentShellPage,
                };
            },
    },
] as const;
