import { zodResolver } from '@hookform/resolvers/zod';
import { Building } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { useLogin } from '@/auth/auth';
import { Button } from '@/components/ui/Button';
import { Field, Input } from '@/components/ui/Field';
import { useServerErrors } from '@/hooks/useServerErrors';
import { appName } from '@/lib/config';

const schema = z.object({
    email: z.string().trim().min(1, 'Enter your email address.').email('Enter a valid email address.'),
    password: z.string().min(1, 'Enter your password.'),
    remember: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

export function LoginPage() {
    const login = useLogin();
    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '', remember: false } });
    const onServerError = useServerErrors(setError, ['email', 'password']);

    // On success the cached user is set and <GuestOnly> redirects to where the user was heading.
    const onSubmit = handleSubmit((values) => login.mutate(values, { onError: onServerError }));

    return (
        <div className="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6 lg:px-8">
            <div className="sm:mx-auto sm:w-full sm:max-w-md">
                <span className="mx-auto flex size-12 items-center justify-center rounded-xl bg-primary-600 text-white">
                    <Building className="size-7" aria-hidden />
                </span>
                <h1 className="mt-6 text-center text-2xl font-bold tracking-tight text-gray-950 dark:text-white">Sign in to {appName}</h1>
            </div>

            <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
                <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5 rounded-2xl bg-white px-5 py-8 shadow-sm ring-1 ring-gray-950/5 sm:px-10 dark:bg-gray-900 dark:ring-white/10">
                    <Field label="Email address" error={errors.email?.message}>
                        {(props) => <Input {...props} {...register('email')} type="email" autoComplete="username" autoFocus />}
                    </Field>
                    <Field label="Password" error={errors.password?.message}>
                        {(props) => <Input {...props} {...register('password')} type="password" autoComplete="current-password" />}
                    </Field>
                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" {...register('remember')} className="size-4 rounded border-gray-300 text-primary-600 accent-primary-600" />
                        Keep me signed in
                    </label>
                    <Button type="submit" loading={login.isPending} className="w-full">
                        Sign in
                    </Button>
                </form>
            </div>
        </div>
    );
}
