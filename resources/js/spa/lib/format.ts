const money = new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', minimumFractionDigits: 2 });
const number = new Intl.NumberFormat('en-KE', { maximumFractionDigits: 2 });
const date = new Intl.DateTimeFormat('en-KE', { day: 'numeric', month: 'short', year: 'numeric' });

/** API amounts arrive as decimal strings, e.g. "45000.00". */
export function formatMoney(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return money.format(Number(value));
}

export function formatNumber(value: string | number | null | undefined, suffix = ''): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${number.format(Number(value))}${suffix}`;
}

export function formatDate(value: string | null | undefined): string {
    return value ? date.format(new Date(value)) : '—';
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}
