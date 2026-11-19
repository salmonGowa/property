import {
    Banknote,
    BarChart3,
    BookOpen,
    Building2,
    CalendarClock,
    CalendarRange,
    DoorOpen,
    FileMinus,
    FileSignature,
    FileText,
    Gavel,
    HandCoins,
    Landmark,
    LayoutDashboard,
    NotebookPen,
    PiggyBank,
    Receipt,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Smartphone,
    Truck,
    UserCog,
    Users,
    Wallet,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import type { PermissionKey } from '@/types/api';

export interface NavItem {
    label: string;
    icon: LucideIcon;
    permission?: PermissionKey;
    /** SPA route, for screens already rebuilt in React. */
    to?: string;
    /** Filament path under /admin, for screens still served by the classic back office. */
    classic?: string;
}

export interface NavGroup {
    label: string | null;
    items: NavItem[];
}

/** Mirrors the Filament navigation groups so staff find things in the same place during the migration. */
export const navigation: NavGroup[] = [
    {
        label: null,
        items: [{ label: 'Dashboard', icon: LayoutDashboard, to: '/' }],
    },
    {
        label: 'Property Management',
        items: [
            { label: 'Properties', icon: Building2, permission: 'properties', to: '/properties' },
            { label: 'Units', icon: DoorOpen, permission: 'units', classic: 'units' },
            { label: 'Tenants', icon: Users, permission: 'tenants', to: '/tenants' },
            { label: 'Leases', icon: FileSignature, permission: 'leases', to: '/leases' },
        ],
    },
    {
        label: 'Maintenance',
        items: [
            { label: 'Work orders', icon: Wrench, permission: 'workOrders', classic: 'work-orders' },
            { label: 'Maintenance schedules', icon: CalendarClock, permission: 'maintenanceSchedules', classic: 'maintenance-schedules' },
        ],
    },
    {
        label: 'Billing & Collections',
        items: [
            { label: 'Invoices', icon: Receipt, permission: 'invoices', classic: 'invoices' },
            { label: 'Payments', icon: Wallet, permission: 'payments', classic: 'payments' },
            { label: 'M-Pesa transactions', icon: Smartphone, permission: 'mpesaTransactions', classic: 'mpesa-transactions' },
            { label: 'Credit notes', icon: FileMinus, permission: 'creditNotes', classic: 'credit-notes' },
            { label: 'Penalty rules', icon: Gavel, permission: 'penaltyRules', classic: 'penalty-rules' },
            { label: 'Repayment plans', icon: HandCoins, permission: 'repaymentPlans', classic: 'repayment-plans' },
            { label: 'Arrears aging', icon: BarChart3, permission: 'invoices', classic: 'arrears-aging-report' },
        ],
    },
    {
        label: 'Procurement & Payables',
        items: [
            { label: 'Vendors', icon: Truck, permission: 'vendors', classic: 'vendors' },
            { label: 'Purchase orders', icon: ShoppingCart, permission: 'purchaseOrders', classic: 'purchase-orders' },
            { label: 'Supplier bills', icon: FileText, permission: 'supplierBills', classic: 'supplier-bills' },
            { label: 'Supplier payments', icon: Banknote, permission: 'supplierPayments', classic: 'supplier-payments' },
            { label: 'Payables aging', icon: BarChart3, permission: 'vendors', classic: 'payables-aging-report' },
        ],
    },
    {
        label: 'Debt & Financing',
        items: [
            { label: 'Lenders', icon: Landmark, permission: 'lenders', classic: 'lenders' },
            { label: 'Loans', icon: PiggyBank, permission: 'loans', classic: 'loans' },
            { label: 'Debt portfolio', icon: BarChart3, permission: 'loans', classic: 'debt-portfolio-report' },
            { label: 'Bad debt provisions', icon: ScrollText, permission: 'accounts', classic: 'bad-debt-provisions' },
        ],
    },
    {
        label: 'Accounting',
        items: [
            { label: 'Chart of accounts', icon: BookOpen, permission: 'accounts', classic: 'accounts' },
            { label: 'Journal entries', icon: NotebookPen, permission: 'journalEntries', classic: 'journal-entries' },
            { label: 'Fiscal periods', icon: CalendarRange, permission: 'fiscalPeriods', classic: 'fiscal-periods' },
            { label: 'Trial balance', icon: BarChart3, permission: 'accounts', classic: 'trial-balance-report' },
        ],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Users', icon: UserCog, permission: 'users', classic: 'users' },
            { label: 'Audit log', icon: ShieldCheck, permission: 'auditLogs', classic: 'audit-logs' },
        ],
    },
];
