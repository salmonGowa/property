import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { PaginationMeta } from '@/types/api';
import { Button } from './Button';

export function Pagination({ meta, onPageChange }: { meta: PaginationMeta; onPageChange: (page: number) => void }) {
    if (meta.total === 0) {
        return null;
    }

    const { current_page: page, last_page: lastPage } = meta;

    return (
        <nav aria-label="Pagination" className="flex items-center justify-between gap-4 border-t border-gray-950/5 px-4 py-3 sm:px-6 dark:border-white/10">
            <p className="text-sm text-gray-600 dark:text-gray-400">
                <span className="hidden sm:inline">Showing </span>
                <span className="font-medium text-gray-900 dark:text-white">{meta.from}</span>–
                <span className="font-medium text-gray-900 dark:text-white">{meta.to}</span> of{' '}
                <span className="font-medium text-gray-900 dark:text-white">{meta.total}</span>
            </p>
            {lastPage > 1 && (
                <div className="flex items-center gap-2">
                    <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPageChange(page - 1)} aria-label="Previous page">
                        <ChevronLeft />
                        <span className="hidden sm:inline">Previous</span>
                    </Button>
                    <span className="text-sm text-gray-600 tabular-nums dark:text-gray-400">
                        {page} / {lastPage}
                    </span>
                    <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)} aria-label="Next page">
                        <span className="hidden sm:inline">Next</span>
                        <ChevronRight />
                    </Button>
                </div>
            )}
        </nav>
    );
}
