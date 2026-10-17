<?php

namespace Tests\Feature\Phase7B;

use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Decisions\FirmDecisionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DecisionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_same_firm_members_share_one_draft_and_other_firm_is_isolated(): void
    {
        [$firm, $quarter, $students] = $this->workspace();
        $service = app(FirmDecisionService::class);
        $payload = $this->payload();
        $saved = $service->saveDraft($students[0], $firm, $quarter, $payload, 0);
        $service->authorize($students[1], $firm);
        $this->assertEquals($payload, $service->decision($firm, $quarter)->draft_payload);
        $this->assertSame($saved->id, $service->decision($firm, $quarter)->id);
        $this->assertDatabaseCount('firm_quarter_decisions', 1);
    }

    public function test_closed_category_injection_and_outsider_access_are_rejected(): void
    {
        [$firm, $quarter, $students] = $this->workspace();
        $service = app(FirmDecisionService::class);
        try {
            $service->saveDraft($students[0], $firm, $quarter, $this->payload() + ['pricing' => ['price_p1_r1' => 1]], 0);
            $this->fail('Closed category injection was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pricing', $e->errors());
        }
        $this->expectException(AuthorizationException::class);
        $service->authorize(Student::query()->whereNotIn('id', $students->pluck('id'))->firstOrFail(), $firm);
    }

    public function test_q1_q3_and_locked_quarters_reject_direct_writes(): void
    {
        [$firm, $quarter, $students] = $this->workspace();
        $quarter->forceFill(['state' => CourseQuarter::CLOSED])->save();
        $this->expectException(ValidationException::class);
        app(FirmDecisionService::class)->saveDraft($students[0], $firm, $quarter, $this->payload(), 0);
    }

    /** @return array{Firm, CourseQuarter, Collection<int, Student>} */
    private function workspace(): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create(['institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id, 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'), 'name' => 'Workspace', 'starts_on' => '2026-09-01', 'ends_on' => '2027-09-01', 'status' => 'deployed']);
        $quarter = CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => 4, 'starts_on' => '2026-09-01', 'ends_on' => '2026-11-01', 'decision_deadline_at' => '2026-10-31', 'state' => CourseQuarter::OPEN]);
        foreach (['forecasting', 'sourcing', 'production'] as $code) {
            CourseDecisionEnablement::query()->create(['course_quarter_id' => $quarter->id, 'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', $code)->value('id'), 'enabled' => true]);
        }
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $students = Student::query()->take(2)->get();
        foreach ($students as $student) {
            FirmMembership::query()->create(['firm_id' => $firm->id, 'course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id, 'assigned_by_faculty_id' => $faculty->id, 'assigned_at' => now()]);
        }

        return [$firm, $quarter, $students];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['forecasting' => ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'production' => ['p1_production' => 130000, 'p2_production' => 61000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0]];
    }
}
