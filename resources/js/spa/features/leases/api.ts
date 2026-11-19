import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { isDetailOf } from '@/lib/queryKeys';
import type { Lease, LettableUnit, Paginated, Resource } from '@/types/api';

export type LeaseTab = 'active' | 'expiring' | 'draft' | 'terminated' | 'all';

export interface LeaseFilters {
    status?: LeaseTab;
    search?: string;
    property_id?: string;
    tenant_id?: number;
    unit_id?: number;
    billing_frequency?: string;
    sort?: string;
    page?: number;
    per_page?: number;
}

export type LeaseList = Paginated<Lease> & { counts: Record<'active' | 'expiring' | 'draft', number> };

/** Field names match the API (snake_case) so 422 errors map straight onto the form. */
export interface LeasePayload {
    tenant_id: number;
    unit_id: number;
    start_date: string;
    end_date: string | null;
    billing_frequency: string;
    due_day: number;
    rent_amount: string;
    service_charge: string;
    deposit_amount: string;
    charge_vat: boolean;
    escalation_rate: string;
    escalation_interval_months: number;
    penalty_rule_id: number | null;
    penalties_exempt: boolean;
    notes: string | null;
}

export interface DepositSettlementPayload {
    date: string;
    deductions: { description: string; amount: string }[];
    refund_method: string | null;
    refund_reference: string | null;
}

export interface DepositSettlement {
    held: string;
    applied: string;
    refunded: string;
    stillHeld: string;
}

export const leaseKeys = {
    all: ['leases'] as const,
    list: (filters: LeaseFilters) => [...leaseKeys.all, 'list', filters] as const,
    detail: (id: number) => [...leaseKeys.all, 'detail', id] as const,
    lettableUnits: (include?: number) => ['lettable-units', include ?? null] as const,
};

export function useLeases(filters: LeaseFilters, enabled = true) {
    return useQuery({
        queryKey: leaseKeys.list(filters),
        queryFn: ({ signal }) => api<LeaseList>('/leases', { query: { ...filters }, signal }),
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useLease(id: number) {
    return useQuery({
        queryKey: leaseKeys.detail(id),
        queryFn: async ({ signal }) => (await api<Resource<Lease>>(`/leases/${id}`, { signal })).data,
    });
}

export function useLettableUnits(include?: number) {
    return useQuery({
        queryKey: leaseKeys.lettableUnits(include),
        queryFn: async ({ signal }) => (await api<{ data: LettableUnit[] }>('/lettable-units', { query: { include }, signal })).data,
    });
}

/**
 * A lease changing state moves unit occupancy, tenant balances and property figures,
 * so every cache that shows them is refreshed.
 */
function useRefreshAfterLeaseChange() {
    const queryClient = useQueryClient();

    return (lease?: Lease, deletedId?: number) => {
        if (lease) {
            queryClient.setQueryData(leaseKeys.detail(lease.id), lease);
        }

        return Promise.all(
            [leaseKeys.all, ['lettable-units'], ['tenants'], ['units'], ['properties']].map((queryKey) =>
                queryClient.invalidateQueries({ queryKey, predicate: (query) => !isDetailOf(query.queryKey, leaseKeys.detail(deletedId ?? -1)) }),
            ),
        );
    };
}

export function useSaveLease(id?: number) {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: async (payload: LeasePayload) => (await api<Resource<Lease>>(id ? `/leases/${id}` : '/leases', { method: id ? 'PUT' : 'POST', body: payload })).data,
        onSuccess: (lease) => refresh(lease),
    });
}

export function useDeleteLease() {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: (id: number) => api<void>(`/leases/${id}`, { method: 'DELETE' }),
        onSuccess: (_, id) => {
            // The deleted lease's screen is still mounted until the caller navigates away; don't refetch it into a 404.
            // Its cache entry is garbage-collected once that screen unmounts.
            return refresh(undefined, id);
        },
    });
}

type LeaseActionResponse<TMeta = Record<string, never>> = Resource<Lease> & { meta?: TMeta };

export function useActivateLease(id: number) {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: () => api<LeaseActionResponse<{ invoicesIssued: number }>>(`/leases/${id}/activate`, { method: 'POST' }),
        onSuccess: (response) => refresh(response.data),
    });
}

export function useTerminateLease(id: number) {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: (payload: { terminated_on: string; reason: string }) =>
            api<LeaseActionResponse<{ invoicesAdjusted: number }>>(`/leases/${id}/terminate`, { method: 'POST', body: payload }),
        onSuccess: (response) => refresh(response.data),
    });
}

export function useRenewLease(id: number) {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: async (payload: { start_date: string; end_date: string | null; rent_amount: string }) =>
            (await api<Resource<Lease>>(`/leases/${id}/renew`, { method: 'POST', body: payload })).data,
        onSuccess: (renewal) => refresh(renewal),
    });
}

export function useChangePenaltyRule(id: number) {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: async (payload: { penalty_rule_id: number | null; penalties_exempt: boolean }) =>
            (await api<Resource<Lease>>(`/leases/${id}/penalty-rule`, { method: 'PUT', body: payload })).data,
        onSuccess: (lease) => refresh(lease),
    });
}

export function useSettleDeposit(id: number) {
    const refresh = useRefreshAfterLeaseChange();

    return useMutation({
        mutationFn: async (payload: DepositSettlementPayload) =>
            (await api<Resource<DepositSettlement>>(`/leases/${id}/deposit-settlement`, { method: 'POST', body: payload })).data,
        onSuccess: () => refresh(),
    });
}
