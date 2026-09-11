import {
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    buildPaginationRange,
    Pagination,
} from '@/shared/ui/pagination';

describe(
    'buildPaginationRange',
    () => {
        it(
            'shows every page when there are seven or fewer pages',
            () => {
                expect(
                    buildPaginationRange(1, 5),
                ).toEqual(
                    [1, 2, 3, 4, 5],
                );
            },
        );

        it(
            'collapses the right side into an ellipsis when the current page is near the start',
            () => {
                expect(
                    buildPaginationRange(1, 10),
                ).toEqual(
                    [1, 2, 'ellipsis', 10],
                );
            },
        );

        it(
            'collapses the left side into an ellipsis when the current page is near the end',
            () => {
                expect(
                    buildPaginationRange(10, 10),
                ).toEqual(
                    [1, 'ellipsis', 9, 10],
                );
            },
        );

        it(
            'collapses both sides into ellipses when the current page is in the middle',
            () => {
                expect(
                    buildPaginationRange(5, 10),
                ).toEqual(
                    [1, 'ellipsis', 4, 5, 6, 'ellipsis', 10],
                );
            },
        );

        it(
            'returns a single page when there is only one page',
            () => {
                expect(
                    buildPaginationRange(1, 1),
                ).toEqual(
                    [1],
                );
            },
        );
    },
);

describe(
    'Pagination',
    () => {
        it(
            'renders nothing when there is only one page',
            () => {
                const { container } =
                    render(
                        <Pagination
                            currentPage={1}
                            lastPage={1}
                            onPageChange={
                                () => {}
                            }
                        />,
                    );

                expect(
                    container,
                ).toBeEmptyDOMElement();
            },
        );

        it(
            'marks the current page and calls onPageChange when another page is clicked',
            () => {
                const onPageChange =
                    vi.fn();

                render(
                    <Pagination
                        currentPage={5}
                        lastPage={10}
                        onPageChange={onPageChange}
                    />,
                );

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman 5',
                        },
                    ),
                ).toHaveAttribute(
                    'aria-current',
                    'page',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman 6',
                        },
                    ),
                );

                expect(
                    onPageChange,
                ).toHaveBeenCalledWith(
                    6,
                );
            },
        );

        it(
            'disables the previous button on the first page and the next button on the last page',
            () => {
                render(
                    <Pagination
                        currentPage={1}
                        lastPage={3}
                        onPageChange={
                            () => {}
                        }
                    />,
                );

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman sebelumnya',
                        },
                    ),
                ).toBeDisabled();

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman berikutnya',
                        },
                    ),
                ).not.toBeDisabled();
            },
        );
    },
);
