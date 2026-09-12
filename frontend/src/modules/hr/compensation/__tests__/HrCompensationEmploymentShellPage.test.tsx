import {
    render,
    screen,
} from '@testing-library/react';
import {
    MemoryRouter,
    Route,
    Routes,
} from 'react-router';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    HrCompensationEmploymentShellPage,
} from '@/modules/hr/compensation/HrCompensationEmploymentShellPage';

const SAMPLE_EMPLOYMENT_ID =
    '01970000-0000-7000-8000-0000000002aa';

function renderShell(
    state:
        | Record<string, unknown>
        | null,
) {
    render(
        <MemoryRouter
            initialEntries={
                [
                    {
                        pathname:
                            `/hr/compensation/employments/${SAMPLE_EMPLOYMENT_ID}`,

                        state,
                    },
                ]
            }
        >
            <Routes>
                <Route
                    path="/hr/compensation/employments/:employmentId"
                    element={
                        <HrCompensationEmploymentShellPage />
                    }
                />
            </Routes>
        </MemoryRouter>,
    );
}

describe(
    'HrCompensationEmploymentShellPage',
    () => {
        it(
            'renders the employee name, status badge, and date range carried via navigation state',
            () => {
                renderShell(
                    {
                        employeeName:
                            'Siti Aminah',

                        employmentStatus:
                            'ACTIVE',

                        employmentStartDate:
                            '2024-01-01',

                        employmentEndDate:
                            null,
                    },
                );

                expect(
                    screen.getByRole(
                        'heading',
                        {
                            name: 'Siti Aminah',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Aktif',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        (
                            _content,
                            element,
                        ) =>
                            element?.textContent
                            === '2024-01-01 – sekarang',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'falls back gracefully to a generic heading when opened without navigation state',
            () => {
                renderShell(
                    null,
                );

                expect(
                    screen.getByRole(
                        'heading',
                        {
                            name: 'Kompensasi & Benefit Employment',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        (
                            _content,
                            element,
                        ) =>
                            element?.textContent
                            === `ID Employment: ${SAMPLE_EMPLOYMENT_ID}`,
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);
