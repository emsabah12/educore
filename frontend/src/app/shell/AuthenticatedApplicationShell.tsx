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
    MembershipSwitcher,
} from '@/app/membership/MembershipSwitcher';
import {
    ApplicationNavigation,
} from '@/app/navigation/ApplicationNavigation';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import {
    WorkspaceSwitcher,
} from '@/app/workspace/WorkspaceSwitcher';

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
        <div className="flex min-h-screen bg-background text-foreground">
            
            <a href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-popover focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-popover-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            >
                Lewati ke konten utama
            </a>

            {/*
             * Persistent sidebar — the single canonical
             * navigation landmark for the whole application.
             *
             * A collapsed/mobile-drawer variant is
             * deliberately out of scope for this step; see
             * the Step 2 handoff notes. It is NOT duplicated
             * as a second horizontal nav, because two
             * landmarks sharing the same accessible name is
             * an accessibility anti-pattern regardless of
             * which one CSS happens to hide at a given
             * viewport.
             */}
            <aside className="flex w-64 shrink-0 flex-col gap-6 border-r border-border bg-card px-4 py-6">
                <div className="flex items-center gap-2 px-2">
                    <div
                        className="h-2.5 w-2.5 rounded-sm bg-primary"
                        aria-hidden="true"
                    />

                    <span className="text-base font-black tracking-tight">
                        <span>EduCore</span>
                        {' '}
                        <span className="font-normal text-muted-foreground">
                            HR
                        </span>
                    </span>
                </div>

                <ApplicationNavigation orientation="vertical" />
            </aside>

            {/* Main column */}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="border-b border-border bg-card">
                    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 px-4 py-4 sm:px-6 lg:items-center lg:px-8">
                        <div className="min-w-0">
                            <p className="text-[11px] font-bold uppercase tracking-widest text-primary">
                                Tenant
                            </p>

                            <p className="truncate text-sm font-semibold text-foreground">
                                {tenant.name}
                            </p>

                            <MembershipSwitcher />
                        </div>

                        <div
                            className="min-w-0 sm:flex sm:flex-wrap sm:items-end sm:justify-end sm:gap-x-6 sm:gap-y-1 lg:text-right"
                            aria-label="Konteks pengguna aktif"
                        >
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-foreground">
                                    {person.name}
                                </p>

                                <p className="truncate text-xs text-muted-foreground">
                                    {user.email}
                                </p>
                            </div>

                            <div className="mt-1 sm:mt-0">
                                <p className="truncate text-xs text-muted-foreground">
                                    Workspace: {workspace.current.label}
                                </p>

                                <WorkspaceSwitcher />
                            </div>
                        </div>

                        <LogoutButton />
                    </div>
                </header>

                <main
                    className="flex-1 px-4 py-8 sm:px-6 lg:px-8"
                    id="main-content"
                    tabIndex={-1}
                >
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
