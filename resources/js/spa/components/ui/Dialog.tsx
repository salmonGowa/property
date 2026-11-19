import { X } from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Button } from './Button';

interface DialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg';
    children: ReactNode;
    footer?: ReactNode;
}

const sizes = { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl' };

/**
 * Native <dialog> (focus trapping, Escape and top-layer stacking for free).
 * On phones it docks to the bottom of the screen as a sheet; from `sm` up it is a centred modal.
 */
export function Dialog({ open, onClose, title, description, size = 'md', children, footer }: DialogProps) {
    const ref = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                if (event.target === ref.current) {
                    onClose();
                }
            }}
            aria-labelledby="dialog-title"
            className={cn(
                'm-0 mt-auto max-h-[92dvh] w-full max-w-none overflow-hidden rounded-t-2xl bg-white p-0 text-gray-900 shadow-xl dark:bg-gray-900 dark:text-gray-100 dark:ring-1 dark:ring-white/10',
                'sm:m-auto sm:max-h-[85vh] sm:rounded-2xl',
                sizes[size],
            )}
        >
            {open && (
                <div className="flex max-h-[inherit] flex-col">
                    <div className="flex items-start justify-between gap-4 border-b border-gray-950/5 px-4 py-4 sm:px-6 dark:border-white/10">
                        <div>
                            <h2 id="dialog-title" className="text-base font-semibold">
                                {title}
                            </h2>
                            {description && <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>}
                        </div>
                        <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label="Close">
                            <X />
                        </Button>
                    </div>
                    <div className="overflow-y-auto px-4 py-5 sm:px-6">{children}</div>
                    {footer && (
                        <div className="flex flex-col-reverse gap-2 border-t border-gray-950/5 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end sm:px-6 dark:border-white/10">
                            {footer}
                        </div>
                    )}
                </div>
            )}
        </dialog>
    );
}

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    message: ReactNode;
    confirmLabel?: string;
    loading?: boolean;
    onConfirm: () => void;
    onClose: () => void;
}

export function ConfirmDialog({ open, title, message, confirmLabel = 'Delete', loading, onConfirm, onClose }: ConfirmDialogProps) {
    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={loading}>
                        Cancel
                    </Button>
                    <Button variant="danger" onClick={onConfirm} loading={loading}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <div className="text-sm text-gray-600 dark:text-gray-300">{message}</div>
        </Dialog>
    );
}
