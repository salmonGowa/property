import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/format';

/** Positive balances are owed (red); negative ones mean the tenant is in credit (green, marked CR). */
export function Balance({ value, className }: { value: string | null | undefined; className?: string }) {
    const amount = Number(value ?? 0);

    return (
        <span className={cn('tabular-nums', amount > 0 && 'font-medium text-red-600 dark:text-red-400', amount < 0 && 'text-green-700 dark:text-green-400', className)}>
            {formatMoney(Math.abs(amount))}
            {amount < 0 && ' CR'}
        </span>
    );
}
