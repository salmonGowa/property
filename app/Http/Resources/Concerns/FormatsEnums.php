<?php

namespace App\Http\Resources\Concerns;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Serialises backed enums as `{value, label, color?}` so the SPA never has to duplicate labels.
 */
trait FormatsEnums
{
    /**
     * @return array{value: string|int, label: string, color?: string}|null
     */
    protected static function enumData(?BackedEnum $enum): ?array
    {
        if ($enum === null) {
            return null;
        }

        $data = [
            'value' => $enum->value,
            'label' => $enum instanceof HasLabel ? (string) $enum->getLabel() : (string) $enum->value,
        ];

        if ($enum instanceof HasColor) {
            $data['color'] = (string) $enum->getColor();
        }

        return $data;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<array{value: string|int, label: string, color?: string}>
     */
    protected static function enumOptions(string $enumClass): array
    {
        return array_map(static::enumData(...), $enumClass::cases());
    }
}
