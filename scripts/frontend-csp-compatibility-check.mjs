import { readFile, readdir, stat } from 'node:fs/promises';
import { extname, join, relative, resolve } from 'node:path';

const repositoryRoot = process.cwd();
const sourceRoot = resolve(repositoryRoot, 'frontend/src');
const sourceHtmlPath = resolve(repositoryRoot, 'frontend/index.html');
const artifactRoot = resolve(repositoryRoot, 'frontend/dist');
const artifactHtmlPath = resolve(artifactRoot, 'index.html');

const productionCspPolicy = Object.freeze([
    "default-src 'none'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self'",
    "font-src 'self'",
    "connect-src 'self'",
    "media-src 'self'",
    "worker-src 'self'",
    "manifest-src 'self'",
    "object-src 'none'",
    "base-uri 'none'",
    "frame-ancestors 'none'",
    "frame-src 'none'",
    "form-action 'self'",
]);

const htmlDetectors = [
    ['inline-script-block', /<script\b(?![^>]*\bsrc\s*=)[^>]*>[\s\S]*?<\/script>/iu],
    ['inline-style-block', /<style\b[^>]*>/iu],
    ['inline-style-attribute', /\sstyle\s*=/iu],
    ['inline-event-handler', /\son[a-z][a-z0-9_-]*\s*=/iu],
    ['meta-csp-delivery', /<meta\b[^>]*http-equiv\s*=\s*["']?Content-Security-Policy/iu],
    ['base-element', /<base\b/iu],
    ['frame-element', /<iframe\b/iu],
    ['object-element', /<object\b/iu],
    ['embed-element', /<embed\b/iu],
    [
        'external-resource-origin',
        /\b(?:src|href|action)\s*=\s*["']\s*(?:https?:|wss?:|\/\/)/iu,
    ],
    [
        'data-or-blob-resource',
        /\b(?:src|href|action)\s*=\s*["']\s*(?:data:|blob:)/iu,
    ],
];

const sourceScriptDetectors = [
    ['eval-call', /(^|[^\w$.])eval\s*\(/u],
    ['new-function-constructor', /\bnew\s+Function\s*\(/u],
    ['function-string-constructor', /\bFunction\s*\(\s*["'`]/u],
    ['string-timer', /\b(?:setTimeout|setInterval)\s*\(\s*["'`]/u],
    [
        'dynamic-script-element',
        /\bcreateElement\s*\(\s*["'`]script["'`]/iu,
    ],
    ['document-write', /\bdocument\.write\s*\(/u],
    [
        'external-origin-literal',
        /["'`](?:https?:\/\/|wss?:\/\/)/iu,
    ],
    [
        'data-or-blob-uri-literal',
        /["'`](?:data:|blob:)/iu,
    ],
];

const productionScriptDetectors = [
    ['eval-call', /(^|[^\w$.])eval\s*\(/u],
    ['new-function-constructor', /\bnew\s+Function\s*\(/u],
];

const jsxDetectors = [
    ['jsx-inline-style', /<[^>]+\sstyle\s*=/iu],
    ['jsx-script-element', /<script\b/iu],
    ['jsx-style-element', /<style\b/iu],
    ['jsx-frame-element', /<iframe\b/iu],
    ['jsx-object-element', /<object\b/iu],
    ['jsx-embed-element', /<embed\b/iu],
];

const cssDetectors = [
    [
        'external-css-url',
        /url\s*\(\s*["']?\s*(?:https?:|\/\/)/iu,
    ],
    [
        'external-css-import',
        /@import\s+(?:url\s*\()?\s*["']?\s*(?:https?:|\/\/)/iu,
    ],
    [
        'data-or-blob-css-url',
        /url\s*\(\s*["']?\s*(?:data:|blob:)/iu,
    ],
];

async function pathExists(path) {
    try {
        await stat(path);
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

async function collectFiles(directory) {
    const files = [];
    const entries = await readdir(
        directory,
        {
            withFileTypes: true,
        },
    );

    for (const entry of entries) {
        const childPath = join(
            directory,
            entry.name,
        );

        if (entry.isDirectory()) {
            files.push(
                ...await collectFiles(
                    childPath,
                ),
            );
        } else if (entry.isFile()) {
            files.push(
                childPath,
            );
        }
    }

    return files;
}

function repositoryPath(path) {
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
    file,
    detector,
) {
    findings.push({
        file,
        detector,
    });
}

function inspectContents(
    findings,
    file,
    contents,
    detectors,
) {
    for (const [
        detector,
        pattern,
    ] of detectors) {
        if (
            pattern.test(
                contents,
            )
        ) {
            addFinding(
                findings,
                file,
                detector,
            );
        }
    }
}

function isExcludedRuntimeSource(path) {
    const file = repositoryPath(
        path,
    );

    return file.startsWith(
        'frontend/src/platform/api/generated/',
    )
        || file.includes(
            '/__tests__/',
        )
        || /\.(?:test|spec)\.(?:js|jsx|ts|tsx)$/u.test(
            file,
        )
        || file.endsWith(
            '.d.ts',
        );
}

function inspectPolicy(findings) {
    const rendered =
        productionCspPolicy.join(
            '; ',
        );

    for (const source of [
        "'unsafe-inline'",
        "'unsafe-eval'",
    ]) {
        if (
            rendered.includes(
                source,
            )
        ) {
            addFinding(
                findings,
                'ADR-030-policy-projection',
                `forbidden-policy-source:${source}`,
            );
        }
    }

    return rendered;
}

async function inspectHtml(
    findings,
    path,
    missingDetector,
) {
    if (
        ! await pathExists(
            path,
        )
    ) {
        addFinding(
            findings,
            repositoryPath(
                path,
            ),
            missingDetector,
        );

        return;
    }

    inspectContents(
        findings,
        repositoryPath(
            path,
        ),
        await readFile(
            path,
            'utf8',
        ),
        htmlDetectors,
    );
}

async function inspectRuntimeSource(findings) {
    if (
        ! await pathExists(
            sourceRoot,
        )
    ) {
        addFinding(
            findings,
            'frontend/src',
            'runtime-source-root-missing',
        );

        return 0;
    }

    let inspectedFiles = 0;

    for (
        const path of await collectFiles(
            sourceRoot,
        )
    ) {
        if (
            isExcludedRuntimeSource(
                path,
            )
        ) {
            continue;
        }

        const extension =
            extname(
                path,
            ).toLowerCase();

        const isScript = [
            '.js',
            '.jsx',
            '.ts',
            '.tsx',
        ].includes(
            extension,
        );

        const isCss =
            extension === '.css';

        if (
            ! isScript
            && ! isCss
        ) {
            continue;
        }

        const contents =
            await readFile(
                path,
                'utf8',
            );

        const file =
            repositoryPath(
                path,
            );

        inspectedFiles += 1;

        if (isScript) {
            inspectContents(
                findings,
                file,
                contents,
                sourceScriptDetectors,
            );
        }

        if (
            extension === '.jsx'
            || extension === '.tsx'
        ) {
            inspectContents(
                findings,
                file,
                contents,
                jsxDetectors,
            );
        }

        if (isCss) {
            inspectContents(
                findings,
                file,
                contents,
                cssDetectors,
            );
        }
    }

    return inspectedFiles;
}

async function inspectProductionArtifacts(
    findings,
) {
    if (
        ! await pathExists(
            artifactRoot,
        )
    ) {
        addFinding(
            findings,
            'frontend/dist',
            'artifact-root-missing',
        );

        return {
            artifactFiles: 0,
            javascriptFiles: 0,
        };
    }

    const artifactStat =
        await stat(
            artifactRoot,
        );

    if (
        ! artifactStat.isDirectory()
    ) {
        addFinding(
            findings,
            'frontend/dist',
            'artifact-root-not-directory',
        );

        return {
            artifactFiles: 0,
            javascriptFiles: 0,
        };
    }

    const files =
        await collectFiles(
            artifactRoot,
        );

    if (
        files.length === 0
    ) {
        addFinding(
            findings,
            'frontend/dist',
            'artifact-root-empty',
        );
    }

    let javascriptFiles = 0;

    for (const path of files) {
        const extension =
            extname(
                path,
            ).toLowerCase();

        const file =
            repositoryPath(
                path,
            );

        if (
            extension === '.js'
            || extension === '.mjs'
        ) {
            javascriptFiles += 1;

            inspectContents(
                findings,
                file,
                await readFile(
                    path,
                    'utf8',
                ),
                productionScriptDetectors,
            );
        } else if (
            extension === '.css'
        ) {
            inspectContents(
                findings,
                file,
                await readFile(
                    path,
                    'utf8',
                ),
                cssDetectors,
            );
        }
    }

    if (
        javascriptFiles === 0
    ) {
        addFinding(
            findings,
            'frontend/dist',
            'production-javascript-missing',
        );
    }

    return {
        artifactFiles:
            files.length,
        javascriptFiles,
    };
}

function normalizeFindings(findings) {
    const unique =
        new Map();

    for (const finding of findings) {
        unique.set(
            `${finding.file}\u0000${finding.detector}`,
            finding,
        );
    }

    return [
        ...unique.values(),
    ].sort(
        (
            left,
            right,
        ) => {
            const fileComparison =
                left.file.localeCompare(
                    right.file,
                );

            return fileComparison !== 0
                ? fileComparison
                : left.detector.localeCompare(
                    right.detector,
                );
        },
    );
}

async function main() {
    const findings = [];

    const renderedPolicy =
        inspectPolicy(
            findings,
        );

    await inspectHtml(
        findings,
        sourceHtmlPath,
        'source-html-missing',
    );

    const runtimeSourceFiles =
        await inspectRuntimeSource(
            findings,
        );

    await inspectHtml(
        findings,
        artifactHtmlPath,
        'production-html-missing',
    );

    const artifactSummary =
        await inspectProductionArtifacts(
            findings,
        );

    const normalizedFindings =
        normalizeFindings(
            findings,
        );

    console.log(
        `CSP compatibility policy: ${renderedPolicy};`,
    );

    console.log(
        `Runtime source files inspected: ${runtimeSourceFiles}`,
    );

    console.log(
        `Production artifact files inspected: ${artifactSummary.artifactFiles}`,
    );

    console.log(
        `Production JavaScript files inspected: ${artifactSummary.javascriptFiles}`,
    );

    if (
        normalizedFindings.length > 0
    ) {
        console.error(
            'Frontend CSP compatibility verification failed.',
        );

        for (
            const finding of normalizedFindings
        ) {
            console.error(
                `- ${finding.file}: ${finding.detector}`,
            );
        }

        console.error(
            `CSP compatibility finding count: ${normalizedFindings.length}`,
        );

        process.exitCode = 1;

        return;
    }

    console.log(
        'Frontend CSP compatibility verification passed.',
    );

    console.log(
        'CSP compatibility finding count: 0',
    );
}

main().catch(
    () => {
        console.error(
            'Frontend CSP compatibility verification failed because the scanner could not complete safely.',
        );

        process.exitCode = 1;
    },
);
