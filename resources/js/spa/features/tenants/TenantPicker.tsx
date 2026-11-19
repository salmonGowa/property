import { zodResolver } from '@hookform/resolvers/zod';
import { Check, Search, UserPlus, X } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { useCan } from '@/auth/auth';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Spinner } from '@/components/ui/Feedback';
import { Input } from '@/components/ui/Field';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useServerErrors } from '@/hooks/useServerErrors';
import type { Tenant } from '@/types/api';
import { useSaveTenant, useTenants } from './api';
import { TenantIdentityFields, tenantFields, tenantSchema, toTenantFormValues, toTenantPayload, type TenantFormValues } from './TenantFields';

export type PickedTenant = Pick<Tenant, 'id' | 'name' | 'accountNumber' | 'phone'>;

interface TenantPickerProps {
    id?: string;
    value: PickedTenant | null;
    onChange: (tenant: PickedTenant | null) => void;
    invalid?: boolean;
    describedBy?: string;
}

/**
 * Searches active tenants as you type (name, account, phone, KRA PIN) and can register a new one
 * without leaving the lease form.
 */
export function TenantPicker({ id, value, onChange, invalid, describedBy }: TenantPickerProps) {
    const canCreate = useCan('tenants', 'create');
    const [search, setSearch] = useState('');
    const [creating, setCreating] = useState(false);
    const debouncedSearch = useDebouncedValue(search, 250);
    const listId = useId();
    const { data, isFetching } = useTenants({ search: debouncedSearch || undefined, status: 'active', sort: 'name', per_page: 8 }, value === null);

    if (value) {
        return (
            <div className="flex items-center gap-3 rounded-lg bg-primary-50/60 px-3 py-2.5 ring-1 ring-primary-600/20 ring-inset dark:bg-primary-500/10 dark:ring-primary-400/30">
                <Check className="size-4 shrink-0 text-primary-600 dark:text-primary-400" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-gray-950 dark:text-white">{value.name}</p>
                    <p className="truncate text-xs text-gray-500 dark:text-gray-400">
                        <span className="font-mono">{value.accountNumber}</span> · {value.phone}
                    </p>
                </div>
                <Button variant="ghost" size="sm" onClick={() => onChange(null)}>
                    <X /> Change
                </Button>
            </div>
        );
    }

    const results = data?.data ?? [];

    return (
        <div className="flex flex-col gap-2">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" aria-hidden />
                <Input
                    id={id}
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search name, account, phone or KRA PIN"
                    className="pl-9"
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    aria-controls={listId}
                    autoComplete="off"
                />
                {isFetching && <Spinner className="absolute top-1/2 right-3 size-4 -translate-y-1/2" />}
            </div>
            <ul id={listId} role="listbox" aria-label="Tenants" className="max-h-64 divide-y divide-gray-950/5 overflow-y-auto rounded-lg ring-1 ring-gray-950/10 dark:divide-white/10 dark:ring-white/10">
                {results.map((tenant) => (
                    <li key={tenant.id} role="option" aria-selected={false}>
                        <button type="button" onClick={() => onChange(tenant)} className="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:hover:bg-white/5 dark:focus-visible:bg-white/5">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium text-gray-950 dark:text-white">{tenant.name}</span>
                                <span className="block truncate text-xs text-gray-500 dark:text-gray-400">
                                    <span className="font-mono">{tenant.accountNumber}</span> · {tenant.phone}
                                </span>
                            </span>
                            <span className="text-xs text-gray-500 dark:text-gray-400">{tenant.type.label}</span>
                        </button>
                    </li>
                ))}
                {data && results.length === 0 && <li className="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">No active tenant matches “{debouncedSearch}”.</li>}
            </ul>
            {canCreate && (
                <Button variant="secondary" size="sm" onClick={() => setCreating(true)} className="self-start">
                    <UserPlus /> Register a new tenant
                </Button>
            )}

            {/* Portalled out of the lease <form>: forms can't nest in the DOM. */}
            {createPortal(
                <Dialog open={creating} onClose={() => setCreating(false)} title="New tenant" description="The essentials now; add other contact details from the tenant's page later." size="lg">
                    {creating && (
                        <QuickTenantForm
                            initialName={search}
                            onClose={() => setCreating(false)}
                            onCreated={(tenant) => {
                                setCreating(false);
                                onChange(tenant);
                            }}
                        />
                    )}
                </Dialog>,
                document.body,
            )}
        </div>
    );
}

function QuickTenantForm({ initialName, onClose, onCreated }: { initialName: string; onClose: () => void; onCreated: (tenant: Tenant) => void }) {
    const save = useSaveTenant();
    const looksLikeName = initialName.trim() !== '' && !/\d/.test(initialName);
    const {
        register,
        handleSubmit,
        setError,
        control,
        formState: { errors },
    } = useForm<TenantFormValues>({ resolver: zodResolver(tenantSchema), defaultValues: { ...toTenantFormValues(), name: looksLikeName ? initialName.trim() : '' } });
    const onServerError = useServerErrors(setError, tenantFields);

    const submit = handleSubmit((values) =>
        save.mutate(toTenantPayload(values), {
            onSuccess: (tenant) => {
                toast.success(`${tenant.name} registered with account ${tenant.accountNumber}.`);
                onCreated(tenant);
            },
            onError: onServerError,
        }),
    );

    // React events bubble through portals, so keep this submit from reaching the lease form.
    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.stopPropagation();
        void submit(event);
    };

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <TenantIdentityFields register={register} control={control} errors={errors} autoFocus />
            <div className="-mx-4 -mb-5 flex flex-col-reverse gap-2 border-t border-gray-950/5 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-6 sm:flex-row sm:justify-end sm:px-6 dark:border-white/10">
                <Button variant="secondary" onClick={onClose} disabled={save.isPending}>
                    Cancel
                </Button>
                <Button type="submit" loading={save.isPending}>
                    Register tenant
                </Button>
            </div>
        </form>
    );
}
