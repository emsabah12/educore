import {
    cn,
} from '@/shared/lib/utils';
import {
    Button,
} from '@/shared/ui/button';

const ELLIPSIS = 'ellipsis' as const;

type PaginationItem =
    | number
    | typeof ELLIPSIS;

/*
 * Always shows the first and last page, the current page,
 * and one sibling on each side of the current page —
 * collapsing any remaining gap into a single ellipsis
 * marker. Pure function so the layout logic is testable
 * without rendering.
 */
export function buildPaginationRange(
    currentPage: number,
    lastPage: number,
): readonly PaginationItem[] {
    if (lastPage <= 1) {
        return [1];
    }

    const siblingCount = 1;

    /*
     * Below this threshold, showing every page number takes
     * no more room than showing an ellipsis would, so there
     * is no reason to collapse anything.
     */
    const maxVisibleWithoutCollapsing = 7;

    if (lastPage <= maxVisibleWithoutCollapsing) {
        return Array.from(
            {
                length: lastPage,
            },
            (
                _value,
                index,
            ) =>
                index + 1,
        );
    }

    const leftSibling =
        Math.max(
            currentPage - siblingCount,
            1,
        );

    const rightSibling =
        Math.min(
            currentPage + siblingCount,
            lastPage,
        );

    const showLeftEllipsis =
        leftSibling > 2;

    const showRightEllipsis =
        rightSibling < lastPage - 1;

    const items: PaginationItem[] = [
        1,
    ];

    if (showLeftEllipsis) {
        items.push(
            ELLIPSIS,
        );
    }

    /*
     * This range already covers the page-2 / (lastPage - 1)
     * boundary whenever leftSibling/rightSibling land there,
     * so nothing further needs to be pushed for those cases
     * separately — doing so would push the same page number
     * twice.
     */
    for (
        let page = Math.max(leftSibling, 2);
        page <= Math.min(rightSibling, lastPage - 1);
        page++
    ) {
        items.push(
            page,
        );
    }

    if (showRightEllipsis) {
        items.push(
            ELLIPSIS,
        );
    }

    items.push(
        lastPage,
    );

    return items;
}

export interface PaginationProps {
    readonly currentPage: number;
    readonly lastPage: number;
    readonly onPageChange: (page: number) => void;
    readonly className?: string;
}

export function Pagination({
    currentPage,
    lastPage,
    onPageChange,
    className,
}: PaginationProps) {
    if (lastPage <= 1) {
        return null;
    }

    const items =
        buildPaginationRange(
            currentPage,
            lastPage,
        );

    return (
        <nav
            aria-label="Navigasi halaman"
            className={
                cn(
                    'flex items-center gap-1',
                    className,
                )
            }
        >
            <Button
                variant="outline"
                size="icon"
                aria-label="Halaman sebelumnya"
                disabled={
                    currentPage <= 1
                }
                onClick={
                    () =>
                        onPageChange(
                            currentPage - 1,
                        )
                }
            >
                ‹
            </Button>

            {
                items.map(
                    (
                        item,
                        index,
                    ) =>
                        item === ELLIPSIS
                            ? (
                                <span
                                    key={
                                        `ellipsis-${index}`
                                    }
                                    className="px-2 text-sm text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    …
                                </span>
                            )
                            : (
                                <Button
                                    key={item}
                                    variant={
                                        item === currentPage
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="icon"
                                    aria-label={
                                        `Halaman ${item}`
                                    }
                                    aria-current={
                                        item === currentPage
                                            ? 'page'
                                            : undefined
                                    }
                                    onClick={
                                        () =>
                                            onPageChange(
                                                item,
                                            )
                                    }
                                >
                                    {
                                        item
                                    }
                                </Button>
                            ),
                )
            }

            <Button
                variant="outline"
                size="icon"
                aria-label="Halaman berikutnya"
                disabled={
                    currentPage >= lastPage
                }
                onClick={
                    () =>
                        onPageChange(
                            currentPage + 1,
                        )
                }
            >
                ›
            </Button>
        </nav>
    );
}