import {
    expect,
    test,
} from '@playwright/test';

const e2eMembershipId =
    '019c8f4a-7b10-7000-8000-000000000004';

const e2eTenantId =
    '019c8f4a-7b10-7000-8000-000000000003';

const e2eEmail =
    'browser-e2e@educore.test';

const e2ePassword =
    'E2eOnly-Secret123!';

const applicationOrigin =
    'http://127.0.0.1:5173';

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
