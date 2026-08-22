import type {
    CanonicalMembershipContext,
} from '@/platform/membership/contract';

const MEMBERSHIP_RESTORATION_STORAGE_KEY =
    'educore.membership-restoration.v1';

export interface MembershipRestorationHint {
    readonly membership_id:
        CanonicalMembershipContext[
            'membership'
        ]['id'];

    readonly tenant_id:
        CanonicalMembershipContext[
            'tenant'
        ]['id'];
}

export interface MembershipRestorationHintStorage {
    getItem(
        key:
            string,
    ): string | null;

    setItem(
        key:
            string,
        value:
            string,
    ): void;

    removeItem(
        key:
            string,
    ): void;
}

function isNonEmptyString(
    value:
        unknown,
): value is string {
    return (
        typeof value
            === 'string'
        && value
            .trim()
            .length
            > 0
    );
}

function isMembershipRestorationHint(
    value:
        unknown,
): value is MembershipRestorationHint {
    if (
        typeof value
            !== 'object'
        || value
            === null
    ) {
        return false;
    }

    if (
        ! (
            'membership_id'
            in value
        )
        || ! (
            'tenant_id'
            in value
        )
    ) {
        return false;
    }

    return (
        isNonEmptyString(
            value.membership_id,
        )
        && isNonEmptyString(
            value.tenant_id,
        )
    );
}

function defaultStorage():
    MembershipRestorationHintStorage | null {
    if (
        typeof window
            === 'undefined'
    ) {
        return null;
    }

    return window.sessionStorage;
}

export function readBrowserMembershipRestorationHint(
    storage:
        MembershipRestorationHintStorage | null =
            defaultStorage(),
): MembershipRestorationHint | null {
    if (
        storage
            === null
    ) {
        return null;
    }

    let serialized:
        string | null;

    try {
        serialized =
            storage.getItem(
                MEMBERSHIP_RESTORATION_STORAGE_KEY,
            );
    } catch {
        /*
         * Storage availability is browser capability,
         * not authentication truth.
         *
         * Fail closed without manufacturing context.
         */
        return null;
    }

    if (
        serialized
            === null
    ) {
        return null;
    }

    let parsed:
        unknown;

    try {
        parsed =
            JSON.parse(
                serialized,
            );
    } catch {
        /*
         * Invalid client-owned state must never become a
         * canonical Membership locator.
         */
        clearBrowserMembershipRestorationHint(
            storage,
        );

        return null;
    }

    if (
        ! isMembershipRestorationHint(
            parsed,
        )
    ) {
        clearBrowserMembershipRestorationHint(
            storage,
        );

        return null;
    }

    return {
        membership_id:
            parsed.membership_id,

        tenant_id:
            parsed.tenant_id,
    };
}

export function persistBrowserMembershipRestorationHint(
    context:
        CanonicalMembershipContext,
    storage:
        MembershipRestorationHintStorage | null =
            defaultStorage(),
): boolean {
    if (
        storage
            === null
    ) {
        return false;
    }

    if (
        ! isNonEmptyString(
            context.membership.id,
        )
        || ! isNonEmptyString(
            context.tenant.id,
        )
    ) {
        return false;
    }

    const hint:
        MembershipRestorationHint = {
            membership_id:
                context.membership.id,

            tenant_id:
                context.tenant.id,
        };

    try {
        storage.setItem(
            MEMBERSHIP_RESTORATION_STORAGE_KEY,
            JSON.stringify(
                hint,
            ),
        );

        return true;
    } catch {
        /*
         * Browser storage failure must not promote or
         * invalidate canonical authentication authority.
         */
        return false;
    }
}

export function clearBrowserMembershipRestorationHint(
    storage:
        MembershipRestorationHintStorage | null =
            defaultStorage(),
): boolean {
    if (
        storage
            === null
    ) {
        return false;
    }

    try {
        storage.removeItem(
            MEMBERSHIP_RESTORATION_STORAGE_KEY,
        );

        return true;
    } catch {
        return false;
    }
}
