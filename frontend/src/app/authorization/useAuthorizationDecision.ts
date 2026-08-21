import {
    useMemo,
} from 'react';

import {
    createAuthorizationDecisionEvaluator,
    type AuthorizationDecision,
    type AuthorizationDecisionEvaluator,
    type PermissionRequirement,
} from '@/platform/authorization';

import {
    useCapabilityState,
} from '@/app/authorization/CapabilityContextProvider';

export function useAuthorizationDecisionEvaluator():
    AuthorizationDecisionEvaluator {
    const capabilityState =
        useCapabilityState();

    /*
     * CapabilityRuntime publishes stable snapshots until
     * authority changes.
     *
     * Build one decision evaluator for the current
     * Capability snapshot so a React consumer can evaluate
     * multiple requirements without rebuilding the
     * permission lookup for every decision.
     */
    return useMemo(
        () =>
            createAuthorizationDecisionEvaluator(
                capabilityState,
            ),
        [
            capabilityState,
        ],
    );
}

export function useAuthorizationDecision(
    requirement:
        PermissionRequirement,
): AuthorizationDecision {
    const evaluator =
        useAuthorizationDecisionEvaluator();

    /*
     * Evaluation itself is synchronous and pure.
     *
     * Do not memoize by requirement object identity here:
     * callers may legitimately provide an inline immutable
     * requirement object on every render.
     */
    return evaluator.evaluate(
        requirement,
    );
}
