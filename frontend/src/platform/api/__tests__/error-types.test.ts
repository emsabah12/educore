import {
    describe,
    expectTypeOf,
    it,
} from 'vitest';

import type {
    CanonicalValidationError,
} from '@/platform/api';

describe(
    'Canonical validation error type contract',
    () => {
        it(
            'models human-readable validation messages as runtime strings',
            () => {
                /*
                 * Human-readable backend copy is not a stable
                 * frontend branching contract.
                 *
                 * The runtime normalizer deliberately accepts
                 * canonical VALIDATION_FAILED envelopes whose
                 * message wording changes while their stable
                 * code and validation structure remain valid.
                 *
                 * The narrowed frontend type must therefore
                 * represent that runtime truth instead of
                 * retaining the generated OpenAPI message
                 * literal.
                 */
                expectTypeOf<
                    CanonicalValidationError[
                        'message'
                    ]
                >().toEqualTypeOf<string>();
            },
        );
    },
);
