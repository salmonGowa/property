import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { BadgeColor } from '@/types/api';

const colors: Record<BadgeColor, string> = {
    primary: 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30',
    success: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/20',
    info: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-400 dark:ring-sky-400/30',
    warning: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/20',
    danger: 'bg-red-50 text-red-700 ring-red-600/10 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/20',
    gray: 'bg-gray-50 text-gray-700 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20',
};

export function Badge({ color = 'gray', className, children }: { color?: BadgeColor; className?: string; children: ReactNode }) {
    return (
        <span className={cn('inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', colors[color], className)}>
            {children}
        </span>
    );
}

export function occupancyColor(rate: number): BadgeColor {
    if (rate >= 90) {
        return 'success';
    }

    return rate >= 70 ? 'warning' : 'danger';
}
