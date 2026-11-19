import { Building, User } from 'lucide-react';
import { useWatch, type Control, type FieldErrors, type UseFormRegister } from 'react-hook-form';
import { z } from 'zod';
import { Field, Input } from '@/components/ui/Field';
import type { Tenant } from '@/types/api';
import type { TenantPayload } from './api';

/** Same patterns the API enforces, so most mistakes are caught before submitting. */
const kenyanMobile = /^(\+?254|0)\s?[17]\d{2}\s?\d{3}\s?\d{3}$/;
const kraPin = /^[AP]\d{9}[A-Z]$/i;

export const tenantSchema = z.object({
    type: z.enum(['individual', 'company']),
    name: z.string().trim().min(1, 'Enter the tenant’s name.').max(255),
    phone: z.string().trim().min(1, 'Enter a phone number.').regex(kenyanMobile, 'Enter a Kenyan mobile number, e.g. 0712 345 678 or +254 712 345 678.'),
    email: z.union([z.literal(''), z.string().trim().email('Enter a valid email address.')]),
    kra_pin: z.union([z.literal(''), z.string().trim().regex(kraPin, 'A KRA PIN is a letter, nine digits and a letter, e.g. A001234567Z.')]),
    id_number: z.string().trim().max(20),
    registration_number: z.string().trim().max(50),
    contact_person: z.string().trim().max(255),
    contact_person_phone: z.string().trim().max(20),
    alternate_phone: z.string().trim().max(20),
    postal_address: z.string().trim().max(255),
    emergency_contact_name: z.string().trim().max(255),
    emergency_contact_phone: z.string().trim().max(20),
    notes: z.string().max(5000),
    is_active: z.boolean(),
});

export type TenantFormValues = z.infer<typeof tenantSchema>;
export const tenantFields = Object.keys(tenantSchema.shape);

export function toTenantFormValues(tenant?: Tenant): TenantFormValues {
    return {
        type: tenant?.type.value ?? 'individual',
        name: tenant?.name ?? '',
        phone: tenant?.phone ?? '',
        email: tenant?.email ?? '',
        kra_pin: tenant?.kraPin ?? '',
        // A masked ID (shown to roles without KYC access) must never be posted back as the real value.
        id_number: tenant?.idNumberMasked ? '' : (tenant?.idNumber ?? ''),
        registration_number: tenant?.registrationNumber ?? '',
        contact_person: tenant?.contactPerson ?? '',
        contact_person_phone: tenant?.contactPersonPhone ?? '',
        alternate_phone: tenant?.alternatePhone ?? '',
        postal_address: tenant?.postalAddress ?? '',
        emergency_contact_name: tenant?.emergencyContactName ?? '',
        emergency_contact_phone: tenant?.emergencyContactPhone ?? '',
        notes: tenant?.notes ?? '',
        is_active: tenant?.isActive ?? true,
    };
}

export function toTenantPayload(values: TenantFormValues, tenant?: Tenant): TenantPayload {
    const optional = (value: string) => (value.trim() === '' ? null : value.trim());
    const isCompany = values.type === 'company';

    return {
        type: values.type,
        name: values.name.trim(),
        phone: values.phone.trim(),
        email: optional(values.email),
        kra_pin: optional(values.kra_pin)?.toUpperCase() ?? null,
        // Leave the stored ID untouched when the user could only see it masked and did not type a new one.
        ...(tenant?.idNumberMasked && values.id_number.trim() === '' ? {} : { id_number: isCompany ? null : optional(values.id_number) }),
        registration_number: isCompany ? optional(values.registration_number) : null,
        contact_person: isCompany ? optional(values.contact_person) : null,
        contact_person_phone: optional(values.contact_person_phone),
        alternate_phone: optional(values.alternate_phone),
        postal_address: optional(values.postal_address),
        emergency_contact_name: optional(values.emergency_contact_name),
        emergency_contact_phone: optional(values.emergency_contact_phone),
        notes: optional(values.notes),
        is_active: values.is_active,
    };
}

interface IdentityFieldsProps {
    register: UseFormRegister<TenantFormValues>;
    control: Control<TenantFormValues>;
    errors: FieldErrors<TenantFormValues>;
    maskedIdNumber?: string | null;
    autoFocus?: boolean;
}

const typeOptions = [
    { value: 'individual', label: 'Individual', icon: User },
    { value: 'company', label: 'Company', icon: Building },
] as const;

/** The minimum needed to register a tenant: who they are and how to reach them. */
export function TenantIdentityFields({ register, control, errors, maskedIdNumber, autoFocus }: IdentityFieldsProps) {
    const type = useWatch({ control, name: 'type' });
    const isCompany = type === 'company';

    return (
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <fieldset className="sm:col-span-2">
                <legend className="text-sm/6 font-medium text-gray-900 dark:text-gray-100">Tenant type</legend>
                <div className="mt-1.5 grid grid-cols-2 gap-3">
                    {typeOptions.map(({ value, label, icon: Icon }) => (
                        <label
                            key={value}
                            className="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium ring-1 ring-gray-300 ring-inset has-checked:bg-primary-50 has-checked:text-primary-700 has-checked:ring-2 has-checked:ring-primary-600 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary-600 dark:ring-white/10 dark:has-checked:bg-primary-500/10 dark:has-checked:text-primary-300 dark:has-checked:ring-primary-500"
                        >
                            <input type="radio" value={value} {...register('type')} className="sr-only" />
                            <Icon className="size-4" aria-hidden />
                            {label}
                        </label>
                    ))}
                </div>
            </fieldset>
            <Field label={isCompany ? 'Company name' : 'Full name'} required error={errors.name?.message} className="sm:col-span-2">
                {(props) => <Input {...props} {...register('name')} autoComplete="off" autoFocus={autoFocus} />}
            </Field>
            <Field label="Phone" required error={errors.phone?.message}>
                {(props) => <Input {...props} {...register('phone')} type="tel" inputMode="tel" placeholder="0712 345 678" autoComplete="off" />}
            </Field>
            <Field label="Email" error={errors.email?.message}>
                {(props) => <Input {...props} {...register('email')} type="email" inputMode="email" autoComplete="off" />}
            </Field>
            <Field label="KRA PIN" error={errors.kra_pin?.message} hint="Needed on invoices for eTIMS and withholding tax.">
                {(props) => <Input {...props} {...register('kra_pin')} placeholder={isCompany ? 'P051234567X' : 'A001234567Z'} autoComplete="off" className="uppercase" maxLength={11} />}
            </Field>
            {isCompany ? (
                <>
                    <Field label="Registration number" error={errors.registration_number?.message}>
                        {(props) => <Input {...props} {...register('registration_number')} placeholder="PVT-AB1234" autoComplete="off" />}
                    </Field>
                    <Field label="Contact person" error={errors.contact_person?.message}>
                        {(props) => <Input {...props} {...register('contact_person')} autoComplete="off" />}
                    </Field>
                </>
            ) : (
                <Field
                    label="National ID / passport number"
                    error={errors.id_number?.message}
                    hint={maskedIdNumber ? `On file: ${maskedIdNumber}. Leave blank to keep it.` : 'Stored encrypted.'}
                >
                    {(props) => <Input {...props} {...register('id_number')} autoComplete="off" />}
                </Field>
            )}
        </div>
    );
}
