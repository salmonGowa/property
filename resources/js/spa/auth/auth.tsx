import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createContext, useContext } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router';
import { FullPageSpinner } from '@/components/ui/Feedback';
import { api, ApiError, ensureCsrfCookie } from '@/lib/api';
import type { CurrentUser, PermissionKey, Resource } from '@/types/api';

export const currentUserKey = ['me'] as const;

export function useCurrentUser() {
    return useQuery({
        queryKey: currentUserKey,
        queryFn: async (): Promise<CurrentUser | null> => {
            try {
                return (await api<Resource<CurrentUser>>('/me')).data;
            } catch (error) {
                if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
                    return null;
                }

                throw error;
            }
        },
        staleTime: 5 * 60_000,
        retry: false,
    });
}

/**
 * Provided by <RequireAuth> from the user it rendered with, so components below it never see the
 * user vanish mid-render when the session ends (sign-out, expiry) before the redirect happens.
 */
const UserContext = createContext<CurrentUser | null>(null);

/** The signed-in user. Only call below <RequireAuth>, where the user is guaranteed. */
export function useUser(): CurrentUser {
    const user = useContext(UserContext);

    if (!user) {
        throw new Error('useUser() called outside an authenticated route.');
    }

    return user;
}

export function useCan(key: PermissionKey, ability: 'viewAny' | 'create' = 'viewAny'): boolean {
    return useCurrentUser().data?.permissions[key]?.[ability] ?? false;
}

export interface LoginCredentials {
    email: string;
    password: string;
    remember: boolean;
}

export function useLogin() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (credentials: LoginCredentials) => {
            await ensureCsrfCookie();

            return (await api<Resource<CurrentUser>>('/auth/login', { method: 'POST', body: credentials })).data;
        },
        onSuccess: (user) => queryClient.setQueryData(currentUserKey, user),
    });
}

export function useLogout() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => api<void>('/auth/logout', { method: 'POST' }),
        onSettled: () => {
            queryClient.setQueryData(currentUserKey, null);
            queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== currentUserKey[0] });
        },
    });
}

export function RequireAuth() {
    const { data: user, isPending } = useCurrentUser();
    const location = useLocation();

    if (isPending) {
        return <FullPageSpinner />;
    }

    if (!user) {
        return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
    }

    return (
        <UserContext.Provider value={user}>
            <Outlet />
        </UserContext.Provider>
    );
}

export function GuestOnly() {
    const { data: user, isPending } = useCurrentUser();
    const location = useLocation();

    if (isPending) {
        return <FullPageSpinner />;
    }

    if (user) {
        const from = (location.state as { from?: string } | null)?.from;

        return <Navigate to={from && from !== '/login' ? from : '/'} replace />;
    }

    return <Outlet />;
}

/** Renders children only when the user may view the given resource; otherwise a 403 page. */
export function RequirePermission({ permission, ability = 'viewAny', children }: { permission: PermissionKey; ability?: 'viewAny' | 'create'; children: React.ReactNode }) {
    const allowed = useCan(permission, ability);

    if (!allowed) {
        return <Navigate to="/forbidden" replace />;
    }

    return <>{children}</>;
}
