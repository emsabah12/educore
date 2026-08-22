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
    ]);