import {
    Outlet,
} from 'react-router';

import {
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    LogoutButton,
} from '@/app/auth/LogoutButton';
import {
    ApplicationNavigation,
} from '@/app/navigation/ApplicationNavigation';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';

export function AuthenticatedApplicationShell() {
    const authentication =
        useBrowserAuthState();

    const workspace =
        useWorkspaceContextState();

    /*
     * The shell lives below the canonical protected
     * application boundary.
     *
     * It still fails closed if React observes an
     * intermediate cross-provider snapshot before the
     * parent protected boundary rerenders.
     *
     * The shell remains presentation-only and does not
     * manufacture authentication or Workspace authority.
     */
    if (
        authentication.status
            !== 'authenticated'
        || workspace.status
            !== 'ready'
    ) {
        return null;
    }

    const {
        person,
        user,
        tenant,
    } =
        authentication.identity;

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-slate-100 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 focus:ring-offset-slate-950"
            >
                Lewati ke konten utama
            </a>

            <header className="border-b border-slate-800 bg-slate-950">
                <div className="mx-auto grid max-w-7xl grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-3 px-4 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center lg:px-8">
                    <div className="min-w-0">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">
                            EduCore
                        </p>

                        <p className="mt-1 truncate text-sm text-slate-300">
                            {tenant.name}
                        </p>
                    </div>

                    <div
                        className="col-span-2 min-w-0 border-t border-slate-900 pt-3 sm:flex sm:flex-wrap sm:items-end sm:justify-between sm:gap-x-6 sm:gap-y-1 lg:col-span-1 lg:border-t-0 lg:pt-0 lg:text-right"
                        aria-label="Konteks pengguna aktif"
                    >
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium text-slate-100">
                                {person.name}
                            </p>

                            <p className="truncate text-xs text-slate-400">
                                {user.email}
                            </p>
                        </div>

                        <p className="mt-1 truncate text-xs text-slate-500 sm:mt-0">
                            Workspace: {workspace.current.label}
                        </p>
                    </div>

                    <div className="col-start-2 row-start-1 lg:col-start-3 lg:row-start-auto">
                        <LogoutButton />
                    </div>
                </div>

                <div className="border-t border-slate-900">
                    <div className="mx-auto max-w-7xl overflow-x-auto overscroll-x-contain px-4 py-2 sm:px-6 lg:px-8">
                        <ApplicationNavigation />
                    </div>
                </div>
            </header>

            <main
                className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
                id="main-content"
                tabIndex={-1}
            >
                <Outlet />
            </main>
        </div>
    );
}
