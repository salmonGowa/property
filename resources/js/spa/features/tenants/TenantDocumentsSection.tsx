import { zodResolver } from '@hookform/resolvers/zod';
import { ExternalLink, FileText, Paperclip, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { ConfirmDialog, Dialog } from '@/components/ui/Dialog';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/Feedback';
import { Field, Input, Select } from '@/components/ui/Field';
import { useLookups } from '@/hooks/useLookups';
import { useServerErrors } from '@/hooks/useServerErrors';
import { errorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { Tenant, TenantDocument } from '@/types/api';
import { useDeleteTenantDocument, useTenantDocuments, useUploadTenantDocument } from './api';

const maxFileBytes = 10 * 1024 * 1024;

const schema = z.object({
    type: z.string().min(1, 'Choose the document type.'),
    expires_on: z.string(),
    file: z
        .instanceof(FileList)
        .refine((files) => files.length === 1, 'Choose a file to upload.')
        .refine((files) => !files[0] || ['application/pdf', 'image/jpeg', 'image/png'].includes(files[0].type), 'Upload a PDF, JPG or PNG file.')
        .refine((files) => !files[0] || files[0].size <= maxFileBytes, 'The file must be 10 MB or smaller.'),
});

type FormValues = z.infer<typeof schema>;
const fields = Object.keys(schema.shape);

/** KYC documents are personal data: only roles allowed to see them get this section at all. */
export function TenantDocumentsSection({ tenant }: { tenant: Tenant }) {
    const { data: documents, isPending, error, refetch } = useTenantDocuments(tenant.id, tenant.can.viewDocuments);
    const [uploading, setUploading] = useState(false);
    const [deleting, setDeleting] = useState<TenantDocument | null>(null);
    const deleteDocument = useDeleteTenantDocument(tenant.id);
    const canManage = tenant.can.manageDocuments;

    const uploadButton = (
        <Button size="sm" onClick={() => setUploading(true)} className="w-full sm:w-auto">
            <Upload /> Upload document
        </Button>
    );

    return (
        <Card>
            <CardHeader title="KYC documents" description="Opened through your signed-in session only; files are never public." actions={canManage && uploadButton} />
            {isPending ? (
                <div className="p-4 sm:p-6">
                    <Skeleton className="h-12" />
                </div>
            ) : error ? (
                <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
            ) : documents.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="No documents"
                    description={tenant.type.value === 'company' ? 'Upload the KRA PIN certificate, CR12 and certificate of incorporation.' : 'Upload the national ID or passport and KRA PIN certificate.'}
                    action={canManage && uploadButton}
                />
            ) : (
                <ul role="list" className="divide-y divide-gray-950/5 dark:divide-white/10">
                    {documents.map((document) => (
                        <li key={document.id} className="flex items-center gap-3 px-4 py-3 sm:px-6">
                            <Paperclip className="size-4 shrink-0 text-gray-400" aria-hidden />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-gray-950 dark:text-white">{document.type.label}</span>
                                    {document.isExpired && <Badge color="danger">Expired {formatDate(document.expiresOn)}</Badge>}
                                </div>
                                <p className="truncate text-sm text-gray-500 dark:text-gray-400">
                                    {document.originalName}
                                    {document.expiresOn && !document.isExpired && ` · expires ${formatDate(document.expiresOn)}`}
                                    {document.uploadedBy && ` · ${document.uploadedBy}`}
                                </p>
                            </div>
                            <a
                                href={document.fileUrl}
                                target="_blank"
                                rel="noopener"
                                className="inline-flex size-8 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5"
                                aria-label={`Open ${document.originalName}`}
                            >
                                <ExternalLink className="size-4" />
                            </a>
                            {canManage && (
                                <Button variant="ghost" size="icon-sm" onClick={() => setDeleting(document)} aria-label={`Delete ${document.originalName}`} className="text-red-600 dark:text-red-400">
                                    <Trash2 />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={uploading} onClose={() => setUploading(false)} title="Upload KYC document" description="PDF, JPG or PNG, up to 10 MB.">
                {uploading && <UploadForm tenantId={tenant.id} onClose={() => setUploading(false)} />}
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                title="Delete this document?"
                message={`${deleting?.type.label ?? ''} (${deleting?.originalName ?? ''}) will be removed permanently.`}
                loading={deleteDocument.isPending}
                onClose={() => setDeleting(null)}
                onConfirm={() =>
                    deleting &&
                    deleteDocument.mutate(deleting.id, {
                        onSuccess: () => {
                            toast.success('Document deleted.');
                            setDeleting(null);
                        },
                        onError: (deleteError) => toast.error(errorMessage(deleteError)),
                    })
                }
            />
        </Card>
    );
}

function UploadForm({ tenantId, onClose }: { tenantId: number; onClose: () => void }) {
    const { data: lookups } = useLookups();
    const upload = useUploadTenantDocument(tenantId);
    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { type: '', expires_on: '' } });
    const onServerError = useServerErrors(setError, fields);

    const onSubmit = handleSubmit((values) => {
        const file = values.file[0];
        if (!file) {
            return;
        }

        upload.mutate(
            { type: values.type, expires_on: values.expires_on || null, file },
            {
                onSuccess: () => {
                    toast.success('Document uploaded.');
                    onClose();
                },
                onError: onServerError,
            },
        );
    });

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
            <Field label="Document type" required error={errors.type?.message}>
                {(props) => (
                    <Select {...props} {...register('type')}>
                        <option value="">Choose…</option>
                        {lookups?.kycDocumentTypes.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field label="Expires on" error={errors.expires_on?.message} hint="For permits and passports; leave empty if it doesn't expire.">
                {(props) => <Input {...props} {...register('expires_on')} type="date" />}
            </Field>
            <Field label="File" required error={errors.file?.message}>
                {(props) => (
                    <input
                        {...props}
                        {...register('file')}
                        type="file"
                        accept="application/pdf,image/jpeg,image/png"
                        className="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:text-gray-300 dark:file:bg-primary-500/10 dark:file:text-primary-300"
                    />
                )}
            </Field>
            <div className="-mx-4 -mb-5 flex flex-col-reverse gap-2 border-t border-gray-950/5 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-6 sm:flex-row sm:justify-end sm:px-6 dark:border-white/10">
                <Button variant="secondary" onClick={onClose} disabled={upload.isPending}>
                    Cancel
                </Button>
                <Button type="submit" loading={upload.isPending}>
                    Upload
                </Button>
            </div>
        </form>
    );
}
