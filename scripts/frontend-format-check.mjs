import {
    readdir,
    readFile,
    stat,
} from 'node:fs/promises';
import {
    extname,
    join,
    relative,
    resolve,
} from 'node:path';

const repositoryRoot =
    process.cwd();

const allowedExtensions =
    new Set([
        '.css',
        '.html',
        '.js',
        '.json',
        '.mjs',
        '.ts',
        '.tsx',
    ]);

const ignoredDirectoryNames =
    new Set([
        'dist',
        'generated',
        'node_modules',
    ]);

const inspectionTargets = [
    'package.json',
    'eslint.config.js',
    'scripts/frontend-artifact-security-check.mjs',
    'scripts/frontend-format-check.mjs',
    'vitest.config.ts',
    'frontend/index.html',
    'frontend/tsconfig.json',
    'frontend/vite.config.ts',
    'frontend/src',
];

async function collectFiles(
    targetPath,
) {
    const absolutePath =
        resolve(
            repositoryRoot,
            targetPath,
        );

    const targetStat =
        await stat(
            absolutePath,
        );

    if (
        targetStat.isFile()
    ) {
        return allowedExtensions.has(
            extname(
                absolutePath,
            ),
        )
            ? [
                absolutePath,
            ]
            : [];
    }

    const entries =
        await readdir(
            absolutePath,
            {
                withFileTypes:
                    true,
            },
        );

    const files = [];

    for (
        const entry of entries
    ) {
        if (
            entry.isDirectory()
            && ignoredDirectoryNames.has(
                entry.name,
            )
        ) {
            continue;
        }

        const childPath =
            join(
                absolutePath,
                entry.name,
            );

        if (
            entry.isDirectory()
        ) {
            files.push(
                ...(
                    await collectFiles(
                        relative(
                            repositoryRoot,
                            childPath,
                        ),
                    )
                ),
            );

            continue;
        }

        if (
            entry.isFile()
            && allowedExtensions.has(
                extname(
                    entry.name,
                ),
            )
        ) {
            files.push(
                childPath,
            );
        }
    }

    return files;
}

function inspectFile(
    filePath,
    contents,
) {
    const problems = [];

    if (
        contents.includes(
            '\t',
        )
    ) {
        problems.push(
            'contains tab characters',
        );
    }

    const lines =
        contents.split(
            /\r?\n/u,
        );

    lines.forEach(
        (
            line,
            index,
        ) => {
            if (
                /[ \t]+$/u.test(
                    line,
                )
            ) {
                problems.push(
                    `line ${index + 1} has trailing whitespace`,
                );
            }
        },
    );

    if (
        contents.length > 0
        && ! contents.endsWith(
            '\n',
        )
    ) {
        problems.push(
            'is missing a final newline',
        );
    }

    return problems.map(
        (
            problem,
        ) =>
            `${
                relative(
                    repositoryRoot,
                    filePath,
                )
            }: ${problem}`,
    );
}

const files = [];

for (
    const target of inspectionTargets
) {
    files.push(
        ...(
            await collectFiles(
                target,
            )
        ),
    );
}

files.sort();

const problems = [];

for (
    const filePath of files
) {
    const contents =
        await readFile(
            filePath,
            'utf8',
        );

    problems.push(
        ...inspectFile(
            filePath,
            contents,
        ),
    );
}

if (
    problems.length > 0
) {
    console.error(
        'Frontend formatting verification failed.',
    );

    for (
        const problem of problems
    ) {
        console.error(
            `- ${problem}`,
        );
    }

    process.exitCode =
        1;
} else {
    console.log(
        `Frontend formatting verification passed for ${files.length} files.`,
    );
}
