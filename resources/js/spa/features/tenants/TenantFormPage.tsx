import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { RequirePermission } from '@/auth/auth';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { ErrorState, FullPageSpinner } from '@/components/ui/Feedback';
import { Field, Input, Switch, Textarea } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/PageHeader';
import { useServerErrors } from '@/hooks/useServerErrors';
import { errorMessage } from '@/lib/api';
import type { Tenant } from '@/types/api';
import { useSaveTenant, useTenant } from './api';
import { TenantIdentityFields, tenantFields, tenantSchema, toTenantFormValues, toTenantPayload, type TenantFormValues } from './TenantFields';

export function CreateTenantPage() {
    return (
        <RequirePermission permission="tenants" ability="create">
            <TenantForm />
        </RequirePermission>
    );
}

export function EditTenantPage() {
    const id = Number(useParams().tenantId);
    const { data: tenant, isPending, error, refetch } = useTenant(id);

    if (isPending) {
        return <FullPageSpinner />;
    }

    if (error) {
        return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;
    }

    if (!tenant.can.update) {
        return <ErrorState message="You don't have permission to edit this tenant." />;
    }

    return <TenantForm tenant={tenant} />;
}

function TenantForm({ tenant }: { tenant?: Tenant }) {
    const navigate = useNavigate();
    const save = useSaveTenant(tenant?.id);
    const {
        register,
        handleSubmit,
        setError,
        control,
        formState: { errors, isDirty },
    } = useForm<TenantFormValues>({ resolver: zodResolver(tenantSchema), defaultValues: toTenantFormValues(tenant) });
    const onServerError = useServerErrors(setError, tenantFields);
    const backTo = tenant ? `/tenants/${tenant.id}` : '/tenants';

    const onSubmit = handleSubmit((values) =>
        save.mutate(toTenantPayload(values, tenant), {
            onSuccess: (saved) => {
                toast.success(tenant ? 'Tenant updated.' : `Tenant registered with account ${saved.accountNumber}.`);
                navigate(`/tenants/${saved.id}`, { replace: !tenant });
            },
            onError: onServerError,
        }),
    );

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader title={tenant ? `Edit ${tenant.name}` : 'New tenant'} back={{ to: backTo, label: tenant ? tenant.name : 'Tenants' }} />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="flex flex-col gap-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="Identity" />
                        <CardBody>
                            <TenantIdentityFields register={register} control={control} errors={errors} maskedIdNumber={tenant?.idNumberMasked ? tenant.idNumber : null} />
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Other contact details" />
                        <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <Field label="Alternate phone" error={errors.alternate_phone?.message}>
                                {(props) => <Input {...props} {...register('alternate_phone')} type="tel" inputMode="tel" />}
                            </Field>
                            <Field label="Contact person phone" error={errors.contact_person_phone?.message}>
                                {(props) => <Input {...props} {...register('contact_person_phone')} type="tel" inputMode="tel" />}
                            </Field>
                            <Field label="Postal address" error={errors.postal_address?.message} className="sm:col-span-2">
                                {(props) => <Input {...props} {...register('postal_address')} placeholder="P.O. Box 12345-00100, Nairobi" />}
                            </Field>
                            <Field label="Emergency contact" error={errors.emergency_contact_name?.message}>
                                {(props) => <Input {...props} {...register('emergency_contact_name')} placeholder="Name" />}
                            </Field>
                            <Field label="Emergency contact phone" error={errors.emergency_contact_phone?.message}>
                                {(props) => <Input {...props} {...register('emergency_contact_phone')} type="tel" inputMode="tel" />}
                            </Field>
                        </CardBody>
                    </Card>
                </div>

                <div className="flex flex-col gap-6">
                    <Card>
                        <CardHeader title="Account" />
                        <CardBody className="flex flex-col gap-5">
                            <div>
                                <p className="text-sm/6 font-medium text-gray-900 dark:text-gray-100">Account number</p>
                                <p className="mt-1 font-mono text-sm text-gray-700 dark:text-gray-300">{tenant?.accountNumber ?? 'Assigned when saved'}</p>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Tenants quote this as the M-Pesa Paybill account number.</p>
                            </div>
                            <Switch {...register('is_active')} label="Active" description="Inactive tenants can't be given new leases." />
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Notes" />
                        <CardBody>
                            <Field label="Notes" error={errors.notes?.message}>
                                {(props) => <Textarea {...props} {...register('notes')} rows={5} />}
                            </Field>
                        </CardBody>
                    </Card>
                </div>
            </div>

            <div className="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-col-reverse gap-2 border-t border-gray-950/5 bg-gray-50/90 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-sm sm:static sm:mx-0 sm:flex-row sm:justify-end sm:border-0 sm:bg-transparent sm:p-0 dark:border-white/10 dark:bg-gray-950/90 sm:dark:bg-transparent">
                <ButtonLink to={backTo} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={save.isPending} disabled={tenant && !isDirty}>
                    {tenant ? 'Save changes' : 'Register tenant'}
                </Button>
            </div>
        </form>
    );
}
