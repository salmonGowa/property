import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { Badge, occupancyColor } from '@/components/ui/Badge';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Detail, Stat } from '@/components/ui/DescriptionList';
import { ConfirmDialog } from '@/components/ui/Dialog';
import { ErrorState, Skeleton } from '@/components/ui/Feedback';
import { PageHeader } from '@/components/ui/PageHeader';
import { UnitsSection } from '@/features/units/UnitsSection';
import { ApiError, errorMessage } from '@/lib/api';
import { formatMoney, formatNumber } from '@/lib/format';
import { useDeleteProperty, useProperty } from './api';
import { PropertyPhoto } from './PropertyPhoto';

export function PropertyPage() {
    const id = Number(useParams().propertyId);
    const navigate = useNavigate();
    const { data: property, isPending, error, refetch } = useProperty(id);
    const deleteProperty = useDeleteProperty();
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    if (isPending) {
        return (
            <div className="flex flex-col gap-6">
                <Skeleton className="h-9 w-64" />
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    {Array.from({ length: 5 }, (_, index) => (
                        <Skeleton key={index} className="h-24" />
                    ))}
                </div>
                <Skeleton className="h-64" />
            </div>
        );
    }

    if (error) {
        const message = error instanceof ApiError && error.status === 404 ? 'This property does not exist or was deleted.' : errorMessage(error);

        return <ErrorState message={message} onRetry={error instanceof ApiError && error.status === 404 ? undefined : () => refetch()} />;
    }

    const stats = property.stats;

    return (
        <>
            <PageHeader
                back={{ to: '/properties', label: 'Properties' }}
                title={property.name}
                meta={
                    <>
                        <Badge>{property.code}</Badge>
                        <Badge color="primary">{property.type.label}</Badge>
                        {!property.isActive && <Badge color="warning">Inactive</Badge>}
                    </>
                }
                actions={
                    <>
                        {property.can.update && (
                            <ButtonLink to={`/properties/${property.id}/edit`} variant="secondary" className="flex-1 sm:flex-none">
                                <Pencil /> Edit
                            </ButtonLink>
                        )}
                        {property.can.delete && (
                            <Button variant="secondary" className="flex-1 text-red-600 sm:flex-none dark:text-red-400" onClick={() => setConfirmingDelete(true)}>
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {stats && (
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
                        <Stat label="Units" value={stats.units} />
                        <Stat label="Occupied" value={stats.occupied} />
                        <Stat label="Vacant" value={stats.vacant} />
                        <Stat label="Occupancy" value={stats.units ? `${stats.occupancyRate}%` : '—'}>
                            {stats.units > 0 && <Badge color={occupancyColor(stats.occupancyRate)}>{stats.occupancyRate >= 90 ? 'Healthy' : stats.occupancyRate >= 70 ? 'Watch' : 'Low'}</Badge>}
                        </Stat>
                        <div className="col-span-2 sm:col-span-2 lg:col-span-1">
                            <Stat label="Monthly rent roll" value={<span className="text-lg sm:text-xl">{formatMoney(stats.monthlyRentRoll)}</span>} />
                        </div>
                    </dl>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader title="Details" />
                        <CardBody>
                            <dl className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                                <Detail label="Location">{property.location}</Detail>
                                <Detail label="Property manager">{property.manager?.name ?? 'Not assigned'}</Detail>
                                <Detail label="Address">{property.address}</Detail>
                                <Detail label="L.R. number">{property.lrNumber}</Detail>
                                <Detail label="Total lettable area">{property.totalAreaSqft ? formatNumber(property.totalAreaSqft, ' sq ft') : null}</Detail>
                                <Detail label="Year built">{property.yearBuilt}</Detail>
                                <Detail label="Late payment penalty rule">{property.penaltyRule?.name ?? 'Company default'}</Detail>
                                <Detail label="Description" wide>
                                    {property.description}
                                </Detail>
                            </dl>
                        </CardBody>
                    </Card>
                    <div className="order-first lg:order-none">
                        <PropertyPhoto property={property} />
                    </div>
                </div>

                <UnitsSection property={property} />
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={`Delete ${property.name}?`}
                message="This permanently removes the property. Properties that have units can only be deactivated."
                loading={deleteProperty.isPending}
                onClose={() => setConfirmingDelete(false)}
                onConfirm={() =>
                    deleteProperty.mutate(property.id, {
                        onSuccess: () => {
                            toast.success(`${property.name} deleted.`);
                            navigate('/properties', { replace: true });
                        },
                        onError: (deleteError) => toast.error(errorMessage(deleteError)),
                    })
                }
            />
        </>
    );
}
