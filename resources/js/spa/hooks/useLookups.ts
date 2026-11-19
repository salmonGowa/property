import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Lookups, Resource } from '@/types/api';

export function useLookups() {
    return useQuery({
        queryKey: ['lookups'],
        queryFn: async () => (await api<Resource<Lookups>>('/lookups')).data,
        staleTime: 10 * 60_000,
    });
}
