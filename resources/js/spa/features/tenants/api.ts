import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { isDetailOf } from '@/lib/queryKeys';
import type { Paginated, Resource, Tenant, TenantDocument } from '@/types/api';

export interface TenantFilters {
    search?: string;
    type?: string;
    status?: 'active' | 'inactive' | 'all';
    owes?: boolean;
    sort?: string;
    page?: number;
    per_page?: number;
}

/** Field names match the API (snake_case) so 422 errors map straight onto the form. */
export interface TenantPayload {
    type: 'individual' | 'company';
    name: string;
    phone: string;
    email: string | null;
    kra_pin: string | null;
    id_number?: string | null;
    registration_number: string | null;
    contact_person: string | null;
    contact_person_phone: string | null;
    alternate_phone: string | null;
    postal_address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    notes: string | null;
    is_active: boolean;
}

export const tenantKeys = {
    all: ['tenants'] as const,
    list: (filters: TenantFilters) => [...tenantKeys.all, 'list', filters] as const,
    detail: (id: number) => [...tenantKeys.all, 'detail', id] as const,
    documents: (id: number) => [...tenantKeys.all, 'documents', id] as const,
};

export function useTenants(filters: TenantFilters, enabled = true) {
    return useQuery({
        queryKey: tenantKeys.list(filters),
        queryFn: ({ signal }) => api<Paginated<Tenant>>('/tenants', { query: { ...filters, owes: filters.owes ? 1 : undefined }, signal }),
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useTenant(id: number, enabled = true) {
    return useQuery({
        queryKey: tenantKeys.detail(id),
        queryFn: async ({ signal }) => (await api<Resource<Tenant>>(`/tenants/${id}`, { signal })).data,
        enabled,
    });
}

export function useSaveTenant(id?: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: TenantPayload) =>
            (await api<Resource<Tenant>>(id ? `/tenants/${id}` : '/tenants', { method: id ? 'PUT' : 'POST', body: payload })).data,
        onSuccess: (tenant) => {
            queryClient.setQueryData(tenantKeys.detail(tenant.id), tenant);

            // Lease screens show the tenant's name and phone too.
            return Promise.all([queryClient.invalidateQueries({ queryKey: tenantKeys.all }), queryClient.invalidateQueries({ queryKey: ['leases'] })]);
        },
    });
}

export function useDeleteTenant() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => api<void>(`/tenants/${id}`, { method: 'DELETE' }),
        onSuccess: (_, id) => {
            // The deleted tenant's screen is still mounted until the caller navigates away; don't refetch it into a 404.
            // Its cache entry is garbage-collected once that screen unmounts.
            return queryClient.invalidateQueries({ queryKey: tenantKeys.all, predicate: (query) => !isDetailOf(query.queryKey, tenantKeys.detail(id)) });
        },
    });
}

export function useTenantDocuments(tenantId: number, enabled: boolean) {
    return useQuery({
        queryKey: tenantKeys.documents(tenantId),
        queryFn: async ({ signal }) => (await api<{ data: TenantDocument[] }>(`/tenants/${tenantId}/documents`, { signal })).data,
        enabled,
    });
}

export function useUploadTenantDocument(tenantId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (input: { type: string; expires_on: string | null; file: File }) => {
            const body = new FormData();
            body.append('type', input.type);
            body.append('file', input.file);
            if (input.expires_on) {
                body.append('expires_on', input.expires_on);
            }

            return (await api<Resource<TenantDocument>>(`/tenants/${tenantId}/documents`, { method: 'POST', body })).data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: tenantKeys.documents(tenantId) }),
    });
}

export function useDeleteTenantDocument(tenantId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (documentId: number) => api<void>(`/tenant-documents/${documentId}`, { method: 'DELETE' }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: tenantKeys.documents(tenantId) }),
    });
}
