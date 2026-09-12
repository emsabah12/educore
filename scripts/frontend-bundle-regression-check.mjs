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

/*
 * Budget diukur terhadap byte GZIP (terkompresi), bukan raw.
 *
 * Alasan: byte raw adalah ukuran sebelum transfer HTTP — hampir
 * semua deployment produksi (termasuk ini) mengaktifkan kompresi
 * gzip/brotli otomatis di layer HTTP, jadi byte yang benar-benar
 * dikirim ke browser pengguna adalah byte gzip. Raw byte cenderung
 * melebih-lebihkan dampak nyata ke pengguna — 466KB raw terdengar
 * mengkhawatirkan, padahal ~141KB gzip (yang sebenarnya di-download)
 * adalah ukuran yang sehat untuk SPA modern.
 *
 * Nilai budget berikut dihitung dari baseline gzip terukur nyata
 * (bukan tebakan) + buffer wajar untuk pertumbuhan fitur:
 *   - JS aggregate gzip saat ditetapkan: ~144.455 byte -> +~38%
 *   - JS largest chunk gzip saat ditetapkan: ~96.152 byte (vendor
 *     chunk react/react-dom/react-router/react-query) -> +~56%,
 *     lebih longgar karena chunk ini spesifik untuk pertumbuhan
 *     dependency framework, bukan app code
 *   - CSS aggregate gzip saat ditetapkan: ~5.945 byte -> +~150%,
 *     paling longgar karena utility-class Tailwind tumbuh kurang
 *     dapat diprediksi seiring bertambahnya komponen
 */
const budgets =
    Object.freeze({
        javascriptAggregateGzipBytes:
            200000,

        javascriptLargestChunkGzipBytes:
            150000,

        cssAggregateGzipBytes:
            15000,
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
            current.gzipBytes
                > largest.gzipBytes
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
            `gzip=${javascriptAggregateGzipBytes}`,
            `budget(gzip)=${budgets.javascriptAggregateGzipBytes}`,
        ].join(
            ' | ',
        ),
    );

    console.log(
        [
            'Largest JavaScript chunk',
            `file=${largestJavascriptAsset.file}`,
            `raw=${largestJavascriptAsset.rawBytes}`,
            `gzip=${largestJavascriptAsset.gzipBytes}`,
            `budget(gzip)=${budgets.javascriptLargestChunkGzipBytes}`,
        ].join(
            ' | ',
        ),
    );

    console.log(
        [
            'CSS aggregate',
            `raw=${cssAggregateRawBytes}`,
            `gzip=${cssAggregateGzipBytes}`,
            `budget(gzip)=${budgets.cssAggregateGzipBytes}`,
        ].join(
            ' | ',
        ),
    );

    if (
        javascriptAggregateGzipBytes
            > budgets.javascriptAggregateGzipBytes
    ) {
        addViolation(
            violations,
            'budget-exceeded:javascript-aggregate-gzip',
            javascriptAggregateGzipBytes,
            budgets.javascriptAggregateGzipBytes,
        );
    }

    if (
        largestJavascriptAsset.gzipBytes
            > budgets.javascriptLargestChunkGzipBytes
    ) {
        addViolation(
            violations,
            'budget-exceeded:javascript-largest-chunk-gzip',
            largestJavascriptAsset.gzipBytes,
            budgets.javascriptLargestChunkGzipBytes,
        );
    }

    if (
        cssAggregateGzipBytes
            > budgets.cssAggregateGzipBytes
    ) {
        addViolation(
            violations,
            'budget-exceeded:css-aggregate-gzip',
            cssAggregateGzipBytes,
            budgets.cssAggregateGzipBytes,
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
