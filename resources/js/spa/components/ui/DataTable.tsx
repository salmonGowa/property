import { flexRender, getCoreRowModel, useReactTable, type ColumnDef, type RowData } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Skeleton } from './Feedback';

declare module '@tanstack/react-table' {
    interface ColumnMeta<TData extends RowData, TValue> {
        /** API sort key; makes the header clickable. */
        sortKey?: string;
        align?: 'left' | 'right' | 'center';
        headerClassName?: string;
        cellClassName?: string;
    }
}

interface DataTableProps<T> {
    columns: ColumnDef<T, any>[];
    data: T[] | undefined;
    loading?: boolean;
    /** Current API sort, e.g. "name" or "-name". */
    sort?: string;
    onSortChange?: (sort: string) => void;
    onRowClick?: (row: T) => void;
    /** Rendered instead of the table below the `md` breakpoint. */
    renderCard: (row: T) => ReactNode;
    empty: ReactNode;
    getRowId: (row: T) => string;
}

const alignClass = { left: 'text-left', right: 'text-right', center: 'text-center' };

/**
 * Server-driven table: sorting and paging happen in the API, so TanStack Table only handles rendering.
 * Small screens get a stacked card list instead of a horizontally scrolling table.
 */
export function DataTable<T>({ columns, data, loading, sort, onSortChange, onRowClick, renderCard, empty, getRowId }: DataTableProps<T>) {
    const table = useReactTable({ data: data ?? [], columns, getCoreRowModel: getCoreRowModel(), manualSorting: true, manualPagination: true, getRowId });

    if (loading && !data) {
        return (
            <div className="flex flex-col gap-3 p-4 sm:p-6">
                {Array.from({ length: 5 }, (_, index) => (
                    <Skeleton key={index} className="h-12 w-full" />
                ))}
            </div>
        );
    }

    if (data && data.length === 0) {
        return <>{empty}</>;
    }

    const rows = table.getRowModel().rows;

    return (
        <div className={cn('transition-opacity', loading && 'opacity-60')}>
            <ul role="list" className="divide-y divide-gray-950/5 md:hidden dark:divide-white/10">
                {rows.map((row) => (
                    <li key={row.id}>{renderCard(row.original)}</li>
                ))}
            </ul>

            <div className="hidden overflow-x-auto md:block">
                <table className="min-w-full divide-y divide-gray-950/5 text-sm dark:divide-white/10">
                    <thead className="bg-gray-50/75 dark:bg-white/[0.02]">
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    const meta = header.column.columnDef.meta;
                                    const sortKey = meta?.sortKey;
                                    const direction = sortKey && sort === sortKey ? 'asc' : sortKey && sort === `-${sortKey}` ? 'desc' : null;
                                    const label = header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext());

                                    return (
                                        <th
                                            key={header.id}
                                            scope="col"
                                            aria-sort={direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : undefined}
                                            className={cn('px-4 py-3 font-semibold whitespace-nowrap text-gray-900 first:pl-6 last:pr-6 dark:text-white', alignClass[meta?.align ?? 'left'], meta?.headerClassName)}
                                        >
                                            {sortKey && onSortChange ? (
                                                <button
                                                    type="button"
                                                    onClick={() => onSortChange(direction === 'asc' ? `-${sortKey}` : sortKey)}
                                                    className="group inline-flex items-center gap-1.5 rounded hover:text-primary-600 dark:hover:text-primary-400"
                                                >
                                                    {label}
                                                    {direction === 'asc' ? (
                                                        <ArrowUp className="size-3.5" />
                                                    ) : direction === 'desc' ? (
                                                        <ArrowDown className="size-3.5" />
                                                    ) : (
                                                        <ArrowUpDown className="size-3.5 text-gray-400 opacity-0 group-hover:opacity-100" />
                                                    )}
                                                </button>
                                            ) : (
                                                label
                                            )}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody className="divide-y divide-gray-950/5 dark:divide-white/10">
                        {rows.map((row) => (
                            <tr
                                key={row.id}
                                onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                                className={cn(onRowClick && 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]')}
                            >
                                {row.getVisibleCells().map((cell) => {
                                    const meta = cell.column.columnDef.meta;

                                    return (
                                        <td key={cell.id} className={cn('px-4 py-3 whitespace-nowrap text-gray-700 first:pl-6 last:pr-6 dark:text-gray-300', alignClass[meta?.align ?? 'left'], meta?.cellClassName)}>
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
