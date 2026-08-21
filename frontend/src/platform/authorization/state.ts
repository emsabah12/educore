import type {
    BrowserApiFailure,
} from '@/platform/api';

import type {
    TenantCapabilityData,
    WorkspaceCapabilityData,
} from '@/platform/authorization/contract';

import type {
    InvalidCapabilityProjection,
} from '@/platform/authorization/validation';

export type CapabilityProjectionData =
    | TenantCapabilityData
    | WorkspaceCapabilityData;

export type CapabilityStateFailure =
    | BrowserApiFailure
    | InvalidCapabilityProjection;

export interface UnresolvedCapabilityState {
    readonly status:
        'unresolved';
}

export interface LoadingCapabilityState {
    readonly status:
        'loading';
}

export interface ReadyCapabilityState {
    readonly status:
        'ready';

    readonly projection:
        CapabilityProjectionData;
}

export interface UnavailableCapabilityState {
    readonly status:
        'unavailable';

    readonly failure:
        CapabilityStateFailure;
}

export type CapabilityState =
    | UnresolvedCapabilityState
    | LoadingCapabilityState
    | ReadyCapabilityState
    | UnavailableCapabilityState;

export type CapabilityAction =
    | {
        readonly type:
            'LOAD_STARTED';
    }
    | {
        readonly type:
            'PROJECTION_ACCEPTED';

        readonly projection:
            CapabilityProjectionData;
    }
    | {
        readonly type:
            'LOAD_FAILED';

        readonly failure:
            CapabilityStateFailure;
    }
    | {
        readonly type:
            'RESET';
    };

export function createInitialCapabilityState():
    UnresolvedCapabilityState {
    return {
        status:
            'unresolved',
    };
}

function invalidTransition(
    state:
        CapabilityState,
    action:
        CapabilityAction,
): never {
    throw new Error(
        [
            'Invalid EduCore Capability transition:',
            state.status,
            '->',
            action.type,
        ].join(' '),
    );
}

export function capabilityReducer(
    state:
        CapabilityState,
    action:
        CapabilityAction,
): CapabilityState {
    switch (
        action.type
    ) {
        case 'LOAD_STARTED':
            /*
             * Starting any capability load removes previous
             * authorization authority immediately.
             *
             * Runtime operation revisions will later guard
             * against stale asynchronous completions.
             *
             * This reducer intentionally does not preserve
             * a previous capability projection while loading.
             */
            return {
                status:
                    'loading',
            };

        case 'PROJECTION_ACCEPTED':
            if (
                state.status
                    !== 'loading'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'ready',

                projection:
                    action.projection,
            };

        case 'LOAD_FAILED':
            if (
                state.status
                    !== 'loading'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'unavailable',

                failure:
                    action.failure,
            };

        case 'RESET':
            return createInitialCapabilityState();
    }
}
