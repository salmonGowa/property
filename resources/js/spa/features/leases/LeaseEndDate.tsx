import { AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/cn';
import { formatDate } from '@/lib/format';
import type { Lease } from '@/types/api';

/** End date, or "Periodic"; active leases ending within 60 days are flagged in amber. */
export function LeaseEndDate({ lease, className }: { lease: Pick<Lease, 'endDate' | 'endsSoon'>; className?: string }) {
    if (!lease.endDate) {
        return <span className={cn('text-gray-500 dark:text-gray-400', className)}>Periodic</span>;
    }

    return (
        <span className={cn('inline-flex items-center gap-1', lease.endsSoon && 'font-medium text-amber-600 dark:text-amber-400', className)}>
            {lease.endsSoon && <AlertTriangle className="size-3.5" aria-label="Ending soon" />}
            {formatDate(lease.endDate)}
        </span>
    );
}
