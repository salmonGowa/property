import { Building2 } from 'lucide-react';
import { cn } from '@/lib/cn';

export function PropertyThumb({ url, className }: { url: string | null; className?: string }) {
    return (
        <div className={cn('flex shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5', className)}>
            {url ? <img src={url} alt="" className="size-full object-cover" loading="lazy" /> : <Building2 className="size-1/2 text-gray-400" aria-hidden />}
        </div>
    );
}
