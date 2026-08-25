import {
    NavLink,
} from 'react-router';

import {
    useApplicationNavigationProjection,
} from '@/app/navigation/useApplicationNavigationProjection';

export function ApplicationNavigation() {
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

    return (
        <nav
            aria-label="Navigasi utama"
            className="min-w-max"
        >
            <ul className="flex min-w-max items-center gap-2">
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
                                    [
                                        'inline-flex min-h-10 items-center rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950',
                                        isActive
                                            ? 'bg-slate-800 text-slate-50'
                                            : 'text-slate-300 hover:bg-slate-900 hover:text-slate-100',
                                    ].join(
                                        ' ',
                                    )
                                }
                            >
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
