import type {
    ApiComponents,
} from '@/platform/api';

export type MembershipSummary =
    ApiComponents['schemas']['MembershipSummary'];

export type MembershipListSuccess =
    ApiComponents['schemas']['MembershipListSuccess'];

export type BrowserMembershipSwitchData =
    ApiComponents['schemas']['BrowserMembershipSwitchData'];

export type BrowserMembershipSwitchSuccess =
    ApiComponents['schemas']['BrowserMembershipSwitchSuccess'];

export type BrowserMembershipSwitchTarget =
    ApiComponents['parameters']['BrowserMembershipPathId'];

export type CanonicalMembership =
    ApiComponents['schemas']['AuthenticatedMembership'];

export type CanonicalTenant =
    ApiComponents['schemas']['AuthenticatedTenant'];

export interface CanonicalMembershipContext {
    readonly membership:
        CanonicalMembership;

    readonly tenant:
        CanonicalTenant;
}
