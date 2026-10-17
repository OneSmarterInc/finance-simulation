<?php

namespace App\Support\Presentation;

/**
 * What each accounting method does to the statements (walkthrough item 83).
 * For an accounting course this is the lesson itself, so the student sees it
 * beside every choice and faculty see it beside every request.
 */
final class AccountingMethodGuide
{
    private const EFFECTS = [
        'inventory_method' => [
            'fifo' => 'First in, first out: the oldest costs go to cost of goods sold first. Fixed in this course.',
        ],
        'depreciation_method' => [
            'straight_line' => 'Spreads the cost of plant and equipment evenly, so depreciation expense is the same every quarter.',
            'double_declining_balance' => 'Puts more depreciation in the early years and less later, so reported profit is lower early and higher later. Cash is unchanged.',
            'units_of_production' => 'Ties depreciation to how much the plant produces, so the expense rises and falls with output.',
        ],
        'shipping_terms' => [
            'fob_shipping_point' => 'Your firm owns purchased goods from the moment the supplier ships them, so goods in transit are yours and the note reports them.',
            'fob_destination' => 'Your firm owns purchased goods only when they arrive, so goods still in transit stay with the supplier.',
        ],
        'bad_debt_method' => [
            'percentage_of_receivables' => 'Sets the allowance for bad debts as a share of what customers owe at quarter end: a balance-sheet view.',
            'percentage_of_sales' => 'Records bad debt expense as a share of the quarter\'s sales: an income-statement view that matches the expense to the revenue that caused it.',
        ],
        'warranty_method' => [
            'percentage_of_sales' => 'Records the expected warranty cost when products are sold and builds a warranty reserve for future claims.',
            'expense_as_incurred' => 'Records warranty cost only when claims are paid, so profit looks higher at first and there is no reserve on the balance sheet.',
        ],
        'excise_tax_treatment' => [
            'cogs_inclusion' => 'Excise duty is part of cost of goods sold, so gross profit is lower. Operating income is the same either way.',
            'separate_operating_expense' => 'Excise duty is shown as an operating expense, so gross profit is higher. Operating income is the same either way.',
        ],
    ];

    public static function effect(string $method, string $value): ?string
    {
        return self::EFFECTS[$method][$value] ?? null;
    }
}
