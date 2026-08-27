import type {
    ChangeEvent,
} from 'react';

import {
    useMembershipContextRuntime,
    useMembershipContextState,
} from '@/app/membership/MembershipContextProvider';

export function MembershipSwitcher() {
    const membership =
        useMembershipContextState();

    const runtime =
        useMembershipContextRuntime();

    if (
        membership.status
            !== 'ready'
        && membership.status
            !== 'switching'
    ) {
        return null;
    }

    /*
     * A dedicated selector adds no value when there is only
     * one available Membership.
     *
     * The authenticated shell already keeps the current
     * Tenant identity visible.
     */
    if (
        membership.memberships.length
            <= 1
    ) {
        return null;
    }

    const switching =
        membership.status
            === 'switching';

    /*
     * Keep the last canonically confirmed Membership selected
     * while a target credential is being prepared.
     *
     * The target must never become visible as current authority
     * merely because the user selected it.
     */
    const currentMembershipId =
        membership.context
            ?.membership
            .id
        ?? '';

    const handleChange = (
        event:
            ChangeEvent<
                HTMLSelectElement
            >,
    ): void => {
        if (switching) {
            return;
        }

        const targetMembershipId =
            event.currentTarget
                .value;

        if (
            targetMembershipId
                === ''
            || targetMembershipId
                === currentMembershipId
        ) {
            return;
        }

        /*
         * Controlled API failures are owned by
         * MembershipContextRuntime and become canonical
         * Membership state.
         *
         * Exceptional promise rejection remains exceptional;
         * presentation must not translate it into invented
         * Membership truth.
         */
        void runtime.switchMembership(
            targetMembershipId,
        );
    };

    return (
        <div className="mt-2">
            <label
                className="sr-only"
                htmlFor="membership-context-switcher"
            >
                Switch institution
            </label>

            <select
                id="membership-context-switcher"
                className="max-w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1 text-sm text-slate-200 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-700 disabled:cursor-wait disabled:opacity-60"
                value={currentMembershipId}
                disabled={switching}
                aria-busy={
                    switching
                }
                onChange={
                    handleChange
                }
            >
                {currentMembershipId === '' ? (
                    <option
                        value=""
                        disabled
                    >
                        Switching institution...
                    </option>
                ) : null}

                {membership.memberships.map(
                    (
                        availableMembership,
                    ) => (
                        <option
                            key={
                                availableMembership
                                    .membership_id
                            }
                            value={
                                availableMembership
                                    .membership_id
                            }
                        >
                            {
                                availableMembership
                                    .tenant_name
                            }
                        </option>
                    ),
                )}
            </select>

            {switching ? (
                <p
                    className="sr-only"
                    role="status"
                >
                    Switching institution...
                </p>
            ) : null}
        </div>
    );
}
