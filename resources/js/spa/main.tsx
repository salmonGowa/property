import './app.css';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';
import { Toaster } from 'sonner';
import { currentUserKey } from '@/auth/auth';
import { ApiError, onUnauthenticated } from '@/lib/api';
import { router } from './router';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: true,
            // Client errors (403, 404, 422) won't fix themselves; only retry network and server failures.
            retry: (failureCount, error) => !(error instanceof ApiError && error.status < 500) && failureCount < 2,
        },
    },
});

// Session expired or the user signed out in another tab: drop the user so <RequireAuth> shows the login page.
onUnauthenticated(() => queryClient.setQueryData(currentUserKey, null));

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
            <Toaster richColors closeButton position="top-right" theme="system" />
        </QueryClientProvider>
    </StrictMode>,
);
