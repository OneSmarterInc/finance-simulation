<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

/**
 * Engine version 2: the engine decided in the engine design note (ED1-ED8, ED10).
 *
 * This is a port of economics/cfmodel/engine.py (version 2 paths) and must
 * reproduce economics/fixtures/v2_reference.json value for value. Change the
 * Python model first, rebuild the fixtures, then change this class to match.
 * Expressions keep the model's order of operations, because floating-point
 * results depend on it.
 *
 * Version 1 (ManufacturingCFEngine) stays as it is: running courses keep it
 * to the end (ED11).
 */
final class ManufacturingCFEngineV2 implements ManufacturingEngine
{
    public const VERSION = 2;

    public const NEW_FIELD_KEYS = [
        'n2_ord_r1_p1', 'n2_ord_r2_p1', 'n2_ord_r3_p1', 'n2_ord_r1_p2', 'n2_ord_r2_p2', 'n2_ord_r3_p2',
        'n2_sold_r1_p1', 'n2_sold_r2_p1', 'n2_sold_r3_p1', 'n2_sold_r1_p2', 'n2_sold_r2_p2', 'n2_sold_r3_p2',
        'n2_short_p1', 'n2_short_p2', 'n2_mkt_lift',
        'n3_units_p1', 'n3_units_p2', 'n3_value_p1', 'n3_value_p2', 'n3_unit_cost',
        'is_beg_inv',
        'n4_mfg_accum', 'n4_tech_accum', 'n4_dc_accum',
    ];

    public const UNIT_FIELD_PREFIXES = ['n2_ord_', 'n2_sold_', 'n2_short_', 'n3_units_'];

    /** ED8: m(b) = 1 + slope * ln((b + offset) / (reference + offset)); m(5%) = 1. */
    public const MARKETING_LIFT_SLOPE = 0.06;

    public const MARKETING_LIFT_OFFSET = 0.01;

    public const MARKETING_REFERENCE_BUDGET = 0.05;

    /**
     * ED14: the warranty estimate under the percentage-of-sales method. 0.4% of
     * net revenue is what version 1 paid out in claims, a little above the
     * claims a firm buying from China with Basic inspection actually gets.
     */
    public const WARRANTY_ESTIMATE_RATE = 0.004;

    public const ELECTION_KEYS = ['inventory_method', 'depreciation_method', 'shipping_terms', 'bad_debt_method', 'warranty_method', 'excise_tax_treatment'];

    /** Inputs version 2 no longer reads (item 76, ED8, ED10). */
    public const UNUSED_INPUTS = [
        'forecasting' => ['forecast_confidence'],
        'pricing' => ['discount_policy'],
        'production' => ['postponed_flag', 'postponed_pct'],
        'marketing' => ['marketing_alloc_champions', 'marketing_alloc_growth', 'marketing_alloc_at_risk', 'marketing_alloc_other'],
        'certification' => ['attestation_scope'],
    ];

    private const REQUIRED_FIELDS = [
        'forecasting' => ['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3'],
        'sourcing' => ['primary_supplier', 'primary_alloc', 'secondary_supplier'],
        'pricing' => ['price_p1_r1', 'price_p1_r2', 'price_p1_r3', 'price_p2_r1', 'price_p2_r2', 'price_p2_r3'],
        'production' => ['p1_production', 'p2_production', 'shifts'],
        'marketing' => ['marketing_budget_pct'],
        'quality' => ['inspection_level'],
        'logistics' => ['logistics_mode'],
        'notes_elections' => self::ELECTION_KEYS,
    ];

    private const PRODUCTS = ['p1', 'p2'];

    private const REGIONS = ['r1', 'r2', 'r3'];

    private const CLASSES = ['mfg', 'tech', 'dc'];

    public function __construct(
        private readonly AccountingIdentityValidator $validator = new AccountingIdentityValidator,
        private readonly DividendDecisionValidator $dividendValidator = new DividendDecisionValidator,
    ) {}

    /** @return list<string> */
    public static function fieldKeys(): array
    {
        return [...ManufacturingCFEngine::FIELD_KEYS, ...self::NEW_FIELD_KEYS];
    }

    public static function isNonMonetary(string $key): bool
    {
        if (in_array($key, ManufacturingCFEngine::NON_MONETARY_FIELD_KEYS, true) || $key === 'n2_mkt_lift') {
            return true;
        }
        foreach (self::UNIT_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function marketingLift(float $budgetPct): float
    {
        $reference = self::MARKETING_REFERENCE_BUDGET + self::MARKETING_LIFT_OFFSET;

        return max(0.0, 1.0 + self::MARKETING_LIFT_SLOPE * log((max(0.0, $budgetPct) + self::MARKETING_LIFT_OFFSET) / $reference));
    }

    /**
     * Version 2's decision adapter: the six elections only, so no other key
     * can switch on an internal setting such as capitalised carrying cost.
     *
     * @param  array<string, mixed>  $elections
     * @return array<string, mixed>
     */
    public static function adaptElections(array $elections): array
    {
        $terms = ($elections['shipping_terms'] ?? null) === 'fob_destination' ? 'FOB Destination' : 'FOB Shipping Point';
        $notes = [
            'fob_terms' => $terms,
            'inv_method' => match ($elections['inventory_method'] ?? null) {
                'lifo' => 'LIFO',
                'weighted_average' => 'Weighted Average',
                default => 'FIFO',
            },
            'depr_method' => ($elections['depreciation_method'] ?? null) === 'double_declining_balance' ? 'Double Declining Balance' : 'Straight-Line',
            'life_mfg' => 10,
            'life_tech' => 5,
            'life_dc' => 20,
            'overhead_rate' => 0.75,
            'carry_cost_treat' => 'Period Cost (Expense)',
            'proc_terms' => $terms,
            'allowance_pct' => 0.02,
            'warranty_rate' => 0.01,
        ];
        foreach (self::ELECTION_KEYS as $key) {
            if (array_key_exists($key, $elections)) {
                $notes[$key] = $elections[$key];
            }
        }
        // ED12: inventory is first-in, first-out in version 2, whatever is submitted.
        $notes['inventory_method'] = 'fifo';
        $notes['inv_method'] = 'FIFO';
        $notes['warranty_rate'] = self::WARRANTY_ESTIMATE_RATE;

        return $notes;
    }

    public function calculate(ManufacturingEngineInput $input): ManufacturingEngineResult
    {
        if ($input->engineVersion !== self::VERSION || $input->market === null) {
            throw new EngineCalculationException('ManufacturingCFEngineV2 calculates engine version 2 with a market only.');
        }
        $this->validateDecisionFields($input->decisions);

        $d = $input->decisions;
        $p = $input->parameters;
        $o = $input->openingPosition;
        $market = $input->market;
        $quarter = $input->quarterNumber;
        $notes = self::adaptElections($d['notes_elections']);
        $pr = $d['pricing'];
        $prod = $d['production'];
        $e = $this->param($p, 'price_elasticity');
        $cap = $this->num($prod, 'shifts') * $this->param($p, 'capacity_per_shift');

        // --- demand and revenue
        $lift = self::marketingLift($this->num($d['marketing'], 'marketing_budget_pct'));
        $dem = [];
        foreach (self::PRODUCTS as $k) {
            foreach (self::REGIONS as $r) {
                $dem[$k][$r] = $this->elastic($this->num($market, "forecast_{$k}_{$r}"), $this->num($pr, "price_{$k}_{$r}"), $this->param($p, "{$k}_base_price"), $e, $lift);
            }
        }
        $made = ['p1' => min($this->num($prod, 'p1_production'), $cap)];
        $made['p2'] = min($this->num($prod, 'p2_production'), max(0, $cap - $made['p1']));
        $onHand = ['p1' => $o->unitsOnHandP1, 'p2' => $o->unitsOnHandP2];
        $avail = $sold = $reg = $left = [];
        foreach (self::PRODUCTS as $k) {
            $avail[$k] = $made[$k] + $onHand[$k];
            $sold[$k] = min($this->sum($dem[$k]), $avail[$k]);
            $reg[$k] = $this->allocate($dem[$k], $sold[$k]);
        }
        $n2 = [];
        foreach (self::PRODUCTS as $k) {
            foreach (self::REGIONS as $r) {
                $n2["n2_{$r}_{$k}"] = $reg[$k][$r] * $this->num($pr, "price_{$k}_{$r}");
            }
        }
        $grossRev = $this->sum($n2);
        $catch = $this->param($p, 'inspection_'.strtolower((string) $d['quality']['inspection_level']).'_catch_rate');
        $defect = $this->supplier($p, (string) $d['sourcing']['primary_supplier'], 'defect_rate');
        $unitsSold = $sold['p1'] + $sold['p2'];
        $defective = round($unitsSold * $defect * (1 - $catch));
        $avgPrice = $unitsSold > 0 ? $grossRev / $unitsSold : 0.0;
        $returns = $defective * $avgPrice * $this->param($p, 'returns_rate_on_defects');
        $rebates = $grossRev * $this->param($p, 'rebate_rate');
        $p1Rev = $n2['n2_r1_p1'] + $n2['n2_r2_p1'] + $n2['n2_r3_p1'];
        $p2Rev = $n2['n2_r1_p2'] + $n2['n2_r2_p2'] + $n2['n2_r3_p2'];
        foreach (self::PRODUCTS as $k) {
            $left[$k] = max(0.0, $avail[$k] - $sold[$k]);
        }
        $totalProd = $made['p1'] + $made['p2'];

        // --- cost of goods sold and inventory
        $src = $d['sourcing'];
        $primaryCost = $this->supplier($p, (string) $src['primary_supplier'], 'cost');
        $sec = strtoupper((string) ($src['secondary_supplier'] ?? 'NONE'));
        $blended = $sec === 'NONE'
            ? $primaryCost
            : $primaryCost * $this->num($src, 'primary_alloc') + $this->supplier($p, $sec, 'cost') * (1 - $this->num($src, 'primary_alloc'));
        $raw = $totalProd * $blended;
        $ot = (int) $this->num($prod, 'shifts') === 3 ? 1 + $this->param($p, 'overtime_premium') : 1.0;
        $labor = $totalProd * $this->param($p, 'base_labor_per_unit') * $ot;
        $depr = $this->depreciation($notes, $o, $p, $totalProd);
        $fixedOh = $this->param($p, 'fixed_overhead_per_period') * $notes['overhead_rate'];
        $overhead = $fixedOh + $depr['mfg'];
        $unitCost = $blended + $this->param($p, 'base_labor_per_unit') * $ot + ($fixedOh + $depr['mfg']) / max($totalProd, 1);
        $endingUnits = $left['p1'] + $left['p2'];
        // FIFO by product: units on hand are sold first, so what is left is
        // this quarter's production, plus old units only when more were left
        // than were made. Stock carried in is one batch at its average cost.
        $openingValue = ['p1' => $o->stockValueP1, 'p2' => $o->stockValueP2];
        $stockValue = [];
        foreach (self::PRODUCTS as $k) {
            $madeLeft = min($left[$k], $made[$k]);
            $oldLeft = $left[$k] - $madeLeft;
            $oldCost = $onHand[$k] > 0 ? $openingValue[$k] / $onHand[$k] : 0.0;
            $stockValue[$k] = CurrencyRounding::cents($madeLeft * $unitCost + $oldLeft * $oldCost);
        }
        $endingInv = $stockValue['p1'] + $stockValue['p2'];
        $beginningInv = $openingValue['p1'] + $openingValue['p2'];
        $lifoReserve = $notes['inv_method'] === 'LIFO' ? $endingUnits * $blended * $this->param($p, 'inflation_rate') : 0.0;
        $lifoAdj = $lifoReserve - $o->lifoReserve;
        $carrying = $endingInv * $this->param($p, 'carrying_cost_rate');
        $capitalized = $notes['carry_cost_treat'] === 'Product Cost (Capitalize)' ? $carrying : 0.0;
        $carryExp = $capitalized > 0 ? 0.0 : $carrying;
        $rawInv = $endingUnits > 0 ? $endingUnits * $blended * $this->param($p, 'inventory_raw_mat_ratio') : 0.0;
        $wip = 0.0; // ED10: postponed production is ignored
        // ED13: goods still on the way are the purchases shipped within each
        // supplier's lead time: units x share x unit cost x lead days / 90.
        $inTransit = 0.0;
        if ($notes['proc_terms'] === 'FOB Shipping Point') {
            $primaryShare = $sec === 'NONE' ? 1.0 : $this->num($src, 'primary_alloc');
            $inTransit = $totalProd * $primaryShare * $primaryCost * $this->supplier($p, (string) $src['primary_supplier'], 'lead_days') / 90;
            if ($sec !== 'NONE') {
                $inTransit += $totalProd * (1 - $this->num($src, 'primary_alloc')) * $this->supplier($p, $sec, 'cost') * $this->supplier($p, $sec, 'lead_days') / 90;
            }
        }

        // --- operating expenses
        $netRev = $p1Rev + $p2Rev - $returns - $rebates;
        $mkt = ($p1Rev + $p2Rev) * $this->num($d['marketing'], 'marketing_budget_pct');
        $mode = strtolower((string) $d['logistics']['logistics_mode']);
        $freight = $unitsSold * $this->param($p, "logistics_{$mode}_cost") + $depr['dc'];
        $late = $unitsSold * (1 - $this->param($p, "logistics_{$mode}_on_time_rate"));
        $slPen = $late * $this->param($p, 'sl_penalty_per_unit');
        $excise = ($n2['n2_r2_p1'] + $n2['n2_r2_p2']) * $this->param($p, 'excise_rate_r2') + ($n2['n2_r3_p1'] + $n2['n2_r3_p2']) * $this->param($p, 'excise_rate_r3');
        $insp = $totalProd * $this->param($p, 'inspection_'.strtolower((string) $d['quality']['inspection_level']).'_cost');
        $periodDepr = $depr['mfg'] + $depr['tech'] + $depr['dc'];
        $admin = $this->param($p, 'admin_overhead') + $depr['tech'];
        // ED14: every defect that gets past inspection comes back, either as a
        // return (priced in returns above) or as a warranty claim at the
        // scenario's cost per claim. The expense is the team's estimate; when
        // claims outrun the reserve, the shortfall is expensed. The reserve
        // carries from quarter to quarter.
        $warPaid = $defective * (1 - $this->param($p, 'returns_rate_on_defects')) * $this->param($p, 'warranty_cost_per_claim');
        $warEstimate = $netRev * $notes['warranty_rate'];
        $warExp = ($notes['warranty_method'] ?? 'percentage_of_sales') === 'expense_as_incurred'
            ? $warPaid
            : $warEstimate + max(0.0, $warPaid - ($o->warrantyReserve + $warEstimate));
        $war = $this->roll($o->warrantyReserve, $warExp, $warPaid);
        $sl = $this->roll($o->serviceLevelAccrual, $slPen, $o->serviceLevelAccrual + $slPen * $this->param($p, 'sl_payment_rate'));
        $exc = $this->roll($o->excisePayable, $excise, $o->excisePayable + $excise * $this->param($p, 'excise_payment_rate'));
        $excExp = ($notes['excise_tax_treatment'] ?? 'cogs_inclusion') === 'separate_operating_expense' ? $excise : 0.0;
        $excCogs = $excise - $excExp;
        $totalCogs = $beginningInv + $raw + $labor + $overhead - $endingInv + $lifoAdj + $carryExp;
        $gp = $p1Rev + $p2Rev - $returns - $rebates - $totalCogs;
        $currentCredit = $netRev * 0.20;
        $grossAr = $o->accountsReceivable + $currentCredit;
        $allowance = $notes['allowance_pct'];
        $badDebt = ($notes['bad_debt_method'] ?? 'percentage_of_receivables') === 'percentage_of_sales'
            ? $netRev * $allowance
            : max(0.0, $grossAr * $allowance - $o->accountsReceivable * $allowance);
        $opInc = $gp - $excCogs - ($mkt + $freight + $slPen + $excExp + $insp + $admin + $warExp + $badDebt);
        $totalOpex = $mkt + $freight + $slPen + $excise + $insp + $admin + $warExp + $badDebt;

        // --- below the line
        $nr = max($netRev, 1);
        $score = min(100, max(0, ($opInc / $nr) * 200 * 0.5 + (($gp - $excCogs) / $nr) * 200 * 0.3 + (100 - ($totalOpex / $nr) * 100) * 0.2));
        [$rate, $limit] = $this->creditTier($score, $p);
        $interest = $o->debt * ($rate / 4);

        $partial = [
            'is_p1_rev' => $p1Rev, 'is_p2_rev' => $p2Rev, 'is_returns' => $returns, 'is_rebates' => $rebates, ...$n2,
            'n2_rebate_added' => $rebates, 'is_raw_mat' => $raw, 'is_labor' => $labor, 'is_overhead' => $overhead, 'is_end_inv' => $endingInv,
            'is_lifo_adj' => $lifoAdj, 'is_carry' => $carryExp, 'n3_raw_mat' => $rawInv, 'n3_wip' => $wip,
            'n3_fg' => max(0, $endingInv - $wip - $rawInv), 'n3_in_transit' => $inTransit, 'n3_lifo_res' => $lifoReserve,
            'n3_lifo_chg' => $lifoAdj, 'n3_overhead' => $overhead,
            'is_marketing' => $mkt, 'is_freight' => $freight, 'is_sl_penalties' => $slPen,
            'is_excise_cogs' => $excCogs, 'is_excise' => $excExp, 'is_inspection' => $insp, 'is_admin' => $admin, 'is_warranty' => $warExp,
            'n6_supplier' => $totalProd * $blended, 'n6_po_fob' => $notes['proc_terms'] === 'FOB Shipping Point' ? 1.0 : 0.0,
            'n6_lease' => 0.0, 'n6_war_open' => $war['open'], 'n6_war_exp' => $war['addition'], 'n6_war_paid' => $war['paid'], 'n6_war_close' => $war['close'],
            'n6_sl_open' => $sl['open'], 'n6_sl_exp' => $sl['addition'], 'n6_sl_paid' => $sl['paid'], 'n6_sl_close' => $sl['close'],
            'n6_exc_open' => $exc['open'], 'n6_exc_acc' => $exc['addition'], 'n6_exc_paid' => $exc['paid'], 'n6_exc_close' => $exc['close'],
            'is_interest' => $interest, 'is_other' => $badDebt, 'n5_rate' => $rate, 'n5_limit' => $limit, 'n5_draw_rate' => $rate,
            'is_beg_inv' => $beginningInv,
        ];
        $statement = self::incomeStatement($this->normalize($partial), $this->param($p, 'tax_rate'));
        $ni = $statement['net_income'];
        $tax = $statement['income_tax_expense'];

        // --- balance sheet before cash, and the PPE note
        $capex = [];
        foreach (self::CLASSES as $k) {
            $capex[$k] = $this->param($p, "capex_{$k}");
        }
        $gross = ['mfg' => $o->ppeComponents->manufacturing + $capex['mfg'], 'tech' => $o->ppeComponents->technology + $capex['tech'], 'dc' => $o->ppeComponents->distributionCenter + $capex['dc']];
        $accumClass = ['mfg' => $o->accumulatedManufacturing + $depr['mfg'], 'tech' => $o->accumulatedTechnology + $depr['tech'], 'dc' => $o->accumulatedDistributionCenter + $depr['dc']];
        $inventory = max(0.0, $endingInv + $capitalized - $lifoReserve) + $inTransit;
        $ap = $totalProd * $blended * 0.30 + $inTransit;
        $ar = max(0.0, $currentCredit - $badDebt);
        $curDebt = round($o->debt * 0.10, 2);
        $ltDebt = round($o->debt - $curDebt, 2);

        // --- cash flow and debt
        $arChg = CurrencyRounding::cents($ar) - $o->accountsReceivable;
        $invChg = CurrencyRounding::cents($inventory) - $o->inventory;
        $apChg = CurrencyRounding::cents($ap) - $o->accountsPayable;
        $warChg = CurrencyRounding::cents($war['close']) - $o->warrantyReserve;
        $slChg = CurrencyRounding::cents($sl['close']) - $o->serviceLevelAccrual;
        $excChg = CurrencyRounding::cents($exc['close']) - $o->excisePayable;
        $ocf = $ni + CurrencyRounding::cents($periodDepr) - $arChg - $invChg + $apChg + $warChg + $slChg + $excChg;
        $icf = -$this->sum($capex);
        $curDebtM = CurrencyRounding::cents($curDebt);
        $ltDebtM = CurrencyRounding::cents($ltDebt);
        $repay = round($curDebtM * $this->param($p, 'borrow_repay_rate'), 2);
        $buffer = $this->param($p, 'minimum_cash_buffer');
        $pre = $o->cash - $o->bankOverdraft + $ocf + $icf - $repay;
        $limitCheck = $this->dividendValidator->validate($quarter, $input->dividend, $o->retainedEarnings, $ni, $pre, $buffer);
        $paidDividend = $limitCheck->requested;
        $draw = $pre < $buffer ? round(min($buffer - $pre, $limit), 2) : 0.0;
        $after = round($pre - $paidDividend + $draw, 2);
        $od = round(max(0.0, -$after), 2);
        $odMove = round($od - $o->bankOverdraft, 2);
        $cash = round(max(0.0, $after), 2);
        $endCur = round(max(0.0, $curDebtM - $repay), 2);
        $endLt = round($ltDebtM + $draw, 2);
        $closingDebt = round($endCur + $endLt, 2);
        $retained = round($o->retainedEarnings - $paidDividend, 2);
        $currentAssets = $cash + $ar + $inventory;
        $currentLiab = $ap + $war['close'] + $sl['close'] + $exc['close'] + $endCur + $od;
        $totalEquity = $o->corporateCapitalization + $retained + $ni;

        $fields = $partial + [
            'is_tax' => $tax, 'is_disposal' => 0.0,
            'bs_cash' => $cash, 'bs_ar' => $ar, 'bs_inventory' => $inventory, 'bs_prepaid' => 0.0, 'bs_ppe_gross' => $this->sum($gross),
            'bs_accum_depr' => $this->sum($accumClass), 'bs_rou' => 0.0, 'bs_tech_net' => 0.0,
            'bs_ap' => $ap, 'bs_wages' => 0.0, 'bs_mkt' => 0.0, 'bs_warranty_res' => $war['close'], 'bs_sl_accrual' => $sl['close'],
            'bs_excise' => $exc['close'], 'bs_curr_ltd' => $endCur, 'bs_def_rev' => 0.0, 'bs_ltd' => $endLt, 'bs_lease' => 0.0,
            'bs_dtl' => 0.0, 'bs_bank_overdraft' => $od, 'bs_common' => $o->corporateCapitalization, 'bs_retained' => $retained, 'bs_curr_ni' => $ni,
            'n2_rebate_open' => 0.0, 'n2_rebate_paid' => $rebates * $this->param($p, 'rebate_payment_rate'),
            'n4_mfg_gross' => $gross['mfg'], 'n4_tech_gross' => $gross['tech'], 'n4_dc_gross' => $gross['dc'],
            'n4_mfg_depr' => $depr['mfg'], 'n4_tech_depr' => $depr['tech'], 'n4_dc_depr' => $depr['dc'],
            'n4_mfg_net' => $gross['mfg'] - $accumClass['mfg'], 'n4_tech_net' => $gross['tech'] - $accumClass['tech'], 'n4_dc_net' => $gross['dc'] - $accumClass['dc'],
            'n4_mfg_accum' => $accumClass['mfg'], 'n4_tech_accum' => $accumClass['tech'], 'n4_dc_accum' => $accumClass['dc'],
            'n4_disposal_nbv' => 0.0, 'n4_disposal_proc' => 0.0, 'n4_disposal_gain' => 0.0,
            'n4_util_rate' => min(1.0, $totalProd / max($cap, 1)),
            'cf_net_income' => $ni, 'cf_depr' => CurrencyRounding::cents($periodDepr), 'cf_rou' => 0.0, 'cf_lifo' => 0.0, 'cf_ar_chg' => $arChg,
            'cf_inv_chg' => $invChg, 'cf_prepaid_chg' => 0.0, 'cf_ap_chg' => $apChg, 'cf_accrued_chg' => $slChg,
            'cf_warranty_chg' => $warChg, 'cf_def_rev_chg' => 0.0, 'cf_excise_chg' => $excChg,
            'cf_capex_mfg' => $capex['mfg'], 'cf_capex_tech' => $capex['tech'], 'cf_capex_dc' => $capex['dc'], 'cf_disposal' => 0.0,
            'cf_borrow_proc' => $draw, 'cf_borrow_repay' => $repay, 'cf_lease_pmt' => 0.0, 'cf_equity' => -$paidDividend,
            'cf_beg_cash' => $o->cash, 'cf_cash_interest' => $interest, 'cf_cash_tax' => $tax, 'cf_overdraft_chg' => $odMove,
            'n5_balance' => $closingDebt, 'n5_auto_draw' => $draw, 'n5_overdraft' => $od, 'n5_shortfall_indicator' => $od > 0 ? 1.0 : 0.0,
            'n5_mat_y1' => $curDebtM, 'n5_mat_y2' => $ltDebtM * 0.40, 'n5_mat_beyond' => $ltDebtM * 0.60,
            'n5_cov_curr' => $currentLiab > 0 ? $currentAssets / $currentLiab : 0.0,
            'n5_cov_de' => $totalEquity > 0 ? $closingDebt / $totalEquity : 0.0,
            'n5_cov_margin' => $netRev > 0 ? $opInc / $netRev : 0.0,
            'n5_cov_interest' => $interest > 0 ? $opInc / $interest : 0.0,
            'n5_cov_cash' => $buffer > 0 ? $cash / $buffer : 0.0,
            'n5_int_recon' => $interest,
            'n2_mkt_lift' => $lift,
            'n3_units_p1' => $left['p1'], 'n3_units_p2' => $left['p2'],
            'n3_value_p1' => $stockValue['p1'], 'n3_value_p2' => $stockValue['p2'],
            'n3_unit_cost' => $totalProd > 0 ? $unitCost : 0.0,
            'n2_short_p1' => max(0.0, $this->sum($dem['p1']) - $sold['p1']),
            'n2_short_p2' => max(0.0, $this->sum($dem['p2']) - $sold['p2']),
        ];
        foreach (self::PRODUCTS as $k) {
            foreach (self::REGIONS as $r) {
                $fields["n2_ord_{$r}_{$k}"] = $dem[$k][$r];
                $fields["n2_sold_{$r}_{$k}"] = $reg[$k][$r];
            }
        }
        $fields = $this->normalize($fields, complete: true);
        $this->validator->validate($fields, $o);

        $intermediates = [
            'engine_version' => self::VERSION, 'quarter_number' => $quarter, 'market' => $market, 'market_lift' => $lift,
            'p1_demand' => $this->sum($dem['p1']), 'p2_demand' => $this->sum($dem['p2']),
            'p1_made' => $made['p1'], 'p2_made' => $made['p2'], 'p1_on_hand' => $onHand['p1'], 'p2_on_hand' => $onHand['p2'],
            'p1_sold' => $sold['p1'], 'p2_sold' => $sold['p2'], 'p1_unsold' => $left['p1'], 'p2_unsold' => $left['p2'],
            'total_production' => $totalProd, 'total_units_sold' => $unitsSold, 'unit_cost' => $unitCost,
            'blended_cost' => $blended, 'credit_score' => $score, 'interest_rate' => $rate, 'borrowing_limit' => $limit,
            'dividend_requested' => $limitCheck->requested, 'dividend' => $paidDividend, 'maximum_dividend' => $limitCheck->maximum,
            'distributable_retained_earnings' => $limitCheck->distributableRetainedEarnings,
            'cash_available_for_dividend' => $limitCheck->cashAvailableAboveBuffer,
            'pre_dividend_cash' => $pre, 'operating_cash_flow' => $ocf,
        ];

        return new ManufacturingEngineResult(
            fields: $fields,
            incomeStatement: self::incomeStatement($fields, $this->param($p, 'tax_rate')),
            balanceSheet: [
                'assets' => $this->validator->assets($fields),
                'liabilities' => $this->validator->liabilities($fields),
                'equity' => $this->validator->equity($fields),
                'line_items' => $this->prefixed($fields, 'bs_'),
            ],
            cashFlowStatement: [
                'operating_cash_flow' => $this->validator->operatingCashFlow($fields),
                'investing_cash_flow' => $this->validator->investingCashFlow($fields),
                'financing_cash_flow' => $this->validator->financingCashFlow($fields),
                'ending_cash' => $fields['bs_cash'],
                'line_items' => $this->prefixed($fields, 'cf_'),
            ],
            notes: [
                'revenue' => $this->prefixed($fields, 'n2_'),
                'inventory' => $this->prefixed($fields, 'n3_'),
                'ppe' => $this->prefixed($fields, 'n4_'),
                'debt' => $this->prefixed($fields, 'n5_'),
                'commitments' => $this->prefixed($fields, 'n6_'),
            ],
            intermediates: $intermediates,
        );
    }

    /**
     * @param  array<string, float>  $f
     * @return array<string, float>
     */
    public static function incomeStatement(array $f, float $taxRate): array
    {
        $netRevenue = CurrencyRounding::cents($f['is_p1_rev'] + $f['is_p2_rev'] - $f['is_returns'] - $f['is_rebates']);
        $cogs = CurrencyRounding::cents($f['is_beg_inv'] + $f['is_raw_mat'] + $f['is_labor'] + $f['is_overhead'] + $f['is_excise_cogs'] - $f['is_end_inv'] + $f['is_lifo_adj'] + $f['is_carry']);
        $grossProfit = CurrencyRounding::cents($netRevenue - $cogs);
        $operatingExpenses = CurrencyRounding::cents($f['is_marketing'] + $f['is_freight'] + $f['is_sl_penalties'] + $f['is_excise'] + $f['is_inspection'] + $f['is_admin'] + $f['is_warranty'] + $f['is_other']);
        $operatingIncome = CurrencyRounding::cents($grossProfit - $operatingExpenses);
        $interest = CurrencyRounding::cents($f['is_interest']);
        $beforeTax = CurrencyRounding::cents($operatingIncome - $interest);
        $tax = $beforeTax <= 0 ? 0.0 : CurrencyRounding::cents($beforeTax * $taxRate);

        return [
            'net_revenue' => $netRevenue,
            'cost_of_goods_sold' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'operating_income' => $operatingIncome,
            'bad_debt_expense' => $f['is_other'],
            'interest_expense' => $interest,
            'income_before_income_taxes' => $beforeTax,
            'income_tax_expense' => $tax,
            'net_income' => CurrencyRounding::cents($beforeTax - $tax),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $decisions
     */
    private function validateDecisionFields(array $decisions): void
    {
        foreach (self::REQUIRED_FIELDS as $category => $keys) {
            if (! array_key_exists($category, $decisions) || ! is_array($decisions[$category])) {
                throw new EngineCalculationException("Missing required decision category [{$category}].");
            }
            foreach ($keys as $key) {
                if (! array_key_exists($key, $decisions[$category])) {
                    throw new EngineCalculationException("Missing required decision field [{$category}.{$key}].");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $notes
     * @return array{mfg: float, tech: float, dc: float}
     */
    private function depreciation(array $notes, OpeningFinancialPosition $o, array $p, float $totalProd): array
    {
        $lives = ['mfg' => $notes['life_mfg'], 'tech' => $notes['life_tech'], 'dc' => $notes['life_dc']];
        $gross = ['mfg' => $o->ppeComponents->manufacturing, 'tech' => $o->ppeComponents->technology, 'dc' => $o->ppeComponents->distributionCenter];
        $accum = ['mfg' => $o->accumulatedManufacturing, 'tech' => $o->accumulatedTechnology, 'dc' => $o->accumulatedDistributionCenter];
        $method = $notes['depreciation_method'] ?? 'straight_line';
        $out = [];
        foreach (self::CLASSES as $k) {
            $book = max(0.0, $gross[$k] - $accum[$k]);
            $straight = $gross[$k] / max($lives[$k], 1) / 4;
            if ($method === 'double_declining_balance') {
                $charge = $book * (2.0 / max($lives[$k], 1) / 4);
            } elseif ($method === 'units_of_production' && $k === 'mfg') {
                // the plant wears with use: one full shift of output is one straight-line quarter
                $charge = $straight * ($totalProd / $this->param($p, 'capacity_per_shift'));
            } else {
                $charge = $straight;
            }
            // rounded per class, so cost - accumulated = net to the cent
            $out[$k] = CurrencyRounding::cents(min($charge, $book));
        }

        return $out;
    }

    private function elastic(float $units, float $price, float $base, float $elasticity, float $lift): float
    {
        if ($units <= 0 || $price <= 0 || $base <= 0) {
            return 0.0;
        }

        return (float) round($units * (($price / $base) ** $elasticity) * $lift);
    }

    /**
     * @param  array{r1: float, r2: float, r3: float}  $regional
     * @return array{r1: float, r2: float, r3: float}
     */
    private function allocate(array $regional, float $sold): array
    {
        $total = $this->sum($regional);
        if ($total <= 0 || $sold <= 0) {
            return ['r1' => 0.0, 'r2' => 0.0, 'r3' => 0.0];
        }
        $r1 = (float) round($sold * $regional['r1'] / $total);
        $r2 = (float) round($sold * $regional['r2'] / $total);

        return ['r1' => $r1, 'r2' => $r2, 'r3' => $sold - $r1 - $r2];
    }

    /** @return array{open: float, addition: float, paid: float, close: float} */
    private function roll(float $opening, float $addition, float $paid): array
    {
        return ['open' => $opening, 'addition' => $addition, 'paid' => $paid, 'close' => $opening + $addition - $paid];
    }

    /** @return array{0: float, 1: float} */
    private function creditTier(float $score, array $p): array
    {
        foreach (['excellent', 'good', 'fair', 'poor'] as $tier) {
            if ($score >= $this->param($p, "credit_tier_{$tier}_min_score")) {
                return [$this->param($p, "credit_tier_{$tier}_rate"), $this->param($p, "credit_tier_{$tier}_limit")];
            }
        }

        return [$this->param($p, 'credit_tier_distressed_rate'), $this->param($p, 'credit_tier_distressed_limit')];
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    private function normalize(array $values, bool $complete = false): array
    {
        if ($complete) {
            $keys = self::fieldKeys();
            $missing = array_diff($keys, array_keys($values));
            $extra = array_diff(array_keys($values), $keys);
            if ($missing !== [] || $extra !== []) {
                throw new EngineCalculationException('Engine v2 field set mismatch: missing ['.implode(', ', $missing).'], extra ['.implode(', ', $extra).'].');
            }
            $values = array_merge(array_fill_keys($keys, 0.0), $values);
        }
        foreach ($values as $key => $value) {
            $values[$key] = self::isNonMonetary($key) ? (float) $value : CurrencyRounding::cents((float) $value);
        }

        return $values;
    }

    /**
     * @param  array<string, float>  $fields
     * @return array<string, float>
     */
    private function prefixed(array $fields, string $prefix): array
    {
        return array_filter($fields, fn (string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<array-key, float|int> $values */
    private function sum(array $values): float
    {
        $total = 0.0;
        foreach ($values as $value) {
            $total += $value;
        }

        return $total;
    }

    private function supplier(array $p, string $code, string $attribute): float
    {
        return $this->param($p, 'supplier_'.strtolower($code).'_'.$attribute);
    }

    private function num(array $values, string $key): float
    {
        return (float) ($values[$key] ?? 0);
    }

    private function param(array $parameters, string $key): float
    {
        if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
            throw new EngineCalculationException("Missing numeric scenario parameter [{$key}].");
        }

        return (float) $parameters[$key];
    }
}
