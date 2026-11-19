import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link } from 'react-router';

interface PageHeaderProps {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    back?: { to: string; label: string };
    meta?: ReactNode;
}

export function PageHeader({ title, description, actions, back, meta }: PageHeaderProps) {
    return (
        <header className="mb-6 flex flex-col gap-4 sm:mb-8">
            {back && (
                <Link to={back.to} className="inline-flex w-fit items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                    <ChevronLeft className="size-4" aria-hidden />
                    {back.label}
                </Link>
            )}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold tracking-tight break-words text-gray-950 sm:text-3xl dark:text-white">{title}</h1>
                    {meta && <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div>}
                    {description && <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>}
            </div>
        </header>
    );
}
