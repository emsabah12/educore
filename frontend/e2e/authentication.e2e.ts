import {
    execFile,
} from 'node:child_process';

import {
    expect,
    test,
} from '@playwright/test';

import {
    armContextRaceResponseGate,
    releaseContextRaceResponseGate,
    resetContextRaceResponseGate,
    waitForContextRaceResponseCapture,
    waitForContextRaceResponseReleaseAcknowledgement,
} from './support/context-race-response-gate.ts';

const e2eMembershipId =
    '019c8f4a-7b10-7000-8000-000000000004';

const e2eTenantId =
    '019c8f4a-7b10-7000-8000-000000000003';

const e2eTenantName =
    'EduCore Browser E2E Tenant';

const e2eSecondTenantId =
    '019c8f4a-7b10-7000-8000-000000000005';

const e2eSecondMembershipId =
    '019c8f4a-7b10-7000-8000-000000000006';

const e2eSecondTenantName =
    'EduCore Browser E2E Tenant Secondary';

const e2eOrganizationId =
    '019c8f4a-7b10-7000-8000-000000000007';

const e2eOrganizationalAssignmentId =
    '019c8f4a-7b10-7000-8000-000000000008';

const e2eOrganizationName =
    'EduCore Browser E2E Organization';

const workspaceRestorationStorageKey =
    'educore.workspace-restoration.v1';

const canonicalE2ESeeder =
    'Database\\Seeders\\E2EBrowserAuthenticationSeeder';

const staleWorkspaceE2ESeeder =
    'Database\\Seeders\\E2EStaleWorkspaceSeeder';

const browserSessionInvalidationE2ESeeder =
    'Database\\Seeders\\E2EBrowserSessionInvalidationSeeder';

const membershipRestorationStorageKey =
    'educore.membership-restoration.v1';

const e2eEmail =
    'browser-e2e@educore.test';

const e2ePassword =
    'E2eOnly-Secret123!';

const applicationOrigin =
    'http://127.0.0.1:5173';

function runE2ESeeder(
    seederClass:
        string,
): Promise<void> {
    return new Promise(
        (
            resolve,
            reject,
        ) => {
            execFile(
                'php',
                [
                    'artisan',
                    'db:seed',
                    '--env=e2e',
                    `--class=${seederClass}`,
                ],
                {
                    cwd:
                        process.cwd(),
                },
                (
                    error,
                    stdout,
                    stderr,
                ) => {
                    if (
                        error
                            === null
                    ) {
                        resolve();

                        return;
                    }

                    reject(
                        new Error(
                            [
                                `E2E seeder failed: ${seederClass}`,
                                `exit: ${error.code ?? 'unknown'}`,
                                stdout.trim(),
                                stderr.trim(),
                            ]
                                .filter(
                                    (
                                        part,
                                    ) =>
                                        part
                                            !== '',
                                )
                                .join(
                                    '\n',
                                ),
                            {
                                cause:
                                    error,
                            },
                        ),
                    );
                },
            );
        },
    );
}

function matchesApplicationApiPath(
    responseUrl: string,
    pathname: string,
): boolean {
    const url =
        new URL(
            responseUrl,
        );

    return (
        url.origin
            === applicationOrigin
        && url.pathname
            === pathname
    );
}

test(
    'real anonymous browser resolves canonical Membership context before becoming authoritative login state',
    async ({
        page,
    }) => {
        const authenticationContextResponse =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    ),
            );

        const membershipDiscoveryResponse =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    ),
            );

        await page.goto(
            '/login',
        );

        const contextResponse =
            await authenticationContextResponse;

        expect(
            contextResponse.status(),
        ).toBe(
            403,
        );

        const contextBody:
            unknown =
            await contextResponse.json();

        expect(
            contextBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
        });

        expect(
            contextResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        const discoveryResponse =
            await membershipDiscoveryResponse;

        expect(
            discoveryResponse.status(),
        ).toBe(
            401,
        );

        const discoveryBody:
            unknown =
            await discoveryResponse.json();

        expect(
            discoveryBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
        });

        expect(
            discoveryResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await expect(
            page.getByLabel(
                'Email',
            ),
        ).toBeEnabled();

        await expect(
            page.getByLabel(
                'Password',
            ),
        ).toBeEnabled();

        await expect(
            page.getByLabel(
                'Tenant UUID',
            ),
        ).toBeEnabled();

        await expect(
            page.getByText(
                'Permintaan masuk tidak dapat diproses. Silakan coba lagi.',
            ),
        ).toHaveCount(
            0,
        );

        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/login',
        );

        expect(
            finalUrl.searchParams.get(
                'returnTo',
            ),
        ).toBe(
            '/',
        );
    },
);

test(
    'real browser authenticates through BrowserSession and reaches the protected Tenant application',
    async ({
        context,
        page,
    }) => {
        /*
         * First establish authoritative anonymous truth.
         *
         * Starting every test with a fresh Playwright
         * BrowserContext proves that persisted E2E database
         * fixture data is not itself browser authority.
         */
        await page.goto(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await expect(
            page.getByLabel(
                'Email',
            ),
        ).toBeEnabled();

        /*
         * Exercise the real controlled LoginForm instead of
         * issuing API requests directly from Playwright.
         */
        await page
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await page
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await page
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        /*
         * Register all network observers before submission.
         *
         * Header predicates distinguish post-login canonical
         * requests from the initial anonymous bootstrap.
         */
        const loginResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/auth/login',
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        const authenticatedContextResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const membershipDiscoveryResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response.status()
                        === 200,
            );

        const workspaceDiscoveryResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const capabilityResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/core/authorization/capabilities',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await page
            .getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            )
            .click();

        const [
            loginResponse,
            authenticatedContextResponse,
            membershipDiscoveryResponse,
            workspaceDiscoveryResponse,
            capabilityResponse,
        ] =
            await Promise.all([
                loginResponsePromise,
                authenticatedContextResponsePromise,
                membershipDiscoveryResponsePromise,
                workspaceDiscoveryResponsePromise,
                capabilityResponsePromise,
            ]);

        /*
         * Browser login returns only safe context.
         *
         * Canonical bearer credential remains inside the
         * server-side BrowserSession vault.
         */
        expect(
            loginResponse.status(),
        ).toBe(
            200,
        );

        const loginBody:
            unknown =
            await loginResponse.json();

        expect(
            loginBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                membership_id:
                    e2eMembershipId,

                tenant_id:
                    e2eTenantId,
            },
        });

        expect(
            JSON.stringify(
                loginBody,
            ),
        ).not.toContain(
            'access_token',
        );

        /*
         * Successful login is not accepted until canonical
         * /auth/me confirms the server-held credential.
         */
        expect(
            authenticatedContextResponse.status(),
        ).toBe(
            200,
        );

        const authenticatedContextBody:
            unknown =
            await authenticatedContextResponse.json();

        expect(
            authenticatedContextBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                user: {
                    email:
                        e2eEmail,
                },

                membership: {
                    id:
                        e2eMembershipId,

                    status:
                        'ACTIVE',
                },

                tenant: {
                    id:
                        e2eTenantId,
                },
            },
        });

        expect(
            membershipDiscoveryResponse.status(),
        ).toBe(
            200,
        );

        expect(
            workspaceDiscoveryResponse.status(),
        ).toBe(
            200,
        );

        const workspaceBody:
            unknown =
            await workspaceDiscoveryResponse.json();

        expect(
            workspaceBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                tenant: {
                    id:
                        e2eTenantId,
                },
            },
        });

        /*
         * An empty RBAC catalog is valid for the current
         * permission-free protected application shell.
         */
        expect(
            capabilityResponse.status(),
        ).toBe(
            200,
        );

        const capabilityBody:
            unknown =
            await capabilityResponse.json();

        expect(
            capabilityBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                scope: {
                    type:
                        'tenant',

                    tenant_id:
                        e2eTenantId,

                    membership_id:
                        e2eMembershipId,
                },

                is_global_superadmin:
                    false,

                permissions:
                    [],
            },
        });

        /*
         * None of the real browser requests may manufacture
         * JavaScript-owned canonical Bearer authority.
         */
        for (
            const response
            of [
                loginResponse,
                authenticatedContextResponse,
                membershipDiscoveryResponse,
                workspaceDiscoveryResponse,
                capabilityResponse,
            ]
        ) {
            expect(
                response
                    .request()
                    .headers()[
                        'authorization'
                    ],
            ).toBeUndefined();
        }

        /*
         * Root protected application requires canonical
         * Auth + Membership + TENANT Workspace readiness,
         * but no additional permission.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        /*
        * FEI-11 authenticated application chrome must be a
        * consequence of the same canonical authority that allowed
        * the protected route.
        *
        * These are real production components rendered by real
        * Chromium. No shell, navigation, or authority projection
        * is mocked in this E2E suite.
        */
        const activeUserContext =
            page.getByLabel(
                'Konteks pengguna aktif',
            );

        await expect(
            activeUserContext,
        ).toBeVisible();

        await expect(
            activeUserContext.getByText(
                'EduCore Browser E2E User',
            ),
        ).toBeVisible();

        await expect(
            activeUserContext.getByText(
                'browser-e2e@educore.test',
            ),
        ).toBeVisible();

        await expect(
            activeUserContext.getByText(
                /^Workspace:\s+/u,
            ),
        ).toBeVisible();

        /*
        * Canonical navigation currently exposes only the real
        * registered root destination.
        *
        * Navigation visibility remains presentation only;
        * ProtectedRouteBoundary is still the security boundary.
        */
        const primaryNavigation =
            page.getByRole(
                'navigation',
                {
                    name:
                        'Navigasi utama',
                },
            );

        await expect(
            primaryNavigation,
        ).toBeVisible();

        const homeLink =
            primaryNavigation.getByRole(
                'link',
                {
                    name:
                        'Beranda',
                },
            );

        await expect(
            homeLink,
        ).toHaveAttribute(
            'href',
            '/',
        );

        await expect(
            homeLink,
        ).toHaveAttribute(
            'aria-current',
            'page',
        );

        await expect(
            primaryNavigation.getByRole(
                'link',
            ),
        ).toHaveCount(
            1,
        );

        /*
        * The protected page must be projected through the
        * authenticated shell's canonical main landmark rather
        * than rendering beside or outside the shell.
        */
        const mainContent =
            page.getByRole(
                'main',
            );

        await expect(
            mainContent,
        ).toHaveAttribute(
            'id',
            'main-content',
        );

        await expect(
            mainContent.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        await expect(
            page.getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            ),
        ).toBeVisible();

        expect(
            new URL(
                page.url(),
            ).pathname,
        ).toBe(
            '/',
        );

        /*
        * FEI-11 responsive shell must retain authenticated
        * context and navigation at a real narrow browser
        * viewport.
        *
        * Reuse the already-authoritative BrowserSession rather
        * than performing a second credential flow merely to
        * exercise responsive CSS.
        */
        const originalViewport =
            page.viewportSize();

        if (
            originalViewport
                === null
        ) {
            throw new Error(
                'Expected the Chromium E2E project to expose a viewport.',
            );
        }

        try {
            await page.setViewportSize({
                width:
                    390,

                height:
                    844,
            });

            expect(
                page.viewportSize(),
            ).toEqual({
                width:
                    390,

                height:
                    844,
            });

            /*
            * Person identity and Workspace context must not
            * disappear at the mobile breakpoint.
            */
            await expect(
                activeUserContext,
            ).toBeVisible();

            await expect(
                activeUserContext.getByText(
                    'EduCore Browser E2E User',
                ),
            ).toBeVisible();

            await expect(
                activeUserContext.getByText(
                    'browser-e2e@educore.test',
                ),
            ).toBeVisible();

            await expect(
                activeUserContext.getByText(
                    /^Workspace:\s+/u,
                ),
            ).toBeVisible();

            /*
            * Global authenticated controls remain available on
            * the narrow shell.
            */
            await expect(
                page.getByRole(
                    'button',
                    {
                        name:
                            'Keluar',
                    },
                ),
            ).toBeVisible();

            await expect(
                primaryNavigation,
            ).toBeVisible();

            await expect(
                homeLink,
            ).toBeVisible();

            await expect(
                homeLink,
            ).toHaveAttribute(
                'aria-current',
                'page',
            );

            await expect(
                mainContent,
            ).toBeVisible();

            /*
            * ApplicationNavigation deliberately owns a
            * non-wrapping intrinsic row while its shell parent
            * owns horizontal scrolling.
            *
            * With only one production item there may be nothing
            * to scroll yet, so assert the real CSS containment
            * contract rather than inventing fake routes.
            */
            const navigationLayout =
                await primaryNavigation.evaluate(
                    (
                        navigation,
                    ) => {
                        const scrollRegion =
                            navigation.parentElement;

                        if (
                            scrollRegion
                                === null
                        ) {
                            throw new Error(
                                'Expected primary navigation to have a responsive scroll container.',
                            );
                        }

                        return {
                            overflowX:
                                window
                                    .getComputedStyle(
                                        scrollRegion,
                                    )
                                    .overflowX,

                            scrollRegionClientWidth:
                                scrollRegion
                                    .clientWidth,

                            scrollRegionScrollWidth:
                                scrollRegion
                                    .scrollWidth,
                        };
                    },
                );

            expect(
                navigationLayout
                    .overflowX,
            ).toBe(
                'auto',
            );

            expect(
                navigationLayout
                    .scrollRegionClientWidth,
            ).toBeGreaterThan(
                0,
            );

            expect(
                navigationLayout
                    .scrollRegionScrollWidth,
            ).toBeGreaterThanOrEqual(
                navigationLayout
                    .scrollRegionClientWidth,
            );

            /*
            * Horizontal navigation overflow must remain inside
            * its dedicated scroll region rather than expanding
            * the complete document beyond the mobile viewport.
            */
            const documentLayout =
                await page.evaluate(
                    () => ({
                        viewportWidth:
                            window.innerWidth,

                        documentScrollWidth:
                            document
                                .documentElement
                                .scrollWidth,
                    }),
                );

            expect(
                documentLayout
                    .viewportWidth,
            ).toBe(
                390,
            );

            expect(
                documentLayout
                    .documentScrollWidth,
            ).toBeLessThanOrEqual(
                documentLayout
                    .viewportWidth,
            );

            /*
            * The canonical navigation entry retains the
            * production min-h-10 touch/focus target in a real
            * browser layout.
            */
            const homeLinkBox =
                await homeLink.boundingBox();

            if (
                homeLinkBox
                    === null
            ) {
                throw new Error(
                    'Expected the visible Beranda navigation link to have a browser layout box.',
                );
            }

            expect(
                homeLinkBox.height,
            ).toBeGreaterThanOrEqual(
                40,
            );
        } finally {
            /*
            * Keep every assertion that follows in the existing
            * authenticated test independent of this temporary
            * responsive viewport check.
            */
            await page.setViewportSize(
                originalViewport,
            );
        }

        /*
        * FEI-11 keyboard accessibility must work in a real
        * browser, not only exist as semantic markup.
        *
        * Reset incidental focus left by the preceding
        * authentication/responsive interaction before exercising
        * the document's natural Tab order.
        */
        await page.evaluate(
            () => {
                const activeElement =
                    document.activeElement;

                if (
                    activeElement
                        instanceof HTMLElement
                ) {
                    activeElement.blur();
                }
            },
        );

        await expect
            .poll(
                () =>
                    page.evaluate(
                        () =>
                            document.activeElement
                                === document.body,
                    ),
            )
            .toBe(
                true,
            );

        const skipToMainLink =
            page.getByRole(
                'link',
                {
                    name:
                        'Lewati ke konten utama',
                },
            );

        /*
        * The skip link is intentionally the first keyboard
        * control in the authenticated shell.
        */
        await page.keyboard.press(
            'Tab',
        );

        await expect(
            skipToMainLink,
        ).toBeFocused();

        /*
        * sr-only gives the unfocused link a 1×1 clipped layout.
        * Once focus:not-sr-only takes effect, the keyboard user
        * must receive a genuine visible control.
        */
        const focusedSkipLinkBox =
            await skipToMainLink.boundingBox();

        if (
            focusedSkipLinkBox
                === null
        ) {
            throw new Error(
                'Expected the focused skip link to have a real browser layout box.',
            );
        }

        expect(
            focusedSkipLinkBox.width,
        ).toBeGreaterThan(
            1,
        );

        expect(
            focusedSkipLinkBox.height,
        ).toBeGreaterThan(
            1,
        );

        /*
        * Activate through the keyboard exactly as a user would.
        */
        await page.keyboard.press(
            'Enter',
        );

        await expect
            .poll(
                () =>
                    new URL(
                        page.url(),
                    ).hash,
            )
            .toBe(
                '#main-content',
            );

        /*
        * The target carries tabIndex=-1 specifically so native
        * fragment navigation can transfer focus without adding
        * main to the normal Tab sequence.
        */
        await expect(
            mainContent,
        ).toBeFocused();

        await expect(
            mainContent,
        ).toHaveAttribute(
            'id',
            'main-content',
        );

        await expect(
            mainContent,
        ).toHaveAttribute(
            'tabindex',
            '-1',
        );

        /*
        * Keep later assertions in this existing authentication
        * scenario independent of the temporary fragment URL and
        * focus state introduced by this accessibility proof.
        */
        await page.evaluate(
            () => {
                window.history.replaceState(
                    window.history.state,
                    '',
                    `${window.location.pathname}${window.location.search}`,
                );

                const activeElement =
                    document.activeElement;

                if (
                    activeElement
                        instanceof HTMLElement
                ) {
                    activeElement.blur();
                }
            },
        );

        expect(
            new URL(
                page.url(),
            ).hash,
        ).toBe(
            '',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toHaveCount(
            0,
        );

        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/',
        );

        /*
         * Playwright may inspect HttpOnly metadata from the
         * browser process even though page JavaScript cannot
         * read the session cookie.
         */
        const cookies =
            await context.cookies();

        const sessionCookie =
            cookies.find(
                (cookie) =>
                    cookie.name
                        === 'laravel-session',
            );

        expect(
            sessionCookie,
        ).toBeDefined();

        expect(
            sessionCookie?.httpOnly,
        ).toBe(
            true,
        );

        expect(
            sessionCookie?.sameSite,
        ).toBe(
            'Lax',
        );

        /*
         * HttpOnly must make the canonical Laravel session
         * identifier invisible to browser JavaScript.
         */
        const visibleCookies =
            await page.evaluate(
                () =>
                    document.cookie,
            );

        expect(
            visibleCookies,
        ).not.toContain(
            'laravel-session=',
        );

        /*
         * Browser storage may contain context restoration
         * hints, but never passwords or Bearer credentials.
         */
        const browserStorage =
            await page.evaluate(
                () => ({
                    localStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.localStorage,
                            ),
                        ),

                    sessionStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.sessionStorage,
                            ),
                        ),
                }),
            );

        const serializedStorage =
            JSON.stringify(
                browserStorage,
            );

        expect(
            serializedStorage,
        ).not.toContain(
            e2ePassword,
        );

        expect(
            serializedStorage,
        ).not.toContain(
            'access_token',
        );

        expect(
            serializedStorage,
        ).not.toMatch(
            /Bearer\s+/iu,
        );
    },

);

test(
    'real BrowserSession remains authoritative after a full page reload without credential replay',
    async ({
                page,
            }) => {
                await page.goto(
                    '/login',
                );

                await expect(
                    page.getByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                    ),
                ).toBeVisible();

                await page
                    .getByLabel(
                        'Email',
                    )
                    .fill(
                        e2eEmail,
                    );

                await page
                    .getByLabel(
                        'Password',
                    )
                    .fill(
                        e2ePassword,
                    );

                await page
                    .getByLabel(
                        'Tenant UUID',
                    )
                    .fill(
                        e2eTenantId,
                    );

                let loginRequestCount =
                    0;

                page.on(
                    'request',
                    (request) => {
                        if (
                            matchesApplicationApiPath(
                                request.url(),
                                '/api/v1/browser/auth/login',
                            )
                            && request.method()
                                === 'POST'
                        ) {
                            loginRequestCount +=
                                1;
                        }
                    },
                );

                /*
                 * Establish the successful-login precondition
                 * through authoritative backend milestones
                 * before testing reload persistence.
                 *
                 * Do not rely on an arbitrary UI timeout:
                 * the real Auth -> Membership -> Workspace
                 * lifecycle may legitimately take longer than
                 * the default assertion timeout.
                 */
                const loginResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/browser/auth/login',
                            )
                            && response
                                .request()
                                .method()
                                === 'POST',
                    );

                const authenticatedContextResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/auth/me',
                            )
                            && response
                                .request()
                                .headers()[
                                    'x-educore-membership-id'
                                ]
                                === e2eMembershipId,
                    );

                const membershipDiscoveryResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/user/my-memberships',
                            )
                            && response.status()
                                === 200,
                    );

                const workspaceDiscoveryResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/user/my-workspaces',
                            )
                            && response
                                .request()
                                .headers()[
                                    'x-educore-membership-id'
                                ]
                                === e2eMembershipId,
                    );

                await page
                    .getByRole(
                        'button',
                        {
                            name:
                                'Masuk',
                        },
                    )
                    .click();

                const [
                    loginResponse,
                    authenticatedContextResponse,
                    membershipDiscoveryResponse,
                    workspaceDiscoveryResponse,
                ] =
                    await Promise.all([
                        loginResponsePromise,
                        authenticatedContextResponsePromise,
                        membershipDiscoveryResponsePromise,
                        workspaceDiscoveryResponsePromise,
                    ]);

                expect(
                    loginResponse.status(),
                ).toBe(
                    200,
                );

                expect(
                    authenticatedContextResponse.status(),
                ).toBe(
                    200,
                );

                expect(
                    membershipDiscoveryResponse.status(),
                ).toBe(
                    200,
                );

                expect(
                    workspaceDiscoveryResponse.status(),
                ).toBe(
                    200,
                );

                expect(
                    loginRequestCount,
                ).toBe(
                    1,
                );

                await expect(
                    page.getByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).toBeVisible();

                /*
                * Register reload lifecycle observers before the
                * browser performs the full document navigation.
                *
                * The new React runtime must recover authentication
                * exclusively from the HttpOnly BrowserSession.
                */
                const reloadedAuthenticationResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/auth/me',
                            )
                            && response
                                .request()
                                .headers()[
                                    'x-educore-membership-id'
                                ]
                                === e2eMembershipId,
                    );

                const reloadedMembershipResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/user/my-memberships',
                            )
                            && response.status()
                                === 200,
                    );

                const reloadedWorkspaceResponsePromise =
                    page.waitForResponse(
                        (response) =>
                            matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/user/my-workspaces',
                            )
                            && response
                                .request()
                                .headers()[
                                    'x-educore-membership-id'
                                ]
                                === e2eMembershipId,
                    );

                await page.reload();

                const [
                    authenticationResponse,
                    membershipResponse,
                    workspaceResponse,
                ] =
                    await Promise.all([
                        reloadedAuthenticationResponsePromise,
                        reloadedMembershipResponsePromise,
                        reloadedWorkspaceResponsePromise,
                    ]);

                expect(
                    authenticationResponse.status(),
                ).toBe(
                    200,
                );

                expect(
                    membershipResponse.status(),
                ).toBe(
                    200,
                );

                expect(
                    workspaceResponse.status(),
                ).toBe(
                    200,
                );

                /*
                * Reload must never cause the browser to replay the
                * login credential flow.
                */
                expect(
                    loginRequestCount,
                ).toBe(
                    1,
                );

                for (
                    const response
                    of [
                        authenticationResponse,
                        membershipResponse,
                        workspaceResponse,
                    ]
                ) {
                    expect(
                        response
                            .request()
                            .headers()[
                                'authorization'
                            ],
                    ).toBeUndefined();
                }

                await expect(
                    page.getByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).toBeVisible();

                /*
                * FEI-11 authenticated application chrome must be rebuilt
                * from the restored canonical BrowserSession authority
                * after a complete document reload.
                *
                * Nothing from the previous React component tree survives
                * page.reload(), so these assertions prove actual shell
                * reconstruction rather than retained in-memory UI.
                */
                const reloadedActiveUserContext =
                    page.getByLabel(
                        'Konteks pengguna aktif',
                    );

                await expect(
                    reloadedActiveUserContext,
                ).toBeVisible();

                await expect(
                    reloadedActiveUserContext.getByText(
                        'EduCore Browser E2E User',
                    ),
                ).toBeVisible();

                await expect(
                    reloadedActiveUserContext.getByText(
                        e2eEmail,
                    ),
                ).toBeVisible();

                await expect(
                    reloadedActiveUserContext.getByText(
                        /^Workspace:\s+/u,
                    ),
                ).toBeVisible();

                /*
                * Navigation must be recomputed from the newly restored
                * authority snapshot rather than surviving from the
                * pre-reload React tree.
                */
                const reloadedPrimaryNavigation =
                    page.getByRole(
                        'navigation',
                        {
                            name:
                                'Navigasi utama',
                        },
                    );

                await expect(
                    reloadedPrimaryNavigation,
                ).toBeVisible();

                const reloadedHomeLink =
                    reloadedPrimaryNavigation.getByRole(
                        'link',
                        {
                            name:
                                'Beranda',
                        },
                    );

                await expect(
                    reloadedHomeLink,
                ).toHaveAttribute(
                    'href',
                    '/',
                );

                await expect(
                    reloadedHomeLink,
                ).toHaveAttribute(
                    'aria-current',
                    'page',
                );

                await expect(
                    reloadedPrimaryNavigation.getByRole(
                        'link',
                    ),
                ).toHaveCount(
                    1,
                );

                /*
                * Page content must again live inside the canonical shell
                * main landmark after the new document/runtime has been
                * created.
                */
                const reloadedMainContent =
                    page.getByRole(
                        'main',
                    );

                await expect(
                    reloadedMainContent,
                ).toHaveAttribute(
                    'id',
                    'main-content',
                );

                await expect(
                    reloadedMainContent,
                ).toHaveAttribute(
                    'tabindex',
                    '-1',
                );

                await expect(
                    reloadedMainContent.getByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).toBeVisible();

                /*
                * Global shell controls and accessibility navigation are
                * also reconstructed from the new authenticated tree.
                */
                await expect(
                    page.getByRole(
                        'button',
                        {
                            name:
                                'Keluar',
                        },
                    ),
                ).toBeVisible();

                await expect(
                    page.getByRole(
                        'link',
                        {
                            name:
                                'Lewati ke konten utama',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '#main-content',
                );

                await expect(
                    page.getByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                    ),
                ).toHaveCount(
                    0,
                );

                const finalUrl =
                    new URL(
                        page.url(),
                    );

                expect(
                    finalUrl.origin,
                ).toBe(
                    applicationOrigin,
                );

                expect(
                    finalUrl.pathname,
                ).toBe(
                    '/',
                );

                /*
                * A fresh document still must not obtain canonical
                * bearer material through browser-owned storage.
                */
                const browserStorage =
                    await page.evaluate(
                        () => ({
                            localStorage:
                                Object.fromEntries(
                                    Object.entries(
                                        window.localStorage,
                                    ),
                                ),

                            sessionStorage:
                                Object.fromEntries(
                                    Object.entries(
                                        window.sessionStorage,
                                    ),
                                ),
                        }),
                    );

                const serializedStorage =
                    JSON.stringify(
                        browserStorage,
                    );

                expect(
                    serializedStorage,
                ).not.toContain(
                    e2ePassword,
                );

                expect(
                    serializedStorage,
                ).not.toContain(
                    'access_token',
                );

                expect(
                    serializedStorage,
                ).not.toMatch(
                    /Bearer\s+/iu,
                );
            },
        );


test(
    'real invalid credentials remain anonymous and expose only controlled login failure UX',
    async ({
        page,
    }) => {
        const wrongPassword =
            'Definitely-Wrong-E2E-Password!';

        const safeFailureMessage =
            'Email, password, atau Tenant UUID tidak cocok.';

        const rawBackendMessage =
            'Invalid authentication credentials.';

        await page.goto(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        const emailInput =
            page.getByLabel(
                'Email',
            );

        const passwordInput =
            page.getByLabel(
                'Password',
            );

        const tenantInput =
            page.getByLabel(
                'Tenant UUID',
            );

        const submitButton =
            page.getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            );

        /*
         * Waiting for enabled form state also proves the
         * initial anonymous BrowserSession bootstrap has
         * already settled before failure-specific request
         * accounting begins.
         */
        await expect(
            submitButton,
        ).toBeEnabled();

        await emailInput.fill(
            e2eEmail,
        );

        await passwordInput.fill(
            wrongPassword,
        );

        await tenantInput.fill(
            e2eTenantId,
        );

        const authenticatedContinuationPaths =
            new Set([
                '/api/v1/auth/me',
                '/api/v1/user/my-memberships',
                '/api/v1/user/my-workspaces',
                '/api/v1/core/authorization/capabilities',
                '/api/v1/core/authorization/workspace-capabilities',
            ]);

        const postFailureContinuationRequests:
            string[] = [];

        /*
         * This listener is installed only after authoritative
         * anonymous bootstrap has settled.
         *
         * Therefore every matching request below would be a
         * continuation caused by this failed login attempt.
         */
        page.on(
            'request',
            (request) => {
                const pathname =
                    new URL(
                        request.url(),
                    ).pathname;

                if (
                    authenticatedContinuationPaths.has(
                        pathname,
                    )
                ) {
                    postFailureContinuationRequests.push(
                        pathname,
                    );
                }
            },
        );

        const loginResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/auth/login',
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        await submitButton.click();

        const loginResponse =
            await loginResponsePromise;

        /*
         * Assert the canonical backend contract separately
         * from the safe presentation contract.
         */
        expect(
            loginResponse.status(),
        ).toBe(
            401,
        );

        const loginBody:
            unknown =
            await loginResponse.json();

        expect(
            loginBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'AUTHENTICATION_FAILED',

            message:
                rawBackendMessage,
        });

        /*
         * Failed browser login must never expose or create
         * JavaScript-owned canonical Bearer authority.
         */
        expect(
            loginResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        expect(
            JSON.stringify(
                loginBody,
            ),
        ).not.toContain(
            'access_token',
        );

        /*
         * Frontend presentation deliberately replaces raw
         * backend authentication prose with controlled UX.
         */
        await expect(
            page.getByText(
                safeFailureMessage,
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                rawBackendMessage,
            ),
        ).toHaveCount(
            0,
        );

        /*
         * BrowserAuthRuntime returns to authoritative
         * anonymous state, so the same form becomes usable
         * again rather than transitioning into an
         * unavailable/protected state.
         */
        await expect(
            submitButton,
        ).toBeEnabled();

        await expect(
            emailInput,
        ).toBeEnabled();

        await expect(
            passwordInput,
        ).toBeEnabled();

        await expect(
            tenantInput,
        ).toBeEnabled();

        /*
         * Preserve useful non-secret correction context.
         *
         * Password persistence itself is deliberately not
         * locked as an E2E UX contract.
         */
        await expect(
            emailInput,
        ).toHaveValue(
            e2eEmail,
        );

        await expect(
            tenantInput,
        ).toHaveValue(
            e2eTenantId,
        );

        /*
         * A rejected credential must stop at login.
         *
         * BrowserAuthRuntime must not attempt canonical
         * identity, Membership, Workspace, or Capability
         * continuation after a failed login response.
         */
        expect(
            postFailureContinuationRequests,
        ).toEqual([]);

        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toHaveCount(
            0,
        );

        /*
         * Browser storage may contain non-secret contextual
         * hints elsewhere in authenticated flows, but a
         * failed credential must never place the attempted
         * password or Bearer material there.
         */
        const browserStorage =
            await page.evaluate(
                () => ({
                    localStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.localStorage,
                            ),
                        ),

                    sessionStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.sessionStorage,
                            ),
                        ),
                }),
            );

        const serializedStorage =
            JSON.stringify(
                browserStorage,
            );

        expect(
            serializedStorage,
        ).not.toContain(
            wrongPassword,
        );

        expect(
            serializedStorage,
        ).not.toContain(
            'access_token',
        );

        expect(
            serializedStorage,
        ).not.toMatch(
            /Bearer\s+/iu,
        );

        /*
         * Beginning a correction dismisses the stale
         * failure presentation without dispatching another
         * authentication request.
         */
        await passwordInput.fill(
            e2ePassword,
        );

        await expect(
            page.getByText(
                safeFailureMessage,
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            submitButton,
        ).toBeEnabled();

        expect(
            postFailureContinuationRequests,
        ).toEqual([]);
    },
);


test(
    'real canonical login validation remains anonymous and exposes only controlled password field UX',
    async ({
        page,
    }) => {
        /*
         * Frontend login validation deliberately requires
         * only a non-empty credential.
         *
         * Canonical LoginTokenRequest additionally requires
         * min:6, making this a deterministic server-side
         * validation boundary exercised through the real UI.
         */
        const canonicallyShortPassword =
            'short';

        const safeFormMessage =
            'Periksa kembali data login yang ditandai.';

        const safePasswordMessage =
            'Password tidak dapat diterima. Periksa kembali password Anda.';

        const rawCanonicalMessage =
            'The submitted data is invalid.';

        await page.goto(
            '/login',
        );

        const emailInput =
            page.getByLabel(
                'Email',
            );

        const passwordInput =
            page.getByLabel(
                'Password',
            );

        const tenantInput =
            page.getByLabel(
                'Tenant UUID',
            );

        const submitButton =
            page.getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            );

        /*
         * Wait until initial BrowserSession anonymous
         * bootstrap has settled before attributing any
         * subsequent network request to this login attempt.
         */
        await expect(
            submitButton,
        ).toBeEnabled();

        await emailInput.fill(
            e2eEmail,
        );

        await passwordInput.fill(
            canonicallyShortPassword,
        );

        await tenantInput.fill(
            e2eTenantId,
        );

        const authenticatedContinuationPaths =
            new Set([
                '/api/v1/auth/me',
                '/api/v1/user/my-memberships',
                '/api/v1/user/my-workspaces',
                '/api/v1/core/authorization/capabilities',
                '/api/v1/core/authorization/workspace-capabilities',
            ]);

        const postValidationContinuationRequests:
            string[] = [];

        page.on(
            'request',
            (request) => {
                const pathname =
                    new URL(
                        request.url(),
                    ).pathname;

                if (
                    authenticatedContinuationPaths.has(
                        pathname,
                    )
                ) {
                    postValidationContinuationRequests.push(
                        pathname,
                    );
                }
            },
        );

        const loginResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/auth/login',
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        await submitButton.click();

        const loginResponse =
            await loginResponsePromise;

        expect(
            loginResponse.status(),
        ).toBe(
            422,
        );

        const loginBody:
            unknown =
            await loginResponse.json();

        /*
         * Lock only stable canonical validation structure.
         *
         * Individual Laravel validation prose remains
         * backend-owned and must not become a frontend UX
         * contract.
         */
        expect(
            loginBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'VALIDATION_FAILED',

            message:
                rawCanonicalMessage,

            errors: {
                password:
                    expect.any(
                        Array,
                    ),
            },
        });

        expect(
            loginResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        expect(
            JSON.stringify(
                loginBody,
            ),
        ).not.toContain(
            'access_token',
        );

        /*
         * The UI consumes canonical field identity but never
         * forwards raw backend validation prose.
         */
        await expect(
            page.getByText(
                safeFormMessage,
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                safePasswordMessage,
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                rawCanonicalMessage,
            ),
        ).toHaveCount(
            0,
        );

        /*
         * AuthRuntime returns to authoritative anonymous
         * state after a failed login transport result.
         */
        await expect(
            submitButton,
        ).toBeEnabled();

        await expect(
            emailInput,
        ).toBeEnabled();

        await expect(
            passwordInput,
        ).toBeEnabled();

        await expect(
            tenantInput,
        ).toBeEnabled();

        expect(
            postValidationContinuationRequests,
        ).toEqual([]);

        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toHaveCount(
            0,
        );

        /*
         * Server validation failure must never create
         * JavaScript-owned credential authority.
         */
        const browserStorage =
            await page.evaluate(
                () => ({
                    localStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.localStorage,
                            ),
                        ),

                    sessionStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.sessionStorage,
                            ),
                        ),
                }),
            );

        const serializedStorage =
            JSON.stringify(
                browserStorage,
            );

        expect(
            serializedStorage,
        ).not.toContain(
            canonicallyShortPassword,
        );

        expect(
            serializedStorage,
        ).not.toContain(
            'access_token',
        );

        expect(
            serializedStorage,
        ).not.toMatch(
            /Bearer\s+/iu,
        );

        /*
         * Correcting the affected field dismisses the stale
         * server validation presentation without dispatching
         * another login request.
         */
        await passwordInput.fill(
            e2ePassword,
        );

        await expect(
            page.getByText(
                safeFormMessage,
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByText(
                safePasswordMessage,
            ),
        ).toHaveCount(
            0,
        );

        expect(
            postValidationContinuationRequests,
        ).toEqual([]);
    },
);


test(
    'real browser logout revokes BrowserSession authority and remains anonymous after reload',
    async ({
        page,
    }) => {
        let loginRequestCount =
            0;

        let logoutRequestCount =
            0;

        let logoutCompleted =
            false;

        const postLogoutMembershipAwareAuthenticationRequests:
            string[] = [];

        page.on(
            'request',
            (request) => {
                const pathname =
                    new URL(
                        request.url(),
                    ).pathname;

                if (
                    pathname
                        === '/api/v1/browser/auth/login'
                    && request.method()
                        === 'POST'
                ) {
                    loginRequestCount +=
                        1;
                }

                if (
                    pathname
                        === '/api/v1/browser/auth/logout'
                    && request.method()
                        === 'POST'
                ) {
                    logoutRequestCount +=
                        1;
                }

                if (
                    logoutCompleted
                    && pathname
                        === '/api/v1/auth/me'
                    && request.headers()[
                        'x-educore-membership-id'
                    ] !== undefined
                ) {
                    postLogoutMembershipAwareAuthenticationRequests.push(
                        pathname,
                    );
                }
            },
        );

        await page.goto(
            '/login',
        );

        const loginSubmit =
            page.getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            );

        /*
         * Enabled login form means initial anonymous
         * BrowserSession bootstrap has already settled.
         */
        await expect(
            loginSubmit,
        ).toBeEnabled();

        await page
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await page
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await page
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        const loginResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/auth/login',
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        await loginSubmit.click();

        const loginResponse =
            await loginResponsePromise;

        expect(
            loginResponse.status(),
        ).toBe(
            200,
        );

        expect(
            loginResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        /*
        * Establish FEI-11 authenticated presentation truth
        * before exercising the real BrowserSession logout.
        *
        * These locators remain live after the click so the same
        * browser objects can prove that the authenticated shell
        * is actually removed from the DOM.
        */
        const authenticatedUserContext =
            page.getByLabel(
                'Konteks pengguna aktif',
            );

        const authenticatedNavigation =
            page.getByRole(
                'navigation',
                {
                    name:
                        'Navigasi utama',
                },
            );

        const authenticatedHomeLink =
            authenticatedNavigation.getByRole(
                'link',
                {
                    name:
                        'Beranda',
                },
            );

        const authenticatedSkipLink =
            page.getByRole(
                'link',
                {
                    name:
                        'Lewati ke konten utama',
                },
            );

        const authenticatedMainContent =
            page.locator(
                '#main-content',
            );

        const authenticatedPageHeading =
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            );

        await expect(
            authenticatedUserContext,
        ).toBeVisible();

        await expect(
            authenticatedUserContext.getByText(
                'EduCore Browser E2E User',
            ),
        ).toBeVisible();

        await expect(
            authenticatedUserContext.getByText(
                e2eEmail,
            ),
        ).toBeVisible();

        await expect(
            authenticatedUserContext.getByText(
                /^Workspace:\s+/u,
            ),
        ).toBeVisible();

        await expect(
            authenticatedNavigation,
        ).toBeVisible();

        await expect(
            authenticatedHomeLink,
        ).toHaveAttribute(
            'href',
            '/',
        );

        await expect(
            authenticatedHomeLink,
        ).toHaveAttribute(
            'aria-current',
            'page',
        );

        await expect(
            authenticatedSkipLink,
        ).toHaveAttribute(
            'href',
            '#main-content',
        );

        await expect(
            authenticatedMainContent,
        ).toHaveCount(
            1,
        );

        await expect(
            authenticatedPageHeading,
        ).toBeVisible();

        await expect(
            page.getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            ),
        ).toBeVisible();

        expect(
            loginRequestCount,
        ).toBe(
            1,
        );

        /*
         * At least Membership restoration must exist after
         * successful canonical login.
         *
         * Discover the exact contextual keys from their
         * verified locator values instead of coupling the
         * E2E test to private storage-key constants.
         */
        const contextualRestorationKeys =
            await page.evaluate(
                ({
                    membershipId,
                    tenantId,
                }) =>
                    Object.entries(
                        window.sessionStorage,
                    )
                        .filter(
                            ([
                                ,
                                value,
                            ]) =>
                                value.includes(
                                    membershipId,
                                )
                                || value.includes(
                                    tenantId,
                                ),
                        )
                        .map(
                            ([
                                key,
                            ]) =>
                                key,
                        ),
                {
                    membershipId:
                        e2eMembershipId,

                    tenantId:
                        e2eTenantId,
                },
            );

        expect(
            contextualRestorationKeys.length,
        ).toBeGreaterThan(
            0,
        );

        const authenticatedCookies =
            await page
                .context()
                .cookies();

        const authenticatedSessionCookie =
            authenticatedCookies.find(
                (cookie) =>
                    cookie.name
                        === 'laravel-session',
            );

        if (
            authenticatedSessionCookie
                === undefined
        ) {
            throw new Error(
                'Expected authenticated Laravel BrowserSession cookie before logout.',
            );
        }

        expect(
            authenticatedSessionCookie
                .httpOnly,
        ).toBe(
            true,
        );

        const logoutCsrfResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/session/csrf',
                    )
                    && response
                        .request()
                        .method()
                        === 'GET'
                    && response.status()
                        === 204,
            );

        const logoutResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/auth/logout',
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        await page
            .getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            )
            .click();

        const [
            logoutCsrfResponse,
            logoutResponse,
        ] =
            await Promise.all([
                logoutCsrfResponsePromise,
                logoutResponsePromise,
            ]);

        expect(
            logoutCsrfResponse.status(),
        ).toBe(
            204,
        );

        expect(
            logoutResponse.status(),
        ).toBe(
            200,
        );

        const logoutBody:
            unknown =
            await logoutResponse.json();

        expect(
            logoutBody,
        ).toEqual({
            status:
                'success',

            message:
                'Logout completed successfully.',
        });

        expect(
            logoutResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        expect(
            logoutCsrfResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        expect(
            loginRequestCount,
        ).toBe(
            1,
        );

        expect(
            logoutRequestCount,
        ).toBe(
            1,
        );

        logoutCompleted =
            true;

        /*
         * Authority loss drives routing. LogoutButton does
         * not navigate directly.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        /*
        * Successful logout must remove the entire authenticated
        * presentation tree, not merely hide protected page
        * content.
        *
        * A stale Person, Workspace, or navigation projection
        * remaining in the DOM would leak authenticated context
        * after BrowserSession revocation.
        */
        await expect(
            authenticatedUserContext,
        ).toHaveCount(
            0,
        );

        await expect(
            authenticatedNavigation,
        ).toHaveCount(
            0,
        );

        await expect(
            authenticatedHomeLink,
        ).toHaveCount(
            0,
        );

        await expect(
            authenticatedSkipLink,
        ).toHaveCount(
            0,
        );

        await expect(
            authenticatedMainContent,
        ).toHaveCount(
            0,
        );

        await expect(
            authenticatedPageHeading,
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            ),
        ).toHaveCount(
            0,
        );

        expect(
            new URL(
                page.url(),
            ).pathname,
        ).toBe(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toHaveCount(
            0,
        );

        const loggedOutUrl =
            new URL(
                page.url(),
            );

        expect(
            loggedOutUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            loggedOutUrl.pathname,
        ).toBe(
            '/login',
        );

        /*
         * Auth loss must invalidate every contextual
         * restoration key that existed while authenticated.
         */
        const contextualStorageAfterLogout =
            await page.evaluate(
                (keys) =>
                    Object.fromEntries(
                        keys.map(
                            (key) => [
                                key,
                                window.sessionStorage
                                    .getItem(
                                        key,
                                    ),
                            ],
                        ),
                    ),
                contextualRestorationKeys,
            );

        for (
            const key
            of contextualRestorationKeys
        ) {
            expect(
                contextualStorageAfterLogout[
                    key
                ],
            ).toBeNull();
        }

        /*
         * Laravel invalidates the authenticated shared
         * session and creates a new anonymous session.
         */
        const loggedOutCookies =
            await page
                .context()
                .cookies();

        const loggedOutSessionCookie =
            loggedOutCookies.find(
                (cookie) =>
                    cookie.name
                        === 'laravel-session',
            );

        if (
            loggedOutSessionCookie
                === undefined
        ) {
            throw new Error(
                'Expected rotated anonymous Laravel session cookie after logout.',
            );
        }

        expect(
            loggedOutSessionCookie
                .httpOnly,
        ).toBe(
            true,
        );

        expect(
            loggedOutSessionCookie
                .value,
        ).not.toBe(
            authenticatedSessionCookie
                .value,
        );

        /*
        * A fresh anonymous BrowserSession first reaches the
        * Tenant-scoped canonical /auth/me boundary without a
        * Membership locator.
        *
        * That boundary intentionally reports context-required
        * before user-scoped Membership discovery establishes
        * definitive anonymous authentication truth.
        */
        const anonymousContextResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ] === undefined,
            );

        const anonymousMembershipResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ] === undefined,
            );

        await page.reload();

        const [
            anonymousContextResponse,
            anonymousMembershipResponse,
        ] =
            await Promise.all([
                anonymousContextResponsePromise,
                anonymousMembershipResponsePromise,
            ]);

        expect(
            anonymousContextResponse
                .status(),
        ).toBe(
            403,
        );

        const anonymousContextBody:
            unknown =
            await anonymousContextResponse
                .json();

        expect(
            anonymousContextBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
        });

        expect(
            anonymousMembershipResponse
                .status(),
        ).toBe(
            401,
        );

        const anonymousMembershipBody:
            unknown =
            await anonymousMembershipResponse
                .json();

        expect(
            anonymousMembershipBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
        });

        /*
        * Neither anonymous-bootstrap request may carry browser
        * bearer authority or replay the former Membership
        * locator.
        */
        for (
            const response
            of [
                anonymousContextResponse,
                anonymousMembershipResponse,
            ]
        ) {
            expect(
                response
                    .request()
                    .headers()[
                        'authorization'
                    ],
            ).toBeUndefined();

            expect(
                response
                    .request()
                    .headers()[
                        'x-educore-membership-id'
                    ],
            ).toBeUndefined();
        }

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        /*
        * A full reload after logout creates a fresh React runtime.
        *
        * Because the server-side BrowserSession was revoked, that
        * new runtime must remain public and must not reconstruct
        * any FEI-11 authenticated shell state from stale browser
        * restoration hints.
        */
        await expect(
            page.getByLabel(
                'Konteks pengguna aktif',
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'navigation',
                {
                    name:
                        'Navigasi utama',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'link',
                {
                    name:
                        'Beranda',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'link',
                {
                    name:
                        'Lewati ke konten utama',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.locator(
                '#main-content',
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            ),
        ).toHaveCount(
            0,
        );

        expect(
            new URL(
                page.url(),
            ).pathname,
        ).toBe(
            '/login',
        );

        expect(
            new URL(
                page.url(),
            ).pathname,
        ).toBe(
            '/login',
        );

        expect(
            loginRequestCount,
        ).toBe(
            1,
        );

        expect(
            logoutRequestCount,
        ).toBe(
            1,
        );

        expect(
            postLogoutMembershipAwareAuthenticationRequests,
        ).toEqual([]);

        /*
         * Logout must leave no browser-owned secret
         * credential material behind.
         */
        const browserStorage =
            await page.evaluate(
                () => ({
                    localStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.localStorage,
                            ),
                        ),

                    sessionStorage:
                        Object.fromEntries(
                            Object.entries(
                                window.sessionStorage,
                            ),
                        ),
                }),
            );

        const serializedStorage =
            JSON.stringify(
                browserStorage,
            );

        expect(
            serializedStorage,
        ).not.toContain(
            e2ePassword,
        );

        expect(
            serializedStorage,
        ).not.toContain(
            'access_token',
        );

        expect(
            serializedStorage,
        ).not.toMatch(
            /Bearer\s+/iu,
        );
    },
);

test(
    'real browser switches canonical Membership context through server-held BrowserSession credentials',
    async ({
        page,
    }) => {
        /*
         * Establish Membership A through the real production
         * login flow.
         */
        await page.goto(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await page
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await page
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await page
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        const initialAuthenticationPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const initialMembershipDiscoveryPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response.status()
                        === 200,
            );

        const initialWorkspacePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await page
            .getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            )
            .click();

        const [
            initialAuthentication,
            initialMembershipDiscovery,
            initialWorkspace,
        ] =
            await Promise.all([
                initialAuthenticationPromise,
                initialMembershipDiscoveryPromise,
                initialWorkspacePromise,
            ]);

        expect(
            initialAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            initialMembershipDiscovery.status(),
        ).toBe(
            200,
        );

        expect(
            initialWorkspace.status(),
        ).toBe(
            200,
        );

        /*
         * The production Membership selector must project
         * canonical discovery truth. The selected value is
         * Membership A, while Membership B is available.
         */
        const membershipSwitcher =
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            );

        await expect(
            membershipSwitcher,
        ).toBeVisible();

        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        await expect(
            membershipSwitcher
                .locator(
                    `option[value="${e2eSecondMembershipId}"]`,
                ),
        ).toHaveText(
            e2eSecondTenantName,
        );

        /*
         * Register all transition observers before the user
         * changes the production selector.
         */
        const csrfResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/session/csrf',
                    )
                    && response
                        .request()
                        .method()
                        === 'GET',
            );

        const membershipSwitchResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        `/api/v1/browser/user/memberships/${e2eSecondMembershipId}/switch`,
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        const switchedAuthenticationPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        const switchedWorkspacePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        const switchedCapabilityPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/core/authorization/capabilities',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        /*
         * Exercise the production switcher.
         *
         * No API request is issued directly by Playwright.
         */
        await membershipSwitcher
            .selectOption(
                e2eSecondMembershipId,
            );

        const [
            csrfResponse,
            membershipSwitchResponse,
            switchedAuthentication,
            switchedWorkspace,
            switchedCapability,
        ] =
            await Promise.all([
                csrfResponsePromise,
                membershipSwitchResponsePromise,
                switchedAuthenticationPromise,
                switchedWorkspacePromise,
                switchedCapabilityPromise,
            ]);

        expect(
            csrfResponse.status(),
        ).toBe(
            204,
        );

        /*
         * Browser membership switch prepares the credential
         * in server-side custody and returns only safe context.
         */
        expect(
            membershipSwitchResponse.status(),
        ).toBe(
            200,
        );

        const switchBody:
            unknown =
            await membershipSwitchResponse
                .json();

        expect(
            switchBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                membership_id:
                    e2eSecondMembershipId,

                tenant_id:
                    e2eSecondTenantId,

                tenant_name:
                    e2eSecondTenantName,
            },
        });

        expect(
            JSON.stringify(
                switchBody,
            ),
        ).not.toContain(
            'access_token',
        );

        const switchRequestHeaders =
            await membershipSwitchResponse
                .request()
                .allHeaders();

        expect(
            switchRequestHeaders[
                'authorization'
            ],
        ).toBeUndefined();

        expect(
            switchRequestHeaders[
                'x-xsrf-token'
            ],
        ).toBeDefined();

        /*
         * Switch success is not canonical authority yet.
         * /auth/me must independently confirm Membership B.
         */
        expect(
            switchedAuthentication.status(),
        ).toBe(
            200,
        );

        const switchedAuthenticationBody:
            unknown =
            await switchedAuthentication
                .json();

        expect(
            switchedAuthenticationBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                membership: {
                    id:
                        e2eSecondMembershipId,

                    status:
                        'ACTIVE',
                },

                tenant: {
                    id:
                        e2eSecondTenantId,

                    name:
                        e2eSecondTenantName,
                },
            },
        });

        expect(
            switchedWorkspace.status(),
        ).toBe(
            200,
        );

        const switchedWorkspaceBody:
            unknown =
            await switchedWorkspace
                .json();

        expect(
            switchedWorkspaceBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                tenant: {
                    id:
                        e2eSecondTenantId,
                },
            },
        });

        expect(
            switchedCapability.status(),
        ).toBe(
            200,
        );

        const switchedCapabilityBody:
            unknown =
            await switchedCapability
                .json();

        expect(
            switchedCapabilityBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                scope: {
                    type:
                        'tenant',

                    tenant_id:
                        e2eSecondTenantId,

                    membership_id:
                        e2eSecondMembershipId,
                },
            },
        });

        /*
         * Canonical API requests remain BrowserSession-owned.
         * No browser JavaScript Bearer authority is introduced
         * by Membership switching.
         */
        for (
            const response
            of [
                csrfResponse,
                membershipSwitchResponse,
                switchedAuthentication,
                switchedWorkspace,
                switchedCapability,
            ]
        ) {
            const headers =
                await response
                    .request()
                    .allHeaders();

            expect(
                headers[
                    'authorization'
                ],
            ).toBeUndefined();
        }

        /*
         * Only after canonical confirmation may the production
         * selector and shell project Membership/Tenant B as
         * current authority.
         */
        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eSecondMembershipId,
        );

        await expect(
            page.locator(
                'header p',
            ).filter({
                hasText:
                    e2eSecondTenantName,
            }).first(),
        ).toBeVisible();

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();
    },
);

test(
    'real BrowserSession keeps canonical Tenant authority isolated between two browser tabs',
    async ({
        context,
        page,
    }) => {
        /*
         * This real-backend scenario deliberately exercises
         * two browser tabs, two canonical context lifecycles,
         * and independent reload restoration.
         *
         * Mark the scenario as slow instead of introducing
         * fixed sleeps or weakening individual assertions.
         */
        test.slow();

        const tabA =
            page;

        /*
         * Tab A establishes Membership/Tenant A through the
         * real production login flow.
         */
        await tabA.goto(
            '/login',
        );

        await expect(
            tabA.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await tabA
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await tabA
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await tabA
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        const tabAAuthenticationPromise =
            tabA.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const tabAMembershipDiscoveryPromise =
            tabA.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response.status()
                        === 200,
            );

        const tabAWorkspacePromise =
            tabA.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await tabA
            .getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            )
            .click();

        const [
            tabAAuthentication,
            tabAMembershipDiscovery,
            tabAWorkspace,
        ] =
            await Promise.all([
                tabAAuthenticationPromise,
                tabAMembershipDiscoveryPromise,
                tabAWorkspacePromise,
            ]);

        expect(
            tabAAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            tabAMembershipDiscovery.status(),
        ).toBe(
            200,
        );

        expect(
            tabAWorkspace.status(),
        ).toBe(
            200,
        );

        const tabASwitcher =
            tabA.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            );

        await expect(
            tabASwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        await expect(
            tabA.locator(
                'header p',
            ).filter({
                hasText:
                    e2eTenantName,
            }).first(),
        ).toBeVisible();

        /*
         * Membership restoration is advisory tab-local state.
         *
         * Tab A must persist only its own canonical
         * Membership/Tenant pair.
         */
        const tabAInitialRestoration =
            await tabA.evaluate(
                (storageKey) =>
                    window
                        .sessionStorage
                        .getItem(
                            storageKey,
                        ),
                membershipRestorationStorageKey,
            );

        expect(
            tabAInitialRestoration,
        ).toContain(
            e2eMembershipId,
        );

        expect(
            tabAInitialRestoration,
        ).toContain(
            e2eTenantId,
        );

        expect(
            tabAInitialRestoration,
        ).not.toContain(
            e2eSecondMembershipId,
        );

        /*
         * Create another Page inside the SAME BrowserContext.
         *
         * BrowserSession cookies are shared, while
         * sessionStorage remains page/tab-local.
         */
        const tabB =
            await context.newPage();

        const tabBMissingContextPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response.status()
                        === 403
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === undefined,
            );

        const tabBMembershipDiscoveryPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response.status()
                        === 200,
            );

        await tabB.goto(
            '/',
        );

        const [
            tabBMissingContext,
            tabBMembershipDiscovery,
        ] =
            await Promise.all([
                tabBMissingContextPromise,
                tabBMembershipDiscoveryPromise,
            ]);

        /*
         * The shared BrowserSession is authenticated enough
         * for User-scope Membership discovery, but this fresh
         * tab owns no Membership locator yet.
         */
        const missingContextBody:
            unknown =
            await tabBMissingContext
                .json();

        expect(
            missingContextBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
        });

        expect(
            tabBMembershipDiscovery.status(),
        ).toBe(
            200,
        );

        const tabBInitialRestoration =
            await tabB.evaluate(
                (storageKey) =>
                    window
                        .sessionStorage
                        .getItem(
                            storageKey,
                        ),
                membershipRestorationStorageKey,
            );

        expect(
            tabBInitialRestoration,
        ).toBeNull();

        await expect(
            tabB.getByRole(
                'heading',
                {
                    name:
                        'Pilih Membership',
                },
            ),
        ).toBeVisible();

        const tabBChooser =
            tabB.getByRole(
                'combobox',
                {
                    name:
                        'Choose institution',
                },
            );

        await expect(
            tabBChooser,
        ).toBeVisible();

        await expect(
            tabBChooser,
        ).toHaveValue(
            '',
        );

        await expect(
            tabBChooser.locator(
                `option[value="${e2eMembershipId}"]`,
            ),
        ).toHaveText(
            e2eTenantName,
        );

        await expect(
            tabBChooser.locator(
                `option[value="${e2eSecondMembershipId}"]`,
            ),
        ).toHaveText(
            e2eSecondTenantName,
        );

        /*
         * Selecting B prepares another credential in the
         * SAME BrowserSession vault, but current authority is
         * still tab-local and requires canonical confirmation.
         */
        const tabBCsrfPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/session/csrf',
                    )
                    && response
                        .request()
                        .method()
                        === 'GET',
            );

        const tabBSwitchPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        `/api/v1/browser/user/memberships/${e2eSecondMembershipId}/switch`,
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        const tabBAuthenticationPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        const tabBWorkspacePromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        const tabBCapabilityPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/core/authorization/capabilities',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        await tabBChooser
            .selectOption(
                e2eSecondMembershipId,
            );

        const [
            tabBCsrf,
            tabBSwitch,
            tabBAuthentication,
            tabBWorkspace,
            tabBCapability,
        ] =
            await Promise.all([
                tabBCsrfPromise,
                tabBSwitchPromise,
                tabBAuthenticationPromise,
                tabBWorkspacePromise,
                tabBCapabilityPromise,
            ]);

        expect(
            tabBCsrf.status(),
        ).toBe(
            204,
        );

        expect(
            tabBSwitch.status(),
        ).toBe(
            200,
        );

        expect(
            tabBAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            tabBWorkspace.status(),
        ).toBe(
            200,
        );

        expect(
            tabBCapability.status(),
        ).toBe(
            200,
        );

        const tabBAuthenticationBody:
            unknown =
            await tabBAuthentication
                .json();

        expect(
            tabBAuthenticationBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                membership: {
                    id:
                        e2eSecondMembershipId,
                },

                tenant: {
                    id:
                        e2eSecondTenantId,

                    name:
                        e2eSecondTenantName,
                },
            },
        });

        const tabBSwitcher =
            tabB.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            );

        await expect(
            tabBSwitcher,
        ).toHaveValue(
            e2eSecondMembershipId,
        );

        await expect(
            tabB.locator(
                'header p',
            ).filter({
                hasText:
                    e2eSecondTenantName,
            }).first(),
        ).toBeVisible();

        /*
         * Tab B now owns only Membership/Tenant B as its
         * restoration hint.
         */
        const tabBRestoration =
            await tabB.evaluate(
                (storageKey) =>
                    window
                        .sessionStorage
                        .getItem(
                            storageKey,
                        ),
                membershipRestorationStorageKey,
            );

        expect(
            tabBRestoration,
        ).toContain(
            e2eSecondMembershipId,
        );

        expect(
            tabBRestoration,
        ).toContain(
            e2eSecondTenantId,
        );

        expect(
            tabBRestoration,
        ).not.toContain(
            e2eMembershipId,
        );

        /*
         * Switching Tab B must not mutate Tab A's advisory
         * locator or visible canonical authority.
         */
        const tabARestorationAfterTabBSwitch =
            await tabA.evaluate(
                (storageKey) =>
                    window
                        .sessionStorage
                        .getItem(
                            storageKey,
                        ),
                membershipRestorationStorageKey,
            );

        expect(
            tabARestorationAfterTabBSwitch,
        ).toContain(
            e2eMembershipId,
        );

        expect(
            tabARestorationAfterTabBSwitch,
        ).not.toContain(
            e2eSecondMembershipId,
        );

        await expect(
            tabASwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        /*
         * Reload Tab A after credential B exists in the
         * shared BrowserSession vault.
         *
         * Canonical restoration must still resolve A.
         */
        const tabAReloadAuthenticationPromise =
            tabA.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const tabAReloadWorkspacePromise =
            tabA.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await tabA.reload();

        const [
            tabAReloadAuthentication,
            tabAReloadWorkspace,
        ] =
            await Promise.all([
                tabAReloadAuthenticationPromise,
                tabAReloadWorkspacePromise,
            ]);

        expect(
            tabAReloadAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            tabAReloadWorkspace.status(),
        ).toBe(
            200,
        );

        const tabAReloadBody:
            unknown =
            await tabAReloadAuthentication
                .json();

        expect(
            tabAReloadBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                membership: {
                    id:
                        e2eMembershipId,
                },

                tenant: {
                    id:
                        e2eTenantId,

                    name:
                        e2eTenantName,
                },
            },
        });

        await expect(
            tabA.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            ),
        ).toHaveValue(
            e2eMembershipId,
        );

        await expect(
            tabA.locator(
                'header p',
            ).filter({
                hasText:
                    e2eTenantName,
            }).first(),
        ).toBeVisible();

        /*
         * Reload Tab B independently.
         *
         * Its own tab-local restoration hint must resolve B,
         * not inherit A from the other page.
         */
        const tabBReloadAuthenticationPromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        const tabBReloadWorkspacePromise =
            tabB.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eSecondMembershipId,
            );

        await tabB.reload();

        const [
            tabBReloadAuthentication,
            tabBReloadWorkspace,
        ] =
            await Promise.all([
                tabBReloadAuthenticationPromise,
                tabBReloadWorkspacePromise,
            ]);

        expect(
            tabBReloadAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            tabBReloadWorkspace.status(),
        ).toBe(
            200,
        );

        await expect(
            tabB.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            ),
        ).toHaveValue(
            e2eSecondMembershipId,
        );

        await expect(
            tabB.locator(
                'header p',
            ).filter({
                hasText:
                    e2eSecondTenantName,
            }).first(),
        ).toBeVisible();

        /*
         * Tab B restoration/reload must likewise leave the
         * still-open Tab A canonical projection unchanged.
         */
        await expect(
            tabA.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            ),
        ).toHaveValue(
            e2eMembershipId,
        );

        await expect(
            tabA.locator(
                'header p',
            ).filter({
                hasText:
                    e2eTenantName,
            }).first(),
        ).toBeVisible();
    },
);

test(
    'real browser switches Workspace context without changing canonical Membership authentication',
    async ({
        page,
    }) => {
        /*
         * Establish canonical Membership A / Tenant A through
         * the real production login flow.
         */
        await page.goto(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await page
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await page
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await page
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        const initialAuthenticationPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const initialMembershipDiscoveryPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response.status()
                        === 200,
            );

        const initialWorkspacePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const initialCapabilityPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/core/authorization/capabilities',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await page
            .getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            )
            .click();

        const [
            initialAuthentication,
            initialMembershipDiscovery,
            initialWorkspace,
            initialCapability,
        ] =
            await Promise.all([
                initialAuthenticationPromise,
                initialMembershipDiscoveryPromise,
                initialWorkspacePromise,
                initialCapabilityPromise,
            ]);

        expect(
            initialAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            initialMembershipDiscovery.status(),
        ).toBe(
            200,
        );

        expect(
            initialWorkspace.status(),
        ).toBe(
            200,
        );

        expect(
            initialCapability.status(),
        ).toBe(
            200,
        );

        /*
         * Real backend discovery must expose both the safe
         * TENANT baseline and the deterministic Organization
         * Workspace owned by Membership A.
         */
        const initialWorkspaceBody:
            unknown =
            await initialWorkspace.json();

        expect(
            initialWorkspaceBody,
        ).toEqual(
            expect.objectContaining({
                status:
                    'success',

                data:
                    expect.objectContaining({
                        tenant:
                            expect.objectContaining({
                                id:
                                    e2eTenantId,
                            }),

                        workspaces:
                            expect.arrayContaining([
                                expect.objectContaining({
                                    type:
                                        'TENANT',

                                    organizational_assignment_id:
                                        null,
                                }),

                                expect.objectContaining({
                                    type:
                                        'ORGANIZATION',

                                    organizational_assignment_id:
                                        e2eOrganizationalAssignmentId,

                                    organization_id:
                                        e2eOrganizationId,

                                    organization_unit_id:
                                        null,

                                    label:
                                        e2eOrganizationName,
                                }),
                            ]),
                    }),
            }),
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        const membershipSwitcher =
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            );

        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        const workspaceSwitcher =
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch Workspace',
                },
            );

        await expect(
            workspaceSwitcher,
        ).toBeVisible();

        await expect(
            workspaceSwitcher,
        ).toHaveValue(
            'TENANT',
        );

        const organizationalOptionValue =
            `ORGANIZATION:${e2eOrganizationalAssignmentId}`;

        await expect(
            workspaceSwitcher.locator(
                `option[value="${organizationalOptionValue}"]`,
            ),
        ).toHaveText(
            e2eOrganizationName,
        );

        /*
         * Observe transition traffic only after login has
         * completely reached stable TENANT authority.
         */
        const transitionRequests:
            {
                readonly method:
                    string;

                readonly pathname:
                    string;
            }[] = [];

        page.on(
            'request',
            (request) => {
                const requestUrl =
                    new URL(
                        request.url(),
                    );

                if (
                    requestUrl.origin
                        !== applicationOrigin
                ) {
                    return;
                }

                transitionRequests.push({
                    method:
                        request.method(),

                    pathname:
                        requestUrl.pathname,
                });
            },
        );

        /*
         * Workspace verification must use the same canonical
         * Membership locator plus the selected organizational
         * assignment locator.
         */
        const workspaceVerificationPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/core/authorization/workspace-capabilities',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId
                    && response
                        .request()
                        .headers()[
                            'x-educore-organizational-assignment-id'
                        ]
                        === e2eOrganizationalAssignmentId,
            );

        /*
         * Exercise only the production Workspace selector.
         * Playwright does not issue the capability request.
         */
        await workspaceSwitcher
            .selectOption(
                organizationalOptionValue,
            );

        const workspaceVerification =
            await workspaceVerificationPromise;

        expect(
            workspaceVerification.status(),
        ).toBe(
            200,
        );

        const verificationHeaders =
            await workspaceVerification
                .request()
                .allHeaders();

        expect(
            verificationHeaders[
                'x-educore-membership-id'
            ],
        ).toBe(
            e2eMembershipId,
        );

        expect(
            verificationHeaders[
                'x-educore-organizational-assignment-id'
            ],
        ).toBe(
            e2eOrganizationalAssignmentId,
        );

        expect(
            verificationHeaders[
                'authorization'
            ],
        ).toBeUndefined();

        const workspaceCapabilityBody:
            unknown =
            await workspaceVerification
                .json();

        expect(
            workspaceCapabilityBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                scope: {
                    type:
                        'organization',

                    tenant_id:
                        e2eTenantId,

                    membership_id:
                        e2eMembershipId,

                    organizational_assignment_id:
                        e2eOrganizationalAssignmentId,

                    organization_id:
                        e2eOrganizationId,

                    organization_unit_id:
                        null,
                },

                is_global_superadmin:
                    false,

                permissions:
                    [],
            },
        });

        expect(
            JSON.stringify(
                workspaceCapabilityBody,
            ),
        ).not.toContain(
            'access_token',
        );

        /*
         * Only verified runtime state may now project the
         * Organization Workspace as current authority.
         */
        await expect(
            workspaceSwitcher,
        ).toHaveValue(
            organizationalOptionValue,
        );

        await expect(
            page.getByText(
                `Workspace: ${e2eOrganizationName}`,
            ),
        ).toBeVisible();

        /*
         * Workspace narrowing must not alter Membership/Tenant
         * authentication authority.
         */
        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        await expect(
            page.locator(
                'header p',
            ).filter({
                hasText:
                    e2eTenantName,
            }).first(),
        ).toBeVisible();

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        /*
         * No Membership credential transition may have been
         * triggered by Workspace selection.
         */
        expect(
            transitionRequests.some(
                (request) =>
                    request.method
                        === 'POST'
                    && /^\/api\/v1\/browser\/user\/memberships\/[^/]+\/switch$/u
                        .test(
                            request.pathname,
                        ),
            ),
        ).toBe(
            false,
        );

        expect(
            transitionRequests.some(
                (request) =>
                    request.method
                        === 'POST'
                    && request.pathname
                        === '/api/v1/browser/auth/login',
            ),
        ).toBe(
            false,
        );

        /*
         * Organizational restoration is advisory, tab-local,
         * and bound to the same Membership/Tenant context.
         */
        const workspaceRestorationHint =
            await page.evaluate(
                (storageKey) => {
                    const serialized =
                        window.sessionStorage
                            .getItem(
                                storageKey,
                            );

                    if (
                        serialized
                            === null
                    ) {
                        return null;
                    }

                    return JSON.parse(
                        serialized,
                    ) as unknown;
                },
                workspaceRestorationStorageKey,
            );

        expect(
            workspaceRestorationHint,
        ).toEqual({
            version:
                1,

            membershipId:
                e2eMembershipId,

            tenantId:
                e2eTenantId,

            organizationalAssignmentId:
                e2eOrganizationalAssignmentId,
        });
    },
);

test(
    'real browser discards a stale organizational Workspace on reload and recovers to verified Tenant authority',
    async ({
        page,
    }) => {
        /*
         * Canonical fixture restoration belongs to the test
         * runner, not browser JavaScript.
         *
         * Always restore the E2E Assignment even when an
         * assertion fails after the stale mutation.
         */
        try {
            await page.goto(
                '/login',
            );

            await expect(
                page.getByRole(
                    'heading',
                    {
                        name:
                            'Masuk ke EduCore',
                    },
                ),
            ).toBeVisible();

            await page
                .getByLabel(
                    'Email',
                )
                .fill(
                    e2eEmail,
                );

            await page
                .getByLabel(
                    'Password',
                )
                .fill(
                    e2ePassword,
                );

            await page
                .getByLabel(
                    'Tenant UUID',
                )
                .fill(
                    e2eTenantId,
                );

            const initialAuthenticationPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/auth/me',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            const initialWorkspaceDiscoveryPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/user/my-workspaces',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            await page
                .getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                )
                .click();

            const [
                initialAuthentication,
                initialWorkspaceDiscovery,
            ] =
                await Promise.all([
                    initialAuthenticationPromise,
                    initialWorkspaceDiscoveryPromise,
                ]);

            expect(
                initialAuthentication.status(),
            ).toBe(
                200,
            );

            expect(
                initialWorkspaceDiscovery.status(),
            ).toBe(
                200,
            );

            const membershipSwitcher =
                page.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch institution',
                    },
                );

            await expect(
                membershipSwitcher,
            ).toHaveValue(
                e2eMembershipId,
            );

            const workspaceSwitcher =
                page.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch Workspace',
                    },
                );

            await expect(
                workspaceSwitcher,
            ).toHaveValue(
                'TENANT',
            );

            const organizationalOptionValue =
                `ORGANIZATION:${e2eOrganizationalAssignmentId}`;

            const workspaceVerificationPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/core/authorization/workspace-capabilities',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId
                        && response
                            .request()
                            .headers()[
                                'x-educore-organizational-assignment-id'
                            ]
                            === e2eOrganizationalAssignmentId,
                );

            await workspaceSwitcher
                .selectOption(
                    organizationalOptionValue,
                );

            const workspaceVerification =
                await workspaceVerificationPromise;

            expect(
                workspaceVerification.status(),
            ).toBe(
                200,
            );

            await expect(
                workspaceSwitcher,
            ).toHaveValue(
                organizationalOptionValue,
            );

            await expect(
                page.getByText(
                    `Workspace: ${e2eOrganizationName}`,
                ),
            ).toBeVisible();

            /*
             * Prove the browser has only advisory restoration
             * state before the backend fixture becomes stale.
             */
            const organizationalHint =
                await page.evaluate(
                    (
                        storageKey,
                    ) =>
                        window.sessionStorage
                            .getItem(
                                storageKey,
                            ),
                    workspaceRestorationStorageKey,
                );

            expect(
                organizationalHint,
            ).not.toBeNull();

            expect(
                organizationalHint,
            ).toContain(
                e2eOrganizationalAssignmentId,
            );

            /*
             * Mutate canonical backend truth outside the
             * browser. The page still displays the previously
             * verified Organization until the next lifecycle
             * resolves fresh authority.
             */
            await runE2ESeeder(
                staleWorkspaceE2ESeeder,
            );

            await expect(
                page.getByText(
                    `Workspace: ${e2eOrganizationName}`,
                ),
            ).toBeVisible();

            /*
             * Register reload observers before navigation.
             *
             * BrowserSession must remain authenticated and
             * Membership A must remain the canonical Tenant
             * locator while Workspace discovery refreshes.
             */
            const reloadedAuthenticationPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/auth/me',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            const reloadedWorkspaceDiscoveryPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/user/my-workspaces',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            const recoveredTenantCapabilityPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/core/authorization/capabilities',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            await page.reload();

            const [
                reloadedAuthentication,
                reloadedWorkspaceDiscovery,
                recoveredTenantCapability,
            ] =
                await Promise.all([
                    reloadedAuthenticationPromise,
                    reloadedWorkspaceDiscoveryPromise,
                    recoveredTenantCapabilityPromise,
                ]);

            expect(
                reloadedAuthentication.status(),
            ).toBe(
                200,
            );

            const authenticationBody:
                unknown =
                await reloadedAuthentication
                    .json();

            expect(
                authenticationBody,
            ).toMatchObject({
                status:
                    'success',

                data: {
                    membership: {
                        id:
                            e2eMembershipId,

                        status:
                            'ACTIVE',
                    },

                    tenant: {
                        id:
                            e2eTenantId,
                    },
                },
            });

            expect(
                reloadedWorkspaceDiscovery.status(),
            ).toBe(
                200,
            );

            const workspaceDiscoveryBody:
                unknown =
                await reloadedWorkspaceDiscovery
                    .json();

            expect(
                workspaceDiscoveryBody,
            ).toEqual(
                expect.objectContaining({
                    status:
                        'success',

                    data:
                        expect.objectContaining({
                            tenant:
                                expect.objectContaining({
                                    id:
                                        e2eTenantId,
                                }),

                            workspaces:
                                expect.arrayContaining([
                                    expect.objectContaining({
                                        type:
                                            'TENANT',
                                    }),
                                ]),
                        }),
                }),
            );

            /*
             * The inactive Assignment must no longer appear in
             * fresh canonical Workspace discovery.
             */
            expect(
                JSON.stringify(
                    workspaceDiscoveryBody,
                ),
            ).not.toContain(
                e2eOrganizationalAssignmentId,
            );

            expect(
                recoveredTenantCapability.status(),
            ).toBe(
                200,
            );

            const recoveredCapabilityBody:
                unknown =
                await recoveredTenantCapability
                    .json();

            expect(
                recoveredCapabilityBody,
            ).toMatchObject({
                status:
                    'success',

                data: {
                    scope: {
                        type:
                            'tenant',

                        tenant_id:
                            e2eTenantId,

                        membership_id:
                            e2eMembershipId,
                    },
                },
            });

            /*
             * The stale organizational restoration locator
             * must never become authoritative after reload.
             */
            await expect(
                page.getByText(
                    `Workspace: ${e2eTenantName}`,
                ),
            ).toBeVisible();

            await expect(
                page.getByText(
                    `Workspace: ${e2eOrganizationName}`,
                ),
            ).toHaveCount(
                0,
            );

            /*
             * Authentication and Membership authority are
             * unaffected by stale Workspace recovery.
             */
            await expect(
                membershipSwitcher,
            ).toHaveValue(
                e2eMembershipId,
            );

            await expect(
                page.getByRole(
                    'heading',
                    {
                        name:
                            'Frontend Foundation',
                    },
                ),
            ).toBeVisible();

            const restoredHint =
                await page.evaluate(
                    (
                        storageKey,
                    ) =>
                        window.sessionStorage
                            .getItem(
                                storageKey,
                            ),
                    workspaceRestorationStorageKey,
                );

            expect(
                restoredHint,
            ).toBeNull();

            /*
             * No JavaScript-owned Bearer credential may appear
             * during stale Workspace recovery.
             */
            for (
                const response
                of [
                    reloadedAuthentication,
                    reloadedWorkspaceDiscovery,
                    recoveredTenantCapability,
                ]
            ) {
                const headers =
                    await response
                        .request()
                        .allHeaders();

                expect(
                    headers[
                        'authorization'
                    ],
                ).toBeUndefined();
            }
        } finally {
            /*
             * Restore canonical fixture state even when the
             * browser assertion path fails after mutation.
             */
            await runE2ESeeder(
                canonicalE2ESeeder,
            );
        }
    },
);

test(
    'real browser denies Academic Students when canonical Tenant capabilities omit the required permission',
    async ({
        page,
    }) => {
        /*
         * Establish real BrowserSession authority through the
         * production login flow before exercising the
         * permission-protected Academic route.
         */
        await page.goto(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await page
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await page
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await page
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        const initialAuthenticationPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const initialWorkspacePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await page
            .getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            )
            .click();

        const [
            initialAuthentication,
            initialWorkspace,
        ] =
            await Promise.all([
                initialAuthenticationPromise,
                initialWorkspacePromise,
            ]);

        expect(
            initialAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            initialWorkspace.status(),
        ).toBe(
            200,
        );

        /*
         * Root has no additional permission requirement.
         *
         * Reaching it first proves Auth/Membership/Workspace
         * bootstrap is valid independently from the later
         * Academic permission denial.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        const membershipSwitcher =
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            );

        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        /*
         * A full authenticated navigation exercises a fresh
         * application lifecycle without mixing this scenario
         * with the later anonymous direct-protected-route
         * acceptance test.
         */
        const deniedAuthenticationPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const deniedWorkspacePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const deniedCapabilityPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/core/authorization/capabilities',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        await page.goto(
            '/academic/students',
        );

        const [
            deniedAuthentication,
            deniedWorkspace,
            deniedCapability,
        ] =
            await Promise.all([
                deniedAuthenticationPromise,
                deniedWorkspacePromise,
                deniedCapabilityPromise,
            ]);

        expect(
            deniedAuthentication.status(),
        ).toBe(
            200,
        );

        expect(
            deniedWorkspace.status(),
        ).toBe(
            200,
        );

        /*
         * Authorization denial comes from canonical Laravel
         * capability truth, not a Playwright mock or a
         * browser-local permission override.
         */
        expect(
            deniedCapability.status(),
        ).toBe(
            200,
        );

        const deniedCapabilityBody:
            unknown =
            await deniedCapability
                .json();

        expect(
            deniedCapabilityBody,
        ).toMatchObject({
            status:
                'success',

            data: {
                scope: {
                    type:
                        'tenant',

                    tenant_id:
                        e2eTenantId,

                    membership_id:
                        e2eMembershipId,
                },

                is_global_superadmin:
                    false,

                permissions:
                    [],
            },
        });

        expect(
            JSON.stringify(
                deniedCapabilityBody,
            ),
        ).not.toContain(
            'academic.students.view',
        );

        /*
         * BrowserSession remains the only browser credential
         * mechanism. Membership is an untrusted locator, never
         * a replacement authorization credential.
         */
        for (
            const response
            of [
                deniedAuthentication,
                deniedWorkspace,
                deniedCapability,
            ]
        ) {
            const headers =
                await response
                    .request()
                    .allHeaders();

            expect(
                headers[
                    'authorization'
                ],
            ).toBeUndefined();
        }

        const capabilityHeaders =
            await deniedCapability
                .request()
                .allHeaders();

        expect(
            capabilityHeaders[
                'x-educore-membership-id'
            ],
        ).toBe(
            e2eMembershipId,
        );

        expect(
            capabilityHeaders[
                'x-educore-organizational-assignment-id'
            ],
        ).toBeUndefined();

        /*
         * Permission absence produces controlled route denial.
         *
         * Authentication is not revoked and the URL is not
         * rewritten into a fake "authorized" destination.
         */
        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/academic/students',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Akses ditolak',
                },
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                'Anda tidak mempunyai permission yang diperlukan untuk membuka halaman ini.',
            ),
        ).toBeVisible();

        /*
         * The business page itself must not render through a
         * denied route boundary.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Academic Students',
                },
            ),
        ).toHaveCount(
            0,
        );

        /*
         * Denial is authorization-only. Canonical
         * Membership/Tenant authority remains intact.
         */
        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        await expect(
            page.locator(
                'header p',
            ).filter({
                hasText:
                    e2eTenantName,
            }).first(),
        ).toBeVisible();
    },
);

test(
    'real anonymous direct protected route redirects to login without activating protected downstream context',
    async ({
        page,
    }) => {
        let workspaceRequestCount =
            0;

        let capabilityRequestCount =
            0;

        /*
         * Observe downstream protected-context transport without
         * intercepting or mocking any request.
         */
        page.on(
            'request',
            (request) => {
                const url =
                    new URL(
                        request.url(),
                    );

                if (
                    url.origin
                        !== applicationOrigin
                ) {
                    return;
                }

                if (
                    url.pathname
                        === '/api/v1/user/my-workspaces'
                ) {
                    workspaceRequestCount +=
                        1;
                }

                if (
                    url.pathname
                        === '/api/v1/core/authorization/capabilities'
                    || url.pathname
                        === '/api/v1/core/authorization/workspace-capabilities'
                ) {
                    capabilityRequestCount +=
                        1;
                }
            },
        );

        /*
         * Fresh BrowserAuth bootstrap establishes the
         * first-party BrowserSession before asking canonical
         * authentication truth.
         */
        const csrfResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/session/csrf',
                    )
                    && response
                        .request()
                        .method()
                        === 'GET',
            );

        const authenticationResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    ),
            );

        /*
         * Without a tab-local Membership locator the canonical
         * auth endpoint first reports missing Membership context.
         *
         * User-scope discovery then determines whether an
         * authenticated BrowserSession exists at all.
         */
        const membershipDiscoveryPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    ),
            );

        /*
         * This is the scenario under test:
         *
         * navigate directly to a protected route from a fresh
         * anonymous BrowserContext rather than visiting /login
         * first.
         */
        await page.goto(
            '/',
        );

        const [
            csrfResponse,
            authenticationResponse,
            membershipDiscovery,
        ] =
            await Promise.all([
                csrfResponsePromise,
                authenticationResponsePromise,
                membershipDiscoveryPromise,
            ]);

        expect(
            csrfResponse.status(),
        ).toBe(
            204,
        );

        expect(
            authenticationResponse.status(),
        ).toBe(
            403,
        );

        const authenticationBody:
            unknown =
            await authenticationResponse
                .json();

        expect(
            authenticationBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
        });

        expect(
            membershipDiscovery.status(),
        ).toBe(
            401,
        );

        const discoveryBody:
            unknown =
            await membershipDiscovery
                .json();

        expect(
            discoveryBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
        });

        /*
         * BrowserSession remains cookie/CSRF-owned. No canonical
         * bearer credential is exposed to JavaScript requests.
         */
        for (
            const response
            of [
                csrfResponse,
                authenticationResponse,
                membershipDiscovery,
            ]
        ) {
            const headers =
                await response
                    .request()
                    .allHeaders();

            expect(
                headers[
                    'authorization'
                ],
            ).toBeUndefined();
        }

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/login',
        );

        expect(
            finalUrl.searchParams.get(
                'returnTo',
            ),
        ).toBe(
            '/',
        );

        /*
         * Authentication denial occurs before protected
         * Workspace/Capability authority can become active.
         */
        expect(
            workspaceRequestCount,
        ).toBe(
            0,
        );

        expect(
            capabilityRequestCount,
        ).toBe(
            0,
        );

        /*
         * Nothing from the authenticated application shell may
         * leak through while the direct route is denied.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'navigation',
                {
                    name:
                        'Navigasi utama',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByLabel(
                'Konteks pengguna aktif',
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            ),
        ).toHaveCount(
            0,
        );
    },
);

test(
    'real browser becomes anonymous after authoritative server-side BrowserSession invalidation',
    async ({
        page,
    }) => {
        /*
         * Ensure the business fixture itself starts from the
         * canonical ACTIVE state. This seeder does not create
         * or preserve browser session authority.
         */
        await runE2ESeeder(
            canonicalE2ESeeder,
        );

        await page.goto(
            '/login',
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        await page
            .getByLabel(
                'Email',
            )
            .fill(
                e2eEmail,
            );

        await page
            .getByLabel(
                'Password',
            )
            .fill(
                e2ePassword,
            );

        await page
            .getByLabel(
                'Tenant UUID',
            )
            .fill(
                e2eTenantId,
            );

        let loginRequestCount =
            0;

        page.on(
            'request',
            (request) => {
                if (
                    matchesApplicationApiPath(
                        request.url(),
                        '/api/v1/browser/auth/login',
                    )
                    && request.method()
                        === 'POST'
                ) {
                    loginRequestCount +=
                        1;
                }
            },
        );

        const loginResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/auth/login',
                    )
                    && response
                        .request()
                        .method()
                        === 'POST',
            );

        const authenticatedContextPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        const membershipDiscoveryPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-memberships',
                    )
                    && response.status()
                        === 200,
            );

        const workspacePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/user/my-workspaces',
                    )
                    && response
                        .request()
                        .headers()[
                            'x-educore-membership-id'
                        ]
                        === e2eMembershipId,
            );

        /*
         * Tenant bootstrap has two distinct Capability
         * projections:
         *
         * 1. Workspace verifier proves TENANT is a safe
         *    Workspace baseline.
         * 2. Active CapabilityRuntime projects authority
         *    after Workspace becomes canonical.
         *
         * Wait for the second projection so server-session
         * invalidation cannot race a still-in-flight response.
         */
        const tenantCapabilityStatuses:
            number[] = [];

        const secondTenantCapabilityPromise =
            page.waitForResponse(
                (response) => {
                    if (
                        ! matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/core/authorization/capabilities',
                        )
                        || response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            !== e2eMembershipId
                    ) {
                        return false;
                    }

                    tenantCapabilityStatuses.push(
                        response.status(),
                    );

                    return (
                        tenantCapabilityStatuses
                            .length
                            === 2
                    );
                },
            );

        await page
            .getByRole(
                'button',
                {
                    name:
                        'Masuk',
                },
            )
            .click();

        const [
            loginResponse,
            authenticatedContext,
            membershipDiscoveryResponse,
            workspaceResponse,
            secondTenantCapabilityResponse,
        ] =
            await Promise.all([
                loginResponsePromise,
                authenticatedContextPromise,
                membershipDiscoveryPromise,
                workspacePromise,
                secondTenantCapabilityPromise,
            ]);

        expect(
            loginResponse.status(),
        ).toBe(
            200,
        );

        expect(
            authenticatedContext.status(),
        ).toBe(
            200,
        );

        expect(
            membershipDiscoveryResponse.status(),
        ).toBe(
            200,
        );

        expect(
            workspaceResponse.status(),
        ).toBe(
            200,
        );

        expect(
            secondTenantCapabilityResponse
                .status(),
        ).toBe(
            200,
        );

        expect(
            tenantCapabilityStatuses,
        ).toEqual([
            200,
            200,
        ]);

        expect(
            loginRequestCount,
        ).toBe(
            1,
        );

        /*
         * Prove protected Tenant authority is genuinely ready
         * before invalidating anything on the server.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toBeVisible();

        const membershipSwitcher =
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            );

        await expect(
            membershipSwitcher,
        ).toHaveValue(
            e2eMembershipId,
        );

        const workspaceSwitcher =
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch Workspace',
                },
            );

        await expect(
            workspaceSwitcher,
        ).toHaveValue(
            'TENANT',
        );

        /*
         * The browser must actually own a secure server-session
         * cookie before this scenario can claim to invalidate a
         * BrowserSession.
         *
         * Do not depend on the configurable Laravel cookie name.
         * The XSRF cookie is browser-readable, while the session
         * cookie is expected to remain HttpOnly.
         */
        const authenticatedCookies =
            await page
                .context()
                .cookies(
                    applicationOrigin,
                );

        const authenticatedSessionCookie =
            authenticatedCookies.find(
                (cookie) =>
                    cookie.httpOnly,
            );

        expect(
            authenticatedSessionCookie,
        ).toBeDefined();

        if (
            authenticatedSessionCookie
                === undefined
        ) {
            throw new Error(
                'Authenticated E2E browser did not expose an HttpOnly BrowserSession cookie.',
            );
        }

        /*
         * Destroy only authoritative server-side session
         * persistence. Playwright does not log out, clear
         * cookies, mutate storage, or issue a synthetic API
         * request.
         */
        await runE2ESeeder(
            browserSessionInvalidationE2ESeeder,
        );

        /*
         * Server-side invalidation cannot itself alter the
         * browser cookie jar.
         *
         * This proves the upcoming anonymous transition is
         * caused by Laravel rejecting stale server authority,
         * not by the test deleting browser credentials.
         */
        const cookiesAfterInvalidation =
            await page
                .context()
                .cookies(
                    applicationOrigin,
                );

        const retainedSessionCookie =
            cookiesAfterInvalidation.find(
                (cookie) =>
                    cookie.name
                        === authenticatedSessionCookie.name,
            );

        expect(
            retainedSessionCookie,
        ).toBeDefined();

        if (
            retainedSessionCookie
                === undefined
        ) {
            throw new Error(
                'BrowserSession cookie disappeared before the browser exercised invalidated server authority.',
            );
        }

        expect(
            retainedSessionCookie.value,
        ).toBe(
            authenticatedSessionCookie.value,
        );

        let protectedContextRequestCount =
            0;

        page.on(
            'request',
            (request) => {
                const url =
                    new URL(
                        request.url(),
                    );

                if (
                    url.origin
                        !== applicationOrigin
                ) {
                    return;
                }

                if (
                    url.pathname
                        === '/api/v1/user/my-workspaces'
                    || url.pathname
                        === '/api/v1/core/authorization/capabilities'
                    || url.pathname
                        === '/api/v1/core/authorization/workspace-capabilities'
                ) {
                    protectedContextRequestCount +=
                        1;
                }
            },
        );

        /*
         * A reload re-enters the canonical BrowserSession
         * bootstrap just like the already-covered successful
         * reload path.
         *
         * After database invalidation, at least one canonical
         * authentication/discovery endpoint must reject the
         * stale BrowserSession with the session-required error.
         */
        const csrfBootstrapPromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/browser/session/csrf',
                    )
                    && response
                        .request()
                        .method()
                        === 'GET',
            );

        const sessionRequiredPromise =
            page.waitForResponse(
                (response) => {
                    if (
                        response.status()
                            !== 401
                    ) {
                        return false;
                    }

                    return (
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/auth/me',
                        )
                        || matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/user/my-memberships',
                        )
                    );
                },
            );

        await page.reload();

        const [
            csrfBootstrap,
            sessionRequiredResponse,
        ] =
            await Promise.all([
                csrfBootstrapPromise,
                sessionRequiredPromise,
            ]);

        expect(
            csrfBootstrap.status(),
        ).toBe(
            204,
        );

        expect(
            sessionRequiredResponse.status(),
        ).toBe(
            401,
        );

        const sessionRequiredBody:
            unknown =
            await sessionRequiredResponse
                .json();

        expect(
            sessionRequiredBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
        });

        const rejectedHeaders =
            await sessionRequiredResponse
                .request()
                .allHeaders();

        expect(
            rejectedHeaders[
                'authorization'
            ],
        ).toBeUndefined();

        /*
         * Session invalidation must never replay credentials or
         * silently perform another login.
         */
        expect(
            loginRequestCount,
        ).toBe(
            1,
        );

        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Masuk ke EduCore',
                },
            ),
        ).toBeVisible();

        const finalUrl =
            new URL(
                page.url(),
            );

        expect(
            finalUrl.origin,
        ).toBe(
            applicationOrigin,
        );

        expect(
            finalUrl.pathname,
        ).toBe(
            '/login',
        );

        expect(
            finalUrl.searchParams.get(
                'returnTo',
            ),
        ).toBe(
            '/',
        );

        /*
         * Once authentication has failed, no protected
         * Workspace or Capability authority may bootstrap.
         */
        expect(
            protectedContextRequestCount,
        ).toBe(
            0,
        );

        /*
         * The authenticated presentation tree must be gone.
         */
        await expect(
            page.getByRole(
                'heading',
                {
                    name:
                        'Frontend Foundation',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'navigation',
                {
                    name:
                        'Navigasi utama',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByLabel(
                'Konteks pengguna aktif',
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch institution',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'combobox',
                {
                    name:
                        'Switch Workspace',
                },
            ),
        ).toHaveCount(
            0,
        );

        await expect(
            page.getByRole(
                'button',
                {
                    name:
                        'Keluar',
                },
            ),
        ).toHaveCount(
            0,
        );
    },
);

test(
    'real browser fences a superseded Tenant capability response after canonical Membership context changes',
    async ({
        page,
    }) => {
        /*
         * The business fixture must begin from its canonical
         * ACTIVE state regardless of which focused/full E2E
         * scenario ran before this one.
         */
        await runE2ESeeder(
            canonicalE2ESeeder,
        );

        resetContextRaceResponseGate();

        let gateCaptured =
            false;

        let gateReleased =
            false;

        let gateCleaned =
            false;

        try {
            await page.goto(
                '/login',
            );

            await expect(
                page.getByRole(
                    'heading',
                    {
                        name:
                            'Masuk ke EduCore',
                    },
                ),
            ).toBeVisible();

            await page
                .getByLabel(
                    'Email',
                )
                .fill(
                    e2eEmail,
                );

            await page
                .getByLabel(
                    'Password',
                )
                .fill(
                    e2ePassword,
                );

            await page
                .getByLabel(
                    'Tenant UUID',
                )
                .fill(
                    e2eTenantId,
                );

            let tenantACapabilityRequests =
                0;

            let tenantACapabilityResponses =
                0;

            let resolveSupersededSettlement:
                (
                    value:
                        | {
                            readonly kind:
                                'response';

                            readonly status:
                                number;
                        }
                        | {
                            readonly kind:
                                'failed';

                            readonly errorText:
                                string
                                | null;
                        },
                ) => void =
                    () => {
                        throw new Error(
                            'Superseded Tenant capability settlement resolved before initialization.',
                        );
                    };

            const supersededSettlementPromise =
                new Promise<
                    | {
                        readonly kind:
                            'response';

                        readonly status:
                            number;
                    }
                    | {
                        readonly kind:
                            'failed';

                        readonly errorText:
                            string
                            | null;
                    }
                >(
                    (
                        resolve,
                    ) => {
                        resolveSupersededSettlement =
                            resolve;
                    },
                );

            page.on(
                'request',
                (request) => {
                    if (
                        matchesApplicationApiPath(
                            request.url(),
                            '/api/v1/core/authorization/capabilities',
                        )
                        && request
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId
                    ) {
                        tenantACapabilityRequests +=
                            1;
                    }
                },
            );

            page.on(
                'response',
                (response) => {
                    if (
                        ! matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/core/authorization/capabilities',
                        )
                        || response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            !== e2eMembershipId
                    ) {
                        return;
                    }

                    tenantACapabilityResponses +=
                        1;

                    /*
                     * Response #1 is the TENANT Workspace
                     * verifier and must pass normally.
                     *
                     * Any later A response observed after the
                     * gate has captured #2 is the superseded
                     * active Capability completion.
                     */
                    if (
                        gateCaptured
                        && tenantACapabilityResponses
                            >= 2
                    ) {
                        resolveSupersededSettlement({
                            kind:
                                'response',

                            status:
                                response.status(),
                        });
                    }
                },
            );

            page.on(
                'requestfailed',
                (request) => {
                    if (
                        ! gateCaptured
                        || ! matchesApplicationApiPath(
                            request.url(),
                            '/api/v1/core/authorization/capabilities',
                        )
                        || request
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            !== e2eMembershipId
                    ) {
                        return;
                    }

                    resolveSupersededSettlement({
                        kind:
                            'failed',

                        errorText:
                            request
                                .failure()
                                ?.errorText
                            ?? null,
                    });
                },
            );

            /*
             * The first matching Tenant-A capability response
             * verifies the safe TENANT Workspace.
             *
             * Hold only the second response: the active
             * Capability projection which must never become
             * authoritative after Membership B wins.
             */
            armContextRaceResponseGate({
                method:
                    'GET',

                pathname:
                    '/api/v1/core/authorization/capabilities',

                membershipId:
                    e2eMembershipId,

                matchOrdinal:
                    2,
            });

            const initialAuthenticationPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/auth/me',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            const initialMembershipDiscoveryPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/user/my-memberships',
                        )
                        && response.status()
                            === 200,
                );

            const initialWorkspacePromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/user/my-workspaces',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eMembershipId,
                );

            const capturedTenantAResponsePromise =
                waitForContextRaceResponseCapture();

            await page
                .getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                )
                .click();

            const [
                initialAuthentication,
                initialMembershipDiscovery,
                initialWorkspace,
                capturedTenantAResponse,
            ] =
                await Promise.all([
                    initialAuthenticationPromise,
                    initialMembershipDiscoveryPromise,
                    initialWorkspacePromise,
                    capturedTenantAResponsePromise,
                ]);

            gateCaptured =
                true;

            expect(
                initialAuthentication.status(),
            ).toBe(
                200,
            );

            expect(
                initialMembershipDiscovery.status(),
            ).toBe(
                200,
            );

            expect(
                initialWorkspace.status(),
            ).toBe(
                200,
            );

            expect(
                capturedTenantAResponse,
            ).toEqual({
                method:
                    'GET',

                pathname:
                    '/api/v1/core/authorization/capabilities',

                status:
                    200,
            });

            /*
             * Exactly two Tenant-A capability requests have
             * reached the real backend, while only the first
             * has reached browser JavaScript.
             */
            expect(
                tenantACapabilityRequests,
            ).toBe(
                2,
            );

            expect(
                tenantACapabilityResponses,
            ).toBe(
                1,
            );

            await expect(
                page.getByRole(
                    'heading',
                    {
                        name:
                            'Frontend Foundation',
                    },
                ),
            ).toBeVisible();

            const membershipSwitcher =
                page.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch institution',
                    },
                );

            await expect(
                membershipSwitcher,
            ).toHaveValue(
                e2eMembershipId,
            );

            /*
             * Register every Membership-B observer before
             * exercising the production selector.
             */
            const csrfResponsePromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/browser/session/csrf',
                        )
                        && response
                            .request()
                            .method()
                            === 'GET',
                );

            const membershipSwitchResponsePromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            `/api/v1/browser/user/memberships/${e2eSecondMembershipId}/switch`,
                        )
                        && response
                            .request()
                            .method()
                            === 'POST',
                );

            const switchedAuthenticationPromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/auth/me',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eSecondMembershipId,
                );

            const switchedWorkspacePromise =
                page.waitForResponse(
                    (response) =>
                        matchesApplicationApiPath(
                            response.url(),
                            '/api/v1/user/my-workspaces',
                        )
                        && response
                            .request()
                            .headers()[
                                'x-educore-membership-id'
                            ]
                            === e2eSecondMembershipId,
                );

            const tenantBCapabilityStatuses:
                number[] = [];

            const secondTenantBCapabilityPromise =
                page.waitForResponse(
                    (response) => {
                        if (
                            ! matchesApplicationApiPath(
                                response.url(),
                                '/api/v1/core/authorization/capabilities',
                            )
                            || response
                                .request()
                                .headers()[
                                    'x-educore-membership-id'
                                ]
                                !== e2eSecondMembershipId
                        ) {
                            return false;
                        }

                        tenantBCapabilityStatuses.push(
                            response.status(),
                        );

                        return (
                            tenantBCapabilityStatuses
                                .length
                                === 2
                        );
                    },
                );

            await membershipSwitcher
                .selectOption(
                    e2eSecondMembershipId,
                );

            const [
                csrfResponse,
                membershipSwitchResponse,
                switchedAuthentication,
                switchedWorkspace,
                secondTenantBCapability,
            ] =
                await Promise.all([
                    csrfResponsePromise,
                    membershipSwitchResponsePromise,
                    switchedAuthenticationPromise,
                    switchedWorkspacePromise,
                    secondTenantBCapabilityPromise,
                ]);

            expect(
                csrfResponse.status(),
            ).toBe(
                204,
            );

            expect(
                membershipSwitchResponse.status(),
            ).toBe(
                200,
            );

            expect(
                switchedAuthentication.status(),
            ).toBe(
                200,
            );

            expect(
                switchedWorkspace.status(),
            ).toBe(
                200,
            );

            expect(
                secondTenantBCapability.status(),
            ).toBe(
                200,
            );

            expect(
                tenantBCapabilityStatuses,
            ).toEqual([
                200,
                200,
            ]);

            const switchedAuthenticationBody:
                unknown =
                await switchedAuthentication
                    .json();

            expect(
                switchedAuthenticationBody,
            ).toMatchObject({
                status:
                    'success',

                data: {
                    membership: {
                        id:
                            e2eSecondMembershipId,
                    },

                    tenant: {
                        id:
                            e2eSecondTenantId,

                        name:
                            e2eSecondTenantName,
                    },
                },
            });

            const switchedWorkspaceBody:
                unknown =
                await switchedWorkspace
                    .json();

            expect(
                switchedWorkspaceBody,
            ).toMatchObject({
                status:
                    'success',

                data: {
                    tenant: {
                        id:
                            e2eSecondTenantId,
                    },
                },
            });

            const tenantBCapabilityBody:
                unknown =
                await secondTenantBCapability
                    .json();

            expect(
                tenantBCapabilityBody,
            ).toMatchObject({
                status:
                    'success',

                data: {
                    scope: {
                        type:
                            'tenant',

                        tenant_id:
                            e2eSecondTenantId,

                        membership_id:
                            e2eSecondMembershipId,
                    },
                },
            });

            /*
             * Membership/Tenant B is now canonically visible
             * before the held Tenant-A response is released.
             */
            const switchedMembershipSwitcher =
                page.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch institution',
                    },
                );

            await expect(
                switchedMembershipSwitcher,
            ).toHaveValue(
                e2eSecondMembershipId,
            );

            await expect(
                page.locator(
                    'header p',
                ).filter({
                    hasText:
                        e2eSecondTenantName,
                }).first(),
            ).toBeVisible();

            /*
             * The old Tenant-A capability request may already
             * have been cancelled by production lifecycle
             * teardown. That is allowed, but the Vite gate
             * still owns the upstream real-Laravel response
             * until we explicitly release it.
             */
            releaseContextRaceResponseGate();

            gateReleased =
                true;

            const releaseAcknowledgement =
                await waitForContextRaceResponseReleaseAcknowledgement();

            expect(
                releaseAcknowledgement,
            ).toEqual(
                capturedTenantAResponse,
            );

            /*
             * Wait until the browser-side stale request is
             * settled either by:
             *
             * - observing the released real response, or
             * - production cancellation after B superseded A.
             *
             * Cancellation-independent stale-completion
             * correctness is separately locked by lower-level
             * generation/revision tests.
             */
            const supersededSettlement =
                await supersededSettlementPromise;

            if (
                supersededSettlement.kind
                    === 'response'
            ) {
                expect(
                    supersededSettlement.status,
                ).toBe(
                    200,
                );
            } else {
                expect(
                    supersededSettlement.errorText,
                ).not.toBe(
                    '',
                );
            }

            /*
             * Releasing/settling superseded A must never
             * restore A into current interactive authority.
             */
            await expect(
                switchedMembershipSwitcher,
            ).toHaveValue(
                e2eSecondMembershipId,
            );

            await expect(
                page.locator(
                    'header p',
                ).filter({
                    hasText:
                        e2eSecondTenantName,
                }).first(),
            ).toBeVisible();

            await expect(
                page.getByRole(
                    'heading',
                    {
                        name:
                            'Frontend Foundation',
                    },
                ),
            ).toBeVisible();

            expect(
                tenantACapabilityRequests,
            ).toBe(
                2,
            );

            /*
             * BrowserSession authority remains server-held.
             * The real race scenario must not introduce a
             * browser Bearer credential.
             */
            for (
                const response
                of [
                    membershipSwitchResponse,
                    switchedAuthentication,
                    switchedWorkspace,
                    secondTenantBCapability,
                ]
            ) {
                const headers =
                    await response
                        .request()
                        .allHeaders();

                expect(
                    headers[
                        'authorization'
                    ],
                ).toBeUndefined();
            }

            /*
             * Advisory restoration state must also remain B
             * after stale A settles.
             */
            const membershipRestoration =
                await page.evaluate(
                    (storageKey) =>
                        window
                            .sessionStorage
                            .getItem(
                                storageKey,
                            ),
                    membershipRestorationStorageKey,
                );

            expect(
                membershipRestoration,
            ).toContain(
                e2eSecondMembershipId,
            );

            expect(
                membershipRestoration,
            ).toContain(
                e2eSecondTenantId,
            );

            expect(
                membershipRestoration,
            ).not.toContain(
                e2eMembershipId,
            );

            resetContextRaceResponseGate();

            gateCleaned =
                true;
        } finally {
            /*
             * Never leave a paused upstream response behind if
             * an assertion fails after capture.
             *
             * A later Playwright run also resets on Vite
             * startup, but this keeps the current server
             * usable for the rest of a full serial suite.
             */
            if (
                ! gateCleaned
            ) {
                if (
                    gateCaptured
                    && ! gateReleased
                ) {
                    releaseContextRaceResponseGate();
                } else if (
                    ! gateCaptured
                ) {
                    resetContextRaceResponseGate();
                }
            }
        }
    },
);
