<?php

namespace App\Services\Engines\CorporateFinance;

final class DecisionAdapter
{
    /**
     * @param  array<string, array<string, mixed>>  $decisions
     * @return array<string, array<string, mixed>>
     */
    public function adapt(array $decisions): array
    {
        $adapted = $decisions;
        $notes = $decisions['notes_elections'] ?? [];

        $adapted['notes_elections'] = array_merge([
            'fob_terms' => $this->shippingTerms($notes['shipping_terms'] ?? 'fob_shipping_point'),
            'inv_method' => $this->inventoryMethod($notes['inventory_method'] ?? 'fifo'),
            'depr_method' => $this->depreciationMethod($notes['depreciation_method'] ?? 'straight_line'),
            'life_mfg' => 10,
            'life_tech' => 5,
            'life_dc' => 20,
            'cap_threshold' => '25000',
            'overhead_rate' => 0.75,
            'carry_cost_treat' => 'Period Cost (Expense)',
            'sl_class' => 'Operating Expense',
            'proc_terms' => $this->shippingTerms($notes['shipping_terms'] ?? 'fob_shipping_point'),
            'rebate_method' => 'Most Likely Estimate',
            'allowance_pct' => 0.02,
            'nrv_assess' => 'Yes',
            'nrv_writedown' => 0,
            'carry_cap' => 0,
            'warranty_rate' => 0.01,
        ], $notes);

        return $adapted;
    }

    private function shippingTerms(mixed $value): string
    {
        return $value === 'fob_destination' ? 'FOB Destination' : 'FOB Shipping Point';
    }

    private function inventoryMethod(mixed $value): string
    {
        return match ($value) {
            'lifo' => 'LIFO',
            'weighted_average' => 'Weighted Average',
            default => 'FIFO',
        };
    }

    private function depreciationMethod(mixed $value): string
    {
        return $value === 'double_declining_balance' ? 'Double Declining Balance' : 'Straight-Line';
    }
}
