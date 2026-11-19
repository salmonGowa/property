import { zodResolver } from '@hookform/resolvers/zod';
import { useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import { useServerErrors } from '@/hooks/useServerErrors';
import type { Lookups, Unit } from '@/types/api';
import { useSaveUnit, type UnitPayload } from './api';

const residentialTypes = ['bedsitter', 'studio', 'apartment', 'house'];
const decimal = /^\d+(\.\d{1,2})?$/;
const amount = (message: string) => z.string().trim().refine((value) => value === '' || decimal.test(value), message);
const smallCount = z
    .string()
    .trim()
    .refine((value) => value === '' || (/^\d+$/.test(value) && Number(value) <= 20), 'Enter a whole number up to 20.');

const schema = z.object({
    code: z.string().trim().min(1, 'Enter the unit number.').max(30),
    type: z.string().min(1, 'Choose a unit type.'),
    status: z.string(),
    floor: z.string().max(20),
    bedrooms: smallCount,
    bathrooms: smallCount,
    area_sqft: amount('Enter the area as a number.'),
    market_rent: z.string().trim().min(1, 'Enter the monthly rent.').refine((value) => decimal.test(value), 'Enter an amount, e.g. 35000.'),
    service_charge: amount('Enter an amount, e.g. 3000.'),
    notes: z.string().max(5000),
});

type FormValues = z.infer<typeof schema>;
const fields = Object.keys(schema.shape);

interface UnitFormDialogProps {
    open: boolean;
    onClose: () => void;
    propertyId: number;
    unit?: Unit;
    lookups: Lookups;
}

export function UnitFormDialog(props: UnitFormDialogProps) {
    return (
        <Dialog open={props.open} onClose={props.onClose} title={props.unit ? `Edit unit ${props.unit.code}` : 'Add unit'} description="Asking terms are used as defaults when you draft a lease." size="lg">
            {/* Remount per unit so the form always starts from that unit's values. */}
            {props.open && <UnitForm key={props.unit?.id ?? 'new'} {...props} />}
        </Dialog>
    );
}

function UnitForm({ onClose, propertyId, unit, lookups }: UnitFormDialogProps) {
    const save = useSaveUnit(propertyId, unit?.id);
    const isOccupied = unit?.status.value === 'occupied';
    const {
        register,
        handleSubmit,
        setError,
        control,
        formState: { errors },
    } = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            code: unit?.code ?? '',
            type: unit?.type.value ?? 'apartment',
            status: unit?.status.value ?? 'vacant',
            floor: unit?.floor ?? '',
            bedrooms: unit?.bedrooms?.toString() ?? '',
            bathrooms: unit?.bathrooms?.toString() ?? '',
            area_sqft: unit?.areaSqft ?? '',
            market_rent: unit?.marketRent ?? '',
            service_charge: unit?.serviceCharge ?? '',
            notes: unit?.notes ?? '',
        },
    });
    const onServerError = useServerErrors(setError, fields);
    const type = useWatch({ control, name: 'type' });
    const isResidential = residentialTypes.includes(type);

    const onSubmit = handleSubmit((values) => {
        const optional = (value: string) => (value.trim() === '' ? null : value.trim());
        const payload: UnitPayload = {
            code: values.code.trim().toUpperCase(),
            type: values.type,
            floor: optional(values.floor),
            bedrooms: isResidential && values.bedrooms ? Number(values.bedrooms) : null,
            bathrooms: isResidential && values.bathrooms ? Number(values.bathrooms) : null,
            area_sqft: optional(values.area_sqft),
            market_rent: values.market_rent.trim(),
            service_charge: optional(values.service_charge),
            notes: optional(values.notes),
            // An occupied unit's status belongs to its lease; the API refuses changes to it.
            ...(isOccupied ? {} : { status: values.status }),
        };

        save.mutate(payload, {
            onSuccess: (saved) => {
                toast.success(unit ? `Unit ${saved.code} updated.` : `Unit ${saved.code} added.`);
                onClose();
            },
            onError: onServerError,
        });
    });

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <Field label="Unit number" required error={errors.code?.message}>
                    {(props) => <Input {...props} {...register('code')} placeholder="e.g. A-101" autoComplete="off" className="uppercase" autoFocus />}
                </Field>
                <Field label="Type" required error={errors.type?.message}>
                    {(props) => (
                        <Select {...props} {...register('type')}>
                            {lookups.unitTypes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field
                    label="Status"
                    error={errors.status?.message}
                    hint={isOccupied ? 'Occupied by an active lease. Terminate the lease to change this.' : 'Occupied is set automatically when a lease is activated.'}
                >
                    {(props) => (
                        <Select {...props} {...register('status')} disabled={isOccupied}>
                            {lookups.unitStatuses
                                .filter((option) => isOccupied || option.value !== 'occupied')
                                .map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                        </Select>
                    )}
                </Field>
                <Field label="Floor" error={errors.floor?.message}>
                    {(props) => <Input {...props} {...register('floor')} placeholder="e.g. Ground, 1st, Mezzanine" />}
                </Field>
                {isResidential && (
                    <>
                        <Field label="Bedrooms" error={errors.bedrooms?.message}>
                            {(props) => <Input {...props} {...register('bedrooms')} inputMode="numeric" />}
                        </Field>
                        <Field label="Bathrooms" error={errors.bathrooms?.message}>
                            {(props) => <Input {...props} {...register('bathrooms')} inputMode="numeric" />}
                        </Field>
                    </>
                )}
                <Field label="Area" error={errors.area_sqft?.message}>
                    {(props) => <Input {...props} {...register('area_sqft')} inputMode="decimal" suffix="sq ft" />}
                </Field>
            </div>

            <fieldset className="grid grid-cols-1 gap-5 border-t border-gray-950/5 pt-5 sm:grid-cols-2 dark:border-white/10">
                <legend className="sr-only">Asking terms</legend>
                <Field label="Monthly rent" required error={errors.market_rent?.message}>
                    {(props) => <Input {...props} {...register('market_rent')} inputMode="decimal" prefix="KES" />}
                </Field>
                <Field label="Monthly service charge" error={errors.service_charge?.message}>
                    {(props) => <Input {...props} {...register('service_charge')} inputMode="decimal" prefix="KES" />}
                </Field>
                <Field label="Notes" error={errors.notes?.message} className="sm:col-span-2">
                    {(props) => <Textarea {...props} {...register('notes')} />}
                </Field>
            </fieldset>

            <div className="-mx-4 -mb-5 flex flex-col-reverse gap-2 border-t border-gray-950/5 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-6 sm:flex-row sm:justify-end sm:px-6 dark:border-white/10">
                <Button variant="secondary" onClick={onClose} disabled={save.isPending}>
                    Cancel
                </Button>
                <Button type="submit" loading={save.isPending}>
                    {unit ? 'Save unit' : 'Add unit'}
                </Button>
            </div>
        </form>
    );
}
