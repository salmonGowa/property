/** Date-only helpers working on "YYYY-MM-DD" strings in local time, matching how the API sends dates. */

function toIso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function fromIso(value: string): Date {
    const [year = 0, month = 1, day = 1] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

export function todayIso(): string {
    return toIso(new Date());
}

export function firstOfNextMonthIso(): string {
    const today = new Date();

    return toIso(new Date(today.getFullYear(), today.getMonth() + 1, 1));
}

/** The last day of a term of `months` starting on `start`, e.g. 2026-11-01 + 12 → 2027-10-31. Clamps to month end like Carbon's addMonthsNoOverflow. */
export function termEndIso(start: string, months: number): string {
    const date = fromIso(start);
    const targetMonth = date.getMonth() + months;
    const lastDayOfTarget = new Date(date.getFullYear(), targetMonth + 1, 0).getDate();
    const end = new Date(date.getFullYear(), targetMonth, Math.min(date.getDate(), lastDayOfTarget));
    end.setDate(end.getDate() - 1);

    return toIso(end);
}

export function isValidIsoDate(value: string): boolean {
    return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(fromIso(value).getTime());
}
