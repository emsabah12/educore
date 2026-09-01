import {
    useBrowserAuthRuntime,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import type {
    BrowserAuthState,
} from '@/platform/auth';

function canStartGlobalLogout(
    status:
        BrowserAuthState['status'],
): boolean {
    return (
        status
            === 'identity-authenticated'
        || status
            === 'authenticated'
    );
}

export function LogoutButton() {
    const runtime =
        useBrowserAuthRuntime();

    const authentication =
        useBrowserAuthState();

    const loggingOut =
        authentication.status
            === 'logging-out';

    const logoutEligible =
        canStartGlobalLogout(
            authentication.status,
        );

    /*
     * Global BrowserSession logout belongs to authenticated
     * User identity, not specifically to Membership/Tenant
     * authority.
     *
     * Therefore both global Identity Context and fully
     * established Membership Context may expose logout.
     *
     * Keep the logging-out projection visible but disabled
     * while the command is already in flight.
     */
    if (
        ! logoutEligible
        && ! loggingOut
    ) {
        return null;
    }

    async function handleLogout(): Promise<void> {
        /*
         * React snapshot controls presentation, but command
         * authorization is checked again against the live
         * runtime state.
         *
         * This prevents stale or duplicate clicks from
         * dispatching LOGOUT_STARTED after the runtime has
         * already moved into logging-out or another
         * non-eligible state.
         */
        const currentStatus =
            runtime.getState()
                .status;

        if (
            ! canStartGlobalLogout(
                currentStatus,
            )
        ) {
            return;
        }

        /*
         * BrowserAuthRuntime owns the complete global logout
         * lifecycle:
         *
         * - CSRF bootstrap
         * - BrowserSession logout transport
         * - authentication state transition
         *
         * Membership, Workspace, restoration hints, and
         * routing react downstream to global authority loss.
         */
        await runtime.logout();
    }

    return (
        <button
            className="rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
            disabled={
                loggingOut
            }
            onClick={() => {
                void handleLogout();
            }}
            type="button"
        >
            {
                loggingOut
                    ? 'Keluar...'
                    : 'Keluar'
            }
        </button>
    );
}
