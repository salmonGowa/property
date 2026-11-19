import { ButtonLink } from '@/components/ui/Button';

function StatusPage({ code, title, message }: { code: string; title: string; message: string }) {
    return (
        <div className="flex flex-col items-center py-16 text-center sm:py-24">
            <p className="text-base font-semibold text-primary-600 dark:text-primary-400">{code}</p>
            <h1 className="mt-2 text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl dark:text-white">{title}</h1>
            <p className="mt-4 max-w-md text-gray-600 dark:text-gray-400">{message}</p>
            <ButtonLink to="/" className="mt-8">
                Back to dashboard
            </ButtonLink>
        </div>
    );
}

export function NotFoundPage() {
    return <StatusPage code="404" title="Page not found" message="We couldn't find the page you were looking for." />;
}

export function ForbiddenPage() {
    return <StatusPage code="403" title="Not allowed" message="Your role doesn't have access to this page. Ask an administrator if you need it." />;
}
