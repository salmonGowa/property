import { createColumnHelper } from '@tanstack/react-table';
import { DoorOpen, KeyRound, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';
import { useCan } from '@/auth/auth';
import { Badge } from '@/components/ui/Badge';
import { Button, buttonVariants } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { DataTable } from '@/components/ui/DataTable';
import { ConfirmDialog } from '@/components/ui/Dialog';
import { EmptyState, ErrorState } from '@/components/ui/Feedback';
import { Input, Select } from '@/components/ui/Field';
import { Pagination } from '@/components/ui/Pagination';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useLookups } from '@/hooks/useLookups';
import { errorMessage } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDate, formatMoney } from '@/lib/format';
import type { Property, Unit } from '@/types/api';
import { useDeleteUnit, usePropertyUnits } from './api';
import { UnitFormDialog } from './UnitFormDialog';

const column = createColumnHelper<Unit>();

/** Leases ending within 60 days are highlighted, matching the classic back office. */
function endsSoon(endDate: string | null | undefined): boolean {
    return !!endDate && new Date(endDate).getTime() - Date.now() < 60 * 86_400_000;
}

export function UnitsSection({ property }: { property: Property }) {
    const canCreate = useCan('units', 'create');
    const canLet = useCan('leases', 'create');
    const { data: lookups } = useLookups();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [sort, setSort] = useState('code');
    const [page, setPage] = useState(1);
    const debouncedSearch = useDebouncedValue(search);
    const [editing, setEditing] = useState<Unit | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Unit | null>(null);
    const deleteUnit = useDeleteUnit();

    const { data, isPending, isFetching, error, refetch } = usePropertyUnits(property.id, { search: debouncedSearch, status, sort, page });

    const actions = (unit: Unit) => (
        <div className="flex items-center justify-end gap-1" onClick={(event) => event.stopPropagation()}>
            {canLet && unit.status.value === 'vacant' && (
                <Link to={`/leases/new?unit=${unit.id}`} className={cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'text-green-700 dark:text-green-400')}>
                    <KeyRound /> Let
                </Link>
            )}
            {unit.can.update && (
                <Button variant="ghost" size="icon-sm" onClick={() => setEditing(unit)} aria-label={`Edit unit ${unit.code}`}>
                    <Pencil />
                </Button>
            )}
            {unit.can.delete && (
                <Button variant="ghost" size="icon-sm" onClick={() => setDeleting(unit)} aria-label={`Delete unit ${unit.code}`} className="text-red-600 dark:text-red-400">
                    <Trash2 />
                </Button>
            )}
        </div>
    );

    const columns = useMemo(
        () => [
            column.accessor('code', { header: 'Unit', meta: { sortKey: 'code' }, cell: ({ getValue }) => <span className="font-semibold text-gray-950 dark:text-white">{getValue()}</span> }),
            column.accessor('type', { header: 'Type', cell: ({ getValue }) => getValue().label }),
            column.accessor('status', { header: 'Status', meta: { sortKey: 'status' }, cell: ({ getValue }) => <Badge color={getValue().color}>{getValue().label}</Badge> }),
            column.accessor((unit) => unit.activeLease?.tenant?.name, {
                id: 'tenant',
                header: 'Tenant',
                meta: { cellClassName: 'max-w-48 truncate' },
                cell: ({ row }) =>
                    row.original.activeLease?.tenant ? (
                        <Link to={`/leases/${row.original.activeLease.id}`} className="hover:text-primary-600 dark:hover:text-primary-400">
                            {row.original.activeLease.tenant.name}
                        </Link>
                    ) : (
                        '—'
                    ),
            }),
            column.accessor('marketRent', { header: 'Asking rent', meta: { sortKey: 'market_rent', align: 'right' }, cell: ({ getValue }) => <span className="tabular-nums">{formatMoney(getValue())}</span> }),
            column.accessor((unit) => unit.activeLease?.endDate, {
                id: 'leaseEnds',
                header: 'Lease ends',
                meta: { headerClassName: 'hidden lg:table-cell', cellClassName: 'hidden lg:table-cell' },
                cell: ({ getValue }) => <span className={cn(endsSoon(getValue()) && 'font-medium text-amber-600 dark:text-amber-400')}>{formatDate(getValue())}</span>,
            }),
            column.display({ id: 'actions', header: () => <span className="sr-only">Actions</span>, cell: ({ row }) => actions(row.original) }),
        ],
        [canLet],
    );

    return (
        <Card>
            <CardHeader
                title="Units"
                description={data ? `${data.meta.total} ${data.meta.total === 1 ? 'unit' : 'units'}` : undefined}
                actions={
                    canCreate &&
                    lookups && (
                        <Button size="sm" onClick={() => setEditing('new')} className="w-full sm:w-auto">
                            <Plus /> Add unit
                        </Button>
                    )
                }
            />

            <div className="flex flex-col gap-3 border-b border-gray-950/5 px-4 py-3 sm:flex-row sm:px-6 dark:border-white/10">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" aria-hidden />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                        placeholder="Search unit number or tenant"
                        className="pl-9"
                        aria-label="Search units"
                    />
                </div>
                <Select
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        setPage(1);
                    }}
                    aria-label="Status"
                    className="sm:w-48"
                >
                    <option value="">All statuses</option>
                    {lookups?.unitStatuses.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
            </div>

            {error ? (
                <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
            ) : (
                <DataTable
                    columns={columns}
                    data={data?.data}
                    loading={isPending || isFetching}
                    sort={sort}
                    onSortChange={(next) => {
                        setSort(next);
                        setPage(1);
                    }}
                    getRowId={(unit) => String(unit.id)}
                    renderCard={(unit) => (
                        <div className="flex items-start gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="font-semibold text-gray-950 dark:text-white">{unit.code}</p>
                                    <Badge color={unit.status.color}>{unit.status.label}</Badge>
                                </div>
                                <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                    {unit.type.label} · <span className="tabular-nums">{formatMoney(unit.marketRent)}</span>
                                </p>
                                {unit.activeLease?.tenant && (
                                    <p className="mt-0.5 truncate text-sm text-gray-700 dark:text-gray-300">
                                        <Link to={`/leases/${unit.activeLease.id}`} className="hover:text-primary-600 dark:hover:text-primary-400">
                                            {unit.activeLease.tenant.name}
                                        </Link>
                                        <span className={cn('text-gray-500', endsSoon(unit.activeLease.endDate) && 'text-amber-600 dark:text-amber-400')}> · ends {formatDate(unit.activeLease.endDate)}</span>
                                    </p>
                                )}
                            </div>
                            {actions(unit)}
                        </div>
                    )}
                    empty={
                        search || status ? (
                            <EmptyState icon={Search} title="No matching units" />
                        ) : (
                            <EmptyState
                                icon={DoorOpen}
                                title="No units yet"
                                description="Add the flats, shops or offices in this property."
                                action={canCreate && lookups && <Button onClick={() => setEditing('new')}><Plus /> Add unit</Button>}
                            />
                        )
                    }
                />
            )}

            {data && <Pagination meta={data.meta} onPageChange={setPage} />}

            {lookups && (
                <UnitFormDialog open={editing !== null} onClose={() => setEditing(null)} propertyId={property.id} unit={editing === 'new' || editing === null ? undefined : editing} lookups={lookups} />
            )}

            <ConfirmDialog
                open={deleting !== null}
                title={`Delete unit ${deleting?.code ?? ''}?`}
                message="The unit will be removed permanently. Units that have ever had a lease can't be deleted."
                loading={deleteUnit.isPending}
                onClose={() => setDeleting(null)}
                onConfirm={() =>
                    deleting &&
                    deleteUnit.mutate(deleting.id, {
                        onSuccess: () => {
                            toast.success(`Unit ${deleting.code} deleted.`);
                            setDeleting(null);
                        },
                        onError: (deleteError) => toast.error(errorMessage(deleteError)),
                    })
                }
            />
        </Card>
    );
}
