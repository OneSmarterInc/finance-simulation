<?php

namespace Tests\Feature\Walkthrough;

use App\Services\Decisions\FirmDecisionService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * House rule U5, walkthrough items 20, 37, 71: no internal names, codes or
 * developer wording on a student or faculty screen. Renders every main
 * page through HTTP and fails on any banned phrase in the visible text.
 */
class ScreenWordingTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    /** Phrases that must never reach a student or faculty screen. */
    private const BANNED = [
        'CF Plus', 'CF Manufacturing', 'FLEXEE', 'Phase 7', 'Phase 8', 'Phase 9', 'payload', 'engine result',
        'Persisted', 'Stored operating margin', 'read-only history', 'System-generated', 'dashboard-calculated',
        'SUP001', 'NONE', 'STANDARD', 'LOCKED', 'DRAFT', 'Fob Shipping Point', 'Cogs Inclusion',
        'Percentage Of Receivables', 'Ppe report', 'adversarial', 'post-bid', 'Laravel',
    ];

    /** Title-cased raw keys such as "Cf Net Income", "Bs Tech Net", "N5 Cov Curr". */
    private const RAW_KEY = '/\b(Cf|Bs|N[2-6]) [A-Z][a-z]+|\bIs (P1|P2|Raw|Labor|Returns|Rebates|End|Lifo|Carry|Excise|Freight|Sl|Inspection|Admin|Warranty|Interest|Disposal|Other|Tax)\b/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_student_and_faculty_screens_use_plain_words(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Wording');
        $course->forceFill(['accounting_election_enabled' => true])->save();
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->completePayload(), 0);

        $studentPages = [
            route('student.courses'),
            route('firms.dashboard', $firm),
            route('firms.history', $firm),
            route('student.decisions.workspace', [$firm, $q4]),
            route('student.accounting-election', [$firm, $q4]),
            route('guide'),
        ];
        foreach ($studentPages as $url) {
            $this->assertPlainWords($this->actingAs($student->user)->get($url)->assertOk()->getContent(), $url);
        }

        $facultyPages = [
            route('dashboard'),
            route('faculty.courses.wizard', $course),
            route('faculty.courses.firms', $course),
            route('faculty.courses.students', $course),
            route('faculty.courses.quarters', $course),
            route('faculty.courses.quarter-results', $course),
            route('faculty.courses.accounting-election-requests', $course),
            route('firms.history', $firm),
            route('guide'),
            route('home'),
        ];
        foreach ($facultyPages as $url) {
            $this->assertPlainWords($this->actingAs($course->faculty->user)->get($url)->assertOk()->getContent(), $url);
        }
    }

    private function assertPlainWords(string $html, string $url): void
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html)), ENT_QUOTES);
        // The course name in this test contains no banned words; strip whitespace noise.
        $text = preg_replace('/\s+/', ' ', $text);

        foreach (self::BANNED as $phrase) {
            $this->assertStringNotContainsString($phrase, $text, "{$url} shows \"{$phrase}\"");
        }
        $this->assertDoesNotMatchRegularExpression(self::RAW_KEY, $text, "{$url} shows a raw database key");
    }
}
