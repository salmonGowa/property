import { ChevronDown, LogOut, Monitor, Moon, Sun } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import { useLogout, useUser } from '@/auth/auth';
import { cn } from '@/lib/cn';
import { initials } from '@/lib/format';
import { useTheme, type Theme } from '@/lib/theme';

const themes: { value: Theme; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

export function UserMenu() {
    const user = useUser();
    const logout = useLogout();
    const navigate = useNavigate();
    const [theme, setTheme] = useTheme();
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);

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

    return (
        <div ref={container} className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-haspopup="menu"
                className="flex items-center gap-x-2 rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-white/5"
            >
                <span className="flex size-8 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">
                    {initials(user.name)}
                </span>
                <span className="hidden text-left lg:block">
                    <span className="block text-sm/5 font-semibold text-gray-900 dark:text-white">{user.name}</span>
                    <span className="block text-xs text-gray-500 dark:text-gray-400">{user.role?.label}</span>
                </span>
                <ChevronDown className="hidden size-4 text-gray-400 lg:block" aria-hidden />
            </button>

            {open && (
                <div role="menu" className="absolute right-0 z-50 mt-2 w-64 origin-top-right rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
                    <div className="px-2.5 py-2">
                        <p className="truncate text-sm font-semibold text-gray-900 dark:text-white">{user.name}</p>
                        <p className="truncate text-xs text-gray-500 dark:text-gray-400">{user.email}</p>
                    </div>
                    <div className="my-1 h-px bg-gray-950/5 dark:bg-white/10" />
                    <div className="px-2.5 py-1.5">
                        <p className="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Theme</p>
                        <div className="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                            {themes.map(({ value, label, icon: Icon }) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setTheme(value)}
                                    aria-pressed={theme === value}
                                    className={cn(
                                        'flex flex-col items-center gap-0.5 rounded-md py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300',
                                        theme === value && 'bg-white text-gray-950 shadow-xs dark:bg-gray-700 dark:text-white',
                                    )}
                                >
                                    <Icon className="size-4" aria-hidden />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="my-1 h-px bg-gray-950/5 dark:bg-white/10" />
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })}
                        className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5"
                    >
                        <LogOut className="size-4" aria-hidden />
                        Sign out
                    </button>
                </div>
            )}
        </div>
    );
}
