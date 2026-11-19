<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    use FormatsEnums;

    /**
     * Account summary for the detail screen; set by the controller so lists stay cheap.
     *
     * @var array{balance: string, overdue: string, activeLeases: int, lastPayment: ?array{amount: string, receivedOn: string}, aging: ?list<array{key: string, label: string, amount: string}>, paybill: ?string}|null
     */
    public ?array $summary = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewKyc = $request->user()->can('viewDocuments', $this->resource);

        return [
            'id' => $this->id,
            'accountNumber' => $this->account_number,
            'type' => self::enumData($this->type),
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'kraPin' => $this->kra_pin,
            // Personal identifiers are only shown in full to those allowed to see KYC data (Data Protection Act, 2019).
            'idNumber' => $canViewKyc ? $this->id_number : $this->maskedIdNumber(),
            'idNumberMasked' => ! $canViewKyc && filled($this->id_number),
            'registrationNumber' => $this->registration_number,
            'contactPerson' => $this->contact_person,
            'contactPersonPhone' => $this->contact_person_phone,
            'alternatePhone' => $this->alternate_phone,
            'postalAddress' => $this->postal_address,
            'emergencyContactName' => $this->emergency_contact_name,
            'emergencyContactPhone' => $this->emergency_contact_phone,
            'notes' => $this->notes,
            'isActive' => $this->is_active,
            'balance' => $this->when($this->resource->hasAttribute('billed_total'), fn (): string => $this->balance()),
            'activeLeasesCount' => $this->whenCounted('activeLeases'),
            'summary' => $this->when($this->summary !== null, fn (): ?array => $this->summary),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $this->when($this->summary !== null, fn (): bool => $request->user()->can('delete', $this->resource)),
                'viewDocuments' => $canViewKyc,
                'manageDocuments' => $request->user()->can('manageDocuments', $this->resource),
            ],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
