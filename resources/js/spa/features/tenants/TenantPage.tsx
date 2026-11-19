import { Copy, ExternalLink, FilePlus2, Mail, Pencil, Phone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { useCan } from '@/auth/auth';
import { Badge } from '@/components/ui/Badge';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Detail, Stat } from '@/components/ui/DescriptionList';
import { ConfirmDialog } from '@/components/ui/Dialog';
import { ErrorState, Skeleton } from '@/components/ui/Feedback';
import { Menu } from '@/components/ui/Menu';
import { PageHeader } from '@/components/ui/PageHeader';
import { ApiError, errorMessage } from '@/lib/api';
import { cn } from '@/lib/cn';
import { filamentUrl } from '@/lib/config';
import { formatDate, formatMoney } from '@/lib/format';
import { useDeleteTenant, useTenant } from './api';
import { Balance } from './Balance';
import { TenantDocumentsSection } from './TenantDocumentsSection';
import { TenantLeasesSection } from './TenantLeasesSection';

export function TenantPage() {
    const id = Number(useParams().tenantId);
    const navigate = useNavigate();
    const canCreateLease = useCan('leases', 'create');
    const { data: tenant, isPending, error, refetch } = useTenant(id);
    const deleteTenant = useDeleteTenant();
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    if (isPending) {
        return (
            <div className="flex flex-col gap-6">
                <Skeleton className="h-9 w-64" />
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {Array.from({ length: 4 }, (_, index) => (
                        <Skeleton key={index} className="h-24" />
                    ))}
                </div>
                <Skeleton className="h-64" />
            </div>
        );
    }

    if (error) {
        const notFound = error instanceof ApiError && error.status === 404;

        return <ErrorState message={notFound ? 'This tenant does not exist or was deleted.' : errorMessage(error)} onRetry={notFound ? undefined : () => refetch()} />;
    }

    const summary = tenant.summary;
    const balance = Number(summary?.balance ?? 0);
    const isCompany = tenant.type.value === 'company';
    const copyAccount = () =>
        navigator.clipboard?.writeText(tenant.accountNumber).then(
            () => toast.success('Account number copied.'),
            () => toast.error('Could not copy; select the number instead.'),
        );

    return (
        <>
            <PageHeader
                back={{ to: '/tenants', label: 'Tenants' }}
                title={tenant.name}
                meta={
                    <>
                        <Badge>
                            <span className="font-mono">{tenant.accountNumber}</span>
                        </Badge>
                        <Badge color="primary">{tenant.type.label}</Badge>
                        {!tenant.isActive && <Badge color="warning">Inactive</Badge>}
                    </>
                }
                actions={
                    <>
                        {canCreateLease && tenant.isActive && (
                            <ButtonLink to={`/leases/new?tenant=${tenant.id}`} className="flex-1 sm:flex-none">
                                <FilePlus2 /> New lease
                            </ButtonLink>
                        )}
                        {tenant.can.update && (
                            <ButtonLink to={`/tenants/${tenant.id}/edit`} variant="secondary" className="flex-1 sm:flex-none">
                                <Pencil /> Edit
                            </ButtonLink>
                        )}
                        <Menu
                            items={[
                                { label: 'Payments & statement', icon: <ExternalLink />, onSelect: () => window.location.assign(`${filamentUrl}/tenants/${tenant.id}`) },
                                { label: 'Delete tenant', icon: <Trash2 />, danger: true, hidden: !tenant.can.delete, onSelect: () => setConfirmingDelete(true) },
                            ]}
                        />
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {summary && (
                    <dl className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                        <Stat label={balance < 0 ? 'In credit' : 'Balance due'} value={formatMoney(Math.abs(balance))} tone={balance > 0 ? 'danger' : balance < 0 ? 'success' : undefined} />
                        <Stat label="Overdue" value={formatMoney(summary.overdue)} tone={Number(summary.overdue) > 0 ? 'danger' : undefined} />
                        <Stat label="Active leases" value={summary.activeLeases} />
                        <Stat
                            label="Last payment"
                            value={summary.lastPayment ? formatMoney(summary.lastPayment.amount) : <span className="text-base font-medium text-gray-500 dark:text-gray-400">None yet</span>}
                            hint={summary.lastPayment ? formatDate(summary.lastPayment.receivedOn) : undefined}
                        />
                    </dl>
                )}

                {summary?.aging && (
                    <Card>
                        <CardHeader title="Arrears aging" description="What is owed, by how long it has been overdue." />
                        <dl className="grid grid-cols-2 divide-gray-950/5 sm:grid-cols-5 sm:divide-x dark:divide-white/10">
                            {summary.aging.map((bucket) => (
                                <div key={bucket.key} className="px-4 py-3 sm:px-6">
                                    <dt className="text-xs font-medium text-gray-500 dark:text-gray-400">{bucket.label}</dt>
                                    <dd className={cn('mt-1 text-sm font-semibold tabular-nums', bucket.key !== 'current' && Number(bucket.amount) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-950 dark:text-white')}>
                                        {formatMoney(bucket.amount)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Card>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader title="Details" />
                        <CardBody>
                            <dl className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                                <Detail label="Phone">
                                    <a href={`tel:${tenant.phone}`} className="inline-flex items-center gap-1.5 text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                        <Phone className="size-3.5" aria-hidden />
                                        {tenant.phone}
                                    </a>
                                </Detail>
                                <Detail label="Email">
                                    {tenant.email && (
                                        <a href={`mailto:${tenant.email}`} className="inline-flex items-center gap-1.5 break-all text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                            <Mail className="size-3.5 shrink-0" aria-hidden />
                                            {tenant.email}
                                        </a>
                                    )}
                                </Detail>
                                <Detail label="KRA PIN">{tenant.kraPin ? <span className="font-mono">{tenant.kraPin}</span> : <span className="text-amber-600 dark:text-amber-400">Missing</span>}</Detail>
                                {isCompany ? (
                                    <>
                                        <Detail label="Registration number">{tenant.registrationNumber}</Detail>
                                        <Detail label="Contact person">{[tenant.contactPerson, tenant.contactPersonPhone].filter(Boolean).join(' · ')}</Detail>
                                    </>
                                ) : (
                                    <Detail label="ID / passport">{tenant.idNumber}</Detail>
                                )}
                                <Detail label="Alternate phone">{tenant.alternatePhone}</Detail>
                                <Detail label="Postal address">{tenant.postalAddress}</Detail>
                                <Detail label="Emergency contact">{[tenant.emergencyContactName, tenant.emergencyContactPhone].filter(Boolean).join(' · ')}</Detail>
                                <Detail label="Notes" wide>
                                    {tenant.notes}
                                </Detail>
                            </dl>
                        </CardBody>
                    </Card>

                    <Card className="order-first h-fit lg:order-none">
                        <CardHeader title="Paying by M-Pesa" />
                        <CardBody className="flex flex-col gap-4">
                            <div>
                                <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Paybill number</p>
                                <p className="mt-1 text-lg font-semibold text-gray-950 tabular-nums dark:text-white">{summary?.paybill ?? <span className="text-sm font-normal text-gray-500">Not configured</span>}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Account number</p>
                                <div className="mt-1 flex items-center gap-2">
                                    <p className="font-mono text-lg font-semibold text-gray-950 dark:text-white">{tenant.accountNumber}</p>
                                    <button type="button" onClick={copyAccount} className="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="Copy account number">
                                        <Copy className="size-4" />
                                    </button>
                                </div>
                            </div>
                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                Payments quoting this account are matched to {tenant.name.split(' ')[0]} automatically. Current balance: <Balance value={summary?.balance} />.
                            </p>
                        </CardBody>
                    </Card>
                </div>

                <TenantLeasesSection tenant={tenant} />

                {tenant.can.viewDocuments && <TenantDocumentsSection tenant={tenant} />}
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={`Delete ${tenant.name}?`}
                message="This permanently removes the tenant. Tenants who have had a lease or invoice can only be deactivated."
                loading={deleteTenant.isPending}
                onClose={() => setConfirmingDelete(false)}
                onConfirm={() =>
                    deleteTenant.mutate(tenant.id, {
                        onSuccess: () => {
                            toast.success(`${tenant.name} deleted.`);
                            navigate('/tenants', { replace: true });
                        },
                        onError: (deleteError) => toast.error(errorMessage(deleteError)),
                    })
                }
            />
        </>
    );
}
