import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { isDetailOf } from '@/lib/queryKeys';
import type { Paginated, Property, Resource } from '@/types/api';

export interface PropertyFilters {
    search?: string;
    type?: string;
    county?: string;
    manager_id?: string;
    status?: 'active' | 'inactive' | 'all';
    sort?: string;
    page?: number;
    per_page?: number;
}

/** Field names match the API (snake_case) so 422 errors map straight onto the form. */
export interface PropertyPayload {
    name: string;
    code: string;
    type: string;
    county: string;
    town: string | null;
    address: string | null;
    lr_number: string | null;
    total_area_sqft: string | null;
    year_built: number | null;
    manager_id: number | null;
    penalty_rule_id: number | null;
    description: string | null;
    is_active: boolean;
}

export const propertyKeys = {
    all: ['properties'] as const,
    list: (filters: PropertyFilters) => [...propertyKeys.all, 'list', filters] as const,
    detail: (id: number) => [...propertyKeys.all, 'detail', id] as const,
};

export function useProperties(filters: PropertyFilters) {
    return useQuery({
        queryKey: propertyKeys.list(filters),
        queryFn: ({ signal }) => api<Paginated<Property>>('/properties', { query: { ...filters }, signal }),
        placeholderData: keepPreviousData,
    });
}

export function useProperty(id: number) {
    return useQuery({
        queryKey: propertyKeys.detail(id),
        queryFn: async ({ signal }) => (await api<Resource<Property>>(`/properties/${id}`, { signal })).data,
    });
}

function useInvalidateOnSuccess() {
    const queryClient = useQueryClient();

    return (property?: Property) => {
        if (property) {
            queryClient.setQueryData(propertyKeys.detail(property.id), property);
        }

        return queryClient.invalidateQueries({ queryKey: propertyKeys.all });
    };
}

export function useSaveProperty(id?: number) {
    const invalidate = useInvalidateOnSuccess();

    return useMutation({
        mutationFn: async (payload: PropertyPayload) =>
            (await api<Resource<Property>>(id ? `/properties/${id}` : '/properties', { method: id ? 'PUT' : 'POST', body: payload })).data,
        onSuccess: (property) => invalidate(property),
    });
}

export function useDeleteProperty() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => api<void>(`/properties/${id}`, { method: 'DELETE' }),
        onSuccess: (_, id) => {
            // The deleted property's screen is still mounted until the caller navigates away; don't refetch it into a 404.
            // Its cache entry is garbage-collected once that screen unmounts.
            return queryClient.invalidateQueries({ queryKey: propertyKeys.all, predicate: (query) => !isDetailOf(query.queryKey, propertyKeys.detail(id)) });
        },
    });
}

export function useUploadPropertyPhoto(id: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (file: File) => {
            const body = new FormData();
            body.append('photo', file);

            return (await api<Resource<Property>>(`/properties/${id}/photo`, { method: 'POST', body })).data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: propertyKeys.all }),
    });
}

export function useRemovePropertyPhoto(id: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api<Resource<Property>>(`/properties/${id}/photo`, { method: 'DELETE' }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: propertyKeys.all }),
    });
}
