import { Building, ExternalLink } from 'lucide-react';
import { NavLink } from 'react-router';
import { useUser } from '@/auth/auth';
import { cn } from '@/lib/cn';
import { appName, filamentUrl } from '@/lib/config';
import { navigation, type NavItem } from './navigation';

const itemClasses = 'group flex items-center gap-x-3 rounded-lg px-2.5 py-2 text-sm/6 font-medium transition-colors';
const idleClasses = 'text-gray-700 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white';

function SidebarLink({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
    const Icon = item.icon;

    if (item.to) {
        return (
            <NavLink
                to={item.to}
                end={item.to === '/'}
                onClick={onNavigate}
                className={({ isActive }) =>
                    cn(itemClasses, isActive ? 'bg-primary-50 text-primary-700 dark:bg-white/5 dark:text-primary-400' : idleClasses)
                }
            >
                <Icon className="size-5 shrink-0 opacity-80" aria-hidden />
                <span className="truncate">{item.label}</span>
            </NavLink>
        );
    }

    return (
        <a href={`${filamentUrl}/${item.classic}`} className={cn(itemClasses, idleClasses)} title="Opens in the classic back office">
            <Icon className="size-5 shrink-0 opacity-60" aria-hidden />
            <span className="truncate">{item.label}</span>
            <ExternalLink className="ml-auto size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-60" aria-label="(classic back office)" />
        </a>
    );
}

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
    const user = useUser();

    const groups = navigation
        .map((group) => ({ ...group, items: group.items.filter((item) => !item.permission || user.permissions[item.permission]?.viewAny) }))
        .filter((group) => group.items.length > 0);

    return (
        <div className="flex h-full flex-col gap-y-5 overflow-y-auto border-r border-gray-950/5 bg-white px-4 pb-4 dark:border-white/10 dark:bg-gray-900">
            <div className="flex h-16 shrink-0 items-center gap-2.5 px-1">
                <span className="flex size-8 items-center justify-center rounded-lg bg-primary-600 text-white">
                    <Building className="size-5" aria-hidden />
                </span>
                <span className="truncate text-base font-semibold text-gray-950 dark:text-white">{appName}</span>
            </div>
            <nav aria-label="Main" className="flex flex-1 flex-col gap-y-6">
                {groups.map((group) => (
                    <div key={group.label ?? 'top'}>
                        {group.label && <h3 className="mb-1 px-2.5 text-xs font-semibold tracking-wide text-gray-400 uppercase dark:text-gray-500">{group.label}</h3>}
                        <ul role="list" className="flex flex-col gap-y-0.5">
                            {group.items.map((item) => (
                                <li key={item.label}>
                                    <SidebarLink item={item} onNavigate={onNavigate} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </nav>
        </div>
    );
}
