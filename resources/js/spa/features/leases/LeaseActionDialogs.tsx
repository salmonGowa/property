import { zodResolver } from '@hookform/resolvers/zod';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { z } from 'zod';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field, Input, Select, Switch } from '@/components/ui/Field';
import { useLookups } from '@/hooks/useLookups';
import { useServerErrors } from '@/hooks/useServerErrors';
import { isValidIsoDate, todayIso } from '@/lib/dates';
import { formatMoney } from '@/lib/format';
import type { Lease } from '@/types/api';
import { useActivateLease, useChangePenaltyRule, useRenewLease, useSettleDeposit, useTerminateLease } from './api';

const decimal = /^\d+(\.\d{1,2})?$/;

export type LeaseAction = 'activate' | 'terminate' | 'renew' | 'penaltyRule' | 'settleDeposit';

interface DialogProps {
    lease: Lease;
    onClose: () => void;
}

/** Shows the dialog for whichever lease action is open; each form mounts fresh so it starts from the lease's current values. */
export function LeaseActionDialogs({ lease, action, onClose }: DialogProps & { action: LeaseAction | null }) {
    const titles: Record<LeaseAction, { title: string; description?: string; size?: 'sm' | 'md' | 'lg' }> = {
        activate: { title: `Activate ${lease.reference}?`, size: 'sm' },
        terminate: { title: 'Terminate lease', description: 'The unit becomes vacant. Rent already billed for days after the termination date is credited back automatically, or voided if unpaid.' },
        renew: { title: 'Renew lease', description: 'Creates a draft lease for the same tenant and unit. Activating it ends this lease the day before the new one starts.' },
        penaltyRule: { title: 'Change penalty rule', description: 'Penalty arrangements may change during a lease, unlike its billing terms.' },
        settleDeposit: { title: 'Settle deposit', description: 'Charges below are billed first, everything owed is paid from the deposit, and the remainder is refunded.', size: 'lg' },
    };
    const current = action ? titles[action] : null;

    return (
        <Dialog open={action !== null} onClose={onClose} title={current?.title ?? ''} description={current?.description} size={current?.size}>
            {action === 'activate' && <ActivateForm lease={lease} onClose={onClose} />}
            {action === 'terminate' && <TerminateForm lease={lease} onClose={onClose} />}
            {action === 'renew' && <RenewForm lease={lease} onClose={onClose} />}
            {action === 'penaltyRule' && <PenaltyRuleForm lease={lease} onClose={onClose} />}
            {action === 'settleDeposit' && <SettleDepositForm lease={lease} onClose={onClose} />}
        </Dialog>
    );
}

function Actions({ onClose, loading, children }: { onClose: () => void; loading: boolean; children: ReactNode }) {
    return (
        <div className="-mx-4 -mb-5 flex flex-col-reverse gap-2 border-t border-gray-950/5 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-6 sm:flex-row sm:justify-end sm:px-6 dark:border-white/10">
            <Button variant="secondary" onClick={onClose} disabled={loading}>
                Cancel
            </Button>
            {children}
        </div>
    );
}

function ActivateForm({ lease, onClose }: DialogProps) {
    const activate = useActivateLease(lease.id);
    const onServerError = useServerErrors(() => undefined, []);
    const hasDeposit = Number(lease.depositAmount) > 0;

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        activate.mutate(undefined, {
            onSuccess: (response) => {
                const issued = response.meta?.invoicesIssued ?? 0;
                toast.success('Lease activated.', { description: `${issued} ${issued === 1 ? 'invoice' : 'invoices'} issued.` });
                onClose();
            },
            onError: onServerError,
        });
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-5">
            <div className="text-sm text-gray-600 dark:text-gray-300">
                <p>
                    This marks <strong>{lease.unit?.fullCode}</strong> as occupied{hasDeposit && <>, invoices the {formatMoney(lease.depositAmount)} deposit</>} and bills any rent already due.
                </p>
                <p className="mt-2">The lease terms can no longer be edited afterwards.</p>
            </div>
            <Actions onClose={onClose} loading={activate.isPending}>
                <Button type="submit" loading={activate.isPending} className="bg-green-600 hover:bg-green-500 dark:bg-green-600 dark:hover:bg-green-500">
                    Activate lease
                </Button>
            </Actions>
        </form>
    );
}

const terminateSchema = z.object({
    terminated_on: z.string().refine(isValidIsoDate, 'Choose the last day of occupation.'),
    reason: z.string().trim().min(1, 'Give a reason, e.g. tenant gave notice.').max(255),
});

function TerminateForm({ lease, onClose }: DialogProps) {
    const terminate = useTerminateLease(lease.id);
    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<z.infer<typeof terminateSchema>>({ resolver: zodResolver(terminateSchema), defaultValues: { terminated_on: lease.startDate > todayIso() ? lease.startDate : todayIso(), reason: '' } });
    const onServerError = useServerErrors(setError, ['terminated_on', 'reason']);

    const onSubmit = handleSubmit((values) =>
        terminate.mutate(values, {
            onSuccess: (response) => {
                const adjusted = response.meta?.invoicesAdjusted ?? 0;
                const depositHeld = Number(response.data.details?.depositHeld ?? 0) > 0;
                const notes = [
                    adjusted > 0 && `${adjusted} ${adjusted === 1 ? 'invoice' : 'invoices'} credited or voided for days after the termination date.`,
                    depositHeld && `Next: settle the ${formatMoney(response.data.details?.depositHeld)} deposit.`,
                ].filter(Boolean);
                toast.success('Lease terminated.', { description: notes.length > 0 ? notes.join(' ') : undefined });
                onClose();
            },
            onError: onServerError,
        }),
    );

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
            <Field label="Last day of occupation" required error={errors.terminated_on?.message}>
                {(props) => <Input {...props} {...register('terminated_on')} type="date" min={lease.startDate} />}
            </Field>
            <Field label="Reason" required error={errors.reason?.message}>
                {(props) => <Input {...props} {...register('reason')} placeholder="e.g. Tenant gave notice, eviction, mutual agreement" maxLength={255} />}
            </Field>
            <Actions onClose={onClose} loading={terminate.isPending}>
                <Button type="submit" variant="danger" loading={terminate.isPending}>
                    Terminate lease
                </Button>
            </Actions>
        </form>
    );
}

const renewSchema = z
    .object({
        start_date: z.string().refine(isValidIsoDate, 'Choose the start date.'),
        end_date: z.string().refine((value) => value === '' || isValidIsoDate(value), 'Enter a valid date.'),
        rent_amount: z.string().trim().refine((value) => decimal.test(value), 'Enter an amount, e.g. 35000.'),
    })
    .superRefine((values, context) => {
        if (values.end_date && values.end_date <= values.start_date) {
            context.addIssue({ code: 'custom', path: ['end_date'], message: 'The end date must be after the start date.' });
        }
    });

function RenewForm({ lease, onClose }: DialogProps) {
    const navigate = useNavigate();
    const renew = useRenewLease(lease.id);
    const defaults = lease.details?.renewalDefaults;
    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<z.infer<typeof renewSchema>>({
        resolver: zodResolver(renewSchema),
        defaultValues: { start_date: defaults?.startDate ?? '', end_date: defaults?.endDate ?? '', rent_amount: defaults?.rentAmount ?? lease.rentAmount },
    });
    const onServerError = useServerErrors(setError, ['start_date', 'end_date', 'rent_amount']);

    const onSubmit = handleSubmit((values) =>
        renew.mutate(
            { start_date: values.start_date, end_date: values.end_date || null, rent_amount: values.rent_amount },
            {
                onSuccess: (renewal) => {
                    toast.success(`Renewal ${renewal.reference} drafted.`, { description: 'Review it, then activate it to take over from this lease.' });
                    onClose();
                    navigate(`/leases/${renewal.id}`);
                },
                onError: onServerError,
            },
        ),
    );

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <Field label="Start date" required error={errors.start_date?.message}>
                    {(props) => <Input {...props} {...register('start_date')} type="date" />}
                </Field>
                <Field label="End date" error={errors.end_date?.message} hint="Leave empty for a periodic tenancy.">
                    {(props) => <Input {...props} {...register('end_date')} type="date" />}
                </Field>
            </div>
            <Field label="New monthly rent" required error={errors.rent_amount?.message} hint={Number(lease.escalationRate) > 0 ? 'Pre-filled with the escalated rent in force at renewal.' : undefined}>
                {(props) => <Input {...props} {...register('rent_amount')} inputMode="decimal" prefix="KES" />}
            </Field>
            <Actions onClose={onClose} loading={renew.isPending}>
                <Button type="submit" loading={renew.isPending}>
                    Draft renewal
                </Button>
            </Actions>
        </form>
    );
}

const penaltySchema = z.object({ penalty_rule_id: z.string(), penalties_exempt: z.boolean() });

function PenaltyRuleForm({ lease, onClose }: DialogProps) {
    const { data: lookups } = useLookups();
    const change = useChangePenaltyRule(lease.id);
    const {
        register,
        handleSubmit,
        setError,
        control,
        formState: { errors },
    } = useForm<z.infer<typeof penaltySchema>>({
        resolver: zodResolver(penaltySchema),
        defaultValues: { penalty_rule_id: lease.penaltyRuleId ? String(lease.penaltyRuleId) : '', penalties_exempt: lease.penaltiesExempt },
    });
    const onServerError = useServerErrors(setError, ['penalty_rule_id', 'penalties_exempt']);
    const exempt = useWatch({ control, name: 'penalties_exempt' });

    const onSubmit = handleSubmit((values) =>
        change.mutate(
            { penalties_exempt: values.penalties_exempt, penalty_rule_id: values.penalties_exempt || !values.penalty_rule_id ? null : Number(values.penalty_rule_id) },
            {
                onSuccess: (updated) => {
                    toast.success('Penalty rule updated.', { description: updated.details?.penalty.source });
                    onClose();
                },
                onError: onServerError,
            },
        ),
    );

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
            <Field label="Penalty rule" error={errors.penalty_rule_id?.message} hint="Leave on inherit to use the property's rule, or the company default.">
                {(props) => (
                    <Select {...props} {...register('penalty_rule_id')} disabled={exempt}>
                        <option value="">Inherit from property / company default</option>
                        {lookups?.penaltyRules.map((rule) => (
                            <option key={rule.id} value={rule.id}>
                                {rule.name}
                                {rule.is_default ? ' (company default)' : ''}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Switch {...register('penalties_exempt')} label="No late penalties on this lease" description="For special agreements, e.g. government or diplomatic tenants." />
            <Actions onClose={onClose} loading={change.isPending}>
                <Button type="submit" loading={change.isPending}>
                    Save
                </Button>
            </Actions>
        </form>
    );
}

const settleSchema = z.object({
    date: z
        .string()
        .refine(isValidIsoDate, 'Choose the settlement date.')
        .refine((value) => value <= todayIso(), 'The settlement date cannot be in the future.'),
    deductions: z.array(
        z.object({
            description: z.string().trim().min(1, 'Describe the charge.').max(255),
            amount: z
                .string()
                .trim()
                .refine((value) => decimal.test(value) && Number(value) > 0, 'Enter an amount above zero.'),
        }),
    ),
    refund_method: z.string(),
    refund_reference: z.string().trim().max(100),
});

type SettleValues = z.infer<typeof settleSchema>;

function SettleDepositForm({ lease, onClose }: DialogProps) {
    const { data: lookups } = useLookups();
    const settle = useSettleDeposit(lease.id);
    const {
        register,
        handleSubmit,
        setError,
        control,
        formState: { errors },
    } = useForm<SettleValues>({
        resolver: zodResolver(settleSchema),
        defaultValues: { date: lease.terminatedOn && lease.terminatedOn < todayIso() ? lease.terminatedOn : todayIso(), deductions: [], refund_method: '', refund_reference: '' },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'deductions' });
    const onServerError = useServerErrors(setError, ['date', 'refund_method', 'refund_reference', ...fields.flatMap((_, index) => [`deductions.${index}.description`, `deductions.${index}.amount`])]);
    const refundMethod = useWatch({ control, name: 'refund_method' });
    const deductions = useWatch({ control, name: 'deductions' });
    const held = Number(lease.details?.depositHeld ?? 0);
    const owed = Math.max(0, Number(lease.details?.tenantBalance ?? 0));
    const charges = deductions.reduce((total, line) => total + (decimal.test(line.amount ?? '') ? Number(line.amount) : 0), 0);
    const applied = Math.min(held, owed + charges);
    const refundable = held - applied;

    const onSubmit = handleSubmit((values) =>
        settle.mutate(
            {
                date: values.date,
                deductions: values.deductions.map((line) => ({ description: line.description.trim(), amount: line.amount.trim() })),
                refund_method: values.refund_method || null,
                refund_reference: values.refund_method ? values.refund_reference || null : null,
            },
            {
                onSuccess: (settlement) => {
                    toast.success('Deposit settled.', {
                        description: `Applied to balance: ${formatMoney(settlement.applied)}. Refunded: ${formatMoney(settlement.refunded)}.${Number(settlement.stillHeld) > 0 ? ` Still held for refund: ${formatMoney(settlement.stillHeld)}.` : ''}`,
                        duration: 10_000,
                    });
                    onClose();
                },
                onError: onServerError,
            },
        ),
    );

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
            <dl className="grid grid-cols-2 gap-3 rounded-lg bg-gray-50 p-3 text-sm sm:grid-cols-4 dark:bg-white/5">
                <div>
                    <dt className="text-gray-500 dark:text-gray-400">Deposit held</dt>
                    <dd className="font-semibold tabular-nums">{formatMoney(held)}</dd>
                </div>
                <div>
                    <dt className="text-gray-500 dark:text-gray-400">Tenant owes</dt>
                    <dd className="font-semibold tabular-nums">{formatMoney(owed)}</dd>
                </div>
                <div>
                    <dt className="text-gray-500 dark:text-gray-400">Applied</dt>
                    <dd className="font-semibold tabular-nums">{formatMoney(applied)}</dd>
                </div>
                <div>
                    <dt className="text-gray-500 dark:text-gray-400">To refund</dt>
                    <dd className="font-semibold text-green-700 tabular-nums dark:text-green-400">{formatMoney(refundable)}</dd>
                </div>
            </dl>

            <Field label="Settlement date" required error={errors.date?.message}>
                {(props) => <Input {...props} {...register('date')} type="date" max={todayIso()} />}
            </Field>

            <fieldset className="flex flex-col gap-3">
                <legend className="text-sm/6 font-medium text-gray-900 dark:text-gray-100">Move-out charges (optional)</legend>
                {fields.map((field, index) => (
                    <div key={field.id} className="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[1fr_10rem_auto]">
                        <Field label={`Charge ${index + 1} description`} error={errors.deductions?.[index]?.description?.message} className="col-span-2 sm:col-span-1 [&>label]:sr-only">
                            {(props) => <Input {...props} {...register(`deductions.${index}.description`)} placeholder="e.g. Repaint bedroom, replace lock" />}
                        </Field>
                        <Field label={`Charge ${index + 1} amount`} error={errors.deductions?.[index]?.amount?.message} className="[&>label]:sr-only">
                            {(props) => <Input {...props} {...register(`deductions.${index}.amount`)} inputMode="decimal" prefix="KES" />}
                        </Field>
                        <Button variant="ghost" size="icon" onClick={() => remove(index)} aria-label={`Remove charge ${index + 1}`} className="text-red-600 dark:text-red-400">
                            <Trash2 />
                        </Button>
                    </div>
                ))}
                <Button variant="secondary" size="sm" onClick={() => append({ description: '', amount: '' })} className="self-start" disabled={fields.length >= 20}>
                    <Plus /> Add charge
                </Button>
            </fieldset>

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <Field label="Refund the balance by" error={errors.refund_method?.message}>
                    {(props) => (
                        <Select {...props} {...register('refund_method')}>
                            <option value="">Don't refund yet</option>
                            {lookups?.refundMethods.map((method) => (
                                <option key={method.value} value={method.value}>
                                    {method.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                {refundMethod && (
                    <Field label="Refund reference" error={errors.refund_reference?.message}>
                        {(props) => <Input {...props} {...register('refund_reference')} placeholder="M-Pesa code, cheque or EFT number" />}
                    </Field>
                )}
            </div>

            <Actions onClose={onClose} loading={settle.isPending}>
                <Button type="submit" loading={settle.isPending}>
                    Settle deposit
                </Button>
            </Actions>
        </form>
    );
}

