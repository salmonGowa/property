<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KenyaCounty;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\FormatsEnums;
use App\Models\PenaltyRule;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Option lists for the SPA's selects and filters, served once and cached client side.
 */
class LookupController extends Controller
{
    use FormatsEnums;

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'propertyTypes' => self::enumOptions(PropertyType::class),
                'counties' => self::enumOptions(KenyaCounty::class),
                'unitTypes' => self::enumOptions(UnitType::class),
                'unitStatuses' => self::enumOptions(UnitStatus::class),
                'propertyManagers' => User::query()
                    ->whereIn('role', [UserRole::PropertyManager, UserRole::Admin])
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'penaltyRules' => PenaltyRule::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_default']),
            ],
        ]);
    }
}
