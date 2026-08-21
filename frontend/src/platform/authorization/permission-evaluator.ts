import type {
    PermissionName,
} from '@/platform/authorization/contract';
import type {
    CapabilityProjectionData,
} from '@/platform/authorization/state';

export interface PermissionEvaluator {
    /*
     * Exact canonical permission matching only.
     *
     * Frontend does not implement wildcard, prefix,
     * hierarchy, role inheritance, or superadmin bypass.
     */
    has(
        permission:
            PermissionName,
    ): boolean;

    hasAll(
        permissions:
            readonly PermissionName[],
    ): boolean;

    hasAny(
        permissions:
            readonly PermissionName[],
    ): boolean;
}

export function createPermissionEvaluator(
    projection:
        CapabilityProjectionData,
): PermissionEvaluator {
    /*
     * Take an immutable-by-ownership snapshot rather than
     * repeatedly scanning projection.permissions.
     *
     * The Set itself remains private so consumers cannot
     * mutate authorization authority through this API.
     */
    const permissions =
        new Set<
            PermissionName
        >(
            projection.permissions,
        );

    const has =
        (
            permission:
                PermissionName,
        ): boolean =>
            permissions.has(
                permission,
            );

    return {
        has,

        hasAll(
            requiredPermissions,
        ) {
            /*
             * No required permission means there is no
             * permission restriction at this evaluation
             * boundary.
             */
            return requiredPermissions
                .every(
                    has,
                );
        },

        hasAny(
            requiredPermissions,
        ) {
            /*
             * An empty ANY requirement cannot be satisfied.
             */
            return requiredPermissions
                .some(
                    has,
                );
        },
    };
}
