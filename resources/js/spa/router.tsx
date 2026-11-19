import { lazy, type ReactNode } from 'react';
import { createBrowserRouter } from 'react-router';
import { GuestOnly, RequireAuth, RequirePermission } from '@/auth/auth';
import { AppLayout } from '@/components/layout/AppLayout';
import { DashboardPage } from '@/pages/DashboardPage';
import { LoginPage } from '@/pages/LoginPage';
import { ForbiddenPage, NotFoundPage } from '@/pages/StatusPages';
import type { PermissionKey } from '@/types/api';

/* Feature screens are split into their own chunks so the shell and login page load fast on mobile data. */
const PropertiesPage = lazy(() => import('@/features/properties/PropertiesPage').then((module) => ({ default: module.PropertiesPage })));
const PropertyPage = lazy(() => import('@/features/properties/PropertyPage').then((module) => ({ default: module.PropertyPage })));
const CreatePropertyPage = lazy(() => import('@/features/properties/PropertyFormPage').then((module) => ({ default: module.CreatePropertyPage })));
const EditPropertyPage = lazy(() => import('@/features/properties/PropertyFormPage').then((module) => ({ default: module.EditPropertyPage })));
const TenantsPage = lazy(() => import('@/features/tenants/TenantsPage').then((module) => ({ default: module.TenantsPage })));
const TenantPage = lazy(() => import('@/features/tenants/TenantPage').then((module) => ({ default: module.TenantPage })));
const CreateTenantPage = lazy(() => import('@/features/tenants/TenantFormPage').then((module) => ({ default: module.CreateTenantPage })));
const EditTenantPage = lazy(() => import('@/features/tenants/TenantFormPage').then((module) => ({ default: module.EditTenantPage })));
const LeasesPage = lazy(() => import('@/features/leases/LeasesPage').then((module) => ({ default: module.LeasesPage })));
const LeasePage = lazy(() => import('@/features/leases/LeasePage').then((module) => ({ default: module.LeasePage })));
const CreateLeasePage = lazy(() => import('@/features/leases/LeaseFormPage').then((module) => ({ default: module.CreateLeasePage })));
const EditLeasePage = lazy(() => import('@/features/leases/LeaseFormPage').then((module) => ({ default: module.EditLeasePage })));

function guarded(permission: PermissionKey, page: ReactNode) {
    return <RequirePermission permission={permission}>{page}</RequirePermission>;
}

export const router = createBrowserRouter(
    [
        {
            element: <GuestOnly />,
            children: [{ path: 'login', element: <LoginPage /> }],
        },
        {
            element: <RequireAuth />,
            children: [
                {
                    element: <AppLayout />,
                    children: [
                        { index: true, element: <DashboardPage /> },
                        {
                            path: 'properties',
                            element: (
                                <RequirePermission permission="properties">
                                    <PropertiesPage />
                                </RequirePermission>
                            ),
                        },
                        { path: 'properties/new', element: <CreatePropertyPage /> },
                        {
                            path: 'properties/:propertyId',
                            element: (
                                <RequirePermission permission="properties">
                                    <PropertyPage />
                                </RequirePermission>
                            ),
                        },
                        { path: 'properties/:propertyId/edit', element: <EditPropertyPage /> },
                        { path: 'tenants', element: guarded('tenants', <TenantsPage />) },
                        { path: 'tenants/new', element: <CreateTenantPage /> },
                        { path: 'tenants/:tenantId', element: guarded('tenants', <TenantPage />) },
                        { path: 'tenants/:tenantId/edit', element: <EditTenantPage /> },
                        { path: 'leases', element: guarded('leases', <LeasesPage />) },
                        { path: 'leases/new', element: <CreateLeasePage /> },
                        { path: 'leases/:leaseId', element: guarded('leases', <LeasePage />) },
                        { path: 'leases/:leaseId/edit', element: <EditLeasePage /> },
                        { path: 'forbidden', element: <ForbiddenPage /> },
                        { path: '*', element: <NotFoundPage /> },
                    ],
                },
            ],
        },
    ],
    { basename: '/app' },
);
