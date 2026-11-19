import { Banknote, ExternalLink, Info, Pencil, Play, RefreshCw, Scale, Trash2, XCircle } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Detail } from '@/components/ui/DescriptionList';
import { ConfirmDialog } from '@/components/ui/Dialog';
import { ErrorState, Skeleton } from '@/components/ui/Feedback';
import { Menu } from '@/components/ui/Menu';
import { PageHeader } from '@/components/ui/PageHeader';
import { ApiError, errorMessage } from '@/lib/api';
import { cn } from '@/lib/cn';
import { filamentUrl } from '@/lib/config';
import { todayIso } from '@/lib/dates';
import { formatDate, formatMoney } from '@/lib/format';
import type { Lease } from '@/types/api';
import { useDeleteLease, useLease } from './api';
import { LeaseActionDialogs, type LeaseAction } from './LeaseActionDialogs';
import { LeaseEndDate } from './LeaseEndDate';

function ordinal(day: number): string {
    const suffix = day % 100 >= 11 && day % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[day % 10] ?? 'th';

    return `${day}${suffix}`;
}

function Notice({ tone = 'info', children }: { tone?: 'info' | 'warning' | 'danger'; children: ReactNode }) {
    return (
        <div
            className={cn(
                'flex gap-3 rounded-xl px-4 py-3 text-sm ring-1 ring-inset sm:px-5',
                tone === 'info' && 'bg-sky-50 text-sky-900 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-200 dark:ring-sky-400/20',
                tone === 'warning' && 'bg-amber-50 text-amber-900 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20',
                tone === 'danger' && 'bg-red-50 text-red-900 ring-red-600/20 dark:bg-red-400/10 dark:text-red-200 dark:ring-red-400/20',
            )}
        >
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div className="flex flex-col gap-1">{children}</div>
        </div>
    );
}

export function LeasePage() {
    const id = Number(useParams().leaseId);
    const navigate = useNavigate();
    const { data: lease, isPending, error, refetch } = useLease(id);
    const deleteLease = useDeleteLease();
    const [action, setAction] = useState<LeaseAction | null>(null);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    if (isPending) {
        return (
            <div className="flex flex-col gap-6">
                <Skeleton className="h-9 w-64" />
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {Array.from({ length: 3 }, (_, index) => (
                        <Skeleton key={index} className="h-56" />
                    ))}
                </div>
            </div>
        );
    }

    if (error) {
        const notFound = error instanceof ApiError && error.status === 404;

        return <ErrorState message={notFound ? 'This lease does not exist or was discarded.' : errorMessage(error)} onRetry={notFound ? undefined : () => refetch()} />;
    }

    const details = lease.details;
    const can = details?.can;
    const status = lease.status.value;

    return (
        <>
            <PageHeader
                back={{ to: '/leases', label: 'Leases' }}
                title={lease.reference}
                meta={
                    <>
                        <Badge color={lease.status.color}>{lease.status.label}</Badge>
                        {lease.endsSoon && <Badge color="warning">Ends soon</Badge>}
                        <span className="text-sm text-gray-500 dark:text-gray-400">
                            {lease.tenant?.name} · {lease.unit?.fullCode}
                        </span>
                    </>
                }
                actions={
                    <>
                        {can?.activate && (
                            <Button onClick={() => setAction('activate')} className="flex-1 bg-green-600 hover:bg-green-500 sm:flex-none dark:bg-green-600 dark:hover:bg-green-500">
                                <Play /> Activate
                            </Button>
                        )}
                        {can?.renew && (
                            <Button onClick={() => setAction('renew')} className="flex-1 sm:flex-none">
                                <RefreshCw /> Renew
                            </Button>
                        )}
                        {can?.settleDeposit && (
                            <Button onClick={() => setAction('settleDeposit')} className="flex-1 sm:flex-none">
                                <Banknote /> Settle deposit
                            </Button>
                        )}
                        {lease.can.update && (
                            <ButtonLink to={`/leases/${lease.id}/edit`} variant="secondary" className="flex-1 sm:flex-none">
                                <Pencil /> Edit
                            </ButtonLink>
                        )}
                        <Menu
                            items={[
                                { label: 'Change penalty rule', icon: <Scale />, hidden: !can?.changePenaltyRule, onSelect: () => setAction('penaltyRule') },
                                { label: 'Invoices', icon: <ExternalLink />, hidden: status === 'draft', onSelect: () => window.location.assign(`${filamentUrl}/leases/${lease.id}`) },
                                { label: 'Terminate lease', icon: <XCircle />, danger: true, hidden: !can?.terminate, onSelect: () => setAction('terminate') },
                                { label: 'Discard draft', icon: <Trash2 />, danger: true, hidden: !lease.can.delete, onSelect: () => setConfirmingDelete(true) },
                            ]}
                        />
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                <LeaseNotices lease={lease} />

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <Card>
                        <CardHeader title="Parties" />
                        <CardBody>
                            <dl className="flex flex-col gap-5">
                                <Detail label="Tenant">
                                    {lease.tenant && (
                                        <Link to={`/tenants/${lease.tenant.id}`} className="font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                            {lease.tenant.name}
                                        </Link>
                                    )}
                                </Detail>
                                <Detail label="Phone">{lease.tenant && <a href={`tel:${lease.tenant.phone}`}>{lease.tenant.phone}</a>}</Detail>
                                <Detail label="Unit">
                                    {lease.unit?.fullCode} · {lease.unit?.type.label}
                                </Detail>
                                <Detail label="Property">
                                    {lease.unit?.property && (
                                        <Link to={`/properties/${lease.unit.property.id}`} className="text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                            {lease.unit.property.name}
                                        </Link>
                                    )}
                                </Detail>
                            </dl>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Term" />
                        <CardBody>
                            <dl className="flex flex-col gap-5">
                                <Detail label="Starts">{formatDate(lease.startDate)}</Detail>
                                <Detail
                                    label="Ends"
                                    hint={
                                        status === 'active' && lease.endDate
                                            ? lease.endDate < todayIso()
                                                ? 'Past the end date: renew or terminate'
                                                : lease.endDate === todayIso()
                                                  ? 'Ends today'
                                                  : `${lease.daysRemaining} ${lease.daysRemaining === 1 ? 'day' : 'days'} remaining`
                                            : undefined
                                    }
                                >
                                    <LeaseEndDate lease={lease} />
                                </Detail>
                                <Detail label="Billed">
                                    {lease.billingFrequency.label}, due on the {ordinal(lease.dueDay)}
                                </Detail>
                                {lease.activatedAt && <Detail label="Activated">{formatDate(lease.activatedAt)}</Detail>}
                                {lease.terminatedOn && (
                                    <Detail label="Terminated" hint={lease.terminationReason}>
                                        {formatDate(lease.terminatedOn)}
                                    </Detail>
                                )}
                            </dl>
                        </CardBody>
                    </Card>

                    <Card className="md:col-span-2 lg:col-span-1">
                        <CardHeader title="Charges" />
                        <CardBody>
                            <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-1">
                                <Detail label="Monthly rent" hint={details?.currentRent ? `${formatMoney(details.currentRent)} today after escalation` : undefined}>
                                    <span className="font-semibold tabular-nums">{formatMoney(lease.rentAmount)}</span>
                                </Detail>
                                <Detail label="Service charge">{formatMoney(lease.serviceCharge)}</Detail>
                                <Detail label="VAT">{lease.chargeVat ? 'Charged on rent and service charge' : 'Not charged'}</Detail>
                                <Detail label="Escalation">{Number(lease.escalationRate) > 0 ? `${Number(lease.escalationRate)}% every ${lease.escalationIntervalMonths} months` : 'None'}</Detail>
                            </dl>
                        </CardBody>
                    </Card>
                </div>

                <Card>
                    <CardHeader title="Late payment & deposit" />
                    <CardBody>
                        <dl className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                            <Detail label="Penalty rule in force" hint={details?.penalty.source}>
                                {details?.penalty.name ?? 'None'}
                            </Detail>
                            <Detail label="Terms">{details?.penalty.summary}</Detail>
                            <Detail label="Deposit held" hint={Number(lease.depositAmount) > 0 ? `Agreed: ${formatMoney(lease.depositAmount)}` : undefined}>
                                <span className="tabular-nums">{status === 'draft' ? 'Invoiced on activation' : formatMoney(details?.depositHeld)}</span>
                            </Detail>
                        </dl>
                    </CardBody>
                </Card>

                {(lease.notes || lease.createdBy) && (
                    <Card>
                        <CardHeader title="Notes" />
                        <CardBody>
                            <dl className="flex flex-col gap-5">
                                {lease.notes && <Detail label="Notes">{lease.notes}</Detail>}
                                {lease.createdBy && <Detail label="Drafted by">{lease.createdBy}</Detail>}
                            </dl>
                        </CardBody>
                    </Card>
                )}
            </div>

            <LeaseActionDialogs lease={lease} action={action} onClose={() => setAction(null)} />

            <ConfirmDialog
                open={confirmingDelete}
                title={`Discard draft ${lease.reference}?`}
                message="The draft is removed permanently. Nothing was billed, so there is nothing to reverse."
                confirmLabel="Discard draft"
                loading={deleteLease.isPending}
                onClose={() => setConfirmingDelete(false)}
                onConfirm={() =>
                    deleteLease.mutate(lease.id, {
                        onSuccess: () => {
                            toast.success(`Draft ${lease.reference} discarded.`);
                            navigate('/leases?status=draft', { replace: true });
                        },
                        onError: (deleteError) => toast.error(errorMessage(deleteError)),
                    })
                }
            />
        </>
    );
}

/** Explains where the lease is in its life and what usually happens next. */
function LeaseNotices({ lease }: { lease: Lease }) {
    const notices: ReactNode[] = [];
    const status = lease.status.value;

    if (status === 'draft') {
        notices.push(
            <Notice key="draft">
                <p className="font-medium">This lease is a draft.</p>
                <p>
                    Activating it marks the unit occupied, invoices the deposit and starts billing rent.
                    {lease.renewedFrom && <> It also ends {lease.renewedFrom.reference} the day before this one starts.</>}
                </p>
            </Notice>,
        );
    }

    if (lease.renewedFrom) {
        notices.push(
            <Notice key="renewed-from">
                <p>
                    Renewal of{' '}
                    <Link to={`/leases/${lease.renewedFrom.id}`} className="font-semibold underline">
                        {lease.renewedFrom.reference}
                    </Link>
                    ; the deposit carries over.
                </p>
            </Notice>,
        );
    }

    if (lease.renewal) {
        notices.push(
            <Notice key="renewal">
                <p>
                    Renewed as{' '}
                    <Link to={`/leases/${lease.renewal.id}`} className="font-semibold underline">
                        {lease.renewal.reference}
                    </Link>{' '}
                    ({lease.renewal.status.label.toLowerCase()}).
                </p>
            </Notice>,
        );
    }

    if (status === 'active' && lease.endDate && lease.endDate < todayIso() && !lease.renewal) {
        notices.push(
            <Notice key="overdue" tone="danger">
                <p>The end date has passed. Renew the lease or terminate it.</p>
            </Notice>,
        );
    } else if (lease.endsSoon && !lease.renewal) {
        notices.push(
            <Notice key="expiring" tone="warning">
                <p>Ends on {formatDate(lease.endDate)}. Renew it or agree a move-out date with the tenant.</p>
            </Notice>,
        );
    }

    if (status === 'terminated' && lease.details?.can.settleDeposit) {
        notices.push(
            <Notice key="settle" tone="warning">
                <p>{formatMoney(lease.details.depositHeld)} deposit is still held. Settle it to deduct any charges and refund the rest.</p>
            </Notice>,
        );
    }

    return notices.length > 0 ? <div className="flex flex-col gap-3">{notices}</div> : null;
}
