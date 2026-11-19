import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Card } from './Card';

/** A headline figure on a record's screen (balance, occupancy...). */
export function Stat({ label, value, hint, tone, children }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'danger' | 'success'; children?: ReactNode }) {
    return (
        <Card className="px-4 py-4 sm:px-5">
            <dt className="truncate text-sm font-medium text-gray-500 dark:text-gray-400">{label}</dt>
            <dd
                className={cn(
                    'mt-1 flex items-center gap-2 text-xl font-semibold tracking-tight text-gray-950 tabular-nums sm:text-2xl dark:text-white',
                    tone === 'danger' && 'text-red-600 dark:text-red-400',
                    tone === 'success' && 'text-green-700 dark:text-green-400',
                )}
            >
                {value}
                {children}
            </dd>
            {hint && <dd className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{hint}</dd>}
        </Card>
    );
}

/** One label/value pair inside a `<dl>`; empty values show a dash. */
export function Detail({ label, children, wide, hint }: { label: string; children: ReactNode; wide?: boolean; hint?: ReactNode }) {
    return (
        <div className={wide ? 'sm:col-span-2' : undefined}>
            <dt className="text-sm font-medium text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="mt-1 text-sm break-words whitespace-pre-line text-gray-900 dark:text-gray-100">{children === null || children === undefined || children === '' ? '—' : children}</dd>
            {hint && <dd className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{hint}</dd>}
        </div>
    );
}
