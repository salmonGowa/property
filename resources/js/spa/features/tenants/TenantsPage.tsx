import { createColumnHelper } from '@tanstack/react-table';
import { ChevronRight, Phone, Plus, Search, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useCan } from '@/auth/auth';
import { Badge } from '@/components/ui/Badge';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DataTable } from '@/components/ui/DataTable';
import { EmptyState, ErrorState } from '@/components/ui/Feedback';
import { Input, Select, Switch } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/PageHeader';
import { Pagination } from '@/components/ui/Pagination';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useLookups } from '@/hooks/useLookups';
import { errorMessage } from '@/lib/api';
import type { Tenant } from '@/types/api';
import { useTenants, type TenantFilters } from './api';
import { Balance } from './Balance';

const column = createColumnHelper<Tenant>();

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'all', label: 'All' },
] as const;

export function TenantsPage() {
    const canCreate = useCan('tenants', 'create');
    const navigate = useNavigate();
    const { data: lookups } = useLookups();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState(params.get('search') ?? '');
    const debouncedSearch = useDebouncedValue(search);

    const filters: TenantFilters = {
        search: params.get('search') ?? undefined,
        type: params.get('type') ?? undefined,
        status: (params.get('status') as TenantFilters['status']) ?? 'active',
        owes: params.get('owes') === '1',
        sort: params.get('sort') ?? 'name',
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

    const { data, isPending, isFetching, error, refetch } = useTenants(filters);
    const isFiltered = !!filters.search || !!filters.type || filters.owes || filters.status !== 'active';

    const columns = useMemo(
        () => [
            column.accessor('accountNumber', {
                header: 'Account',
                meta: { sortKey: 'account_number' },
                cell: ({ getValue }) => <span className="font-mono text-xs">{getValue()}</span>,
            }),
            column.accessor('name', {
                header: 'Tenant',
                meta: { sortKey: 'name', cellClassName: 'max-w-72' },
                cell: ({ row }) => (
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <Link to={`/tenants/${row.original.id}`} onClick={(event) => event.stopPropagation()} className="truncate font-semibold text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">
                                {row.original.name}
                            </Link>
                            {!row.original.isActive && <Badge color="warning">Inactive</Badge>}
                        </div>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{row.original.type.label}</p>
                    </div>
                ),
            }),
            column.accessor('phone', { header: 'Phone', meta: { cellClassName: 'tabular-nums' } }),
            column.accessor('activeLeasesCount', { header: 'Leases', meta: { align: 'center' } }),
            column.accessor('balance', {
                header: 'Balance',
                meta: { sortKey: 'balance', align: 'right' },
                cell: ({ getValue }) => <Balance value={getValue()} />,
            }),
        ],
        [],
    );

    return (
        <>
            <PageHeader
                title="Tenants"
                description="Individuals and companies renting units, and what they owe."
                actions={
                    canCreate && (
                        <ButtonLink to="/tenants/new" className="w-full sm:w-auto">
                            <Plus /> New tenant
                        </ButtonLink>
                    )
                }
            />

            <Card>
                <div className="flex flex-col gap-3 border-b border-gray-950/5 p-4 sm:px-6 dark:border-white/10">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" aria-hidden />
                            <Input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, account, phone, email or KRA PIN" className="pl-9" aria-label="Search tenants" />
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <SegmentedControl label="Status" value={filters.status ?? 'active'} options={statusOptions} onChange={(status) => setFilter('status', status === 'active' ? undefined : status)} />
                            <Select value={filters.type ?? ''} onChange={(event) => setFilter('type', event.target.value || undefined)} aria-label="Type" className="sm:w-40">
                                <option value="">All types</option>
                                {lookups?.tenantTypes.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Switch label="Owes money" checked={filters.owes} onChange={(event) => setFilter('owes', event.target.checked ? '1' : undefined)} className="items-center sm:justify-start" />
                        </div>
                    </div>
                </div>

                {error ? (
                    <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
                ) : (
                    <DataTable
                        columns={columns}
                        data={data?.data}
                        loading={isPending || isFetching}
                        sort={filters.sort}
                        onSortChange={(sort) => setFilter('sort', sort === 'name' ? undefined : sort)}
                        onRowClick={(tenant) => navigate(`/tenants/${tenant.id}`)}
                        getRowId={(tenant) => String(tenant.id)}
                        renderCard={(tenant) => (
                            <Link to={`/tenants/${tenant.id}`} className="flex items-center gap-3 px-4 py-3 active:bg-gray-50 dark:active:bg-white/5">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <p className="truncate font-semibold text-gray-950 dark:text-white">{tenant.name}</p>
                                        {!tenant.isActive && <Badge color="warning">Inactive</Badge>}
                                    </div>
                                    <p className="mt-0.5 flex items-center gap-1 text-sm text-gray-500 tabular-nums dark:text-gray-400">
                                        <Phone className="size-3.5" aria-hidden />
                                        {tenant.phone}
                                        <span className="font-mono text-xs"> · {tenant.accountNumber}</span>
                                    </p>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {tenant.activeLeasesCount} active {tenant.activeLeasesCount === 1 ? 'lease' : 'leases'}
                                    </p>
                                </div>
                                <div className="text-right text-sm">
                                    <Balance value={tenant.balance} />
                                </div>
                                <ChevronRight className="size-5 shrink-0 text-gray-400" aria-hidden />
                            </Link>
                        )}
                        empty={
                            isFiltered ? (
                                <EmptyState icon={Search} title="No matching tenants" description="Try a different search or clear the filters." />
                            ) : (
                                <EmptyState
                                    icon={Users}
                                    title="No tenants yet"
                                    description="Register a tenant, then draft their lease."
                                    action={
                                        canCreate && (
                                            <ButtonLink to="/tenants/new">
                                                <Plus /> New tenant
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
