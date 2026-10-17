<?php

namespace Tests\Feature\Phase8;

use App\Livewire\InheritedHistory;
use App\Livewire\Student\FirmDashboard;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\FirmEngineResult;
use App\Support\Presentation\StatementPresenter;
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

        // History is the complete record: every quarter side by side (walkthrough items 40, 41).
        $component = Livewire::actingAs($student->user)->test(InheritedHistory::class, ['firm' => $firm]);

        $component->assertSee('Q1 · warm-up')->assertSee('Q2 · warm-up')->assertSee('Q3 · warm-up')->assertSee('Q4');
        foreach ($results as $result) {
            $this->assertStatementPayloadIsRendered($component, $result);
        }

        // Classified balance sheet (N2) and sectioned cash flow (item 39).
        $component->assertSeeInOrder(['Current assets', 'Total current assets', 'Property, plant and equipment, net', 'Total assets',
            'Current liabilities', 'Total current liabilities', 'Total long-term liabilities', 'Total liabilities', 'Total equity', 'Total liabilities and equity']);
        $component->assertSeeInOrder(['Operating activities', 'Net cash from operating activities', 'Investing activities',
            'Net cash from investing activities', 'Financing activities', 'Net cash from financing activities',
            'Net change in cash', 'Cash at beginning of quarter', 'Cash at end of quarter']);
        $component->assertSeeInOrder(['Gross profit', 'Operating income', 'Income before income taxes', 'Income tax expense', 'Net income']);

        // Dividend shown by its effect on cash, in parentheses (item 38).
        $component->assertSee('Dividends paid')->assertSee(StatementPresenter::accounting((float) $q4->fields['cf_equity']));
        $this->assertStringStartsWith('(', StatementPresenter::accounting((float) $q4->fields['cf_equity']));

        // Raw keys never reach the screen (item 37).
        foreach (['Cf Net Income', 'Cf Depr', 'Bs Tech Net', 'N5 Cov Curr', 'Ppe report'] as $raw) {
            $component->assertDontSee($raw);
        }

        $component->assertDontSee('Submit')->assertDontSee('Delete')->assertDontSee('Regenerate');
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
            ->assertSee('Long-term debt')
            ->assertSee('Bank overdraft')
            ->assertSee(StatementPresenter::accounting((float) $result->fields['bs_bank_overdraft']))
            ->assertSee('Change in bank overdraft')
            ->assertSee(StatementPresenter::accounting((float) $result->fields['cf_overdraft_chg']))
            ->assertSeeInOrder(['Financial shortfall', 'Yes'])
            ->assertSee('committed borrowing facility')
            ->assertSee('the simulation charges no interest on it')
            ->assertDontSee('Overdraft interest expense');
    }

    private function assertStatementPayloadIsRendered($component, FirmEngineResult $result): void
    {
        foreach ([
            'net_revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses',
            'operating_income', 'income_before_income_taxes', 'net_income',
        ] as $key) {
            $component->assertSee(StatementPresenter::accounting((float) $result->income_statement[$key]));
        }
        // Interest and tax are deductions, shown in parentheses.
        foreach (['interest_expense', 'income_tax_expense'] as $key) {
            $component->assertSee(StatementPresenter::accounting(-(float) $result->income_statement[$key]));
        }

        foreach (['assets', 'liabilities', 'equity'] as $key) {
            $component->assertSee(StatementPresenter::accounting((float) $result->balance_sheet[$key]));
        }
        foreach (['bs_cash', 'bs_ar', 'bs_ap', 'bs_ltd', 'bs_retained'] as $key) {
            $value = (float) $result->balance_sheet['line_items'][$key];
            if (abs($value) >= 0.005) {
                $component->assertSee(StatementPresenter::accounting($value));
            }
        }

        foreach (['operating_cash_flow', 'investing_cash_flow', 'financing_cash_flow', 'ending_cash'] as $key) {
            $component->assertSee(StatementPresenter::accounting((float) $result->cash_flow_statement[$key]));
        }

        $this->assertCount(5, $result->notes);
        foreach (StatementPresenter::NOTES as $heading) {
            $component->assertSee($heading);
        }
        $component->assertSee(StatementPresenter::format((float) $result->fields['n5_cov_margin'], 'percent'));
        $component->assertSee(StatementPresenter::format((float) $result->fields['n5_cov_curr'], 'ratio'));
    }
}
