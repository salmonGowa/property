import { createColumnHelper } from '@tanstack/react-table';
import { Building2, ChevronRight, Plus, Search, SlidersHorizontal } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useCan } from '@/auth/auth';
import { Badge, occupancyColor } from '@/components/ui/Badge';
import { ButtonLink, Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DataTable } from '@/components/ui/DataTable';
import { EmptyState, ErrorState } from '@/components/ui/Feedback';
import { Input, Select } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/PageHeader';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Pagination } from '@/components/ui/Pagination';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useLookups } from '@/hooks/useLookups';
import { errorMessage } from '@/lib/api';
import type { Property } from '@/types/api';
import { useProperties, type PropertyFilters } from './api';
import { PropertyThumb } from './PropertyThumb';

const column = createColumnHelper<Property>();

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'all', label: 'All' },
] as const;

export function PropertiesPage() {
    const canCreate = useCan('properties', 'create');
    const navigate = useNavigate();
    const { data: lookups } = useLookups();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState(params.get('search') ?? '');
    const debouncedSearch = useDebouncedValue(search);
    const [filtersOpen, setFiltersOpen] = useState(() => ['type', 'county', 'manager_id'].some((key) => params.has(key)));

    const filters: PropertyFilters = {
        search: params.get('search') ?? undefined,
        type: params.get('type') ?? undefined,
        county: params.get('county') ?? undefined,
        manager_id: params.get('manager_id') ?? undefined,
        status: (params.get('status') as PropertyFilters['status']) ?? 'active',
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

    const { data, isPending, isFetching, error, refetch } = useProperties(filters);
    const activeFilterCount = [filters.type, filters.county, filters.manager_id].filter(Boolean).length;

    const columns = useMemo(
        () => [
            column.accessor('name', {
                header: 'Property',
                meta: { sortKey: 'name' },
                cell: ({ row }) => (
                    <div className="flex items-center gap-3">
                        <PropertyThumb url={row.original.photoUrl} className="size-10" />
                        <div className="min-w-0">
                            <Link to={`/properties/${row.original.id}`} onClick={(event) => event.stopPropagation()} className="font-semibold text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">
                                {row.original.name}
                            </Link>
                            <p className="text-xs text-gray-500 dark:text-gray-400">{row.original.code}</p>
                        </div>
                    </div>
                ),
            }),
            column.accessor('type', { header: 'Type', cell: ({ getValue }) => <Badge>{getValue().label}</Badge> }),
            column.accessor('location', { header: 'Location', cell: ({ getValue }) => getValue() || '—', meta: { cellClassName: 'max-w-56 truncate' } }),
            column.accessor('unitsCount', { header: 'Units', meta: { sortKey: 'units_count', align: 'center' } }),
            column.accessor('occupancyRate', {
                header: 'Occupancy',
                meta: { align: 'center' },
                cell: ({ row }) => (row.original.unitsCount ? <Badge color={occupancyColor(row.original.occupancyRate ?? 0)}>{row.original.occupancyRate}%</Badge> : '—'),
            }),
            column.accessor((property) => property.manager?.name, {
                id: 'manager',
                header: 'Manager',
                meta: { headerClassName: 'hidden xl:table-cell', cellClassName: 'hidden xl:table-cell' },
                cell: ({ getValue }) => getValue() ?? '—',
            }),
            column.display({
                id: 'status',
                header: () => <span className="sr-only">Status</span>,
                meta: { align: 'right' },
                cell: ({ row }) => (row.original.isActive ? null : <Badge color="warning">Inactive</Badge>),
            }),
        ],
        [],
    );

    return (
        <>
            <PageHeader
                title="Properties"
                description="Buildings in the portfolio, their units and occupancy."
                actions={
                    canCreate && (
                        <ButtonLink to="/properties/new" className="w-full sm:w-auto">
                            <Plus /> New property
                        </ButtonLink>
                    )
                }
            />

            <Card>
                <div className="flex flex-col gap-3 border-b border-gray-950/5 p-4 sm:px-6 dark:border-white/10">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" aria-hidden />
                            <Input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, code, town or L.R. number" className="pl-9" aria-label="Search properties" />
                        </div>
                        <div className="flex items-center gap-2">
                            <SegmentedControl
                                label="Status"
                                value={filters.status ?? 'active'}
                                options={statusOptions}
                                onChange={(status) => setFilter('status', status === 'active' ? undefined : status)}
                                className="flex-1 sm:flex-none"
                            />
                            <Button variant="secondary" onClick={() => setFiltersOpen((open) => !open)} aria-expanded={filtersOpen} aria-controls="property-filters">
                                <SlidersHorizontal />
                                <span className="hidden sm:inline">Filters</span>
                                {activeFilterCount > 0 && <span className="rounded-full bg-primary-600 px-1.5 text-xs text-white">{activeFilterCount}</span>}
                            </Button>
                        </div>
                    </div>

                    {filtersOpen && (
                        <div id="property-filters" className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Select value={filters.type ?? ''} onChange={(event) => setFilter('type', event.target.value || undefined)} aria-label="Type">
                                <option value="">All types</option>
                                {lookups?.propertyTypes.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select value={filters.county ?? ''} onChange={(event) => setFilter('county', event.target.value || undefined)} aria-label="County">
                                <option value="">All counties</option>
                                {lookups?.counties.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select value={filters.manager_id ?? ''} onChange={(event) => setFilter('manager_id', event.target.value || undefined)} aria-label="Manager">
                                <option value="">Any manager</option>
                                {lookups?.propertyManagers.map((manager) => (
                                    <option key={manager.id} value={manager.id}>
                                        {manager.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    )}
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
                        onRowClick={(property) => navigate(`/properties/${property.id}`)}
                        getRowId={(property) => String(property.id)}
                        renderCard={(property) => (
                            <Link to={`/properties/${property.id}`} className="flex items-center gap-3 px-4 py-3 active:bg-gray-50 dark:active:bg-white/5">
                                <PropertyThumb url={property.photoUrl} className="size-12" />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <p className="truncate font-semibold text-gray-950 dark:text-white">{property.name}</p>
                                        {!property.isActive && <Badge color="warning">Inactive</Badge>}
                                    </div>
                                    <p className="truncate text-sm text-gray-500 dark:text-gray-400">
                                        {property.code} · {property.location || property.type.label}
                                    </p>
                                    <div className="mt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span>
                                            {property.unitsCount} {property.unitsCount === 1 ? 'unit' : 'units'}
                                        </span>
                                        {!!property.unitsCount && <Badge color={occupancyColor(property.occupancyRate ?? 0)}>{property.occupancyRate}% let</Badge>}
                                    </div>
                                </div>
                                <ChevronRight className="size-5 shrink-0 text-gray-400" aria-hidden />
                            </Link>
                        )}
                        empty={
                            filters.search || activeFilterCount > 0 || filters.status !== 'active' ? (
                                <EmptyState icon={Search} title="No matching properties" description="Try a different search or clear the filters." />
                            ) : (
                                <EmptyState
                                    icon={Building2}
                                    title="No properties yet"
                                    description="Add your first building to start letting units."
                                    action={canCreate && <ButtonLink to="/properties/new"><Plus /> New property</ButtonLink>}
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
