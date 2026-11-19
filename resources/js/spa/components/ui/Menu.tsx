import { MoreHorizontal } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Button } from './Button';

export interface MenuItem {
    label: string;
    icon?: ReactNode;
    onSelect: () => void;
    danger?: boolean;
    hidden?: boolean;
}

/** "More actions" dropdown for secondary or destructive record actions. Renders nothing when every item is hidden. */
export function Menu({ items, label = 'More actions', className }: { items: MenuItem[]; label?: string; className?: string }) {
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);
    const visible = items.filter((item) => !item.hidden);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    if (visible.length === 0) {
        return null;
    }

    return (
        <div ref={container} className={cn('relative', className)}>
            <Button variant="secondary" size="icon" onClick={() => setOpen((value) => !value)} aria-haspopup="menu" aria-expanded={open} aria-label={label}>
                <MoreHorizontal />
            </Button>
            {open && (
                <div role="menu" className="absolute right-0 z-40 mt-2 w-56 origin-top-right rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
                    {visible.map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                setOpen(false);
                                item.onSelect();
                            }}
                            className={cn(
                                'flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-white/5 [&_svg]:size-4',
                                item.danger ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200',
                            )}
                        >
                            {item.icon}
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
