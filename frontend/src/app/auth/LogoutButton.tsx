import {
    useBrowserAuthRuntime,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';

export function LogoutButton() {
    const runtime =
        useBrowserAuthRuntime();

    const authentication =
        useBrowserAuthState();

    const loggingOut =
        authentication.status
            === 'logging-out';

    /*
     * The protected application normally renders this
     * component only while authentication is authoritative.
     *
     * Keep the component defensive so route/provider
     * transitions cannot expose an invalid logout command.
     */
    if (
        authentication.status
            !== 'authenticated'
        && authentication.status
            !== 'logging-out'
    ) {
        return null;
    }

    async function handleLogout(): Promise<void> {
        /*
         * React snapshot controls the visible button state,
         * but the live runtime state is checked again at the
         * command boundary.
         *
         * This prevents duplicate/stale clicks from
         * dispatching LOGOUT_STARTED after another logout
         * has already begun.
         */
        if (
            runtime.getState()
                .status
                !== 'authenticated'
        ) {
            return;
        }

        /*
         * BrowserAuthRuntime owns the complete logout
         * lifecycle:
         *
         * - CSRF bootstrap
         * - BrowserSession logout transport
         * - authentication state transition
         *
         * Membership, Workspace, restoration hints, and
         * routing react downstream to authority loss.
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