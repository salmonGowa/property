import { AlertTriangle, Loader2, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Button } from './Button';

export function Spinner({ className }: { className?: string }) {
    return <Loader2 className={cn('size-5 animate-spin text-gray-400', className)} aria-label="Loading" />;
}

export function FullPageSpinner() {
    return (
        <div className="flex min-h-full items-center justify-center p-8">
            <Spinner className="size-8" />
        </div>
    );
}

export function Skeleton({ className }: { className?: string }) {
    return <div className={cn('animate-pulse rounded-md bg-gray-200 dark:bg-white/10', className)} />;
}

export function EmptyState({ icon: Icon, title, description, action }: { icon: LucideIcon; title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center px-4 py-12 text-center">
            <div className="rounded-full bg-gray-100 p-3 dark:bg-white/5">
                <Icon className="size-6 text-gray-400" aria-hidden />
            </div>
            <h3 className="mt-4 text-sm font-semibold text-gray-900 dark:text-white">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">{description}</p>}
            {action && <div className="mt-6">{action}</div>}
        </div>
    );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
    return (
        <div role="alert" className="flex flex-col items-center px-4 py-12 text-center">
            <AlertTriangle className="size-8 text-amber-500" aria-hidden />
            <p className="mt-3 text-sm text-gray-700 dark:text-gray-300">{message}</p>
            {onRetry && (
                <Button variant="secondary" size="sm" className="mt-4" onClick={onRetry}>
                    Try again
                </Button>
            )}
        </div>
    );
}
