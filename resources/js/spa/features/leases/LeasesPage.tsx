import { createColumnHelper } from '@tanstack/react-table';
import { ChevronRight, FilePlus2, FileSignature, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useCan } from '@/auth/auth';
import { Badge } from '@/components/ui/Badge';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DataTable } from '@/components/ui/DataTable';
import { EmptyState, ErrorState } from '@/components/ui/Feedback';
import { Input, Select } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/PageHeader';
import { Pagination } from '@/components/ui/Pagination';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useProperties } from '@/features/properties/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useLookups } from '@/hooks/useLookups';
import { errorMessage } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import type { Lease } from '@/types/api';
import { useLeases, type LeaseFilters, type LeaseList, type LeaseTab } from './api';
import { LeaseEndDate } from './LeaseEndDate';

const column = createColumnHelper<Lease>();

const tabs: { value: LeaseTab; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'expiring', label: 'Expiring' },
    { value: 'draft', label: 'Drafts' },
    { value: 'terminated', label: 'Terminated' },
    { value: 'all', label: 'All' },
];

const emptyMessages: Record<LeaseTab, string> = {
    active: 'No active leases.',
    expiring: 'No leases end in the next 60 days.',
    draft: 'No drafts waiting to be activated.',
    terminated: 'No terminated leases.',
    all: 'No leases yet.',
};

export function LeasesPage() {
    const canCreate = useCan('leases', 'create');
    const navigate = useNavigate();
    const { data: lookups } = useLookups();
    const { data: properties } = useProperties({ status: 'all', sort: 'name', page: 1, per_page: 100 });
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState(params.get('search') ?? '');
    const debouncedSearch = useDebouncedValue(search);

    const status = (params.get('status') as LeaseTab | null) ?? 'active';
    const filters: LeaseFilters = {
        status,
        search: params.get('search') ?? undefined,
        property_id: params.get('property_id') ?? undefined,
        billing_frequency: params.get('billing_frequency') ?? undefined,
        sort: params.get('sort') ?? undefined,
        page: Number(params.get('page') ?? 1),
    };

    /** Writes a filter to the URL (so it survives reloads and can be shared) and resets to page 1. */
    const setFilter = (key: string, value: string | undefined, resetPage = true) => {
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) {
                    next.set(key, value);
                } else {
                    next.delete(key);
                }
                if (resetPage && key !== 'page') {
                    next.delete('page');
                }

                return next;
            },
            { replace: true },
        );
    };

    useEffect(() => {
        if ((params.get('search') ?? '') !== debouncedSearch) {
            setFilter('search', debouncedSearch || undefined);
        }
    }, [debouncedSearch]);

    const { data, isPending, isFetching, error, refetch } = useLeases(filters);
    // The API sorts the expiring tab by end date and everything else newest first when no sort is chosen.
    const effectiveSort = filters.sort ?? (status === 'expiring' ? 'end_date' : '-start_date');
    const showStatus = status === 'all';

    const columns = useMemo(
        () => [
            column.accessor('reference', {
                header: 'Lease',
                meta: { sortKey: 'reference' },
                cell: ({ row }) => (
                    <Link to={`/leases/${row.original.id}`} onClick={(event) => event.stopPropagation()} className="font-semibold text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">
                        {row.original.reference}
                    </Link>
                ),
            }),
            column.accessor((lease) => lease.tenant?.name, {
                id: 'tenant',
                header: 'Tenant',
                meta: { cellClassName: 'max-w-56 truncate' },
                cell: ({ getValue }) => getValue() ?? '—',
            }),
            column.accessor((lease) => lease.unit?.fullCode, { id: 'unit', header: 'Unit', cell: ({ getValue }) => getValue() ?? '—' }),
            ...(showStatus ? [column.accessor('status', { header: 'Status', cell: ({ getValue }) => <Badge color={getValue().color}>{getValue().label}</Badge> })] : []),
            column.accessor('startDate', {
                header: 'Starts',
                meta: { sortKey: 'start_date', headerClassName: 'hidden lg:table-cell', cellClassName: 'hidden lg:table-cell' },
                cell: ({ getValue }) => formatDate(getValue()),
            }),
            column.accessor('endDate', { header: 'Ends', meta: { sortKey: 'end_date' }, cell: ({ row }) => <LeaseEndDate lease={row.original} /> }),
            column.accessor('rentAmount', {
                header: 'Monthly rent',
                meta: { sortKey: 'rent_amount', align: 'right' },
                cell: ({ getValue }) => <span className="tabular-nums">{formatMoney(getValue())}</span>,
            }),
        ],
        [showStatus],
    );

    return (
        <>
            <PageHeader
                title="Leases"
                description="Tenancy agreements from draft through renewal and move-out."
                actions={
                    canCreate && (
                        <ButtonLink to="/leases/new" className="w-full sm:w-auto">
                            <FilePlus2 /> New lease
                        </ButtonLink>
                    )
                }
            />

            <Card>
                <div className="flex flex-col gap-3 border-b border-gray-950/5 p-4 sm:px-6 dark:border-white/10">
                    <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                        <SegmentedControl
                            label="Lease status"
                            value={status}
                            options={tabs.map((tab) => ({ ...tab, count: isCountedTab(tab.value) ? data?.counts[tab.value] : undefined }))}
                            onChange={(tab) => {
                                setParams(
                                    (current) => {
                                        const next = new URLSearchParams(current);
                                        next.delete('page');
                                        next.delete('sort');
                                        if (tab === 'active') {
                                            next.delete('status');
                                        } else {
                                            next.set('status', tab);
                                        }

                                        return next;
                                    },
                                    { replace: true },
                                );
                            }}
                            className="w-max min-w-full sm:min-w-0"
                        />
                    </div>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" aria-hidden />
                            <Input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search lease, tenant or unit" className="pl-9" aria-label="Search leases" />
                        </div>
                        <Select value={filters.property_id ?? ''} onChange={(event) => setFilter('property_id', event.target.value || undefined)} aria-label="Property" className="sm:w-56">
                            <option value="">All properties</option>
                            {properties?.data.map((property) => (
                                <option key={property.id} value={property.id}>
                                    {property.name}
                                </option>
                            ))}
                        </Select>
                        <Select value={filters.billing_frequency ?? ''} onChange={(event) => setFilter('billing_frequency', event.target.value || undefined)} aria-label="Billing frequency" className="sm:w-44">
                            <option value="">Any billing cycle</option>
                            {lookups?.billingFrequencies.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>

                {error ? (
                    <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
                ) : (
                    <DataTable
                        columns={columns}
                        data={data?.data}
                        loading={isPending || isFetching}
                        sort={effectiveSort}
                        onSortChange={(sort) => setFilter('sort', sort)}
                        onRowClick={(lease) => navigate(`/leases/${lease.id}`)}
                        getRowId={(lease) => String(lease.id)}
                        renderCard={(lease) => (
                            <Link to={`/leases/${lease.id}`} className="flex items-center gap-3 px-4 py-3 active:bg-gray-50 dark:active:bg-white/5">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate font-semibold text-gray-950 dark:text-white">{lease.tenant?.name}</p>
                                        {showStatus && <Badge color={lease.status.color}>{lease.status.label}</Badge>}
                                    </div>
                                    <p className="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                                        {lease.unit?.fullCode} · {lease.reference}
                                    </p>
                                    <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                        Ends <LeaseEndDate lease={lease} />
                                    </p>
                                </div>
                                <p className="text-sm font-medium text-gray-950 tabular-nums dark:text-white">{formatMoney(lease.rentAmount)}</p>
                                <ChevronRight className="size-5 shrink-0 text-gray-400" aria-hidden />
                            </Link>
                        )}
                        empty={
                            filters.search || filters.property_id || filters.billing_frequency ? (
                                <EmptyState icon={Search} title="No matching leases" description="Try a different search or clear the filters." />
                            ) : (
                                <EmptyState
                                    icon={FileSignature}
                                    title={emptyMessages[status]}
                                    description={status === 'active' || status === 'all' ? 'Let a vacant unit to create the first lease.' : undefined}
                                    action={
                                        canCreate &&
                                        (status === 'active' || status === 'all') && (
                                            <ButtonLink to="/leases/new">
                                                <FilePlus2 /> New lease
                                            </ButtonLink>
                                        )
                                    }
                                />
                            )
                        }
                    />
                )}

                {data && <Pagination meta={data.meta} onPageChange={(page) => setFilter('page', page > 1 ? String(page) : undefined, false)} />}
            </Card>
        </>
    );
}

function isCountedTab(tab: LeaseTab): tab is keyof LeaseList['counts'] {
    return tab === 'active' || tab === 'expiring' || tab === 'draft';
}
