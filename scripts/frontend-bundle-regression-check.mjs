import {
    readFile,
    readdir,
    stat,
} from 'node:fs/promises';
import {
    extname,
    join,
    relative,
    resolve,
} from 'node:path';
import {
    gzipSync,
} from 'node:zlib';

const repositoryRoot =
    process.cwd();

const artifactRoot =
    resolve(
        repositoryRoot,
        'frontend/dist',
    );

const budgets =
    Object.freeze({
        javascriptAggregateBytes:
            400000,

        javascriptLargestChunkBytes:
            400000,

        cssAggregateBytes:
            20000,
    });

const javascriptExtensions =
    new Set([
        '.js',
        '.mjs',
    ]);

const cssExtensions =
    new Set([
        '.css',
    ]);

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

async function measureAsset(
    absolutePath,
) {
    const contents =
        await readFile(
            absolutePath,
        );

    return {
        file:
            repositoryPath(
                absolutePath,
            ),

        extension:
            extname(
                absolutePath,
            ).toLowerCase(),

        rawBytes:
            contents.length,

        gzipBytes:
            gzipSync(
                contents,
            ).length,
    };
}

function sumMetric(
    assets,
    metric,
) {
    return assets.reduce(
        (
            total,
            asset,
        ) => (
            total
            + asset[
                metric
            ]
        ),
        0,
    );
}

function findLargestAsset(
    assets,
) {
    if (
        assets.length
            === 0
    ) {
        return null;
    }

    return assets.reduce(
        (
            largest,
            current,
        ) => (
            current.rawBytes
                > largest.rawBytes
                ? current
                : largest
        ),
    );
}

function reportAssets(
    label,
    assets,
) {
    console.log(
        `${label} asset count: ${assets.length}`,
    );

    for (
        const asset of assets
    ) {
        console.log(
            `${asset.file} | raw=${asset.rawBytes} | gzip=${asset.gzipBytes}`,
        );
    }
}

function addViolation(
    violations,
    detector,
    actual,
    maximum,
) {
    violations.push({
        detector,
        actual,
        maximum,
    });
}

async function inspectBundle() {
    const violations =
        [];

    if (
        ! await pathExists(
            artifactRoot,
        )
    ) {
        console.error(
            'Frontend bundle regression verification failed.',
        );

        console.error(
            '- frontend/dist: artifact-root-missing',
        );

        process.exitCode =
            1;

        return;
    }

    const artifactStat =
        await stat(
            artifactRoot,
        );

    if (
        ! artifactStat.isDirectory()
    ) {
        console.error(
            'Frontend bundle regression verification failed.',
        );

        console.error(
            '- frontend/dist: artifact-root-not-directory',
        );

        process.exitCode =
            1;

        return;
    }

    const files =
        await collectFiles(
            artifactRoot,
        );

    if (
        files.length
            === 0
    ) {
        console.error(
            'Frontend bundle regression verification failed.',
        );

        console.error(
            '- frontend/dist: artifact-root-empty',
        );

        process.exitCode =
            1;

        return;
    }

    const assets =
        await Promise.all(
            files.map(
                (
                    filePath,
                ) => (
                    measureAsset(
                        filePath,
                    )
                ),
            ),
        );

    assets.sort(
        (
            left,
            right,
        ) => (
            left.file.localeCompare(
                right.file,
            )
        ),
    );

    const javascriptAssets =
        assets.filter(
            (
                asset,
            ) => (
                javascriptExtensions.has(
                    asset.extension,
                )
            ),
        );

    const cssAssets =
        assets.filter(
            (
                asset,
            ) => (
                cssExtensions.has(
                    asset.extension,
                )
            ),
        );

    if (
        javascriptAssets.length
            === 0
    ) {
        console.error(
            'Frontend bundle regression verification failed.',
        );

        console.error(
            '- frontend/dist: javascript-bundle-missing',
        );

        process.exitCode =
            1;

        return;
    }

    const javascriptAggregateRawBytes =
        sumMetric(
            javascriptAssets,
            'rawBytes',
        );

    const javascriptAggregateGzipBytes =
        sumMetric(
            javascriptAssets,
            'gzipBytes',
        );

    const cssAggregateRawBytes =
        sumMetric(
            cssAssets,
            'rawBytes',
        );

    const cssAggregateGzipBytes =
        sumMetric(
            cssAssets,
            'gzipBytes',
        );

    const largestJavascriptAsset =
        findLargestAsset(
            javascriptAssets,
        );

    reportAssets(
        'JavaScript',
        javascriptAssets,
    );

    reportAssets(
        'CSS',
        cssAssets,
    );

    console.log(
        [
            'JavaScript aggregate',
            `raw=${javascriptAggregateRawBytes}`,
            `budget=${budgets.javascriptAggregateBytes}`,
            `gzip=${javascriptAggregateGzipBytes}`,
        ].join(
            ' | ',
        ),
    );

    console.log(
        [
            'Largest JavaScript chunk',
            `file=${largestJavascriptAsset.file}`,
            `raw=${largestJavascriptAsset.rawBytes}`,
            `budget=${budgets.javascriptLargestChunkBytes}`,
            `gzip=${largestJavascriptAsset.gzipBytes}`,
        ].join(
            ' | ',
        ),
    );

    console.log(
        [
            'CSS aggregate',
            `raw=${cssAggregateRawBytes}`,
            `budget=${budgets.cssAggregateBytes}`,
            `gzip=${cssAggregateGzipBytes}`,
        ].join(
            ' | ',
        ),
    );

    if (
        javascriptAggregateRawBytes
            > budgets.javascriptAggregateBytes
    ) {
        addViolation(
            violations,
            'budget-exceeded:javascript-aggregate',
            javascriptAggregateRawBytes,
            budgets.javascriptAggregateBytes,
        );
    }

    if (
        largestJavascriptAsset.rawBytes
            > budgets.javascriptLargestChunkBytes
    ) {
        addViolation(
            violations,
            'budget-exceeded:javascript-largest-chunk',
            largestJavascriptAsset.rawBytes,
            budgets.javascriptLargestChunkBytes,
        );
    }

    if (
        cssAggregateRawBytes
            > budgets.cssAggregateBytes
    ) {
        addViolation(
            violations,
            'budget-exceeded:css-aggregate',
            cssAggregateRawBytes,
            budgets.cssAggregateBytes,
        );
    }

    if (
        violations.length
            > 0
    ) {
        console.error(
            'Frontend bundle regression verification failed.',
        );

        for (
            const violation of violations
        ) {
            console.error(
                [
                    `- ${violation.detector}`,
                    `actual=${violation.actual}`,
                    `maximum=${violation.maximum}`,
                ].join(
                    ' | ',
                ),
            );
        }

        console.error(
            `Bundle budget violation count: ${violations.length}`,
        );

        process.exitCode =
            1;

        return;
    }

    console.log(
        'Frontend bundle regression verification passed.',
    );

    console.log(
        'Bundle budget violation count: 0',
    );
}

inspectBundle().catch(
    () => {
        console.error(
            'Frontend bundle regression verification failed because the scanner could not complete safely.',
        );

        process.exitCode =
            1;
    },
);
