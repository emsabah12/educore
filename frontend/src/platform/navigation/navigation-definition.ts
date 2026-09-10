export type ApplicationNavigationDestination =
    `/${string}`;

export interface ApplicationNavigationDefinition {
    /*
     * Stable presentation/navigation identity.
     *
     * This is not a translated label and is not an
     * authorization role or permission.
     */
    readonly id:
        string;

    /*
     * Stable application route identity associated with the
     * registered destination.
     *
     * Navigation may refer to route identity, but it does
     * not copy route authorization policy.
     */
    readonly routeId:
        string;

    readonly label:
        string;

    readonly destination:
        ApplicationNavigationDestination;

    /*
     * Optional Subscription feature code (see
     * TenantSubscriptionService::effectiveFeatureCodes on the
     * backend) required for this destination to be visible.
     *
     * This is deliberately layered ON TOP OF, not instead of,
     * the route's own ProtectedRoutePolicy/permission check —
     * a Subscription feature answers "did the tenant buy
     * this module at all", while the route policy answers
     * "can this specific Membership use it". Omit for
     * destinations that are not feature-gated.
     */
    readonly requiredFeature?:
        string;
}

function requireNonEmpty(
    value:
        string,
    field:
        string,
): string {
    if (
        value.trim().length
            === 0
    ) {
        throw new Error(
            `EduCore navigation definition requires a non-empty ${field}.`,
        );
    }

    return value;
}

function normalizeDestination(
    destination:
        ApplicationNavigationDestination,
): ApplicationNavigationDestination {
    if (
        destination.length
            === 0
        || ! destination.startsWith(
            '/',
        )
        || destination.startsWith(
            '//',
        )
        || destination.includes(
            '\\',
        )
    ) {
        throw new Error(
            'EduCore navigation definition requires a safe root-relative destination.',
        );
    }

    return destination;
}

export function defineApplicationNavigation(
    definition:
        ApplicationNavigationDefinition,
): ApplicationNavigationDefinition {
    return Object.freeze({
        id:
            requireNonEmpty(
                definition.id,
                'id',
            ),

        routeId:
            requireNonEmpty(
                definition.routeId,
                'routeId',
            ),

        label:
            requireNonEmpty(
                definition.label,
                'label',
            ),

        destination:
            normalizeDestination(
                definition.destination,
            ),

        ...(
            definition.requiredFeature === undefined
                ? {}
                : {
                    requiredFeature:
                        requireNonEmpty(
                            definition.requiredFeature,
                            'requiredFeature',
                        ),
                }
        ),
    });
}

export const applicationNavigationCatalog =
    Object.freeze([
        defineApplicationNavigation({
            id:
                'application.home',

            routeId:
                'root',

            label:
                'Beranda',

            destination:
                '/',
        }),

        defineApplicationNavigation({
            id:
                'hr.workforce',

            routeId:
                'hr.workforce.index',

            label:
                'Kepegawaian',

            destination:
                '/hr/workforce',

            requiredFeature:
                'hr_module',
        }),

        defineApplicationNavigation({
            id:
                'settings.tenant-roles',

            routeId:
                'settings.tenant-roles.index',

            label:
                'Role Kustom',

            destination:
                '/settings/roles',

            requiredFeature:
                'custom_roles',
        }),
    ]);
