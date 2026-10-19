<?php

namespace App\Services\Tour;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\Scenario;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Debrief\QuarterReplay;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Practice\PracticeRunService;
use App\Support\Presentation\DecisionPresenter;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Live walkthrough L8: the figures the public adopter tour quotes.
 *
 * Every figure comes from one practice run of a reference course on
 * Flexee Financial Accounting+, played by the same engine a class uses.
 * `php artisan flexee:tour-figures` plays it on a throwaway course,
 * writes resources/tour/figures.json, and removes everything it made. The
 * tour pages only read the file, so visitors never run the engine.
 */
final class TourFigures
{
    public const PATH = 'tour/figures.json';

    /** When each area opens in the reference course: the golden fixtures' schedule. */
    private const OPENS = ['forecasting' => 4, 'sourcing' => 4, 'notes_elections' => 4, 'pricing' => 5, 'marketing' => 6, 'production' => 7, 'quality' => 8, 'logistics' => 8];

    public function __construct(
        private readonly CourseConfigurationService $courses,
        private readonly PracticeRunService $runs,
    ) {}

    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = resource_path(self::PATH);
        if (! is_file($path)) {
            throw new RuntimeException('The tour figures are missing. Run php artisan flexee:tour-figures.');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Play the reference course and return the figures (without writing them).
     *
     * @return array<string, mixed>
     */
    public function generate(Faculty $faculty): array
    {
        $previous = config('flexee.engine_version_for_new_courses');
        config(['flexee.engine_version_for_new_courses' => 2]);
        $source = null;
        $practice = null;
        try {
            $source = $this->referenceCourse($faculty);
            $practice = $this->runs->playTo($faculty, $this->runs->create($faculty, $source), 11);

            return $this->collect($practice);
        } finally {
            if ($practice?->exists) {
                $this->runs->remove($faculty, $practice->fresh() ?? $practice);
            }
            if ($source) {
                $this->removeSource($source);
            }
            config(['flexee.engine_version_for_new_courses' => $previous]);
        }
    }

    /** @param array<string, mixed> $figures */
    public static function write(array $figures): void
    {
        $path = resource_path(self::PATH);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($figures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, mixed> */
    private function collect(Course $practice): array
    {
        $firms = $practice->firms()->get()->keyBy(fn ($f) => strtolower((string) $f->code));
        $result = fn (string $team, int $q): FirmEngineResult => $firms[$team]->engineResults()->where('quarter_number', $q)->firstOrFail();
        $f = fn (FirmEngineResult $r, string $key): float => round((float) ($r->fields[$key] ?? 0), 2);
        $is = fn (FirmEngineResult $r, string $key): float => round((float) ($r->income_statement[$key] ?? 0), 2);

        $careful = [];
        foreach ([1, 2, 3, 4] as $q) {
            $r = $result('careful', $q);
            $careful[$q] = [
                'revenue' => $is($r, 'net_revenue'), 'gross_profit' => $is($r, 'gross_profit'), 'net_income' => $is($r, 'net_income'),
                'cash' => $f($r, 'bs_cash'), 'inventory' => $f($r, 'bs_inventory'),
            ];
        }
        $q3 = $result('careful', 3);
        $q4 = $result('careful', 4);
        $sum = fn (FirmEngineResult $r, string $prefix): int => (int) round(array_sum(array_map(fn ($k) => (float) $r->fields[$k], array_filter(array_keys($r->fields), fn ($k) => str_starts_with($k, $prefix)))));
        $careful[4] += [
            'ordered' => $sum($q4, 'n2_ord_'), 'sold' => $sum($q4, 'n2_sold_'),
            'stock' => (int) round((float) $q4->fields['n3_units_p1'] + (float) $q4->fields['n3_units_p2']),
            'dividend' => round(-(float) $q4->fields['cf_equity'], 2), 'in_transit' => $f($q4, 'n3_in_transit'),
            // The cash flow fields hold the increase; the statement subtracts it.
            'inventory_change' => $f($q4, 'cf_inv_chg'), 'receivables_change' => $f($q4, 'cf_ar_chg'),
            'payables_change' => $f($q4, 'cf_ap_chg'), 'depreciation' => $f($q4, 'cf_depr'),
        ];

        $q4Quarter = $practice->quarters()->where('quarter_number', 4)->firstOrFail();
        $decision = $firms['careful']->quarterDecisions()->where('course_quarter_id', $q4Quarter->id)->firstOrFail()->resolved_payload;
        $forecast = [];
        foreach (['p1_r1' => 'Product 1, Northeast', 'p1_r2' => 'Product 1, Central', 'p2_r1' => 'Product 2, Northeast', 'p2_r2' => 'Product 2, Central'] as $key => $label) {
            [$p, $region] = explode('_', $key);
            $forecast[] = ['label' => $label, 'value' => (int) $decision['forecasting']['forecast_'.$key], 'last_orders' => (int) round((float) $q3->fields['n2_ord_'.$region.'_'.$p])];
        }
        $parameters = app(FirmDecisionService::class)->parameters($firms['careful']);

        // Careless switches to declining balance in Quarter 6.
        $before = $result('careless', 5);
        $after = $result('careless', 6);
        $pretax = (float) ($after->income_statement['income_before_income_taxes'] ?? 0);
        $taxRate = $pretax > 0 ? (float) $after->income_statement['income_tax_expense'] / $pretax : 0.0;
        $extra = (float) $after->fields['cf_depr'] - (float) $before->fields['cf_depr'];

        $explainer = $this->depreciationExplainer($firms['careful'], $q4, (array) ($decision['notes_elections'] ?? []));

        $value = [];
        foreach ($this->runs->valueCreated($practice) as $q => $teams) {
            foreach ($teams as $team => $v) {
                $value[$team][$q] = round($v, 2);
            }
        }
        $evaluation = $this->runs->evaluation($practice);

        return [
            'about' => 'A practice run of a reference Flexee Financial Accounting+ course, played by the same engine a class uses. Written by php artisan flexee:tour-figures.',
            'generated_on' => now()->toDateString(),
            'engine_version' => $practice->engineVersion(),
            'quarters' => 11,
            'evaluation_opens_with' => $evaluation['opens_with'] ?? null,
            'careful' => $careful,
            'decisions' => [
                'forecast' => $forecast,
                'supplier' => DecisionPresenter::supplierDetail((string) $decision['sourcing']['primary_supplier'], $parameters),
                'previous_supplier' => DecisionPresenter::supplierDetail('SUP001', $parameters),
            ],
            'careless_depreciation' => [
                'before' => round((float) $before->fields['cf_depr'], 2),
                'after' => round((float) $after->fields['cf_depr'], 2),
                'after_tax_effect' => round($extra * (1 - $taxRate), 2),
            ],
            'value_created' => $value,
            'depreciation_explainer' => $explainer,
            'evaluation' => [
                'firms' => array_map(fn ($r) => array_intersect_key($r, array_flip(['team', 'consultant', 'true_worth'])), $evaluation['firms'] ?? []),
                'results' => array_map(fn ($r) => array_intersect_key($r, array_flip(['target', 'winner', 'price', 'true_stake', 'difference'])), $evaluation['results'] ?? []),
            ],
        ];
    }

    /**
     * U4 layer 2 (MO2): Careful's Quarter 4 as it was played, on straight-line,
     * and the same quarter played again in memory on double-declining balance,
     * with the same decisions. The engine works out both; nothing is saved.
     *
     * @param  array<string, mixed>  $played  the methods the quarter was played with
     * @return array<string, mixed>
     */
    private function depreciationExplainer(Firm $firm, FirmEngineResult $stored, array $played): array
    {
        if (($played['depreciation_method'] ?? null) !== 'straight_line') {
            throw new RuntimeException('The reference team no longer plays Quarter 4 on straight-line; the depreciation explainer needs it.');
        }
        $declining = app(QuarterReplay::class)->replay($firm, 4, ['depreciation_method' => 'double_declining_balance'])
            ?? throw new RuntimeException('Quarter 4 could not be played again for the depreciation explainer.');

        $side = function (array $fields, array $is, array $cf, array $bs): array {
            $money = fn ($v): float => round((float) $v, 2);

            $sold = array_sum(array_map(fn ($k) => (float) $fields[$k], array_filter(array_keys($fields), fn ($k) => str_starts_with((string) $k, 'n2_sold_'))));

            return [
                'units_sold' => (int) round($sold),
                'depreciation' => $money($fields['cf_depr']),
                'cost_of_goods_sold' => $money($is['cost_of_goods_sold']),
                'operating_expenses' => $money($is['operating_expenses']),
                'income_before_taxes' => $money($is['income_before_income_taxes']),
                'income_tax' => $money($is['income_tax_expense']),
                'net_income' => $money($is['net_income']),
                'operating_cash_flow' => $money($cf['operating_cash_flow']),
                'net_plant' => $money((float) $fields['bs_ppe_gross'] - (float) $fields['bs_accum_depr']),
                'inventory' => $money($fields['bs_inventory']),
                'cash' => $money($fields['bs_cash']),
                'equity' => $money($bs['equity']),
            ];
        };

        return [
            'quarter' => 4,
            'straight_line' => $side((array) $stored->fields, (array) $stored->income_statement, (array) $stored->cash_flow_statement, (array) $stored->balance_sheet),
            'double_declining' => $side($declining->fields, $declining->incomeStatement, $declining->cashFlowStatement, $declining->balanceSheet),
        ];
    }

    private function referenceCourse(Faculty $faculty): Course
    {
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $course = $this->courses->saveSetup($faculty, [
            'name' => 'Tour reference course', 'institution_id' => $faculty->institution_id, 'scenario_id' => $scenario->id,
            'starts_on' => '2027-01-01', 'ends_on' => '2029-12-31', 'payment_mode' => null,
            // Method requests on, so the teams set their methods directly (Careless switches in Quarter 6).
            'cf_plus_enabled' => true, 'accounting_election_enabled' => true,
            'build_quarters' => 3, 'adversarial_quarters' => 2, 'post_bid_quarters' => 3,
        ]);
        $schedule = [];
        foreach (range(1, 11) as $quarter) {
            $start = now()->setDate(2027, 1, 1)->startOfDay()->addMonths(3 * ($quarter - 1));
            $end = $start->copy()->addMonths(3)->subDay();
            $schedule[] = ['quarter_number' => $quarter, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'decision_deadline_at' => $end->toDateString()];
        }
        $this->courses->saveSchedule($faculty, $course, $schedule);
        $matrix = [];
        foreach (range(4, 11) as $quarter) {
            foreach ($scenario->decisionCategories()->pluck('code') as $code) {
                $matrix[$quarter][$code] = isset(self::OPENS[$code]) && $quarter >= self::OPENS[$code];
            }
        }
        $this->courses->saveEnablements($faculty, $course, $matrix);
        $this->courses->saveFacultyRoles($faculty, $course, []);

        return $course->fresh();
    }

    /** The throwaway course was never deployed: it has setup rows only. */
    private function removeSource(Course $source): void
    {
        DB::transaction(function () use ($source): void {
            $quarters = DB::table('course_quarters')->where('course_id', $source->id)->pluck('id');
            DB::table('course_decision_enablements')->whereIn('course_quarter_id', $quarters)->delete();
            DB::table('course_quarters')->where('course_id', $source->id)->delete();
            DB::table('course_faculty_roles')->where('course_id', $source->id)->delete();
            DB::table('courses')->where('id', $source->id)->delete();
        });
    }
}
