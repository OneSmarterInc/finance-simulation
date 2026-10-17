<?php

namespace Tests\Feature\Phase8;

use App\Livewire\Student\FirmDashboard;
use App\Models\CourseFacultyRole;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmMembership;
use App\Models\Student;
use App\Support\Presentation\FinancialValueFormatter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class FirmDashboardTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_inherited_and_closed_quarters_render_literal_stored_statements_reports_trends_and_dividend(): void
    {
        [, $firm, $student] = $this->phase7bCourse('Dashboard fidelity');
        $this->appendResult($firm, 4, 500.00);
        $this->appendResult($firm, 5, 0.00);

        // The dashboard shows where the firm stands: the latest quarter beside the one before.
        Livewire::actingAs($student->user)->test(FirmDashboard::class, ['firm' => $firm])
            ->assertSee('Where your firm stands')
            ->assertSee('Q4')->assertSee('Q5')->assertDontSee('Q1 · warm-up')
            ->assertSee('Summary')->assertSee('Decisions')->assertSee('Income statement')->assertSee('Balance sheet')
            ->assertSee('Cash flow')->assertSee('Notes')
            ->assertSee('$123,456.78')->assertSee('$45,678.90')->assertSee('$77,777.88')
            ->assertSee('$12,345.67')->assertSee('$65,432.21')
            ->assertSee('$64,197.65')->assertSee('$48,148.24')
            ->assertSee('Revenue by region and product')->assertSee('Inventory')->assertSee('Property, plant and equipment')
            ->assertSee('Borrowing and financial ratios')->assertSee('Commitments, reserves and accruals')
            ->assertSee('Operating margin')->assertSee('12.3%')
            ->assertSee('Dividends paid')->assertSee('($500.00)');

        // History keeps every quarter, warm-up marked as inherited.
        Livewire::actingAs($student->user)->test(\App\Livewire\InheritedHistory::class, ['firm' => $firm])
            ->assertSee('Q1 · warm-up')->assertSee('Q2 · warm-up')->assertSee('Q3 · warm-up')->assertSee('Q4')->assertSee('Q5');
    }

    public function test_dashboard_is_read_only_and_navigation_does_not_mutate_phase7_records(): void
    {
        [, $firm, $student] = $this->phase7bCourse('Read only dashboard');
        $this->appendResult($firm, 4, 0.00);
        $hashes = $firm->engineResults()->orderBy('quarter_number')->pluck('payload_hash', 'id')->all();
        $counts = [FirmEngineResult::count(), $firm->quarterDecisions()->count(), $firm->quarterCloses()->count()];

        Livewire::actingAs($student->user)->test(FirmDashboard::class, ['firm' => $firm])
            ->assertDontSee('Save draft')->assertDontSee('Submit decision')->assertDontSee('Regenerate')->assertDontSee('Delete');
        Livewire::actingAs($student->user)->test(\App\Livewire\InheritedHistory::class, ['firm' => $firm])
            ->assertDontSee('Save draft')->assertDontSee('Submit decision')->assertDontSee('Regenerate')->assertDontSee('Delete');

        $this->assertSame($hashes, $firm->engineResults()->orderBy('quarter_number')->pluck('payload_hash', 'id')->all());
        $this->assertSame($counts, [FirmEngineResult::count(), $firm->quarterDecisions()->count(), $firm->quarterCloses()->count()]);
    }

    public function test_missing_optional_supplementary_reports_render_safely(): void
    {
        [, $firm, $student] = $this->phase7bCourse('Optional reports');
        $result = $this->appendResult($firm, 4, 0.00, []);

        $firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        Livewire::actingAs($student->user)->test(FirmDashboard::class, ['firm' => $firm])
            ->assertSee(\App\Support\Presentation\StatementPresenter::accounting((float) $result->income_statement['net_income']));
        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertOk();
    }

    public function test_guest_members_and_owner_access_have_identical_financial_content(): void
    {
        [$course, $firm, $student] = $this->phase7bCourse('Shared dashboard');
        $second = Student::factory()->create(['institution_id' => $course->institution_id]);
        $this->addMember($firm, $second, $course->faculty);
        $expected = FinancialValueFormatter::currency($firm->engineResults()->where('quarter_number', 3)->firstOrFail()->income_statement['net_income']);

        $this->get(route('firms.dashboard', $firm))->assertRedirect(route('login'));
        $this->actingAs($student->user)->get(route('firms.dashboard', [$firm, 'quarter' => 3]))->assertOk()->assertSee($expected);
        $this->actingAs($second->user)->get(route('firms.dashboard', [$firm, 'quarter' => 3]))->assertOk()->assertSee($expected);
        $this->actingAs($course->faculty->user)->get(route('firms.dashboard', [$firm, 'quarter' => 3]))->assertOk()->assertSee($expected);
    }

    public function test_cross_firm_cross_course_and_uuid_substitution_are_denied(): void
    {
        [$course, , $student] = $this->phase7bCourse('Authorized firm');
        $otherFirm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        [, $crossCourseFirm] = $this->phase7bCourse('Other course');
        $crossCourseFirm->memberships()->where('student_id', $student->id)->delete();

        $this->actingAs($student->user)->get(route('firms.dashboard', $otherFirm))->assertForbidden();
        $this->actingAs($student->user)->get(route('firms.dashboard', $crossCourseFirm))->assertForbidden();
        Livewire::actingAs($student->user)->test(FirmDashboard::class, ['firm' => $otherFirm])->assertForbidden();
        $this->actingAs($student->user)->get('/firms/'.fake()->uuid().'/dashboard')->assertNotFound();
    }

    public function test_authorized_cofaculty_is_allowed_but_unrelated_faculty_are_denied(): void
    {
        [$course, $firm] = $this->phase7bCourse('Faculty dashboard');
        $cofaculty = Faculty::factory()->create(['institution_id' => $course->institution_id]);
        $unrelated = Faculty::factory()->create(['institution_id' => $course->institution_id]);
        $crossInstitution = Faculty::factory()->create();
        CourseFacultyRole::query()->create(['course_id' => $course->id, 'faculty_id' => $cofaculty->id, 'role' => CourseFacultyRole::ROLE_CO_FACULTY]);

        $this->actingAs($cofaculty->user)->get(route('firms.dashboard', $firm))->assertOk()->assertSee($firm->name);
        $this->actingAs($unrelated->user)->get(route('firms.dashboard', $firm))->assertForbidden();
        $this->actingAs($crossInstitution->user)->get(route('firms.dashboard', $firm))->assertForbidden();
    }

    public function test_soft_deleted_membership_is_denied(): void
    {
        [, $firm, $student] = $this->phase7bCourse('Deleted membership');
        FirmMembership::query()->where('firm_id', $firm->id)->where('student_id', $student->id)->firstOrFail()->delete();
        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertForbidden();
    }

    /** @param array<string, mixed>|null $notes */
    private function appendResult(Firm $firm, int $quarter, float $dividend, ?array $notes = null): FirmEngineResult
    {
        $source = $firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $income = array_merge($source->income_statement, [
            'net_revenue' => 123456.78, 'cost_of_goods_sold' => 45678.90, 'gross_profit' => 77777.88,
            'operating_expenses' => 12345.67, 'operating_income' => 65432.21, 'interest_expense' => 1234.56,
            'income_before_income_taxes' => 64197.65, 'income_tax_expense' => 16049.41, 'net_income' => 48148.24,
        ]);
        $fields = $source->fields;
        $fields['bs_cash'] = 8888.88 + $quarter;
        $fields['n5_cov_margin'] = 0.1234;
        $fields['cf_equity'] = -$dividend;
        $balance = $source->balance_sheet;
        $balance['equity'] = 9999.99 + $quarter;
        $payload = [
            'firm_id' => $firm->id, 'course_id' => $firm->course_id, 'institution_id' => $firm->institution_id,
            'quarter_number' => $quarter, 'fields' => $fields, 'income_statement' => $income,
            'balance_sheet' => $balance, 'cash_flow_statement' => $source->cash_flow_statement,
            'notes' => $notes ?? $source->notes, 'intermediates' => $source->intermediates,
            'engine_contract' => FirmEngineResult::ENGINE_CONTRACT, 'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE,
            'immutable' => true, 'validated_at' => now(),
        ];

        return FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))]);
    }
}
