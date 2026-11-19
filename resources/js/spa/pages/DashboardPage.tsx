import { ArrowRight, Building2, ChevronRight, DoorOpen, ExternalLink, Percent } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { useCan, useUser } from '@/auth/auth';
import { Card, CardHeader } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Feedback';
import { PageHeader } from '@/components/ui/PageHeader';
import { useLeases } from '@/features/leases/api';
import { LeaseEndDate } from '@/features/leases/LeaseEndDate';
import { useProperties } from '@/features/properties/api';
import { filamentUrl } from '@/lib/config';

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

function StatCard({ icon: Icon, label, value, loading }: { icon: typeof Building2; label: string; value: ReactNode; loading: boolean }) {
    return (
        <Card className="flex items-center gap-4 p-4 sm:p-5">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                <Icon className="size-5" aria-hidden />
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm text-gray-500 dark:text-gray-400">{label}</p>
                {loading ? <Skeleton className="mt-1 h-7 w-16" /> : <p className="text-2xl font-semibold text-gray-950 tabular-nums dark:text-white">{value}</p>}
            </div>
        </Card>
    );
}

export function DashboardPage() {
    const user = useUser();
    const canViewProperties = useCan('properties');
    const canViewLeases = useCan('leases');

    return (
        <>
            <PageHeader title={`${greeting()}, ${user.name.split(' ')[0]}`} description={user.role?.label} />

            {canViewProperties && <PortfolioSummary />}

            {canViewLeases && <LeasesNeedingAttention />}

            <Card className="mt-6 p-5 sm:p-6">
                <h2 className="text-base font-semibold text-gray-950 dark:text-white">The new back office is rolling out</h2>
                <p className="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-400">
                    Properties, units, tenants and leases are available here. Everything else opens in the classic back office (marked with <ExternalLink className="inline size-3.5" aria-label="an external-link icon" /> in the menu) until it moves over.
                </p>
                <a href={filamentUrl} className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400">
                    Open the classic back office <ArrowRight className="size-4" aria-hidden />
                </a>
            </Card>
        </>
    );
}

function PortfolioSummary() {
    const { data, isPending } = useProperties({ status: 'active', page: 1 });
    const properties = data?.data ?? [];
    const units = properties.reduce((total, property) => total + (property.unitsCount ?? 0), 0);
    const pageIsComplete = data ? data.meta.total <= data.meta.per_page : false;
    const occupancy = units > 0 ? Math.round(properties.reduce((total, property) => total + ((property.occupancyRate ?? 0) * (property.unitsCount ?? 0)) / 100, 0) / units * 100) : 0;

    return (
        <section aria-label="Portfolio" className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Link to="/properties" className="rounded-xl focus-visible:outline-2 focus-visible:outline-primary-600">
                <StatCard icon={Building2} label="Active properties" value={data?.meta.total ?? 0} loading={isPending} />
            </Link>
            {pageIsComplete && (
                <>
                    <StatCard icon={DoorOpen} label="Units" value={units} loading={isPending} />
                    <StatCard icon={Percent} label="Occupancy" value={`${occupancy}%`} loading={isPending} />
                </>
            )}
        </section>
    );
}

/** Leases ending within 60 days and drafts waiting to be activated. */
function LeasesNeedingAttention() {
    const { data, isPending } = useLeases({ status: 'expiring', per_page: 5 });

    if (isPending) {
        return <Skeleton className="mt-6 h-48" />;
    }

    if (!data || (data.counts.expiring === 0 && data.counts.draft === 0)) {
        return null;
    }

    return (
        <Card className="mt-6">
            <CardHeader
                title="Leases needing attention"
                description={[
                    data.counts.expiring > 0 && `${data.counts.expiring} ending within 60 days`,
                    data.counts.draft > 0 && `${data.counts.draft} ${data.counts.draft === 1 ? 'draft' : 'drafts'} waiting to be activated`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    data.counts.draft > 0 && (
                        <Link to="/leases?status=draft" className="text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400">
                            View drafts
                        </Link>
                    )
                }
            />
            {data.data.length > 0 && (
                <ul role="list" className="divide-y divide-gray-950/5 dark:divide-white/10">
                    {data.data.map((lease) => (
                        <li key={lease.id}>
                            <Link to={`/leases/${lease.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 sm:px-6 dark:hover:bg-white/[0.03]">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-gray-950 dark:text-white">{lease.tenant?.name}</p>
                                    <p className="truncate text-sm text-gray-500 dark:text-gray-400">{lease.unit?.fullCode}</p>
                                </div>
                                <LeaseEndDate lease={lease} className="text-sm" />
                                <ChevronRight className="size-5 shrink-0 text-gray-400" aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {data.counts.expiring > data.data.length && (
                <div className="border-t border-gray-950/5 px-4 py-3 sm:px-6 dark:border-white/10">
                    <Link to="/leases?status=expiring" className="text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400">
                        All {data.counts.expiring} expiring leases
                    </Link>
                </div>
            )}
        </Card>
    );
}
