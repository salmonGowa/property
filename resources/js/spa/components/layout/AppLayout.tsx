import { Menu, X } from 'lucide-react';
import { Suspense, useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router';
import { Button } from '@/components/ui/Button';
import { FullPageSpinner } from '@/components/ui/Feedback';
import { cn } from '@/lib/cn';
import { Sidebar } from './Sidebar';
import { UserMenu } from './UserMenu';

/**
 * Below `lg` the sidebar is an off-canvas drawer opened from the top bar; from `lg` up it is fixed.
 */
export function AppLayout() {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const location = useLocation();

    useEffect(() => setDrawerOpen(false), [location.pathname]);

    useEffect(() => {
        if (!drawerOpen) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => event.key === 'Escape' && setDrawerOpen(false);
        document.addEventListener('keydown', onKeyDown);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = '';
        };
    }, [drawerOpen]);

    return (
        <div className="min-h-full">
            <div className={cn('fixed inset-0 z-50 lg:hidden', !drawerOpen && 'pointer-events-none')} aria-hidden={!drawerOpen}>
                <div className={cn('absolute inset-0 bg-gray-950/50 transition-opacity duration-300', drawerOpen ? 'opacity-100' : 'opacity-0')} onClick={() => setDrawerOpen(false)} />
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Navigation"
                    inert={!drawerOpen}
                    className={cn('relative flex h-full w-72 max-w-[85vw] transition-transform duration-300 ease-out', drawerOpen ? 'translate-x-0' : '-translate-x-full')}
                >
                    <div className="w-full">
                        <Sidebar onNavigate={() => setDrawerOpen(false)} />
                    </div>
                    <Button variant="ghost" size="icon" onClick={() => setDrawerOpen(false)} aria-label="Close navigation" className={cn('absolute top-3 -right-12 text-white transition-opacity hover:bg-white/10 dark:text-white', drawerOpen ? 'opacity-100' : 'invisible opacity-0')}>
                        <X />
                    </Button>
                </div>
            </div>

            <aside className="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col">
                <Sidebar />
            </aside>

            <div className="lg:pl-64">
                <header className="sticky top-0 z-30 flex h-16 items-center gap-x-3 border-b border-gray-950/5 bg-white/85 px-4 backdrop-blur-sm sm:px-6 lg:px-8 dark:border-white/10 dark:bg-gray-900/85">
                    <Button variant="ghost" size="icon" className="-ml-2 lg:hidden" onClick={() => setDrawerOpen(true)} aria-label="Open navigation" aria-expanded={drawerOpen}>
                        <Menu className="size-5!" />
                    </Button>
                    <div className="flex-1" />
                    <UserMenu />
                </header>

                <main className="mx-auto w-full max-w-7xl px-4 py-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:px-6 sm:py-8 lg:px-8">
                    <Suspense fallback={<FullPageSpinner />}>
                        <Outlet />
                    </Suspense>
                </main>
            </div>
        </div>
    );
}
