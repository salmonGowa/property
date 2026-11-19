import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Card({ className, children }: { className?: string; children: ReactNode }) {
    return <section className={cn('rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10', className)}>{children}</section>;
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="flex flex-col gap-3 border-b border-gray-950/5 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-white/10">
            <div className="min-w-0">
                <h2 className="text-base font-semibold text-gray-950 dark:text-white">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export function CardBody({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('px-4 py-5 sm:px-6', className)}>{children}</div>;
}
