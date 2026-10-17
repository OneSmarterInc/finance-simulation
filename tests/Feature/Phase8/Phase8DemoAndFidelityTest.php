<?php

namespace Tests\Feature\Phase8;

use App\Livewire\Student\FirmDashboard;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\FirmEngineResult;
use App\Support\Presentation\FinancialValueFormatter;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Phase8DemoSeeder;
use Database\Seeders\Phase8OverdraftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase8DemoAndFidelityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_standard_seed_excludes_the_opt_in_phase8_demo(): void
    {
        $this->assertDatabaseMissing('courses', ['name' => Phase8DemoSeeder::CLOSED_COURSE]);
        $this->assertDatabaseMissing('courses', ['name' => Phase8DemoSeeder::WALKTHROUGH_COURSE]);
        $this->assertDatabaseMissing('courses', ['name' => Phase8OverdraftDemoSeeder::COURSE]);

        $source = file_get_contents((new \ReflectionClass(Phase8DemoSeeder::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('FirmEngineResult', $source);
        $this->assertStringNotContainsString('DB::', $source);
    }

    public function test_opt_in_demo_uses_real_services_and_is_complete_and_idempotent(): void
    {
        $this->seed(Phase8DemoSeeder::class);

        $closed = Course::query()->where('name', Phase8DemoSeeder::CLOSED_COURSE)->firstOrFail();
        $walkthrough = Course::query()->where('name', Phase8DemoSeeder::WALKTHROUGH_COURSE)->firstOrFail();
        $closedFirms = $closed->firms()->withCount(['memberships', 'engineResults'])->orderBy('code')->get();

        $this->assertCount(2, $closedFirms);
        $this->assertSame([1, 2], $closedFirms->pluck('memberships_count')->sort()->values()->all());
        $this->assertSame([4, 4], $closedFirms->pluck('engine_results_count')->all());
        $this->assertSame(CourseQuarter::CLOSED, $closed->quarters()->where('quarter_number', 4)->value('state'));
        $this->assertSame(CourseQuarter::OPEN, $walkthrough->quarters()->where('quarter_number', 4)->value('state'));
        $this->assertSame(1, $walkthrough->firms()->count());
        $this->assertSame(3, $walkthrough->firms()->firstOrFail()->engineResults()->count());

        $results = FirmEngineResult::query()->whereIn('course_id', [$closed->id, $walkthrough->id])->get();
        $this->assertCount(11, $results);
        $this->assertTrue($results->every(fn (FirmEngineResult $result): bool => $result->immutable && $result->validated_at !== null));
        $this->assertTrue($results->every(fn (FirmEngineResult $result): bool => preg_match('/^[a-f0-9]{64}$/', $result->payload_hash) === 1));
        $this->assertSame(
            [FirmEngineResult::PROVENANCE_WARMUP, FirmEngineResult::PROVENANCE_STUDENT_CLOSE],
            $results->pluck('provenance')->unique()->sort()->values()->all(),
        );

        $firmAQuarterFour = $closedFirms->firstWhere('code', 'P8-A')->engineResults()->where('quarter_number', 4)->firstOrFail();
        $this->assertSame(-250000.0, (float) $firmAQuarterFour->fields['cf_equity']);
        $this->assertSame(-250000.0, (float) $firmAQuarterFour->cash_flow_statement['line_items']['cf_equity']);

        $snapshot = $results->sortBy('id')->map(fn (FirmEngineResult $result): array => [$result->id, $result->payload_hash, $result->created_at->toJSON(), $result->updated_at->toJSON()])->values()->all();
        $this->seed(Phase8DemoSeeder::class);
        $after = FirmEngineResult::query()->whereIn('course_id', [$closed->id, $walkthrough->id])->get()->sortBy('id');
        $this->assertSame($snapshot, $after->map(fn (FirmEngineResult $result): array => [$result->id, $result->payload_hash, $result->created_at->toJSON(), $result->updated_at->toJSON()])->values()->all());
    }

    public function test_genuine_inherited_and_closed_results_render_exact_persisted_values(): void
    {
        $this->seed(Phase8DemoSeeder::class);

        $course = Course::query()->where('name', Phase8DemoSeeder::CLOSED_COURSE)->firstOrFail();
        $firm = $course->firms()->where('code', 'P8-A')->firstOrFail();
        $student = $firm->memberships()->with('student.user')->firstOrFail()->student;
        $results = $firm->engineResults()->orderBy('quarter_number')->get()->keyBy('quarter_number');
        $q1 = $results->get(1);
        $q4 = $results->get(4);

        $this->assertInstanceOf(FirmEngineResult::class, $q1);
        $this->assertInstanceOf(FirmEngineResult::class, $q4);
        $this->assertSame(FirmEngineResult::PROVENANCE_WARMUP, $q1->provenance);
        $this->assertSame(FirmEngineResult::PROVENANCE_STUDENT_CLOSE, $q4->provenance);

        $component = Livewire::actingAs($student->user)->test(FirmDashboard::class, ['firm' => $firm]);

        foreach ($results as $quarter => $result) {
            $component
                ->assertSee('Q'.$quarter)
                ->assertSee(FinancialValueFormatter::currency($result->income_statement['net_revenue']))
                ->assertSee(FinancialValueFormatter::currency($result->fields['bs_cash']))
                ->assertSee(FinancialValueFormatter::currency($result->balance_sheet['equity']))
                ->assertSee(number_format((float) $result->fields['n5_cov_margin'], 4));
        }

        $component->call('selectQuarter', 1)->assertSee('System-generated inherited read-only history');
        $this->assertStatementPayloadIsRendered($component, $q1);
        foreach (['Prepaid Expenses', 'Accrued Wages', 'Accrued Marketing', 'Deferred Revenue', 'Lease Liability', 'Deferred Tax Liability'] as $label) {
            $component->assertSeeInOrder([$label, '$0.00']);
        }

        $component
            ->call('selectQuarter', 4)
            ->assertSee('Closed student quarter - read-only')
            ->assertSeeInOrder([
                'Operating Income',
                'Interest Expense',
                'Income Before Income Taxes',
                'Income Tax Expense',
                'Net Income',
            ]);
        $this->assertStatementPayloadIsRendered($component, $q4);

        $component
            ->assertSee('Dividend paid (stored financing movement)')
            ->assertSee(FinancialValueFormatter::currency($q4->fields['cf_equity']))
            ->assertSee('Bank Overdraft')
            ->assertSee(FinancialValueFormatter::currency($q4->fields['bs_bank_overdraft']))
            ->assertSee(FinancialValueFormatter::currency($q4->fields['cf_overdraft_chg']))
            ->assertSee('The current simulation does not apply an interest cost to bank overdraft.')
            ->assertDontSee('Total Current Assets')
            ->assertDontSee('Total Current Liabilities')
            ->assertDontSee('Total Liabilities and Equity')
            ->assertDontSee('Save')
            ->assertDontSee('Submit')
            ->assertDontSee('Delete')
            ->assertDontSee('Regenerate');
    }

    public function test_real_service_overdraft_result_renders_separately_with_exact_stored_values(): void
    {
        $this->seed(Phase8OverdraftDemoSeeder::class);

        $course = Course::query()->where('name', Phase8OverdraftDemoSeeder::COURSE)->firstOrFail();
        $firm = $course->firms()->where('code', Phase8OverdraftDemoSeeder::FIRM_CODE)->firstOrFail();
        $student = $firm->memberships()->with('student.user')->firstOrFail()->student;
        $result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();

        $this->assertSame(FirmEngineResult::PROVENANCE_STUDENT_CLOSE, $result->provenance);
        $this->assertTrue($result->immutable);
        $this->assertNotNull($result->validated_at);
        $this->assertGreaterThan(0.01, (float) $result->fields['bs_bank_overdraft']);
        $this->assertSame((float) $result->fields['bs_bank_overdraft'], (float) $result->fields['cf_overdraft_chg']);
        $this->assertSame(1.0, (float) $result->fields['n5_shortfall_indicator']);
        $this->assertSame(0.0, (float) $result->fields['bs_cash']);
        $this->assertSame(12500000.0, (float) $result->fields['cf_borrow_proc']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->payload_hash);

        Livewire::actingAs($student->user)->test(FirmDashboard::class, ['firm' => $firm])
            ->assertSee('Long-Term Debt')
            ->assertSee('Bank Overdraft')
            ->assertSee(FinancialValueFormatter::currency($result->fields['bs_bank_overdraft']))
            ->assertSee('Financing movement')
            ->assertSee(FinancialValueFormatter::currency($result->fields['cf_overdraft_chg']))
            ->assertSee('Financial shortfall indicated')
            ->assertSee('Bank overdraft represents cash usage beyond')
            ->assertSee('committed borrowing facility')
            ->assertSee('The current simulation does not apply an interest cost to bank overdraft.')
            ->assertDontSee('Overdraft interest expense');
    }

    private function assertStatementPayloadIsRendered($component, FirmEngineResult $result): void
    {
        foreach ([
            'net_revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses',
            'operating_income', 'interest_expense', 'income_before_income_taxes',
            'income_tax_expense', 'net_income',
        ] as $key) {
            $component->assertSee(FinancialValueFormatter::currency($result->income_statement[$key]));
        }

        foreach (['assets', 'liabilities', 'equity'] as $key) {
            $component->assertSee(FinancialValueFormatter::currency($result->balance_sheet[$key]));
        }
        foreach (['bs_cash', 'bs_ar', 'bs_inventory', 'bs_ap', 'bs_curr_ltd', 'bs_ltd', 'bs_bank_overdraft', 'bs_retained'] as $key) {
            $component->assertSee(FinancialValueFormatter::currency($result->balance_sheet['line_items'][$key]));
        }

        foreach (['operating_cash_flow', 'investing_cash_flow', 'financing_cash_flow', 'ending_cash'] as $key) {
            $component->assertSee(FinancialValueFormatter::currency($result->cash_flow_statement[$key]));
        }
        foreach (['cf_equity', 'cf_overdraft_chg'] as $key) {
            $component->assertSee(FinancialValueFormatter::currency($result->cash_flow_statement['line_items'][$key]));
        }

        $this->assertCount(5, $result->notes);
        foreach (['Revenue report', 'Inventory report', 'Ppe report', 'Debt report', 'Commitments report'] as $heading) {
            $component->assertSee($heading);
        }
        foreach ($result->notes as $report) {
            $this->assertIsArray($report);
            $firstKey = array_key_first($report);
            $this->assertNotNull($firstKey);
            $value = $report[$firstKey];
            $expected = in_array($firstKey, FirmDashboard::NON_MONETARY_NOTE_KEYS, true)
                ? FinancialValueFormatter::number($value)
                : FinancialValueFormatter::currency($value);
            $component->assertSee($expected);
        }
    }
}
