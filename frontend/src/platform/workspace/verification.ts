import type {
    BrowserApiFailure,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';

import type {
    WorkspaceSummary,
} from '@/platform/workspace/contract';

export interface WorkspaceVerificationOptions {
    readonly signal?:
        AbortSignal;
}

export interface WorkspaceVerificationSuccess {
    readonly ok:
        true;
}

export type WorkspaceVerificationResult =
    | WorkspaceVerificationSuccess
    | BrowserApiFailure;

export interface WorkspaceContextVerifier {
    verify(
        context:
            CanonicalMembershipContext,
        workspace:
            WorkspaceSummary,
        options?:
            WorkspaceVerificationOptions,
    ): Promise<
        WorkspaceVerificationResult
    >;
}
