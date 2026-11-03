<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Kenyan withholding tax on payments to resident suppliers. Rates live in config/ledger.php.
 */
enum WithholdingTaxType: string implements HasLabel
{
    case None = 'none';
    case ProfessionalFees = 'professional_fees';
    case ManagementFees = 'management_fees';
    case ContractualFees = 'contractual_fees';

    public function getLabel(): string
    {
        $name = match ($this) {
            self::None => 'Not applicable (goods, exempt supplier)',
            self::ProfessionalFees => 'Professional fees',
            self::ManagementFees => 'Management fees',
            self::ContractualFees => 'Contractual fees (repairs, construction)',
        };

        return $this === self::None ? $name : "{$name} - ".rtrim(rtrim($this->rate(), '0'), '.').'%';
    }

    /**
     * Percentage withheld from the amount before VAT.
     */
    public function rate(): string
    {
        return bcadd((string) config("ledger.withholding_tax_rates.{$this->value}", '0'), '0', 2);
    }
}
