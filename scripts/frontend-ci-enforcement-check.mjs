import {
    readFile,
    stat,
} from 'node:fs/promises';
import {
    relative,
    resolve,
} from 'node:path';

const repositoryRoot =
    process.cwd();

const workflowRelativePath =
    '.github/workflows/frontend-verification.yml';

const workflowPath =
    resolve(
        repositoryRoot,
        workflowRelativePath,
    );

const checkoutAction =
    'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1';

const setupNodeAction =
    'actions/setup-node@48b55a011bda9f5d6aeb4c2d9c7362e8dae4041e';

const expectedRunCommands = [
    'npm ci',
    'npm run frontend:ci:check',
    'npm run frontend:verify',
];

const expectedStepMarkers = [
    '- name: Check out repository',
    '- name: Set up Node.js',
    '- name: Install dependencies',
    '- name: Verify CI workflow contract',
    '- name: Verify frontend',
];

function repositoryPath(
    path,
) {
    return relative(
        repositoryRoot,
        path,
    ).replaceAll(
        '\\',
        '/',
    );
}

function addFinding(
    findings,
    detector,
) {
    findings.push({
        file:
            workflowRelativePath,
        detector,
    });
}

async function pathExists(
    path,
) {
    try {
        await stat(
            path,
        );

        return true;
    } catch (error) {
        if (
            error
            && typeof error === 'object'
            && 'code' in error
            && error.code === 'ENOENT'
        ) {
            return false;
        }

        throw error;
    }
}

function normalizeContents(
    contents,
) {
    return contents.replaceAll(
        '\r\n',
        '\n',
    );
}

function trimBlankEdges(
    lines,
) {
    let start =
        0;

    let end =
        lines.length;

    while (
        start < end
        && lines[
            start
        ].trim() === ''
    ) {
        start +=
            1;
    }

    while (
        end > start
        && lines[
            end - 1
        ].trim() === ''
    ) {
        end -=
            1;
    }

    return lines.slice(
        start,
        end,
    );
}

function inspectExactBlock(
    findings,
    lines,
    detector,
    startMarker,
    endMarker,
    expectedLines,
) {
    const startIndex =
        lines.indexOf(
            startMarker,
        );

    const endIndex =
        lines.indexOf(
            endMarker,
        );

    if (
        startIndex < 0
        || endIndex < 0
        || endIndex <= startIndex
    ) {
        addFinding(
            findings,
            `${detector}:missing`,
        );

        return;
    }

    const actual =
        trimBlankEdges(
            lines.slice(
                startIndex,
                endIndex,
            ),
        );

    if (
        JSON.stringify(
            actual,
        ) !== JSON.stringify(
            expectedLines,
        )
    ) {
        addFinding(
            findings,
            `${detector}:mismatch`,
        );
    }
}

function inspectExactLineCount(
    findings,
    lines,
    detector,
    expectedLine,
    expectedCount = 1,
) {
    const actualCount =
        lines.filter(
            (
                line,
            ) =>
                line === expectedLine,
        ).length;

    if (
        actualCount !== expectedCount
    ) {
        addFinding(
            findings,
            `${detector}:count-${actualCount}`,
        );
    }
}

function inspectWorkflowName(
    findings,
    lines,
) {
    if (
        lines[
            0
        ] !== 'name: Frontend Verification'
    ) {
        addFinding(
            findings,
            'workflow-name',
        );
    }
}

function inspectTopLevelPolicy(
    findings,
    lines,
) {
    inspectExactBlock(
        findings,
        lines,
        'trigger-policy',
        'on:',
        'permissions:',
        [
            'on:',
            '  pull_request:',
            '    branches:',
            '      - main',
            '  push:',
            '    branches:',
            '      - main',
            '  workflow_dispatch:',
        ],
    );

    inspectExactBlock(
        findings,
        lines,
        'permission-policy',
        'permissions:',
        'concurrency:',
        [
            'permissions:',
            '  contents: read',
        ],
    );

    inspectExactBlock(
        findings,
        lines,
        'concurrency-policy',
        'concurrency:',
        'jobs:',
        [
            'concurrency:',
            '  group: frontend-verification-${{ github.workflow }}-${{ github.ref }}',
            '  cancel-in-progress: true',
        ],
    );
}

function inspectJobOwnership(
    findings,
    lines,
) {
    const jobsIndex =
        lines.indexOf(
            'jobs:',
        );

    if (
        jobsIndex < 0
    ) {
        addFinding(
            findings,
            'jobs-section-missing',
        );

        return;
    }

    const jobIds =
        lines.slice(
            jobsIndex + 1,
        )
            .map(
                (
                    line,
                ) =>
                    /^  ([A-Za-z0-9_-]+):\s*$/u.exec(
                        line,
                    ),
            )
            .filter(
                Boolean,
            )
            .map(
                (
                    match,
                ) =>
                    match[
                        1
                    ],
            );

    if (
        JSON.stringify(
            jobIds,
        ) !== JSON.stringify(
            [
                'frontend-verification',
            ],
        )
    ) {
        addFinding(
            findings,
            `job-ownership:${jobIds.join(',') || 'none'}`,
        );
    }

    inspectExactLineCount(
        findings,
        lines,
        'job-name',
        '    name: Verify frontend security and build gates',
    );

    inspectExactLineCount(
        findings,
        lines,
        'runner',
        '    runs-on: ubuntu-latest',
    );

    inspectExactLineCount(
        findings,
        lines,
        'timeout',
        '    timeout-minutes: 15',
    );
}

function inspectActionUses(
    findings,
    lines,
) {
    const uses =
        [];

    for (
        const line of lines
    ) {
        const match =
            /^\s*uses:\s+([^\s#]+)(?:\s+#.*)?$/u.exec(
                line,
            );

        if (
            match
        ) {
            uses.push(
                match[
                    1
                ],
            );
        }
    }

    const expected = [
        checkoutAction,
        setupNodeAction,
    ];

    if (
        JSON.stringify(
            uses,
        ) !== JSON.stringify(
            expected,
        )
    ) {
        addFinding(
            findings,
            `action-ownership:${uses.join(',') || 'none'}`,
        );
    }

    for (
        const action of uses
    ) {
        const separatorIndex =
            action.lastIndexOf(
                '@',
            );

        const ref =
            separatorIndex >= 0
                ? action.slice(
                    separatorIndex + 1,
                )
                : '';

        if (
            ! /^[0-9a-f]{40}$/u.test(
                ref,
            )
        ) {
            addFinding(
                findings,
                `mutable-action-ref:${action}`,
            );
        }
    }

    inspectExactLineCount(
        findings,
        lines,
        'checkout-pin',
        `        uses: ${checkoutAction} # v7.0.1`,
    );

    inspectExactLineCount(
        findings,
        lines,
        'setup-node-pin',
        `        uses: ${setupNodeAction} # v6.4.0`,
    );
}

function inspectActionConfiguration(
    findings,
    lines,
) {
    inspectExactLineCount(
        findings,
        lines,
        'checkout-credentials',
        '          persist-credentials: false',
    );

    inspectExactLineCount(
        findings,
        lines,
        'node-version',
        "          node-version: '22'",
    );

    inspectExactLineCount(
        findings,
        lines,
        'npm-cache',
        '          cache: npm',
    );

    inspectExactLineCount(
        findings,
        lines,
        'cache-lockfile',
        '          cache-dependency-path: package-lock.json',
    );
}

function inspectRunCommands(
    findings,
    lines,
) {
    const runCommands =
        [];

    for (
        const line of lines
    ) {
        const match =
            /^\s*run:\s+(.+?)\s*$/u.exec(
                line,
            );

        if (
            match
        ) {
            runCommands.push(
                match[
                    1
                ],
            );
        }
    }

    if (
        JSON.stringify(
            runCommands,
        ) !== JSON.stringify(
            expectedRunCommands,
        )
    ) {
        addFinding(
            findings,
            `run-command-ownership:${runCommands.join(' -> ') || 'none'}`,
        );
    }
}

function inspectStepOrder(
    findings,
    lines,
) {
    const indexes =
        expectedStepMarkers.map(
            (
                marker,
            ) =>
                lines.findIndex(
                    (
                        line,
                    ) =>
                        line.trim() === marker,
                ),
        );

    if (
        indexes.some(
            (
                index,
            ) =>
                index < 0,
        )
    ) {
        addFinding(
            findings,
            'step-order:missing-step',
        );

        return;
    }

    for (
        let index = 1;
        index < indexes.length;
        index += 1
    ) {
        if (
            indexes[
                index
            ] <= indexes[
                index - 1
            ]
        ) {
            addFinding(
                findings,
                'step-order:mismatch',
            );

            return;
        }
    }
}

function inspectForbiddenPatterns(
    findings,
    contents,
) {
    const forbiddenPatterns = [
        [
            'dangerous-trigger:pull-request-target',
            /\bpull_request_target\s*:/u,
        ],
        [
            'dangerous-trigger:workflow-run',
            /\bworkflow_run\s*:/u,
        ],
        [
            'dangerous-trigger:schedule',
            /^\s*schedule\s*:/mu,
        ],
        [
            'write-permission',
            /^\s*(?:actions|checks|contents|deployments|id-token|issues|packages|pages|pull-requests|security-events|statuses):\s*write\s*$/mu,
        ],
        [
            'continue-on-error',
            /^\s*continue-on-error:\s*true\s*$/mu,
        ],
        [
            'conditional-execution',
            /^\s*if:\s*/mu,
        ],
        [
            'workflow-env',
            /^\s*env:\s*$/mu,
        ],
        [
            'secrets-context',
            /\$\{\{\s*secrets\./u,
        ],
        [
            'backend-command',
            /^\s*run:\s*.*\b(?:php|composer)\b.*$/miu,
        ],
        [
            'e2e-command',
            /^\s*run:\s*.*(?:frontend:e2e|playwright).*$/miu,
        ],
        [
            'non-ci-install',
            /^\s*run:\s*npm\s+(?:install|i)\b.*$/miu,
        ],
    ];

    for (
        const [
            detector,
            pattern,
        ] of forbiddenPatterns
    ) {
        if (
            pattern.test(
                contents,
            )
        ) {
            addFinding(
                findings,
                detector,
            );
        }
    }
}

async function inspectWorkflow(
    findings,
) {
    if (
        ! await pathExists(
            workflowPath,
        )
    ) {
        addFinding(
            findings,
            'workflow-missing',
        );

        return;
    }

    const workflowStat =
        await stat(
            workflowPath,
        );

    if (
        ! workflowStat.isFile()
    ) {
        addFinding(
            findings,
            'workflow-not-file',
        );

        return;
    }

    const contents =
        normalizeContents(
            await readFile(
                workflowPath,
                'utf8',
            ),
        );

    if (
        contents.length === 0
    ) {
        addFinding(
            findings,
            'workflow-empty',
        );

        return;
    }

    if (
        ! contents.endsWith(
            '\n',
        )
    ) {
        addFinding(
            findings,
            'workflow-final-newline',
        );
    }

    const lines =
        contents.split(
            '\n',
        );

    inspectWorkflowName(
        findings,
        lines,
    );

    inspectTopLevelPolicy(
        findings,
        lines,
    );

    inspectJobOwnership(
        findings,
        lines,
    );

    inspectActionUses(
        findings,
        lines,
    );

    inspectActionConfiguration(
        findings,
        lines,
    );

    inspectRunCommands(
        findings,
        lines,
    );

    inspectStepOrder(
        findings,
        lines,
    );

    inspectForbiddenPatterns(
        findings,
        contents,
    );
}

function normalizeFindings(
    findings,
) {
    return [
        ...new Map(
            findings
                .map(
                    (
                        finding,
                    ) => [
                        `${finding.file}\u0000${finding.detector}`,
                        finding,
                    ],
                ),
        ).values(),
    ].sort(
        (
            left,
            right,
        ) =>
            left.file.localeCompare(
                right.file,
            )
            || left.detector.localeCompare(
                right.detector,
            ),
    );
}

async function main() {
    const findings = [];

    await inspectWorkflow(
        findings,
    );

    const normalizedFindings =
        normalizeFindings(
            findings,
        );

    console.log(
        `Frontend CI workflow: ${repositoryPath(workflowPath)}`,
    );

    console.log(
        'Frontend CI Node runtime: 22',
    );

    console.log(
        `Frontend CI checkout action: ${checkoutAction}`,
    );

    console.log(
        `Frontend CI setup-node action: ${setupNodeAction}`,
    );

    if (
        normalizedFindings.length > 0
    ) {
        console.error(
            'Frontend CI enforcement verification failed.',
        );

        for (
            const finding of normalizedFindings
        ) {
            console.error(
                `- ${finding.file}: ${finding.detector}`,
            );
        }

        console.error(
            `CI enforcement finding count: ${normalizedFindings.length}`,
        );

        process.exitCode =
            1;

        return;
    }

    console.log(
        'Frontend CI enforcement verification passed.',
    );

    console.log(
        'CI enforcement finding count: 0',
    );
}

main().catch(
    (
        error,
    ) => {
        console.error(
            'Frontend CI enforcement verification failed unexpectedly.',
        );

        console.error(
            error instanceof Error
                ? error.message
                : String(
                    error,
                ),
        );

        process.exitCode =
            1;
    },
);
