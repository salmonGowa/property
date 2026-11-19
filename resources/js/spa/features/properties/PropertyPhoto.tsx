import { ImagePlus, Trash2 } from 'lucide-react';
import { useRef } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { errorMessage } from '@/lib/api';
import type { Property } from '@/types/api';
import { useRemovePropertyPhoto, useUploadPropertyPhoto } from './api';
import { PropertyThumb } from './PropertyThumb';

const maxBytes = 4 * 1024 * 1024;

export function PropertyPhoto({ property }: { property: Property }) {
    const input = useRef<HTMLInputElement>(null);
    const upload = useUploadPropertyPhoto(property.id);
    const remove = useRemovePropertyPhoto(property.id);

    const onFile = (file: File | undefined) => {
        if (!file) {
            return;
        }

        if (file.size > maxBytes) {
            toast.error('Choose a photo under 4 MB.');

            return;
        }

        upload.mutate(file, {
            onSuccess: () => toast.success('Photo updated.'),
            onError: (error) => toast.error(errorMessage(error)),
        });
    };

    return (
        <Card className="overflow-hidden">
            <PropertyThumb url={property.photoUrl} className="aspect-[4/3] w-full rounded-none" />
            {property.can.update && (
                <div className="flex gap-2 p-3">
                    <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => onFile(event.target.files?.[0])} tabIndex={-1} />
                    <Button variant="secondary" size="sm" className="flex-1" loading={upload.isPending} onClick={() => input.current?.click()}>
                        <ImagePlus /> {property.photoUrl ? 'Replace photo' : 'Add photo'}
                    </Button>
                    {property.photoUrl && (
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Remove photo"
                            className="text-red-600 dark:text-red-400"
                            loading={remove.isPending}
                            onClick={() => remove.mutate(undefined, { onSuccess: () => toast.success('Photo removed.'), onError: (error) => toast.error(errorMessage(error)) })}
                        >
                            {!remove.isPending && <Trash2 />}
                        </Button>
                    )}
                </div>
            )}
        </Card>
    );
}
