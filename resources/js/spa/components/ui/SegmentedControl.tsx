import { cn } from '@/lib/cn';

interface SegmentedControlProps<T extends string> {
    label: string;
    value: T;
    options: readonly { value: T; label: string; count?: number }[];
    onChange: (value: T) => void;
    className?: string;
}

/** A row of mutually exclusive choices (status tabs, list filters); fills the width on phones. */
export function SegmentedControl<T extends string>({ label, value, options, onChange, className }: SegmentedControlProps<T>) {
    return (
        <div role="radiogroup" aria-label={label} className={cn('flex rounded-lg bg-gray-100 p-1 dark:bg-white/5', className)}>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap text-gray-600 sm:flex-none dark:text-gray-300',
                        value === option.value && 'bg-white text-gray-950 shadow-xs dark:bg-gray-700 dark:text-white',
                    )}
                >
                    {option.label}
                    {option.count !== undefined && option.count > 0 && <span className="rounded-full bg-gray-200 px-1.5 text-xs tabular-nums dark:bg-white/10">{option.count}</span>}
                </button>
            ))}
        </div>
    );
}
