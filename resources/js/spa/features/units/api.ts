import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { propertyKeys } from '@/features/properties/api';
import { api } from '@/lib/api';
import type { Paginated, Resource, Unit } from '@/types/api';

export interface UnitFilters {
    search?: string;
    status?: string;
    sort?: string;
    page?: number;
}

export interface UnitPayload {
    code: string;
    type: string;
    status?: string;
    floor: string | null;
    bedrooms: number | null;
    bathrooms: number | null;
    area_sqft: string | null;
    market_rent: string;
    service_charge: string | null;
    notes: string | null;
}

export const unitKeys = {
    all: ['units'] as const,
    forProperty: (propertyId: number, filters: UnitFilters) => [...unitKeys.all, 'property', propertyId, filters] as const,
};

export function usePropertyUnits(propertyId: number, filters: UnitFilters) {
    return useQuery({
        queryKey: unitKeys.forProperty(propertyId, filters),
        queryFn: ({ signal }) => api<Paginated<Unit>>(`/properties/${propertyId}/units`, { query: { ...filters }, signal }),
        placeholderData: keepPreviousData,
    });
}

/** Unit changes move the property's occupancy figures too, so both caches are refreshed. */
function useRefreshAfterUnitChange() {
    const queryClient = useQueryClient();

    return () => Promise.all([queryClient.invalidateQueries({ queryKey: unitKeys.all }), queryClient.invalidateQueries({ queryKey: propertyKeys.all })]);
}

export function useSaveUnit(propertyId: number, unitId?: number) {
    const refresh = useRefreshAfterUnitChange();

    return useMutation({
        mutationFn: async (payload: UnitPayload) =>
            (await api<Resource<Unit>>(unitId ? `/units/${unitId}` : `/properties/${propertyId}/units`, { method: unitId ? 'PUT' : 'POST', body: payload })).data,
        onSuccess: refresh,
    });
}

export function useDeleteUnit() {
    const refresh = useRefreshAfterUnitChange();

    return useMutation({
        mutationFn: (unitId: number) => api<void>(`/units/${unitId}`, { method: 'DELETE' }),
        onSuccess: refresh,
    });
}
