<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

class ManufacturingCFEngineExecutedLegacyParityTest extends TestCase
{
    private const REBUILT_ONLY_OVERDRAFT_FIELDS = [
        'bs_bank_overdraft' => 0.0,
        'cf_overdraft_chg' => 0.0,
        'n5_overdraft' => 0.0,
        'n5_shortfall_indicator' => 0.0,
    ];

    private const BUCKETS = [
        'exact parity' => [
            'bs_def_rev', 'bs_dtl', 'bs_excise', 'bs_inventory', 'bs_lease', 'bs_mkt', 'bs_prepaid', 'bs_rou', 'bs_sl_accrual', 'bs_wages', 'bs_warranty_res',
            'cf_ap_chg', 'cf_borrow_proc', 'cf_capex_dc', 'cf_capex_mfg', 'cf_capex_tech', 'cf_def_rev_chg', 'cf_disposal', 'cf_equity', 'cf_excise_chg', 'cf_lifo', 'cf_prepaid_chg', 'cf_rou', 'cf_warranty_chg',
            'is_carry', 'is_disposal', 'is_end_inv', 'is_inspection', 'is_labor', 'is_lifo_adj', 'is_marketing', 'is_p1_rev', 'is_p2_rev', 'is_raw_mat', 'is_rebates', 'is_returns', 'is_sl_penalties', 'is_warranty',
            'n2_r1_p1', 'n2_r1_p2', 'n2_r2_p1', 'n2_r2_p2', 'n2_r3_p1', 'n2_r3_p2', 'n2_rebate_added', 'n2_rebate_open', 'n2_rebate_paid',
            'n3_fg', 'n3_in_transit', 'n3_lifo_chg', 'n3_lifo_res', 'n3_raw_mat', 'n3_wip',
            'n4_disposal_gain', 'n4_disposal_nbv', 'n4_disposal_proc', 'n4_util_rate',
            'n5_auto_draw', 'n5_draw_rate', 'n5_limit', 'n5_rate',
            'n6_exc_acc', 'n6_exc_close', 'n6_exc_open', 'n6_exc_paid', 'n6_lease', 'n6_po_fob', 'n6_sl_close', 'n6_sl_exp', 'n6_sl_open', 'n6_sl_paid', 'n6_supplier', 'n6_war_close', 'n6_war_exp', 'n6_war_open', 'n6_war_paid',
        ],
        'rounding difference' => [],
        'opening-contract rewrite' => ['bs_cash', 'bs_common', 'bs_curr_ltd', 'bs_curr_ni', 'bs_ltd', 'bs_retained', 'cf_beg_cash', 'cf_borrow_repay', 'cf_cash_interest', 'cf_cash_tax', 'cf_lease_pmt', 'cf_net_income', 'is_interest', 'n5_balance', 'n5_cov_cash', 'n5_cov_curr', 'n5_cov_de', 'n5_cov_interest', 'n5_int_recon', 'n5_mat_beyond', 'n5_mat_y1', 'n5_mat_y2'],
        'working-capital bug fix' => ['bs_ap', 'bs_ar', 'cf_accrued_chg', 'cf_ar_chg', 'cf_inv_chg'],
        'Technology/PPE correction' => ['bs_accum_depr', 'bs_ppe_gross', 'bs_tech_net', 'cf_depr', 'n4_cwip', 'n4_dc_depr', 'n4_dc_gross', 'n4_dc_net', 'n4_mfg_depr', 'n4_mfg_gross', 'n4_mfg_net', 'n4_tech_depr', 'n4_tech_gross', 'n4_tech_net'],
        'approved accounting-classification change' => ['is_admin', 'is_freight', 'is_other', 'is_overhead', 'n3_overhead', 'n5_cov_margin'],
        'approved downstream tax consequence' => ['is_tax'],
        // The economic excise amount is unchanged; cogs_inclusion moves it from is_excise to is_excise_cogs.
        'approved excise presentation' => ['is_excise'],
        'unexplained difference' => [],
    ];

    public function test_executed_legacy_output_and_every_bucket_are_frozen(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/Engines/CorporateFinance/manufacturing_cf_legacy_executed_output.json')), true, flags: JSON_THROW_ON_ERROR);
        $result = $this->calculateHistoricalFixture();
        $fixtureBuckets = $fixture['bucket_membership'];

        foreach (self::BUCKETS as $bucket => $fields) {
            sort($fields);
            $actual = $fixtureBuckets[$bucket] ?? [];
            sort($actual);
            $this->assertSame($fields, $actual, "Bucket membership changed for {$bucket}");
        }

        $this->assertSame(125, $fixture['provenance']['legacy_field_count']);
        $this->assertCount(125, $fixture['fields']);
        $this->assertSame('B15413D88E4CFD65FB8242023175E195497E8321D6873312AD29359CF31F9F42', $fixture['provenance']['legacy_engine_source_sha256']);
        $this->assertSame('1B9BB58E1FE27974584B3C15749380087C7E5070F6E5594E30A3F92C8BD93856', $fixture['provenance']['input_fixture_sha256']);
        $this->assertSame(
            'Legacy is_excise presents the full calculated excise as operating expense; rebuilt cogs_inclusion presents the same amount in is_excise_cogs and leaves is_excise at zero.',
            $fixture['provenance']['excise_presentation_rationale'],
        );
        $this->assertSame([
            'authority' => 'CF_Rebuild_Phase_3_Corrective_1.docx',
            'cf_accrued_chg' => 'Preserve the signed service-level accrual movement; legacy also used closing accrued stocks instead of an ending-minus-opening measurement.',
            'cf_warranty_chg' => 'Preserve the signed warranty-reserve movement instead of its absolute magnitude.',
            'cf_excise_chg' => 'Preserve the signed excise-payable movement instead of its absolute magnitude.',
        ], $fixture['provenance']['approved_legacy_defect_corrections']);

        foreach ($fixture['fields'] as $line) {
            $this->assertContains($line['field'], self::BUCKETS[$line['bucket']], "Unclassified legacy field {$line['field']}");

            if ($line['rebuilt_field'] === null) {
                $this->assertArrayNotHasKey($line['field'], $result->fields);

                continue;
            }

            $this->assertArrayHasKey($line['rebuilt_field'], $result->fields);
            $actual = $result->fields[$line['rebuilt_field']];

            if ($line['bucket'] === 'exact parity') {
                $this->assertEqualsWithDelta($line['legacy_value'], $actual, 0.01, "Exact legacy field changed: {$line['field']}");
            } else {
                $this->assertEqualsWithDelta($line['rebuilt_expected_value'], $actual, 0.01, "Explained rebuilt value changed: {$line['field']}");
                $this->assertEqualsWithDelta($line['expected_delta'], $actual - $line['legacy_value'], 0.01, "Explained delta changed: {$line['field']}");
                $this->assertNotSame('', (string) $line['reason']);
            }
        }

        $expectedCurrent = array_values(array_filter(array_column($fixture['fields'], 'rebuilt_field')));
        $expectedCurrent[] = $fixture['rebuilt_only_field']['field'];
        $expectedCurrent = array_merge($expectedCurrent, array_keys(self::REBUILT_ONLY_OVERDRAFT_FIELDS));
        sort($expectedCurrent);
        $actualCurrent = array_keys($result->fields);
        sort($actualCurrent);
        $this->assertSame($expectedCurrent, $actualCurrent);
        $this->assertSame(3904608.0, $result->fields['is_excise_cogs']);
        foreach (self::REBUILT_ONLY_OVERDRAFT_FIELDS as $field => $expected) {
            $this->assertSame($expected, $result->fields[$field], "Rebuilt-only overdraft field changed: {$field}");
        }
    }

    private function calculateHistoricalFixture(): object
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 500;
        $parameters['p2_base_price'] = 850;
        $decisions = [
            'forecasting' => ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => 500, 'price_p1_r2' => 500, 'price_p1_r3' => 500, 'price_p2_r1' => 850, 'price_p2_r2' => 850, 'price_p2_r3' => 850, 'discount_policy' => 'none'],
            'production' => ['p1_production' => 125000, 'p2_production' => 60000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.05, 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05],
            'quality' => ['inspection_level' => 'BASIC'],
            'logistics' => ['logistics_mode' => 'STANDARD'],
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ];

        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(1, $decisions, $parameters, OpeningFinancialPosition::fromScenarioParameters($parameters)));
    }
}
