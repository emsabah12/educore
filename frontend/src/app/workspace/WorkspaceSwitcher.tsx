import type {
    ChangeEvent,
} from 'react';

import {
    useWorkspaceContextRuntime,
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import type {
    WorkspaceContextState,
    WorkspaceSummary,
} from '@/platform/workspace';

type SelectableWorkspaceState =
    Extract<
        WorkspaceContextState,
        {
            readonly status:
                'ready'
                | 'switching';
        }
    >;

function workspaceSelectionValue(
    workspace:
        WorkspaceSummary,
): string {
    if (
        workspace.type
            === 'TENANT'
    ) {
        return 'TENANT';
    }

    /*
     * Organizational assignment identifiers are canonical
     * locators from Workspace discovery.
     *
     * Including the Workspace type keeps the browser-facing
     * option identity explicit without treating the value as
     * authority.
     */
    return [
        workspace.type,
        workspace
            .organizational_assignment_id,
    ].join(':');
}

interface InteractiveWorkspaceSwitcherProps {
    readonly workspace:
        SelectableWorkspaceState;
}

function InteractiveWorkspaceSwitcher({
    workspace,
}: InteractiveWorkspaceSwitcherProps) {
    const runtime =
        useWorkspaceContextRuntime();

    const switching =
        workspace.status
            === 'switching';

    const currentValue =
        workspaceSelectionValue(
            workspace.current,
        );

    const handleChange = (
        event:
            ChangeEvent<
                HTMLSelectElement
            >,
    ): void => {
        if (switching) {
            return;
        }

        const selectedValue =
            event.currentTarget
                .value;

        if (
            selectedValue
                === currentValue
        ) {
            return;
        }

        const target =
            workspace.workspaces.find(
                (
                    availableWorkspace,
                ) =>
                    workspaceSelectionValue(
                        availableWorkspace,
                    )
                        === selectedValue,
            );

        /*
         * Browser select values remain untrusted.
         *
         * Only a target found in the current canonical
         * Workspace catalog may reach WorkspaceContextRuntime.
         */
        if (
            target
                === undefined
        ) {
            return;
        }

        /*
         * Controlled verification failures are represented by
         * WorkspaceContextRuntime state.
         *
         * Exceptional rejection remains exceptional and must
         * not be translated into invented Workspace authority
         * by this presentation component.
         */
        void runtime.switchWorkspace(
            target,
        );
    };

    return (
        <div className="mt-2">
            <label
                className="sr-only"
                htmlFor="workspace-context-switcher"
            >
                Switch Workspace
            </label>

            <select
                id="workspace-context-switcher"
                className="max-w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1 text-xs text-slate-200 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-700 disabled:cursor-wait disabled:opacity-60"
                value={currentValue}
                disabled={switching}
                aria-busy={
                    switching
                }
                onChange={
                    handleChange
                }
            >
                {workspace.workspaces.map(
                    (
                        availableWorkspace,
                    ) => (
                        <option
                            key={
                                workspaceSelectionValue(
                                    availableWorkspace,
                                )
                            }
                            value={
                                workspaceSelectionValue(
                                    availableWorkspace,
                                )
                            }
                        >
                            {
                                availableWorkspace
                                    .label
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
                    Switching Workspace...
                </p>
            ) : null}
        </div>
    );
}

export function WorkspaceSwitcher() {
    const workspace =
        useWorkspaceContextState();

    if (
        workspace.status
            !== 'ready'
        && workspace.status
            !== 'switching'
    ) {
        return null;
    }

    /*
     * TENANT remains a complete and valid Workspace.
     *
     * A selector only adds value when discovery exposes an
     * alternative organizational Workspace.
     */
    if (
        workspace.workspaces.length
            <= 1
    ) {
        return null;
    }

    /*
     * Runtime access lives in a child component so callers
     * whose catalog contains only the TENANT baseline do not
     * require an interactive Workspace runtime dependency.
     */
    return (
        <InteractiveWorkspaceSwitcher
            workspace={workspace}
        />
    );
}
