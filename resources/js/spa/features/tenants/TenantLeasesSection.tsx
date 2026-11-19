import { ChevronRight, FilePlus2, FileSignature } from 'lucide-react';
import { Link } from 'react-router';
import { useCan } from '@/auth/auth';
import { Badge } from '@/components/ui/Badge';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/Feedback';
import { useLeases } from '@/features/leases/api';
import { LeaseEndDate } from '@/features/leases/LeaseEndDate';
import { errorMessage } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import type { Tenant } from '@/types/api';

export function TenantLeasesSection({ tenant }: { tenant: Tenant }) {
    const canCreate = useCan('leases', 'create') && tenant.isActive;
    const { data, isPending, error, refetch } = useLeases({ tenant_id: tenant.id, status: 'all', sort: '-start_date', per_page: 50 });
    const newLease = (
        <ButtonLink to={`/leases/new?tenant=${tenant.id}`} size="sm" className="w-full sm:w-auto">
            <FilePlus2 /> New lease
        </ButtonLink>
    );

    return (
        <Card>
            <CardHeader title="Leases" description={data ? `${data.meta.total} ${data.meta.total === 1 ? 'lease' : 'leases'}` : undefined} actions={canCreate && newLease} />
            {isPending ? (
                <div className="flex flex-col gap-3 p-4 sm:p-6">
                    <Skeleton className="h-12" />
                    <Skeleton className="h-12" />
                </div>
            ) : error ? (
                <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
            ) : data.data.length === 0 ? (
                <EmptyState icon={FileSignature} title="No leases yet" description="Draft a lease to let a unit to this tenant." action={canCreate && newLease} />
            ) : (
                <ul role="list" className="divide-y divide-gray-950/5 dark:divide-white/10">
                    {data.data.map((lease) => (
                        <li key={lease.id}>
                            <Link to={`/leases/${lease.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 sm:px-6 dark:hover:bg-white/[0.03]">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold text-gray-950 dark:text-white">{lease.unit?.fullCode}</span>
                                        <Badge color={lease.status.color}>{lease.status.label}</Badge>
                                    </div>
                                    <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                        {lease.reference} · {lease.unit?.property?.name}
                                    </p>
                                    <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                        {formatDate(lease.startDate)} – <LeaseEndDate lease={lease} />
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p className="text-sm font-medium text-gray-950 tabular-nums dark:text-white">{formatMoney(lease.rentAmount)}</p>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">per month</p>
                                </div>
                                <ChevronRight className="size-5 shrink-0 text-gray-400" aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
