import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { z } from 'zod';
import { RequirePermission } from '@/auth/auth';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { ErrorState, FullPageSpinner } from '@/components/ui/Feedback';
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/PageHeader';
import { useLookups } from '@/hooks/useLookups';
import { useServerErrors } from '@/hooks/useServerErrors';
import { errorMessage } from '@/lib/api';
import type { Lookups, Property } from '@/types/api';
import { useProperty, useSaveProperty, type PropertyPayload } from './api';

const decimal = /^\d+(\.\d{1,2})?$/;
const maxYear = new Date().getFullYear() + 5;

const schema = z.object({
    name: z.string().trim().min(1, 'Enter the property name.').max(255),
    code: z.string().trim().min(1, 'Enter a short code, e.g. KIL-01.').max(20, 'Keep the code to 20 characters or fewer.'),
    type: z.string().min(1, 'Choose a property type.'),
    county: z.string().min(1, 'Choose a county.'),
    town: z.string().max(255),
    address: z.string().max(2000),
    lr_number: z.string().max(255),
    total_area_sqft: z.string().trim().refine((value) => value === '' || decimal.test(value), 'Enter the area as a number, e.g. 12500.'),
    year_built: z
        .string()
        .trim()
        .refine((value) => value === '' || (/^\d{4}$/.test(value) && Number(value) >= 1900 && Number(value) <= maxYear), `Enter a year between 1900 and ${maxYear}.`),
    manager_id: z.string(),
    penalty_rule_id: z.string(),
    description: z.string().max(5000),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;
const fields = Object.keys(schema.shape);

function toFormValues(property?: Property): FormValues {
    return {
        name: property?.name ?? '',
        code: property?.code ?? '',
        type: property?.type.value ?? 'residential',
        county: property?.county.value ?? 'nairobi',
        town: property?.town ?? '',
        address: property?.address ?? '',
        lr_number: property?.lrNumber ?? '',
        total_area_sqft: property?.totalAreaSqft ?? '',
        year_built: property?.yearBuilt ? String(property.yearBuilt) : '',
        manager_id: property?.managerId ? String(property.managerId) : '',
        penalty_rule_id: property?.penaltyRuleId ? String(property.penaltyRuleId) : '',
        description: property?.description ?? '',
        is_active: property?.isActive ?? true,
    };
}

function toPayload(values: FormValues): PropertyPayload {
    const optional = (value: string) => (value.trim() === '' ? null : value.trim());

    return {
        name: values.name.trim(),
        code: values.code.trim().toUpperCase(),
        type: values.type,
        county: values.county,
        town: optional(values.town),
        address: optional(values.address),
        lr_number: optional(values.lr_number),
        total_area_sqft: optional(values.total_area_sqft),
        year_built: values.year_built ? Number(values.year_built) : null,
        manager_id: values.manager_id ? Number(values.manager_id) : null,
        penalty_rule_id: values.penalty_rule_id ? Number(values.penalty_rule_id) : null,
        description: optional(values.description),
        is_active: values.is_active,
    };
}

export function CreatePropertyPage() {
    return (
        <RequirePermission permission="properties" ability="create">
            <PropertyForm />
        </RequirePermission>
    );
}

export function EditPropertyPage() {
    const id = Number(useParams().propertyId);
    const { data: property, isPending, error, refetch } = useProperty(id);

    if (isPending) {
        return <FullPageSpinner />;
    }

    if (error) {
        return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;
    }

    if (!property.can.update) {
        return <ErrorState message="You don't have permission to edit this property." />;
    }

    return <PropertyForm property={property} />;
}

/** Selects are uncontrolled, so the form mounts only once their options exist. */
function PropertyForm({ property }: { property?: Property }) {
    const { data: lookups, error, refetch } = useLookups();

    if (error) {
        return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;
    }

    return lookups ? <PropertyFormFields property={property} lookups={lookups} /> : <FullPageSpinner />;
}

function PropertyFormFields({ property, lookups }: { property?: Property; lookups: Lookups }) {
    const navigate = useNavigate();
    const save = useSaveProperty(property?.id);
    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isDirty },
    } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toFormValues(property) });
    const onServerError = useServerErrors(setError, fields);

    const backTo = property ? `/properties/${property.id}` : '/properties';

    const onSubmit = handleSubmit((values) =>
        save.mutate(toPayload(values), {
            onSuccess: (saved) => {
                toast.success(property ? 'Property updated.' : 'Property created.');
                navigate(`/properties/${saved.id}`, { replace: !property });
            },
            onError: onServerError,
        }),
    );

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader title={property ? `Edit ${property.name}` : 'New property'} back={{ to: backTo, label: property ? property.name : 'Properties' }} />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="flex flex-col gap-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="Property details" />
                        <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <Field label="Name" required error={errors.name?.message}>
                                {(props) => <Input {...props} {...register('name')} placeholder="e.g. Kilimani Heights" autoComplete="off" />}
                            </Field>
                            <Field label="Code" required error={errors.code?.message} hint="Short code shown on invoices and unit references.">
                                {(props) => <Input {...props} {...register('code')} placeholder="e.g. KIL-01" autoComplete="off" className="uppercase" />}
                            </Field>
                            <Field label="Type" required error={errors.type?.message}>
                                {(props) => (
                                    <Select {...props} {...register('type')}>
                                        {lookups.propertyTypes.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label="Property manager" error={errors.manager_id?.message}>
                                {(props) => (
                                    <Select {...props} {...register('manager_id')}>
                                        <option value="">Not assigned</option>
                                        {lookups.propertyManagers.map((manager) => (
                                            <option key={manager.id} value={manager.id}>
                                                {manager.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label="Late payment penalty rule" error={errors.penalty_rule_id?.message} hint="Applies to every lease here unless a lease sets its own." className="sm:col-span-2">
                                {(props) => (
                                    <Select {...props} {...register('penalty_rule_id')}>
                                        <option value="">Company default</option>
                                        {lookups.penaltyRules.map((rule) => (
                                            <option key={rule.id} value={rule.id}>
                                                {rule.name}
                                                {rule.is_default ? ' (default)' : ''}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label="Description" error={errors.description?.message} className="sm:col-span-2">
                                {(props) => <Textarea {...props} {...register('description')} />}
                            </Field>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Location" />
                        <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <Field label="County" required error={errors.county?.message}>
                                {(props) => (
                                    <Select {...props} {...register('county')}>
                                        {lookups.counties.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label="Town" error={errors.town?.message}>
                                {(props) => <Input {...props} {...register('town')} placeholder="e.g. Kilimani" />}
                            </Field>
                            <Field label="Address" error={errors.address?.message}>
                                {(props) => <Textarea {...props} rows={2} {...register('address')} placeholder="Street, road or landmark" />}
                            </Field>
                            <Field label="L.R. / title number" error={errors.lr_number?.message}>
                                {(props) => <Input {...props} {...register('lr_number')} placeholder="e.g. Nairobi/Block 1/234" />}
                            </Field>
                        </CardBody>
                    </Card>
                </div>

                <div className="flex flex-col gap-6">
                    <Card>
                        <CardHeader title="Status" />
                        <CardBody>
                            <Switch {...register('is_active')} label="Active" description="Inactive properties are hidden from lease and billing screens." />
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Building" />
                        <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-1">
                            <Field label="Total lettable area" error={errors.total_area_sqft?.message}>
                                {(props) => <Input {...props} {...register('total_area_sqft')} inputMode="decimal" suffix="sq ft" />}
                            </Field>
                            <Field label="Year built" error={errors.year_built?.message}>
                                {(props) => <Input {...props} {...register('year_built')} inputMode="numeric" maxLength={4} />}
                            </Field>
                        </CardBody>
                    </Card>
                </div>
            </div>

            <div className="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-col-reverse gap-2 border-t border-gray-950/5 bg-gray-50/90 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-sm sm:static sm:mx-0 sm:flex-row sm:justify-end sm:border-0 sm:bg-transparent sm:p-0 dark:border-white/10 dark:bg-gray-950/90 sm:dark:bg-transparent">
                <ButtonLink to={backTo} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={save.isPending} disabled={property && !isDirty}>
                    {property ? 'Save changes' : 'Create property'}
                </Button>
            </div>
        </form>
    );
}
