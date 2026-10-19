<?php

namespace Tests\Feature\Ai;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Student;
use App\Services\Ai\AskClaude;
use App\Services\Ai\AskClaudeTools;
use App\Services\Ai\FigureCheck;
use App\Services\Ai\ResultMeasures;
use App\Services\Interpretations\InterpretationService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * U11 layer 2 (AE1): Ask Claude reads the teams' explanations, can say where
 * a claim doesn't match the stored results, quotes the students' words but
 * never states their figures as fact, and never grades.
 */
class AskClaudeExplanationsTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    private const ANSWERS = [
        'changed' => 'Cash rose to $9.9 million and net revenue rose by 40% on the quarter before.',
        'why' => 'Customers ordered more in the busy season, and the simulation produced to match.',
        'notes' => 'The inventory note shows goods in transit from the supplier that the statements fold in.',
    ];

    private Course $course;

    private Firm $firm;

    private Student $student;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['flexee.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
        [$this->course, $this->firm, $this->student, $q4] = $this->phase7bCourse('Explained course');
        $this->faculty = $this->course->faculty;
        $this->course->forceFill(['interpretations_required' => true])->save();
        app(QuarterManagementService::class)->open($this->faculty, $q4);
        app(InterpretationService::class)->submit($this->student, $this->firm->fresh(), $q4->fresh(), self::ANSWERS);
    }

    public function test_the_lookup_gives_the_explanations_with_students_as_pseudonyms_and_keeps_their_words_apart(): void
    {
        $tools = new AskClaudeTools($this->course);
        $out = $tools->run('get_team_explanations', []);

        $this->assertTrue($out['course_asks_for_explanations']);
        $this->assertSame(3, $out['quarter']);
        $row = $out['firms'][0];
        $this->assertStringStartsWith('submitted by Student 1 on ', $row['status']);
        $this->assertSame(self::ANSWERS['changed'], $row['answers']['What changed?']);
        $this->assertSame(['Student 1' => $this->student->user->name], $tools->names());
        $this->assertStringNotContainsString((string) $this->student->user->email, json_encode($out));
        $this->assertContains(self::ANSWERS['changed'], $tools->quotable());
        $this->assertArrayNotHasKey('answers', AskClaudeTools::factual('get_team_explanations', $out)['firms'][0]);
    }

    public function test_claude_may_quote_a_teams_figure_but_not_state_it_as_fact(): void
    {
        $q3 = $this->firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $cash = ResultMeasures::format('cash', ResultMeasures::value('cash', $q3));
        $name = $this->firm->name;
        Http::fake(['*' => Http::sequence()
            ->push(['content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_team_explanations', 'input' => ['quarter' => 3]],
                ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'get_results', 'input' => ['measures' => ['cash'], 'quarters' => [3]]],
            ], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 900, 'output_tokens' => 40]])
            ->push(['content' => [['type' => 'text', 'text' => "{$name} wrote that cash \"rose to \$9.9 million\". The stored results show cash of {$cash} at the end of Quarter 3. {$name}'s net revenue rose by 40%."]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1100, 'output_tokens' => 90]]),
        ]);

        $result = app(AskClaude::class)->ask($this->faculty, $this->course, ['page' => 'faculty.courses.explanations', 'label' => 'Team explanations', 'details' => []], 'Where do the explanations not match the results?');

        $this->assertSame("{$name} wrote that cash \"rose to \$9.9 million\". The stored results show cash of {$cash} at the end of Quarter 3.", $result['answer']);
        $this->assertSame([['sentence' => "{$name}'s net revenue rose by 40%.", 'figures' => ['40%']]], $result['dropped'], 'a student\'s figure stated as fact is dropped');
        $this->assertSame("the teams' explanations of Quarter 3, 1 of 1 submitted", $result['lookups'][0]['summary']);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['system'][0]['text'] ?? '', 'never suggest a grade or mark'));
    }

    public function test_the_figure_check_lets_a_quoted_figure_through_only_from_students_words(): void
    {
        $check = FigureCheck::apply('They wrote "cash rose 40%". Cash rose 40%. They wrote "profit was $5".', ['cash was $10'], ['cash rose 40%']);

        $this->assertSame('They wrote "cash rose 40%".', $check['answer']);
        $this->assertSame(['40%', '$5'], array_merge(...array_column($check['dropped'], 'figures')));
    }

    public function test_the_team_explanations_page_suggests_questions_about_them(): void
    {
        $this->course->forceFill(['ai_helper_enabled' => true])->save();
        $this->actingAs($this->faculty->user)
            ->get(route('faculty.courses.explanations', $this->course).'?quarter=3')
            ->assertOk()
            ->assertSee('data-test="ask-claude-button"', false)
            ->assertSee('Where do the explanations not match the teams&#039; results?', false);
    }
}
