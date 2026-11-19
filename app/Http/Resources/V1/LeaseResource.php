<?php

namespace App\Http\Resources\V1;

use App\Enums\LeaseStatus;
use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\Lease;
use App\Models\User;
use App\Services\Leasing\DepositService;
use App\Services\Leasing\RentSchedule;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lease
 */
class LeaseResource extends JsonResource
{
    use FormatsEnums;

    /**
     * Leases whose fixed term ends within this many days are flagged, as in the classic back office.
     */
    public const int EXPIRY_WARNING_DAYS = 60;

    /**
     * Figures and abilities for the detail screen; set by the controller so lists stay cheap.
     *
     * @var array<string, mixed>|null
     */
    public ?array $details = null;

    /**
     * The full lease with the figures and abilities its screen needs.
     */
    public static function detailed(Lease $lease): self
    {
        $lease->refresh()->load([
            'tenant:id,name,account_number,phone',
            'unit.property.penaltyRule',
            'penaltyRule',
            'renewedFrom:id,reference',
            'renewal:id,reference,status,renewed_from_id',
            'creator:id,name',
        ])->loadCount('invoices');

        /** @var User $user */
        $user = request()->user();
        $schedule = app(RentSchedule::class);
        $penalty = $lease->effectivePenaltyRule();
        $depositHeld = $lease->status === LeaseStatus::Draft ? '0.00' : app(DepositService::class)->heldFor($lease);
        $canRenew = $user->can('renew', $lease);
        $renewFrom = ($lease->end_date ?? today())->copy()->addDay();

        $resource = new self($lease);
        $resource->details = [
            'currentRent' => Money::isPositive((string) $lease->escalation_rate)
                ? $schedule->monthlyRentOn($lease, today()->max($lease->start_date))
                : null,
            'penalty' => [
                'name' => $penalty['rule']?->name,
                'source' => $penalty['source'],
                'summary' => ucfirst($penalty['rule']?->summary() ?? 'No late payment penalties'),
            ],
            'depositHeld' => $depositHeld,
            'tenantBalance' => $lease->tenant->balance(),
            'invoicesCount' => $lease->invoices_count,
            'renewalDefaults' => $canRenew ? [
                'startDate' => $renewFrom->toDateString(),
                'endDate' => $renewFrom->copy()->addYear()->subDay()->toDateString(),
                'rentAmount' => $schedule->monthlyRentOn($lease, $renewFrom),
            ] : null,
            'can' => [
                'activate' => $user->can('activate', $lease),
                'terminate' => $user->can('terminate', $lease),
                'renew' => $canRenew,
                'changePenaltyRule' => $user->can('changePenaltyRule', $lease),
                'settleDeposit' => $user->can('settleDeposit', $lease) && Money::isPositive($depositHeld),
            ],
        ];

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isActive = $this->status === LeaseStatus::Active;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => self::enumData($this->status),
            'tenantId' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant', fn (): array => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'accountNumber' => $this->tenant->account_number,
                'phone' => $this->tenant->phone,
            ]),
            'unitId' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn (): array => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'fullCode' => $this->unit->relationLoaded('property') ? $this->unit->fullCode() : $this->unit->code,
                'type' => self::enumData($this->unit->type),
                'property' => $this->unit->relationLoaded('property') ? [
                    'id' => $this->unit->property->id,
                    'code' => $this->unit->property->code,
                    'name' => $this->unit->property->name,
                ] : null,
            ]),
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'endsSoon' => $isActive && $this->end_date !== null && $this->end_date->lte(today()->addDays(self::EXPIRY_WARNING_DAYS)),
            'daysRemaining' => $isActive && $this->end_date?->isFuture() ? (int) today()->diffInDays($this->end_date) : null,
            'billingFrequency' => self::enumData($this->billing_frequency),
            'rentAmount' => $this->rent_amount,
            'serviceCharge' => $this->service_charge,
            'depositAmount' => $this->deposit_amount,
            'chargeVat' => $this->charge_vat,
            'dueDay' => $this->due_day,
            'escalationRate' => $this->escalation_rate,
            'escalationIntervalMonths' => $this->escalation_interval_months,
            'penaltyRuleId' => $this->penalty_rule_id,
            'penaltiesExempt' => $this->penalties_exempt,
            'activatedAt' => $this->activated_at?->toIso8601String(),
            'terminatedOn' => $this->terminated_on?->toDateString(),
            'terminationReason' => $this->termination_reason,
            'renewedFrom' => $this->whenLoaded('renewedFrom', fn (): ?array => $this->renewedFrom ? ['id' => $this->renewedFrom->id, 'reference' => $this->renewedFrom->reference] : null),
            'renewal' => $this->whenLoaded('renewal', fn (): ?array => $this->renewal ? [
                'id' => $this->renewal->id,
                'reference' => $this->renewal->reference,
                'status' => self::enumData($this->renewal->status),
            ] : null),
            'notes' => $this->notes,
            'createdBy' => $this->whenLoaded('creator', fn (): ?string => $this->creator?->name),
            'details' => $this->when($this->details !== null, fn (): ?array => $this->details),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
