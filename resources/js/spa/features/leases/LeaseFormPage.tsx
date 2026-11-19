import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, ArrowRight, Banknote, CalendarDays, CircleCheck, Scale, Users, type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Controller, useForm, useWatch, type Control, type FieldErrors, type UseFormRegister, type UseFormSetValue } from 'react-hook-form';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { toast } from 'sonner';
import { z } from 'zod';
import { RequirePermission } from '@/auth/auth';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { ErrorState, FullPageSpinner } from '@/components/ui/Feedback';
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/PageHeader';
import { useTenant } from '@/features/tenants/api';
import { TenantPicker, type PickedTenant } from '@/features/tenants/TenantPicker';
import { useLookups } from '@/hooks/useLookups';
import { useServerErrors } from '@/hooks/useServerErrors';
import { errorMessage } from '@/lib/api';
import { cn } from '@/lib/cn';
import { firstOfNextMonthIso, isValidIsoDate, termEndIso } from '@/lib/dates';
import { formatDate, formatMoney } from '@/lib/format';
import type { Lease, LettableUnit, Lookups } from '@/types/api';
import { useLease, useLettableUnits, useSaveLease, type LeasePayload } from './api';

const decimal = /^\d+(\.\d{1,2})?$/;
const optionalAmount = z
    .string()
    .trim()
    .refine((value) => value === '' || decimal.test(value), 'Enter an amount, e.g. 3000.');

const baseSchema = z.object({
    tenant_id: z.string().min(1, 'Choose the tenant.'),
    unit_id: z.string().min(1, 'Choose the unit to let.'),
    start_date: z.string().refine(isValidIsoDate, 'Choose the start date.'),
    end_date: z.string().refine((value) => value === '' || isValidIsoDate(value), 'Enter a valid date.'),
    billing_frequency: z.string().min(1, 'Choose how often rent is billed.'),
    due_day: z
        .string()
        .trim()
        .refine((value) => /^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= 28, 'Rent must fall due between the 1st and the 28th.'),
    rent_amount: z.string().trim().min(1, 'Enter the monthly rent.').refine((value) => decimal.test(value), 'Enter an amount, e.g. 35000.'),
    service_charge: optionalAmount,
    deposit_amount: optionalAmount,
    charge_vat: z.boolean(),
    escalation_rate: z
        .string()
        .trim()
        .refine((value) => value === '' || (decimal.test(value) && Number(value) <= 100), 'Enter a percentage between 0 and 100.'),
    escalation_interval_months: z.enum(['12', '24', '36']),
    penalty_rule_id: z.string(),
    penalties_exempt: z.boolean(),
    notes: z.string().max(5000),
});

const schema = baseSchema.superRefine((values, context) => {
    if (values.end_date && values.start_date && values.end_date <= values.start_date) {
        context.addIssue({ code: 'custom', path: ['end_date'], message: 'The end date must be after the start date.' });
    }
});

type FormValues = z.infer<typeof baseSchema>;
type FieldName = keyof FormValues;
const fields = Object.keys(baseSchema.shape);

const termOptions = [
    { months: 12, label: '1 year' },
    { months: 24, label: '2 years' },
    { months: 36, label: '3 years' },
    { months: 60, label: '5 years' },
    { months: 72, label: '6 years' },
    { months: 0, label: 'Periodic (no fixed end)' },
];

function toFormValues(lease?: Lease, unit?: LettableUnit, tenantId?: number): FormValues {
    const start = lease?.startDate ?? firstOfNextMonthIso();

    return {
        tenant_id: String(lease?.tenantId ?? tenantId ?? ''),
        unit_id: String(lease?.unitId ?? unit?.id ?? ''),
        start_date: start,
        end_date: lease ? (lease.endDate ?? '') : termEndIso(start, 12),
        billing_frequency: lease?.billingFrequency.value ?? 'monthly',
        due_day: String(lease?.dueDay ?? 5),
        rent_amount: lease?.rentAmount ?? unit?.marketRent ?? '',
        service_charge: lease?.serviceCharge ?? unit?.serviceCharge ?? '',
        deposit_amount: lease?.depositAmount ?? '',
        charge_vat: lease?.chargeVat ?? unit?.isCommercial ?? false,
        escalation_rate: lease?.escalationRate ?? '0',
        escalation_interval_months: String(lease?.escalationIntervalMonths ?? 12) as FormValues['escalation_interval_months'],
        penalty_rule_id: lease?.penaltyRuleId ? String(lease.penaltyRuleId) : '',
        penalties_exempt: lease?.penaltiesExempt ?? false,
        notes: lease?.notes ?? '',
    };
}

function toPayload(values: FormValues): LeasePayload {
    return {
        tenant_id: Number(values.tenant_id),
        unit_id: Number(values.unit_id),
        start_date: values.start_date,
        end_date: values.end_date || null,
        billing_frequency: values.billing_frequency,
        due_day: Number(values.due_day),
        rent_amount: values.rent_amount.trim(),
        service_charge: values.service_charge.trim() || '0',
        deposit_amount: values.deposit_amount.trim() || '0',
        charge_vat: values.charge_vat,
        escalation_rate: values.escalation_rate.trim() || '0',
        escalation_interval_months: Number(values.escalation_interval_months),
        penalty_rule_id: values.penalties_exempt || !values.penalty_rule_id ? null : Number(values.penalty_rule_id),
        penalties_exempt: values.penalties_exempt,
        notes: values.notes.trim() || null,
    };
}

export function CreateLeasePage() {
    return (
        <RequirePermission permission="leases" ability="create">
            <LeaseFormLoader />
        </RequirePermission>
    );
}

export function EditLeasePage() {
    const id = Number(useParams().leaseId);
    const { data: lease, isPending, error, refetch } = useLease(id);

    if (isPending) {
        return <FullPageSpinner />;
    }

    if (error) {
        return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;
    }

    if (!lease.can.update) {
        return <ErrorState message="Only draft leases can be edited. Once active, the terms drive billing." />;
    }

    return <LeaseFormLoader lease={lease} />;
}

/** Waits for the option lists (and a pre-selected tenant from ?tenant=) before mounting the form with its defaults. */
function LeaseFormLoader({ lease }: { lease?: Lease }) {
    const [params] = useSearchParams();
    const tenantParam = Number(params.get('tenant')) || undefined;
    const lookups = useLookups();
    const units = useLettableUnits(lease?.unitId);
    // Editing already has the tenant on the lease; a new lease may arrive with ?tenant= from a tenant's page.
    const tenant = useTenant(tenantParam ?? 0, !lease && tenantParam !== undefined);
    const waitingForTenant = !lease && tenantParam !== undefined && tenant.isPending;

    const failure = lookups.error ?? units.error;
    if (failure) {
        return <ErrorState message={errorMessage(failure)} onRetry={() => void Promise.all([lookups.refetch(), units.refetch()])} />;
    }

    if (!lookups.data || !units.data || waitingForTenant) {
        return <FullPageSpinner />;
    }

    const presetUnit = units.data.find((unit) => unit.id === Number(params.get('unit')));
    const presetTenant: PickedTenant | null = lease?.tenant ?? (tenant.data?.isActive ? tenant.data : null);

    return <LeaseForm lease={lease} lookups={lookups.data} units={units.data} presetUnit={presetUnit} presetTenant={presetTenant} />;
}

interface StepDefinition {
    id: string;
    title: string;
    description: string;
    icon: LucideIcon;
    fields: FieldName[];
}

const steps: StepDefinition[] = [
    { id: 'parties', title: 'Tenant & unit', description: 'Who is renting what', icon: Users, fields: ['tenant_id', 'unit_id'] },
    { id: 'term', title: 'Term', description: 'Dates and billing cycle', icon: CalendarDays, fields: ['start_date', 'end_date', 'billing_frequency', 'due_day'] },
    { id: 'charges', title: 'Charges', description: 'Rent, deposit and VAT', icon: Banknote, fields: ['rent_amount', 'service_charge', 'deposit_amount', 'charge_vat', 'escalation_rate', 'escalation_interval_months'] },
    { id: 'penalties', title: 'Late payment', description: 'Penalty rule', icon: Scale, fields: ['penalty_rule_id', 'penalties_exempt'] },
    { id: 'review', title: 'Review', description: 'Confirm and save as draft', icon: CircleCheck, fields: ['notes'] },
];

interface LeaseFormProps {
    lease?: Lease;
    lookups: Lookups;
    units: LettableUnit[];
    presetUnit?: LettableUnit;
    presetTenant: PickedTenant | null;
}

function LeaseForm({ lease, lookups, units, presetUnit, presetTenant }: LeaseFormProps) {
    const navigate = useNavigate();
    const save = useSaveLease(lease?.id);
    const isWizard = !lease;
    const [stepIndex, setStepIndex] = useState(0);
    const [tenant, setTenant] = useState<PickedTenant | null>(presetTenant);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toFormValues(lease, presetUnit, presetTenant?.id) });
    const {
        handleSubmit,
        setError,
        trigger,
        formState: { errors, isDirty },
    } = form;
    const onServerError = useServerErrors(setError, fields);
    const backTo = lease ? `/leases/${lease.id}` : '/leases';

    // Wizard steps validate with trigger(), not submit, so react-hook-form would not re-check a field as it
    // is corrected. Re-validate any field that is showing an error as soon as it changes.
    useEffect(() => {
        const subscription = form.watch((_, { name }) => {
            if (name && form.getFieldState(name).error) {
                void trigger(name);
            }
        });

        return () => subscription.unsubscribe();
    }, [form, trigger]);
    const step = steps[stepIndex] ?? steps[0]!;
    const isLastStep = stepIndex === steps.length - 1;

    const submit = handleSubmit(
        (values) =>
            save.mutate(toPayload(values), {
                onSuccess: (saved) => {
                    toast.success(lease ? 'Lease updated.' : `Lease ${saved.reference} saved as draft.`);
                    navigate(`/leases/${saved.id}`, { replace: !lease });
                },
                onError: (error) => {
                    onServerError(error);
                    // Send the user back to the first step holding a server-side error.
                    const failedStep = steps.findIndex((candidate) => candidate.fields.some((field) => form.getFieldState(field).error));
                    if (isWizard && failedStep >= 0) {
                        setStepIndex(failedStep);
                    }
                },
            }),
        (invalid) => {
            const failedStep = steps.findIndex((candidate) => candidate.fields.some((field) => field in invalid));
            if (isWizard && failedStep >= 0) {
                setStepIndex(failedStep);
            }
        },
    );

    const goNext = async () => {
        if (await trigger(step.fields, { shouldFocus: true })) {
            setStepIndex((index) => Math.min(index + 1, steps.length - 1));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const sections: Record<string, ReactNode> = {
        parties: <PartiesSection form={form} units={units} tenant={tenant} onTenantChange={setTenant} lease={lease} />,
        term: <TermSection register={form.register} control={form.control} setValue={form.setValue} errors={errors} lookups={lookups} />,
        charges: <ChargesSection register={form.register} control={form.control} setValue={form.setValue} errors={errors} lookups={lookups} />,
        penalties: <PenaltySection register={form.register} control={form.control} errors={errors} lookups={lookups} />,
        review: <ReviewSection register={form.register} control={form.control} errors={errors} lookups={lookups} units={units} tenant={tenant} isWizard={isWizard} />,
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                // Enter in a field moves the wizard forward instead of saving early.
                if (isWizard && !isLastStep) {
                    void goNext();
                } else {
                    void submit(event);
                }
            }}
            noValidate
        >
            <PageHeader title={lease ? `Edit ${lease.reference}` : 'New lease'} back={{ to: backTo, label: lease ? lease.reference : 'Leases' }} description={lease ? 'Draft leases can be changed freely until they are activated.' : undefined} />

            {isWizard ? (
                <>
                    <Stepper current={stepIndex} onSelect={(index) => index < stepIndex && setStepIndex(index)} />
                    <Card>
                        <CardHeader title={step.title} description={step.description} />
                        <CardBody>{sections[step.id]}</CardBody>
                    </Card>
                </>
            ) : (
                <div className="flex flex-col gap-6">
                    {steps
                        .filter((candidate) => candidate.id !== 'review')
                        .map((candidate) => (
                            <Card key={candidate.id}>
                                <CardHeader title={candidate.title} description={candidate.description} />
                                <CardBody>{sections[candidate.id]}</CardBody>
                            </Card>
                        ))}
                    <Card>
                        <CardHeader title="Notes" />
                        <CardBody>
                            <Field label="Notes" error={errors.notes?.message}>
                                {(props) => <Textarea {...props} {...form.register('notes')} placeholder="Special terms, e.g. rent-free fit-out period, parking allocation" />}
                            </Field>
                        </CardBody>
                    </Card>
                </div>
            )}

            {/* The wizard keeps Back and Next side by side on phones; elsewhere actions stack like other forms. */}
            <div
                className={cn(
                    'sticky bottom-0 z-20 -mx-4 mt-6 flex gap-2 border-t border-gray-950/5 bg-gray-50/90 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-sm sm:static sm:mx-0 sm:flex-row sm:justify-end sm:border-0 sm:bg-transparent sm:p-0 dark:border-white/10 dark:bg-gray-950/90 sm:dark:bg-transparent',
                    isWizard ? '*:flex-1 sm:*:flex-none' : 'flex-col-reverse',
                )}
            >
                {isWizard && stepIndex > 0 ? (
                    <Button variant="secondary" onClick={() => setStepIndex((index) => index - 1)} disabled={save.isPending}>
                        <ArrowLeft /> Back
                    </Button>
                ) : (
                    <ButtonLink to={backTo} variant="secondary">
                        Cancel
                    </ButtonLink>
                )}
                {isWizard && !isLastStep ? (
                    <Button type="submit">
                        Next <ArrowRight />
                    </Button>
                ) : (
                    <Button type="submit" loading={save.isPending} disabled={!!lease && !isDirty}>
                        {lease ? 'Save changes' : 'Save as draft'}
                    </Button>
                )}
            </div>
        </form>
    );
}

function Stepper({ current, onSelect }: { current: number; onSelect: (index: number) => void }) {
    const step = steps[current] ?? steps[0]!;

    return (
        <nav aria-label="Progress" className="mb-6">
            {/* Phones: one line and a progress bar. */}
            <div className="sm:hidden">
                <p className="text-sm font-medium text-gray-900 dark:text-white">
                    Step {current + 1} of {steps.length}: {step.title}
                </p>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div className="h-full rounded-full bg-primary-600 transition-all dark:bg-primary-500" style={{ width: `${((current + 1) / steps.length) * 100}%` }} />
                </div>
            </div>
            <ol role="list" className="hidden gap-2 sm:grid sm:grid-cols-5">
                {steps.map((candidate, index) => {
                    const state = index < current ? 'done' : index === current ? 'current' : 'upcoming';
                    const Icon = candidate.icon;

                    return (
                        <li key={candidate.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(index)}
                                disabled={state !== 'done'}
                                aria-current={state === 'current' ? 'step' : undefined}
                                className={cn(
                                    'flex w-full flex-col gap-1 border-t-4 pt-3 text-left',
                                    state === 'upcoming' ? 'border-gray-200 dark:border-white/10' : 'border-primary-600 dark:border-primary-500',
                                    state === 'done' && 'hover:border-primary-800 dark:hover:border-primary-300',
                                )}
                            >
                                <span className={cn('flex items-center gap-1.5 text-xs font-semibold', state === 'upcoming' ? 'text-gray-500 dark:text-gray-400' : 'text-primary-600 dark:text-primary-400')}>
                                    <Icon className="size-3.5" aria-hidden />
                                    {candidate.title}
                                </span>
                                <span className="hidden text-xs text-gray-500 lg:block dark:text-gray-400">{candidate.description}</span>
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

interface SectionProps {
    register: UseFormRegister<FormValues>;
    control: Control<FormValues>;
    errors: FieldErrors<FormValues>;
    lookups: Lookups;
}

function PartiesSection({ form, units, tenant, onTenantChange, lease }: { form: ReturnType<typeof useForm<FormValues>>; units: LettableUnit[]; tenant: PickedTenant | null; onTenantChange: (tenant: PickedTenant | null) => void; lease?: Lease }) {
    const {
        control,
        setValue,
        formState: { errors },
    } = form;
    const byProperty = useMemo(() => {
        const groups = new Map<string, LettableUnit[]>();
        for (const unit of units) {
            groups.set(unit.property.name, [...(groups.get(unit.property.name) ?? []), unit]);
        }

        return [...groups.entries()];
    }, [units]);

    return (
        <div className="grid grid-cols-1 gap-5">
            <Field label="Tenant" required error={errors.tenant_id?.message} hint="Only active tenants are listed.">
                {(props) => (
                    <TenantPicker
                        id={props.id}
                        invalid={props['aria-invalid']}
                        describedBy={props['aria-describedby']}
                        value={tenant}
                        onChange={(picked) => {
                            onTenantChange(picked);
                            setValue('tenant_id', picked ? String(picked.id) : '', { shouldDirty: true, shouldValidate: picked !== null });
                        }}
                    />
                )}
            </Field>
            <Controller
                control={control}
                name="unit_id"
                render={({ field }) => (
                    <Field label="Unit" required error={errors.unit_id?.message} hint={units.length === 0 ? 'No vacant or reserved units right now.' : 'Only vacant and reserved units are listed. Choosing one fills in its asking rent.'}>
                        {(props) => (
                            <Select
                                {...props}
                                ref={field.ref}
                                name={field.name}
                                value={field.value}
                                onBlur={field.onBlur}
                                onChange={(event) => {
                                    field.onChange(event.target.value);
                                    const unit = units.find((candidate) => candidate.id === Number(event.target.value));
                                    if (unit) {
                                        setValue('rent_amount', unit.marketRent, { shouldDirty: true });
                                        setValue('service_charge', unit.serviceCharge, { shouldDirty: true });
                                        setValue('charge_vat', unit.isCommercial, { shouldDirty: true });
                                    }
                                }}
                            >
                                <option value="">Choose a unit…</option>
                                {byProperty.map(([propertyName, propertyUnits]) => (
                                    <optgroup key={propertyName} label={propertyName}>
                                        {propertyUnits.map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.code} · {unit.type.label} · asking {formatMoney(unit.marketRent)}
                                                {unit.status.value !== 'vacant' && unit.id !== lease?.unitId ? ` (${unit.status.label.toLowerCase()})` : ''}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </Select>
                        )}
                    </Field>
                )}
            />
        </div>
    );
}

function TermSection({ register, control, setValue, errors, lookups }: SectionProps & { setValue: UseFormSetValue<FormValues> }) {
    const startDate = useWatch({ control, name: 'start_date' });
    const [termMonths, setTermMonths] = useState('');

    const applyTerm = (months: string, start: string) => {
        if (months === '' || !isValidIsoDate(start)) {
            return;
        }
        setValue('end_date', months === '0' ? '' : termEndIso(start, Number(months)), { shouldDirty: true, shouldValidate: true });
    };

    const startField = register('start_date');

    return (
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <Field label="Start date" required error={errors.start_date?.message}>
                {(props) => (
                    <Input
                        {...props}
                        {...startField}
                        type="date"
                        onChange={(event) => {
                            void startField.onChange(event);
                            applyTerm(termMonths, event.target.value);
                        }}
                    />
                )}
            </Field>
            <Field label="Term" hint="Sets the end date for you; you can still adjust it.">
                {(props) => (
                    <Select
                        {...props}
                        value={termMonths}
                        onChange={(event) => {
                            setTermMonths(event.target.value);
                            applyTerm(event.target.value, startDate);
                        }}
                    >
                        <option value="">Choose a term…</option>
                        {termOptions.map((option) => (
                            <option key={option.months} value={option.months}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field label="End date" error={errors.end_date?.message} hint="Leave empty for a periodic tenancy.">
                {(props) => <Input {...props} {...register('end_date')} type="date" min={startDate || undefined} />}
            </Field>
            <Field label="Billed" required error={errors.billing_frequency?.message}>
                {(props) => (
                    <Select {...props} {...register('billing_frequency')}>
                        {lookups.billingFrequencies.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field label="Rent due on day" required error={errors.due_day?.message} hint="Invoices are issued on the 1st and fall due on this day.">
                {(props) => <Input {...props} {...register('due_day')} inputMode="numeric" suffix="of the month" maxLength={2} />}
            </Field>
        </div>
    );
}

function ChargesSection({ register, control, setValue, errors, lookups }: SectionProps & { setValue: UseFormSetValue<FormValues> }) {
    const rent = useWatch({ control, name: 'rent_amount' });
    const typicalDeposit = decimal.test(rent ?? '') && Number(rent) > 0 ? (Number(rent) * 2).toFixed(2) : null;

    return (
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <Field label="Monthly rent" required error={errors.rent_amount?.message}>
                {(props) => <Input {...props} {...register('rent_amount')} inputMode="decimal" prefix="KES" />}
            </Field>
            <Field label="Monthly service charge" error={errors.service_charge?.message}>
                {(props) => <Input {...props} {...register('service_charge')} inputMode="decimal" prefix="KES" />}
            </Field>
            <Field
                label="Security deposit"
                error={errors.deposit_amount?.message}
                hint={
                    <>
                        Invoiced once when the lease is activated and held as a liability, not income.
                        {typicalDeposit && (
                            <>
                                {' '}
                                <button type="button" onClick={() => setValue('deposit_amount', typicalDeposit, { shouldDirty: true, shouldValidate: true })} className="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                    Use 2 months ({formatMoney(typicalDeposit)})
                                </button>
                            </>
                        )}
                    </>
                }
            >
                {(props) => <Input {...props} {...register('deposit_amount')} inputMode="decimal" prefix="KES" />}
            </Field>
            <div className="flex items-start sm:pt-7">
                <Switch {...register('charge_vat')} label="Charge VAT on rent and service charge" description={`Commercial lettings attract VAT at ${Number(lookups.vatRate)}%; residential lettings are exempt.`} className="w-full" />
            </div>
            <Field label="Annual escalation" error={errors.escalation_rate?.message}>
                {(props) => <Input {...props} {...register('escalation_rate')} inputMode="decimal" suffix="%" />}
            </Field>
            <Field label="Escalate every" error={errors.escalation_interval_months?.message}>
                {(props) => (
                    <Select {...props} {...register('escalation_interval_months')}>
                        <option value="12">12 months</option>
                        <option value="24">24 months</option>
                        <option value="36">36 months</option>
                    </Select>
                )}
            </Field>
        </div>
    );
}

function PenaltySection({ register, control, errors, lookups }: SectionProps) {
    const exempt = useWatch({ control, name: 'penalties_exempt' });

    return (
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <Field label="Penalty rule" error={errors.penalty_rule_id?.message} hint="Leave on inherit to use the property's rule, or the company default.">
                {(props) => (
                    <Select {...props} {...register('penalty_rule_id')} disabled={exempt}>
                        <option value="">Inherit from property / company default</option>
                        {lookups.penaltyRules.map((rule) => (
                            <option key={rule.id} value={rule.id}>
                                {rule.name}
                                {rule.is_default ? ' (company default)' : ''}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <div className="sm:pt-7">
                <Switch {...register('penalties_exempt')} label="No late penalties on this lease" description="For special agreements, e.g. government or diplomatic tenants." />
            </div>
        </div>
    );
}

function ReviewSection({ register, control, errors, lookups, units, tenant, isWizard }: SectionProps & { units: LettableUnit[]; tenant: PickedTenant | null; isWizard: boolean }) {
    const values = useWatch({ control });
    const unit = units.find((candidate) => candidate.id === Number(values.unit_id));
    const rent = Number(values.rent_amount || 0);
    const serviceCharge = Number(values.service_charge || 0);
    const vat = values.charge_vat ? ((rent + serviceCharge) * Number(lookups.vatRate)) / 100 : 0;
    const frequency = lookups.billingFrequencies.find((option) => option.value === values.billing_frequency);
    const rule = lookups.penaltyRules.find((candidate) => candidate.id === Number(values.penalty_rule_id));

    const rows: [string, ReactNode][] = [
        ['Tenant', tenant?.name],
        ['Unit', unit ? `${unit.fullCode} · ${unit.type.label}` : null],
        ['Term', `${formatDate(values.start_date)} to ${values.end_date ? formatDate(values.end_date) : 'periodic (no fixed end)'}`],
        ['Billing', `${frequency?.label ?? ''}, due on day ${values.due_day} of the month`],
        ['Rent', formatMoney(rent)],
        ['Service charge', formatMoney(serviceCharge)],
        ...(vat > 0 ? ([[`VAT (${Number(lookups.vatRate)}%)`, formatMoney(vat)]] as [string, ReactNode][]) : []),
        ['Total per month', <strong key="total">{formatMoney(rent + serviceCharge + vat)}</strong>],
        ['Deposit', formatMoney(values.deposit_amount || 0)],
        ['Escalation', Number(values.escalation_rate) > 0 ? `${Number(values.escalation_rate)}% every ${values.escalation_interval_months} months` : 'None'],
        ['Late penalties', values.penalties_exempt ? 'Exempt' : (rule?.name ?? 'Inherited from the property or company default')],
    ];

    return (
        <div className="flex flex-col gap-5">
            <dl className="divide-y divide-gray-950/5 rounded-lg ring-1 ring-gray-950/10 dark:divide-white/10 dark:ring-white/10">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex flex-col gap-0.5 px-4 py-2.5 sm:flex-row sm:justify-between sm:gap-4">
                        <dt className="text-sm text-gray-500 dark:text-gray-400">{label}</dt>
                        <dd className="text-sm text-gray-950 tabular-nums sm:text-right dark:text-white">{value || '—'}</dd>
                    </div>
                ))}
            </dl>
            <Field label="Notes" error={errors.notes?.message}>
                {(props) => <Textarea {...props} {...register('notes')} placeholder="Special terms, e.g. rent-free fit-out period, parking allocation" />}
            </Field>
            {isWizard && <p className="text-sm text-gray-500 dark:text-gray-400">The lease is saved as a draft. Activate it from the next screen to occupy the unit and start billing.</p>}
        </div>
    );
}
