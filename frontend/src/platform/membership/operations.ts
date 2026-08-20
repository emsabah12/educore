import type {
    BrowserApiClient,
} from '@/platform/api';

import {
    discoverBrowserMemberships,
    type DiscoverBrowserMembershipsOptions,
} from '@/platform/membership/discovery';
import {
    switchBrowserMembership,
    type SwitchBrowserMembershipOptions,
} from '@/platform/membership/switch';
import type {
    BrowserMembershipSwitchTarget,
} from '@/platform/membership/contract';

export interface MembershipContextOperations {
    discover(
        options?:
            DiscoverBrowserMembershipsOptions,
    ): ReturnType<
        typeof discoverBrowserMemberships
    >;

    switchMembership(
        membershipId:
            BrowserMembershipSwitchTarget,
        options?:
            SwitchBrowserMembershipOptions,
    ): ReturnType<
        typeof switchBrowserMembership
    >;
}

export function createMembershipContextOperations(
    client: BrowserApiClient,
): MembershipContextOperations {
    return {
        discover(
            options,
        ) {
            return discoverBrowserMemberships(
                client,
                options,
            );
        },

        switchMembership(
            membershipId,
            options,
        ) {
            return switchBrowserMembership(
                client,
                membershipId,
                options,
            );
        },
    };
}
