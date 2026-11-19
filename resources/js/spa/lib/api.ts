/**
 * Thin fetch wrapper for the /api/v1 endpoints.
 *
 * Authentication is Sanctum's cookie session: the browser sends the session cookie and we echo the
 * XSRF-TOKEN cookie back in the X-XSRF-TOKEN header on every state-changing request.
 */

export type ValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: ValidationErrors = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }

    get isValidation(): boolean {
        return this.status === 422;
    }
}

type QueryValue = string | number | boolean | null | undefined;

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
    query?: Record<string, QueryValue>;
    signal?: AbortSignal;
}

let unauthenticatedHandler: (() => void) | null = null;

/** Called whenever the API answers 401, so the app can drop the cached user and show the login page. */
export function onUnauthenticated(handler: () => void): void {
    unauthenticatedHandler = handler;
}

function readXsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

export async function ensureCsrfCookie(): Promise<void> {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
}

export async function api<T>(path: string, options: RequestOptions = {}, isRetry = false): Promise<T> {
    const { method = 'GET', body, query, signal } = options;
    const url = new URL(`/api/v1${path}`, window.location.origin);

    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, String(value));
        }
    }

    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const isFormData = body instanceof FormData;

    if (body !== undefined && !isFormData) {
        headers['Content-Type'] = 'application/json';
    }

    if (method !== 'GET') {
        if (readXsrfToken() === null) {
            await ensureCsrfCookie();
        }

        const token = readXsrfToken();
        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    const response = await fetch(url, {
        method,
        headers,
        signal,
        credentials: 'same-origin',
        body: body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
    });

    // 419: the CSRF token expired (e.g. the tab sat idle). Refresh it once and retry.
    if (response.status === 419 && !isRetry) {
        await ensureCsrfCookie();

        return api<T>(path, options, true);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        const data = (payload ?? {}) as { message?: string; errors?: ValidationErrors };

        if (response.status === 401) {
            unauthenticatedHandler?.();
        }

        throw new ApiError(response.status, data.message || response.statusText || 'Request failed', data.errors ?? {});
    }

    return payload as T;
}

/** First validation message for a field, or the general message for any other error. */
export function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        const first = Object.values(error.errors)[0]?.[0];

        return first ?? error.message;
    }

    return error instanceof Error ? error.message : 'Something went wrong.';
}
