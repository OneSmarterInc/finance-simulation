<?php

namespace Tests\Support\Phase7B;

use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Warmup\InheritedWarmupHistoryService;

trait BuildsPhase7BCourse
{
    /** @return array{Course, Firm, Student, CourseQuarter, CourseQuarter} */
    protected function phase7bCourse(string $name = 'Phase 7B Evidence'): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create(['institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id, 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'), 'name' => $name, 'starts_on' => '2026-09-01', 'ends_on' => '2028-01-01', 'status' => 'deployed']);
        CourseFacultyRole::query()->create(['course_id' => $course->id, 'faculty_id' => $faculty->id, 'role' => CourseFacultyRole::ROLE_OWNER]);
        $quarters = collect();
        foreach (range(1, 5) as $number) {
            $quarters[$number] = CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => $number, 'starts_on' => now()->addMonths($number)->toDateString(), 'ends_on' => now()->addMonths($number + 1)->toDateString(), 'decision_deadline_at' => now()->addMonths($number + 1), 'state' => $number <= 3 ? CourseQuarter::CLOSED : CourseQuarter::LOCKED]);
        }
        foreach ([4, 5] as $number) {
            foreach (['forecasting', 'sourcing', 'production'] as $code) {
                CourseDecisionEnablement::query()->create(['course_quarter_id' => $quarters[$number]->id, 'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', $code)->value('id'), 'enabled' => true]);
            }
        }
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $student = Student::query()->firstOrFail();
        $this->addMember($firm, $student, $faculty);
        app(InheritedWarmupHistoryService::class)->initialize($firm);

        return [$course->fresh(['faculty']), $firm->fresh(), $student, $quarters[4], $quarters[5]];
    }

    protected function addMember(Firm $firm, Student $student, Faculty $faculty): void
    {
        FirmMembership::query()->create(['firm_id' => $firm->id, 'course_id' => $firm->course_id, 'student_id' => $student->id, 'institution_id' => $firm->institution_id, 'assigned_by_faculty_id' => $faculty->id, 'assigned_at' => now()]);
    }

    /** @return array<string, mixed> */
    protected function completePayload(int $offset = 0): array
    {
        return ['forecasting' => ['forecast_p1_r1' => 30000 + $offset, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1, 'secondary_supplier' => 'NONE'],
            'production' => ['p1_production' => 130000 + $offset, 'p2_production' => 61000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0]];
    }
}
