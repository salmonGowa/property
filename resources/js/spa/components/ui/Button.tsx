import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import type { ButtonHTMLAttributes } from 'react';
import { Link, type LinkProps } from 'react-router';
import { cn } from '@/lib/cn';

export const buttonVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-2 rounded-lg text-sm font-semibold whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-primary-600 text-white shadow-sm hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400',
                secondary:
                    'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:hover:bg-white/10',
                danger: 'bg-red-600 text-white shadow-sm hover:bg-red-500 focus-visible:outline-red-600',
                ghost: 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
            },
            size: {
                sm: 'h-8 px-2.5',
                md: 'h-10 px-3.5',
                icon: 'size-10',
                'icon-sm': 'size-8',
            },
        },
        defaultVariants: { variant: 'primary', size: 'md' },
    },
);

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants> & { loading?: boolean };

export function Button({ className, variant, size, loading = false, disabled, children, type = 'button', ...props }: ButtonProps) {
    return (
        <button type={type} className={cn(buttonVariants({ variant, size }), className)} disabled={disabled || loading} {...props}>
            {loading && <Loader2 className="animate-spin" aria-hidden />}
            {children}
        </button>
    );
}

export function ButtonLink({ className, variant, size, ...props }: LinkProps & VariantProps<typeof buttonVariants>) {
    return <Link className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
