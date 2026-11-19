import { useCallback } from 'react';
import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { toast } from 'sonner';
import { ApiError, errorMessage } from '@/lib/api';

/**
 * Maps a 422 response onto the form's fields (API field names match form field names);
 * anything else becomes a toast.
 */
export function useServerErrors<T extends FieldValues>(setError: UseFormSetError<T>, fields: readonly string[]) {
    return useCallback(
        (error: unknown) => {
            if (error instanceof ApiError && error.isValidation) {
                let mappedAny = false;

                for (const [field, messages] of Object.entries(error.errors)) {
                    if (fields.includes(field) && messages[0]) {
                        setError(field as Path<T>, { type: 'server', message: messages[0] }, { shouldFocus: !mappedAny });
                        mappedAny = true;
                    }
                }

                if (mappedAny) {
                    return;
                }
            }

            toast.error(errorMessage(error));
        },
        [setError, fields],
    );
}
