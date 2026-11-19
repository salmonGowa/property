import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

const controlClasses =
    'block w-full rounded-lg border-0 bg-white px-3 py-2 text-base text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 aria-invalid:ring-red-500 sm:text-sm/6 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:placeholder:text-gray-500 dark:focus:ring-primary-500 dark:disabled:bg-white/[0.02]';

interface FieldProps {
    label: string;
    error?: string;
    hint?: ReactNode;
    required?: boolean;
    className?: string;
    children: (props: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string }) => ReactNode;
}

/** Label, control, hint and error message wired together for screen readers. */
export function Field({ label, error, hint, required, className, children }: FieldProps) {
    const id = useId();
    const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <label htmlFor={id} className="text-sm/6 font-medium text-gray-900 dark:text-gray-100">
                {label}
                {required && <span className="text-red-600"> *</span>}
            </label>
            {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy })}
            {error ? (
                <p id={`${id}-error`} className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            ) : (
                hint && (
                    <p id={`${id}-hint`} className="text-sm text-gray-500 dark:text-gray-400">
                        {hint}
                    </p>
                )
            )}
        </div>
    );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { prefix?: string; suffix?: string };

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ className, prefix, suffix, ...props }, ref) {
    if (!prefix && !suffix) {
        return <input ref={ref} className={cn(controlClasses, className)} {...props} />;
    }

    return (
        <div className="relative">
            {prefix && <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-500">{prefix}</span>}
            <input ref={ref} className={cn(controlClasses, prefix && 'pl-12', suffix && 'pr-14', className)} {...props} />
            {suffix && <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-gray-500">{suffix}</span>}
        </div>
    );
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({ className, ...props }, ref) {
    return <textarea ref={ref} rows={3} className={cn(controlClasses, className)} {...props} />;
});

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select({ className, ...props }, ref) {
    return <select ref={ref} className={cn(controlClasses, 'pr-8', className)} {...props} />;
});

type SwitchProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & { label: string; description?: string };

export const Switch = forwardRef<HTMLInputElement, SwitchProps>(function Switch({ label, description, className, id, ...props }, ref) {
    const generatedId = useId();
    const inputId = id ?? generatedId;

    return (
        <div className={cn('flex items-start justify-between gap-4', className)}>
            <label htmlFor={inputId} className="flex flex-col">
                <span className="text-sm/6 font-medium text-gray-900 dark:text-gray-100">{label}</span>
                {description && <span className="text-sm text-gray-500 dark:text-gray-400">{description}</span>}
            </label>
            <span className="relative inline-flex h-6 w-11 shrink-0 rounded-full bg-gray-200 p-0.5 ring-1 ring-gray-900/5 ring-inset transition-colors has-checked:bg-primary-600 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary-600 dark:bg-white/10 dark:has-checked:bg-primary-500">
                <span className="size-5 rounded-full bg-white shadow-xs ring-1 ring-gray-900/5 transition-transform duration-200 ease-in-out [:has(:checked)>&]:translate-x-5" />
                <input ref={ref} id={inputId} type="checkbox" role="switch" className="absolute inset-0 appearance-none focus:outline-none" {...props} />
            </span>
        </div>
    );
});
