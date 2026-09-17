import type {
    SVGProps,
} from 'react';
import {
    NavLink,
} from 'react-router';

import {
    useApplicationNavigationProjection,
} from '@/app/navigation/useApplicationNavigationProjection';

export type ApplicationNavigationOrientation =
    | 'horizontal'
    | 'vertical';

export interface ApplicationNavigationProps {
    /*
     * Presentation-only layout switch.
     *
     * Never changes which items are visible — that remains
     * fully owned by useApplicationNavigationProjection().
     * Defaults to 'horizontal' to preserve every existing
     * call site's current behaviour.
     */
    readonly orientation?:
        ApplicationNavigationOrientation;
}

/*
 * Presentation-only icon lookup keyed by the stable
 * navigation id from applicationNavigationCatalog.
 *
 * This map intentionally lives here, not in
 * navigation-definition.ts — the catalog's contract is
 * routing/authorization data, not UI decoration. An unknown
 * id (a future catalog entry we have not styled yet) safely
 * falls back to a generic marker instead of throwing.
 */
function NavigationIcon(
    {
        navigationId,
        ...svgProps
    }: SVGProps<SVGSVGElement> & {
        readonly navigationId:
            string;
    },
) {
    const shared: SVGProps<SVGSVGElement> = {
        width: 18,
        height: 18,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 2,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        'aria-hidden': true,
        ...svgProps,
    };

    switch (navigationId) {
        case 'application.home':
            return (
                <svg {...shared}>
                    <rect x="3" y="3" width="7" height="7" />
                    <rect x="14" y="3" width="7" height="7" />
                    <rect x="14" y="14" width="7" height="7" />
                    <rect x="3" y="14" width="7" height="7" />
                </svg>
            );

        case 'hr.workforce':
            return (
                <svg {...shared}>
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
            );

        case 'hr.compensation':
            return (
                <svg {...shared}>
                    <line x1="12" y1="1" x2="12" y2="23" />
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                </svg>
            );

        case 'settings.tenant-members':
            return (
                <svg {...shared}>
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
            );

        case 'settings.tenant-roles':
            return (
                <svg {...shared}>
                    <path d="M12 2 3 7v6c0 5 4 9 9 9s9-4 9-9V7z" />
                </svg>
            );

        case 'settings.organizations':
            return (
                <svg {...shared}>
                    <path d="M3 21h18" />
                    <path d="M5 21V7l7-4 7 4v14" />
                    <path d="M9 9h1" />
                    <path d="M9 13h1" />
                    <path d="M14 9h1" />
                    <path d="M14 13h1" />
                </svg>
            );

        default:
            return (
                <svg {...shared}>
                    <circle cx="12" cy="12" r="3" />
                </svg>
            );
    }
}

export function ApplicationNavigation(
    {
        orientation = 'horizontal',
    }: ApplicationNavigationProps = {},
) {
    const projection =
        useApplicationNavigationProjection();

    const visibleItems =
        projection.filter(
            (item) =>
                item.status
                    === 'visible',
        );

    /*
     * Navigation is presentation only.
     *
     * Hidden navigation does not grant or revoke access.
     * Protected routing remains the security boundary for
     * direct navigation to registered application routes.
     */
    if (
        visibleItems.length
            === 0
    ) {
        return null;
    }

    const isVertical =
        orientation === 'vertical';

    return (
        <nav
            aria-label="Navigasi utama"
            className={
                isVertical
                    ? 'w-full'
                    : 'min-w-max'
            }
        >
            <ul
                className={
                    isVertical
                        ? 'flex flex-col gap-1'
                        : 'flex min-w-max items-center gap-2'
                }
            >
                {visibleItems.map(
                    ({
                        navigation,
                    }) => (
                        <li
                            key={
                                navigation.id
                            }
                        >
                            <NavLink
                                end={
                                    navigation
                                        .destination
                                        === '/'
                                }
                                to={
                                    navigation
                                        .destination
                                }
                                className={({
                                    isActive,
                                }) =>
                                    (
                                        isVertical
                                            ? [
                                                'flex min-h-11 items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                                                isActive
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                            ]
                                            : [
                                                'inline-flex min-h-10 items-center rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                                                isActive
                                                    ? 'bg-accent text-accent-foreground'
                                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                            ]
                                    ).join(
                                        ' ',
                                    )
                                }
                            >
                                {
                                    isVertical
                                    && (
                                        <NavigationIcon
                                            navigationId={
                                                navigation.id
                                            }
                                        />
                                    )
                                }

                                {
                                    navigation
                                        .label
                                }
                            </NavLink>
                        </li>
                    ),
                )}
            </ul>
        </nav>
    );
}