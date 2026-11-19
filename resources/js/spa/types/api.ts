export type BadgeColor = 'primary' | 'success' | 'info' | 'warning' | 'danger' | 'gray';

export interface EnumValue<T extends string = string> {
    value: T;
    label: string;
    color?: BadgeColor;
}

export interface Resource<T> {
    data: T;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
}

export interface Permission {
    viewAny: boolean;
    create: boolean;
}

export type PermissionKey =
    | 'properties'
    | 'units'
    | 'tenants'
    | 'leases'
    | 'workOrders'
    | 'maintenanceSchedules'
    | 'invoices'
    | 'payments'
    | 'mpesaTransactions'
    | 'creditNotes'
    | 'penaltyRules'
    | 'repaymentPlans'
    | 'vendors'
    | 'purchaseOrders'
    | 'supplierBills'
    | 'supplierPayments'
    | 'lenders'
    | 'loans'
    | 'accounts'
    | 'journalEntries'
    | 'fiscalPeriods'
    | 'users'
    | 'auditLogs';

export interface CurrentUser {
    id: number;
    name: string;
    email: string;
    role: EnumValue | null;
    permissions: Record<PermissionKey, Permission>;
}

export interface RecordAbilities {
    update: boolean;
    delete: boolean;
}

export interface NamedRef {
    id: number;
    name: string;
}

export interface PropertyStats {
    units: number;
    occupied: number;
    vacant: number;
    occupancyRate: number;
    monthlyRentRoll: string;
}

export interface Property {
    id: number;
    code: string;
    name: string;
    type: EnumValue;
    county: EnumValue;
    town: string | null;
    location: string;
    address: string | null;
    lrNumber: string | null;
    totalAreaSqft: string | null;
    yearBuilt: number | null;
    description: string | null;
    isActive: boolean;
    photoUrl: string | null;
    managerId: number | null;
    manager?: NamedRef | null;
    penaltyRuleId: number | null;
    penaltyRule?: NamedRef | null;
    unitsCount?: number;
    occupancyRate?: number;
    stats?: PropertyStats;
    can: RecordAbilities;
    createdAt: string | null;
    updatedAt: string | null;
}

export interface Unit {
    id: number;
    propertyId: number;
    property?: { id: number; code: string; name: string };
    code: string;
    type: EnumValue;
    isCommercial: boolean;
    status: EnumValue<'vacant' | 'reserved' | 'occupied' | 'maintenance'>;
    floor: string | null;
    bedrooms: number | null;
    bathrooms: number | null;
    areaSqft: string | null;
    marketRent: string;
    serviceCharge: string;
    notes: string | null;
    activeLease?: {
        id: number;
        reference: string;
        endDate: string | null;
        tenant: NamedRef | null;
    } | null;
    can: RecordAbilities;
}


export interface Lookups {
    propertyTypes: EnumValue[];
    counties: EnumValue[];
    unitTypes: EnumValue[];
    unitStatuses: EnumValue[];
    tenantTypes: EnumValue<'individual' | 'company'>[];
    kycDocumentTypes: EnumValue[];
    leaseStatuses: EnumValue<LeaseStatus>[];
    billingFrequencies: EnumValue[];
    refundMethods: EnumValue[];
    vatRate: string;
    propertyManagers: NamedRef[];
    penaltyRules: (NamedRef & { is_default: boolean })[];
}

export interface TenantSummary {
    balance: string;
    overdue: string;
    activeLeases: number;
    lastPayment: { amount: string; receivedOn: string } | null;
    aging: { key: string; label: string; amount: string }[] | null;
    paybill: string | null;
}

export interface Tenant {
    id: number;
    accountNumber: string;
    type: EnumValue<'individual' | 'company'>;
    name: string;
    phone: string;
    email: string | null;
    kraPin: string | null;
    idNumber: string | null;
    idNumberMasked: boolean;
    registrationNumber: string | null;
    contactPerson: string | null;
    contactPersonPhone: string | null;
    alternatePhone: string | null;
    postalAddress: string | null;
    emergencyContactName: string | null;
    emergencyContactPhone: string | null;
    notes: string | null;
    isActive: boolean;
    balance?: string;
    activeLeasesCount?: number;
    summary?: TenantSummary;
    can: { update: boolean; delete?: boolean; viewDocuments: boolean; manageDocuments: boolean };
    createdAt: string | null;
}

export interface TenantDocument {
    id: number;
    type: EnumValue;
    originalName: string;
    expiresOn: string | null;
    isExpired: boolean;
    uploadedBy?: string | null;
    fileUrl: string;
    createdAt: string;
}

export type LeaseStatus = 'draft' | 'active' | 'terminated';

export interface LeaseDetails {
    currentRent: string | null;
    penalty: { name: string | null; source: string; summary: string };
    depositHeld: string;
    tenantBalance: string;
    invoicesCount: number;
    renewalDefaults: { startDate: string; endDate: string; rentAmount: string } | null;
    can: { activate: boolean; terminate: boolean; renew: boolean; changePenaltyRule: boolean; settleDeposit: boolean };
}

export interface Lease {
    id: number;
    reference: string;
    status: EnumValue<LeaseStatus>;
    tenantId: number;
    tenant?: { id: number; name: string; accountNumber: string; phone: string };
    unitId: number;
    unit?: { id: number; code: string; fullCode: string; type: EnumValue; property: { id: number; code: string; name: string } | null };
    startDate: string;
    endDate: string | null;
    endsSoon: boolean;
    daysRemaining: number | null;
    billingFrequency: EnumValue;
    rentAmount: string;
    serviceCharge: string;
    depositAmount: string;
    chargeVat: boolean;
    dueDay: number;
    escalationRate: string;
    escalationIntervalMonths: number;
    penaltyRuleId: number | null;
    penaltiesExempt: boolean;
    activatedAt: string | null;
    terminatedOn: string | null;
    terminationReason: string | null;
    renewedFrom?: { id: number; reference: string } | null;
    renewal?: { id: number; reference: string; status: EnumValue<LeaseStatus> } | null;
    notes: string | null;
    createdBy?: string | null;
    details?: LeaseDetails;
    can: RecordAbilities;
}

export interface LettableUnit {
    id: number;
    code: string;
    fullCode: string;
    type: EnumValue;
    isCommercial: boolean;
    status: EnumValue;
    marketRent: string;
    serviceCharge: string;
    property: { id: number; code: string; name: string };
}
