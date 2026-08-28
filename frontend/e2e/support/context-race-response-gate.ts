import {
    existsSync,
    mkdirSync,
    readFileSync,
    rmSync,
    watch,
    writeFileSync,
} from 'node:fs';
import type {
    IncomingMessage,
} from 'node:http';
import {
    fileURLToPath,
    URL,
} from 'node:url';

export const contextRaceResponseGateEnvironmentVariable =
    'EDUCORE_E2E_CONTEXT_RACE_RESPONSE_GATE';

export const contextRaceResponseGateEnabledValue =
    '1';

export interface ContextRaceResponseGateSpecification {
    readonly method:
        'GET';

    readonly pathname:
        string;

    readonly membershipId:
        string;

    /*
     * Defaults to the first matching response.
     *
     * A larger ordinal lets an E2E scenario allow earlier
     * canonical requests to complete before holding the
     * response whose late delivery is under test.
     */
    readonly matchOrdinal?:
        number;

    readonly organizationalAssignmentId?:
        string;
}

export interface ContextRaceResponseGateCapture {
    readonly method:
        string;

    readonly pathname:
        string;

    readonly status:
        number;
}

interface ProxyServerLike {
    on(
        event:
            'proxyRes',
        listener:
            (
                proxyResponse:
                    IncomingMessage,
                request:
                    IncomingMessage,
            ) => void,
    ): void;
}

const gateDirectory =
    fileURLToPath(
        new URL(
            '../../../node_modules/.cache/educore-context-race-response-gate/',
            import.meta.url,
        ),
    );

const armPath =
    fileURLToPath(
        new URL(
            'arm.json',
            `file://${gateDirectory.replaceAll('\\', '/')}/`,
        ),
    );

const capturePath =
    fileURLToPath(
        new URL(
            'capture.json',
            `file://${gateDirectory.replaceAll('\\', '/')}/`,
        ),
    );

const releasePath =
    fileURLToPath(
        new URL(
            'release',
            `file://${gateDirectory.replaceAll('\\', '/')}/`,
        ),
    );

const releaseAcknowledgementPath =
    fileURLToPath(
        new URL(
            'release-acknowledgement.json',
            `file://${gateDirectory.replaceAll('\\', '/')}/`,
        ),
    );

function ensureGateDirectory(): void {
    mkdirSync(
        gateDirectory,
        {
            recursive:
                true,
        },
    );
}

function removeGateFile(
    path:
        string,
): void {
    rmSync(
        path,
        {
            force:
                true,
        },
    );
}

function normalizeHeader(
    value:
        string
        | string[]
        | undefined,
): string | undefined {
    if (
        typeof value
            === 'string'
    ) {
        return value;
    }

    if (
        Array.isArray(
            value,
        )
    ) {
        return value[
            0
        ];
    }

    return undefined;
}

function parseGateSpecification(
    value:
        unknown,
): ContextRaceResponseGateSpecification {
    if (
        typeof value
            !== 'object'
        || value
            === null
        || Array.isArray(
            value,
        )
    ) {
        throw new Error(
            'Context race response gate specification must be an object.',
        );
    }

    const record =
        value as Record<
            string,
            unknown
        >;

    if (
        record.method
            !== 'GET'
        || typeof record.pathname
            !== 'string'
        || ! record.pathname
        || typeof record.membershipId
            !== 'string'
        || ! record.membershipId
        || (
            record.matchOrdinal
                !== undefined
            && (
                typeof record.matchOrdinal
                    !== 'number'
                || ! Number.isInteger(
                    record.matchOrdinal,
                )
                || record.matchOrdinal
                    < 1
            )
        )
        || (
            record
                .organizationalAssignmentId
                !== undefined
            && (
                typeof record
                    .organizationalAssignmentId
                    !== 'string'
                || ! record
                    .organizationalAssignmentId
            )
        )
    ) {
        throw new Error(
            'Context race response gate specification is invalid.',
        );
    }

    return {
        method:
            record.method,

        pathname:
            record.pathname,

        membershipId:
            record.membershipId,

        ...(
            typeof record.matchOrdinal
                === 'number'
                ? {
                    matchOrdinal:
                        record.matchOrdinal,
                }
                : {}
        ),

        ...(
            typeof record
                .organizationalAssignmentId
                === 'string'
                ? {
                    organizationalAssignmentId:
                        record
                            .organizationalAssignmentId,
                }
                : {}
        ),
    };
}

function readArmedGate():
    ContextRaceResponseGateSpecification
    | null {
    if (
        ! existsSync(
            armPath,
        )
    ) {
        return null;
    }

    const serialized =
        readFileSync(
            armPath,
            'utf-8',
        );

    return parseGateSpecification(
        JSON.parse(
            serialized,
        ) as unknown,
    );
}

function requestMatchesGate(
    request:
        IncomingMessage,
    gate:
        ContextRaceResponseGateSpecification,
): boolean {
    if (
        request.method
            !== gate.method
    ) {
        return false;
    }

    const requestUrl =
        new URL(
            request.url
                ?? '/',
            'http://127.0.0.1',
        );

    if (
        requestUrl.pathname
            !== gate.pathname
    ) {
        return false;
    }

    const membershipId =
        normalizeHeader(
            request.headers[
                'x-educore-membership-id'
            ],
        );

    if (
        membershipId
            !== gate.membershipId
    ) {
        return false;
    }

    if (
        gate
            .organizationalAssignmentId
            === undefined
    ) {
        return true;
    }

    return (
        normalizeHeader(
            request.headers[
                'x-educore-organizational-assignment-id'
            ],
        )
        === gate
            .organizationalAssignmentId
    );
}

function waitForFile(
    path:
        string,
): Promise<void> {
    ensureGateDirectory();

    if (
        existsSync(
            path,
        )
    ) {
        return Promise.resolve();
    }

    return new Promise(
        (
            resolve,
            reject,
        ) => {
            const watcher =
                watch(
                    gateDirectory,
                    (
                        _eventType,
                        filename,
                    ) => {
                        if (
                            filename
                                === null
                        ) {
                            return;
                        }

                        if (
                            existsSync(
                                path,
                            )
                        ) {
                            watcher.close();

                            resolve();
                        }
                    },
                );

            watcher.on(
                'error',
                (
                    error,
                ) => {
                    watcher.close();

                    reject(
                        error,
                    );
                },
            );

            /*
             * Close the race between the initial existsSync()
             * check and watch() registration.
             */
            if (
                existsSync(
                    path,
                )
            ) {
                watcher.close();

                resolve();
            }
        },
    );
}

export function resetContextRaceResponseGate(): void {
    ensureGateDirectory();

    removeGateFile(
        armPath,
    );

    removeGateFile(
        capturePath,
    );

    removeGateFile(
        releasePath,
    );

    removeGateFile(
        releaseAcknowledgementPath,
    );
}

export function armContextRaceResponseGate(
    specification:
        ContextRaceResponseGateSpecification,
): void {
    resetContextRaceResponseGate();

    writeFileSync(
        armPath,
        `${JSON.stringify(
            specification,
        )}\n`,
        'utf-8',
    );
}

function readContextRaceResponseGateObservation(
    path:
        string,
    label:
        string,
): ContextRaceResponseGateCapture {
    const serialized =
        readFileSync(
            path,
            'utf-8',
        );

    const value:
        unknown =
        JSON.parse(
            serialized,
        );

    if (
        typeof value
            !== 'object'
        || value
            === null
        || Array.isArray(
            value,
        )
    ) {
        throw new Error(
            `Context race response gate ${label} is invalid.`,
        );
    }

    const record =
        value as Record<
            string,
            unknown
        >;

    if (
        typeof record.method
            !== 'string'
        || typeof record.pathname
            !== 'string'
        || typeof record.status
            !== 'number'
    ) {
        throw new Error(
            `Context race response gate ${label} is invalid.`,
        );
    }

    return {
        method:
            record.method,

        pathname:
            record.pathname,

        status:
            record.status,
    };
}

export async function waitForContextRaceResponseCapture():
    Promise<ContextRaceResponseGateCapture> {
    await waitForFile(
        capturePath,
    );

    return readContextRaceResponseGateObservation(
        capturePath,
        'capture',
    );
}

export async function waitForContextRaceResponseReleaseAcknowledgement():
    Promise<ContextRaceResponseGateCapture> {
    await waitForFile(
        releaseAcknowledgementPath,
    );

    return readContextRaceResponseGateObservation(
        releaseAcknowledgementPath,
        'release acknowledgement',
    );
}

export function releaseContextRaceResponseGate(): void {
    ensureGateDirectory();

    removeGateFile(
        releaseAcknowledgementPath,
    );

    writeFileSync(
        releasePath,
        'release\n',
        'utf-8',
    );
}

export function configureContextRaceResponseGate(
    proxy:
        ProxyServerLike,
): void {
    ensureGateDirectory();

    /*
     * Every Vite E2E server starts with no armed response.
     * A stale prior failed-test gate must never affect a new run.
     */
    resetContextRaceResponseGate();

    proxy.on(
        'proxyRes',
        (
            proxyResponse,
            request,
        ) => {
            const gate =
                readArmedGate();

            if (
                gate
                    === null
                || ! requestMatchesGate(
                    request,
                    gate,
                )
            ) {
                return;
            }

            const remainingMatchOrdinal =
                gate.matchOrdinal
                ?? 1;

            /*
             * Earlier matching responses deliberately pass
             * through untouched.
             *
             * Rewrite the armed specification with the
             * remaining ordinal instead of introducing a
             * timer or process-local counter. The filesystem
             * remains the single coordination source shared
             * by Playwright and the Vite proxy.
             */
            if (
                remainingMatchOrdinal
                    > 1
            ) {
                const nextGate:
                    ContextRaceResponseGateSpecification = {
                    ...gate,

                    matchOrdinal:
                        remainingMatchOrdinal
                        - 1,
                };

                writeFileSync(
                    armPath,
                    `${JSON.stringify(
                        nextGate,
                    )}\n`,
                    'utf-8',
                );

                return;
            }

            /*
             * The requested matching response now owns the
             * one-shot hold. Remove the arm before pausing so
             * later matching responses cannot be captured by
             * the same gate.
             */
            removeGateFile(
                armPath,
            );

            proxyResponse.pause();

            const requestUrl =
                new URL(
                    request.url
                        ?? '/',
                    'http://127.0.0.1',
                );

            const capture:
                ContextRaceResponseGateCapture = {
                    method:
                        request.method
                        ?? '',

                    pathname:
                        requestUrl.pathname,

                    status:
                        proxyResponse.statusCode
                        ?? 0,
                };

            writeFileSync(
                capturePath,
                `${JSON.stringify(
                    capture,
                )}\n`,
                'utf-8',
            );

            void waitForFile(
                releasePath,
            )
                .then(
                    () => {
                        proxyResponse.resume();

                        /*
                         * Tell Playwright that the Vite proxy
                         * has consumed the release signal.
                         *
                         * This acknowledgement deliberately
                         * records only the already-minimized
                         * request identity/status metadata.
                         */
                        writeFileSync(
                            releaseAcknowledgementPath,
                            `${JSON.stringify(
                                capture,
                            )}\n`,
                            'utf-8',
                        );
                    },
                )
                .catch(
                    (
                        error:
                            unknown,
                    ) => {
                        proxyResponse.destroy(
                            error instanceof Error
                                ? error
                                : new Error(
                                    'Context race response gate failed while waiting for release.',
                                ),
                        );
                    },
                );
        },
    );
}
