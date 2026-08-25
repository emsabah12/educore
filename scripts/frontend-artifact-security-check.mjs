import {
    readFile,
    readdir,
    stat,
} from 'node:fs/promises';
import {
    basename,
    extname,
    join,
    relative,
    resolve,
} from 'node:path';

const repositoryRoot =
    process.cwd();

const artifactRoot =
    resolve(
        repositoryRoot,
        'frontend/dist',
    );

const browserSourceRoot =
    resolve(
        repositoryRoot,
        'frontend/src',
    );

const browserHtmlPath =
    resolve(
        repositoryRoot,
        'frontend/index.html',
    );

const envExamplePath =
    resolve(
        repositoryRoot,
        '.env.example',
    );

const textArtifactExtensions =
    new Set([
        '.css',
        '.html',
        '.js',
        '.json',
        '.mjs',
        '.svg',
        '.txt',
        '.xml',
    ]);

const browserSourceExtensions =
    new Set([
        '.js',
        '.jsx',
        '.ts',
        '.tsx',
    ]);

const privateCredentialExtensions =
    new Set([
        '.key',
        '.p12',
        '.pem',
        '.pfx',
    ]);

const artifactContentDetectors = [
    {
        name:
            'source-map-reference',
        pattern:
            /(?:\/\/[#@]\s*sourceMappingURL=|\/\*#\s*sourceMappingURL=)/u,
    },
    {
        name:
            'private-key-marker',
        pattern:
            /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/u,
    },
    {
        name:
            'aws-access-key',
        pattern:
            /\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/u,
    },
    {
        name:
            'github-token',
        pattern:
            /\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/u,
    },
    {
        name:
            'jwt-token',
        pattern:
            /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/u,
    },
    {
        name:
            'generic-secret-assignment',
        pattern:
            /\b(?:client_secret|api_key|access_key|secret_key|private_key|password)\b["']?\s*[:=]\s*["'][^"'\r\n]{8,}["']/iu,
    },
];

const suspiciousPublicEnvironmentNamePattern =
    /^VITE_[A-Za-z0-9_]*(?:SECRET|TOKEN|PASSWORD|PRIVATE|API_KEY|ACCESS_KEY|CLIENT_SECRET|DATABASE)[A-Za-z0-9_]*$/u;

const browserEnvironmentDetectors = [
    {
        name:
            'browser-import-meta-env',
        pattern:
            /\bimport\.meta\.env\b/u,
    },
    {
        name:
            'browser-process-env',
        pattern:
            /\bprocess\.env\b/u,
    },
];

async function pathExists(
    absolutePath,
) {
    try {
        await stat(
            absolutePath,
        );

        return true;
    } catch (
        error
    ) {
        if (
            error
            && typeof error
                === 'object'
            && 'code' in error
            && error.code
                === 'ENOENT'
        ) {
            return false;
        }

        throw error;
    }
}

async function collectFiles(
    directory,
) {
    const files =
        [];

    const entries =
        await readdir(
            directory,
            {
                withFileTypes:
                    true,
            },
        );

    for (
        const entry of entries
    ) {
        const childPath =
            join(
                directory,
                entry.name,
            );

        if (
            entry.isDirectory()
        ) {
            files.push(
                ...(
                    await collectFiles(
                        childPath,
                    )
                ),
            );

            continue;
        }

        if (
            entry.isFile()
        ) {
            files.push(
                childPath,
            );
        }
    }

    return files;
}

function repositoryPath(
    absolutePath,
) {
    return relative(
        repositoryRoot,
        absolutePath,
    ).replaceAll(
        '\\',
        '/',
    );
}

function addFinding(
    findings,
    filePath,
    detector,
) {
    findings.push({
        file:
            filePath,
        detector,
    });
}

function inspectArtifactFilename(
    findings,
    absolutePath,
) {
    const filename =
        basename(
            absolutePath,
        );

    const extension =
        extname(
            filename,
        ).toLowerCase();

    if (
        extension
            === '.map'
    ) {
        addFinding(
            findings,
            repositoryPath(
                absolutePath,
            ),
            'forbidden-file:source-map',
        );
    }

    if (
        filename
            === '.env'
        || filename.startsWith(
            '.env.',
        )
    ) {
        addFinding(
            findings,
            repositoryPath(
                absolutePath,
            ),
            'forbidden-file:environment',
        );
    }

    if (
        privateCredentialExtensions.has(
            extension,
        )
    ) {
        addFinding(
            findings,
            repositoryPath(
                absolutePath,
            ),
            'forbidden-file:private-credential',
        );
    }
}

async function inspectArtifactContents(
    findings,
    absolutePath,
) {
    const extension =
        extname(
            absolutePath,
        ).toLowerCase();

    if (
        ! textArtifactExtensions.has(
            extension,
        )
    ) {
        return;
    }

    const contents =
        await readFile(
            absolutePath,
            'utf8',
        );

    for (
        const detector of artifactContentDetectors
    ) {
        if (
            detector.pattern.test(
                contents,
            )
        ) {
            addFinding(
                findings,
                repositoryPath(
                    absolutePath,
                ),
                `secret-or-source:${detector.name}`,
            );
        }
    }
}

async function inspectBrowserSource(
    findings,
) {
    if (
        ! await pathExists(
            browserSourceRoot,
        )
    ) {
        addFinding(
            findings,
            'frontend/src',
            'browser-source-root-missing',
        );

        return;
    }

    const files =
        await collectFiles(
            browserSourceRoot,
        );

    for (
        const filePath of files
    ) {
        if (
            ! browserSourceExtensions.has(
                extname(
                    filePath,
                ).toLowerCase(),
            )
        ) {
            continue;
        }

        if (
            repositoryPath(
                filePath,
            ).startsWith(
                'frontend/src/platform/api/generated/',
            )
        ) {
            continue;
        }

        const contents =
            await readFile(
                filePath,
                'utf8',
            );

        for (
            const detector of browserEnvironmentDetectors
        ) {
            if (
                detector.pattern.test(
                    contents,
                )
            ) {
                addFinding(
                    findings,
                    repositoryPath(
                        filePath,
                    ),
                    detector.name,
                );
            }
        }
    }
}

async function inspectBrowserHtml(
    findings,
) {
    if (
        ! await pathExists(
            browserHtmlPath,
        )
    ) {
        addFinding(
            findings,
            'frontend/index.html',
            'browser-html-missing',
        );

        return;
    }

    const contents =
        await readFile(
            browserHtmlPath,
            'utf8',
        );

    if (
        /%VITE_[A-Za-z0-9_]+%/u.test(
            contents,
        )
    ) {
        addFinding(
            findings,
            'frontend/index.html',
            'browser-vite-html-reference',
        );
    }
}

async function inspectPublicEnvironmentNames(
    findings,
) {
    if (
        ! await pathExists(
            envExamplePath,
        )
    ) {
        return;
    }

    const contents =
        await readFile(
            envExamplePath,
            'utf8',
        );

    const lines =
        contents.split(
            /\r?\n/u,
        );

    for (
        const line of lines
    ) {
        const match =
            line.match(
                /^[ \t]*(VITE_[A-Za-z0-9_]+)[ \t]*=/u,
            );

        if (
            ! match
        ) {
            continue;
        }

        const variableName =
            match[1];

        if (
            suspiciousPublicEnvironmentNamePattern.test(
                variableName,
            )
        ) {
            addFinding(
                findings,
                '.env.example',
                `suspicious-public-env:${variableName}`,
            );
        }
    }
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

        return 0;
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

        return 0;
    }

    const files =
        await collectFiles(
            artifactRoot,
        );

    if (
        files.length
            === 0
    ) {
        addFinding(
            findings,
            'frontend/dist',
            'artifact-root-empty',
        );
    }

    for (
        const filePath of files
    ) {
        inspectArtifactFilename(
            findings,
            filePath,
        );

        await inspectArtifactContents(
            findings,
            filePath,
        );
    }

    return files.length;
}

function normalizeFindings(
    findings,
) {
    const uniqueFindings =
        new Map();

    for (
        const finding of findings
    ) {
        const key =
            `${finding.file}\u0000${finding.detector}`;

        uniqueFindings.set(
            key,
            finding,
        );
    }

    return [
        ...uniqueFindings.values(),
    ].sort(
        (
            left,
            right,
        ) => {
            const fileComparison =
                left.file.localeCompare(
                    right.file,
                );

            if (
                fileComparison
                    !== 0
            ) {
                return fileComparison;
            }

            return left.detector.localeCompare(
                right.detector,
            );
        },
    );
}

async function main() {
    const findings =
        [];

    const artifactFileCount =
        await inspectProductionArtifacts(
            findings,
        );

    await inspectBrowserSource(
        findings,
    );

    await inspectBrowserHtml(
        findings,
    );

    await inspectPublicEnvironmentNames(
        findings,
    );

    const normalizedFindings =
        normalizeFindings(
            findings,
        );

    if (
        normalizedFindings.length
            > 0
    ) {
        console.error(
            'Frontend production artifact security verification failed.',
        );

        for (
            const finding of normalizedFindings
        ) {
            console.error(
                `- ${finding.file}: ${finding.detector}`,
            );
        }

        console.error(
            `Security finding count: ${normalizedFindings.length}`,
        );

        process.exitCode =
            1;

        return;
    }

    console.log(
        `Frontend production artifact security verification passed for ${artifactFileCount} artifact files.`,
    );

    console.log(
        'Security finding count: 0',
    );
}

main().catch(
    () => {
        console.error(
            'Frontend production artifact security verification failed because the scanner could not complete safely.',
        );

        process.exitCode =
            1;
    },
);
