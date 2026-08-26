import {
    expect,
    test,
} from '@playwright/test';

const applicationOrigin =
    'http://127.0.0.1:5173';

const backendOrigin =
    'http://127.0.0.1:8000';

const e2eEmail =
    'browser-e2e@educore.test';

const e2ePassword =
    'E2eOnly-Secret123!';

const e2eTenantId =
    '019c8f4a-7b10-7000-8000-000000000003';

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
    'real same-site sibling-origin login mutation is rejected by Laravel request-forgery protection',
    async ({
        page,
    }) => {
        /*
         * Establish a real browser document on Laravel's
         * sibling origin.
         *
         * Port 8000 and port 5173 are different origins while
         * remaining same-site. EduCore deliberately configures
         * Laravel request-forgery protection with
         * allowSameSite=false, so this origin must not be
         * trusted as mutation authority.
         */
        await page.goto(
            `${backendOrigin}/up`,
        );

        expect(
            new URL(
                page.url(),
            ).origin,
        ).toBe(
            backendOrigin,
        );

        const rejectedLoginResponsePromise =
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

        /*
         * HTML form submission performs a full document
         * navigation.
         *
         * Receiving the HTTP response does not mean Chromium
         * has completed committing that rejected document.
         * Synchronize explicitly so the later return to /login
         * cannot race the rejected POST navigation.
         */
        const rejectedLoginNavigationPromise =
            page.waitForURL(
                `${applicationOrigin}/api/v1/browser/auth/login`,
                {
                    waitUntil:
                        'domcontentloaded',
                },
            );

        /*
         * Submit credentials that are otherwise valid for the
         * deterministic E2E fixture.
         *
         * Do not bootstrap CSRF and do not manufacture an
         * X-XSRF-TOKEN header. If the request-forgery boundary
         * were accidentally bypassed, this payload would be
         * eligible to establish BrowserSession authority.
         */
        await page.evaluate(
            ({
                action,
                email,
                password,
                tenantUuid,
            }) => {
                const form =
                    document.createElement(
                        'form',
                    );

                form.method =
                    'POST';

                form.action =
                    action;

                const fields = {
                    email,
                    password,
                    tenant_uuid:
                        tenantUuid,
                };

                for (
                    const [
                        name,
                        value,
                    ]
                    of Object.entries(
                        fields,
                    )
                ) {
                    const input =
                        document.createElement(
                            'input',
                        );

                    input.type =
                        'hidden';

                    input.name =
                        name;

                    input.value =
                        value;

                    form.append(
                        input,
                    );
                }

                document.body.append(
                    form,
                );

                form.submit();
            },
            {
                action:
                    `${applicationOrigin}/api/v1/browser/auth/login`,

                email:
                    e2eEmail,

                password:
                    e2ePassword,

                tenantUuid:
                    e2eTenantId,
            },
        );

        const rejectedLoginResponse =
            await rejectedLoginResponsePromise;

        await rejectedLoginNavigationPromise;

        /*
         * Do not branch on Laravel's human-readable
         * TokenMismatchException message.
         *
         * HTTP 419 is the transport-level rejection evidence.
         */
        expect(
            rejectedLoginResponse.status(),
        ).toBe(
            419,
        );

        const rejectedRequestHeaders =
            await rejectedLoginResponse
                .request()
                .allHeaders();

        /*
         * This is intentionally not a trusted same-origin
         * navigation.
         */
        expect(
            rejectedRequestHeaders[
                'sec-fetch-site'
            ],
        ).toBe(
            'same-site',
        );

        /*
         * No JavaScript-owned bearer or CSRF authority may be
         * manufactured by this hostile mutation.
         */
        expect(
            rejectedRequestHeaders[
                'authorization'
            ],
        ).toBeUndefined();

        expect(
            rejectedRequestHeaders[
                'x-xsrf-token'
            ],
        ).toBeUndefined();

        /*
         * Prove the rejected mutation did not establish
         * BrowserSession authority.
         *
         * Re-enter the real SPA and observe Laravel's canonical
         * anonymous bootstrap responses rather than trusting UI
         * state alone.
         */
        const authenticationContextResponsePromise =
            page.waitForResponse(
                (response) =>
                    matchesApplicationApiPath(
                        response.url(),
                        '/api/v1/auth/me',
                    ),
            );

        const membershipDiscoveryResponsePromise =
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

        const [
            authenticationContextResponse,
            membershipDiscoveryResponse,
        ] =
            await Promise.all([
                authenticationContextResponsePromise,
                membershipDiscoveryResponsePromise,
            ]);

        expect(
            authenticationContextResponse.status(),
        ).toBe(
            403,
        );

        const authenticationContextBody:
            unknown =
            await authenticationContextResponse.json();

        expect(
            authenticationContextBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
        });

        expect(
            membershipDiscoveryResponse.status(),
        ).toBe(
            401,
        );

        const membershipDiscoveryBody:
            unknown =
            await membershipDiscoveryResponse.json();

        expect(
            membershipDiscoveryBody,
        ).toMatchObject({
            status:
                'error',

            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
        });

        expect(
            authenticationContextResponse
                .request()
                .headers()[
                    'authorization'
                ],
        ).toBeUndefined();

        expect(
            membershipDiscoveryResponse
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

        expect(
            new URL(
                page.url(),
            ).pathname,
        ).toBe(
            '/login',
        );
    },
);
