import {
    normalizeObservabilityException,
    type SafeObservabilityException,
} from '@/platform/observability/exception';
import {
    createSafeObservabilityMetadata,
    type SafeObservabilityMetadata,
} from '@/platform/observability/metadata';

export type ObservabilitySignalKind =
    | 'event'
    | 'exception';

interface ObservabilitySignalBase {
    readonly name:
        string;

    readonly metadata:
        SafeObservabilityMetadata;
}

export interface ObservabilityEventSignal
    extends ObservabilitySignalBase {
    readonly kind:
        'event';
}

export interface ObservabilityExceptionSignal
    extends ObservabilitySignalBase {
    readonly kind:
        'exception';

    readonly exception:
        SafeObservabilityException;
}

export type ObservabilitySignal =
    | ObservabilityEventSignal
    | ObservabilityExceptionSignal;

export interface ObservabilityAdapter {
    capture(
        signal:
            ObservabilitySignal,
    ): void;
}

export interface ObservabilityPort {
    captureEvent(
        name:
            string,
        metadata?:
            Readonly<
                Record<
                    string,
                    unknown
                >
            >,
    ): void;

    captureException(
        name:
            string,
        error:
            unknown,
        metadata?:
            Readonly<
                Record<
                    string,
                    unknown
                >
            >,
    ): void;
}

function createObservabilityEventSignal(
    name:
        string,
    metadata:
        Readonly<
            Record<
                string,
                unknown
            >
        >,
): ObservabilityEventSignal {
    return {
        kind:
            'event',

        name,

        metadata:
            createSafeObservabilityMetadata(
                metadata,
            ),
    };
}

function createObservabilityExceptionSignal(
    name:
        string,
    error:
        unknown,
    metadata:
        Readonly<
            Record<
                string,
                unknown
            >
        >,
): ObservabilityExceptionSignal {
    return {
        kind:
            'exception',

        name,

        metadata:
            createSafeObservabilityMetadata(
                metadata,
            ),

        exception:
            normalizeObservabilityException(
                error,
            ),
    };
}

function captureObservabilitySignal(
    adapter:
        ObservabilityAdapter,
    signal:
        ObservabilitySignal,
): void {
    try {
        adapter.capture(
            signal,
        );
    } catch {
        /*
         * Observability is secondary infrastructure.
         *
         * Provider failure must never break authentication,
         * context recovery, navigation, or business flow.
         *
         * Do not emit the provider exception through another
         * logging channel because the thrown value may itself
         * contain sensitive provider or application context.
         */
    }
}

export function createObservabilityPort(
    adapter:
        ObservabilityAdapter,
): ObservabilityPort {
    return {
        captureEvent(
            name,
            metadata = {},
        ): void {
            captureObservabilitySignal(
                adapter,
                createObservabilityEventSignal(
                    name,
                    metadata,
                ),
            );
        },

        captureException(
            name,
            error,
            metadata = {},
        ): void {
            captureObservabilitySignal(
                adapter,
                createObservabilityExceptionSignal(
                    name,
                    error,
                    metadata,
                ),
            );
        },
    };
}
