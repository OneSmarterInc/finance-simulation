<?php

namespace Tests\Feature\Ai;

use App\Livewire\Faculty\Courses\AccountingElectionRequests;
use App\Livewire\Student\AccountingElection;
use App\Models\AccountingRequestReview;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\Student;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use App\Services\Ai\AskClaude;
use App\Services\Ai\AskClaudeTools;
use App\Services\Ai\RequestReviews;
use App\Services\Ai\ResultMeasures;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * U12 (decided 17 October 2026; RV1, 19 October 2026): Claude's review of
 * each accounting-method request, written once by the clock, checked,
 * stored, and shown to faculty after the team's reason, with the draft reply
 * waiting in the note box.
 */
class RequestReviewsTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    private const PROPOSAL = [
        'depreciation_method' => 'double_declining_balance',
        'shipping_terms' => 'fob_shipping_point',
        'bad_debt_method' => 'percentage_of_receivables',
        'warranty_method' => 'percentage_of_sales',
        'excise_tax_treatment' => 'cogs_inclusion',
    ];

    private const REASON = 'Our plant loses productivity fastest in its first years, so accelerated depreciation matches cost to benefit.';

    private const REPLY = 'Your reason says the plant loses productivity early, but it doesn\'t show it. Tell me what in the statements points that way, and file again.';

    private Course $course;

    private Firm $firm;

    private Student $student;

    private CourseQuarter $q4;

    private string $netIncome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['flexee.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
        [$course, $firm, $this->student, $q4] = $this->phase7bCourse('Reviewed requests');
        $course->forceFill(['accounting_election_enabled' => true])->save();
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        [$this->course, $this->firm, $this->q4] = [$course->fresh(['faculty']), $firm->fresh(), $q4->fresh()];
        $q3 = $this->firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $this->netIncome = ResultMeasures::format('net_income', ResultMeasures::value('net_income', $q3));
    }

    public function test_a_request_is_reviewed_once_checked_and_stored(): void
    {
        $request = app(FirmAccountingElectionRequestService::class)->submit($this->student, $this->firm, $this->q4, self::PROPOSAL, self::REASON);
        $this->fakeReview();
        $reviews = app(RequestReviews::class);

        $this->assertSame(1, $reviews->tick());
        $review = $request->claudeReview()->firstOrFail();

        $this->assertSame('ask', $review->recommendation);
        $this->assertSame([
            "Net income was {$this->netIncome} in Quarter 3, and the team's reason doesn't connect it to the plant.",
            'This is the firm\'s first request, so frequency is no concern.',
        ], $review->reasons, 'the reason with a figure the results never gave is dropped');
        $this->assertSame(self::REPLY, $review->reply);
        $this->assertSame(['12,345'], $review->dropped[0]['figures']);
        $this->assertGreaterThan(0, $review->cost_usd);
        $this->assertSame($review->cost_usd, round(AskClaude::spentThisMonth(), 4), 'reviews count against the site ceiling');
        $this->assertTrue($reviews->due(10)->isEmpty(), 'written once');

        Http::assertSent(function (Request $r): bool {
            $text = $r->data()['messages'][0]['content'];

            return $r->data()['tool_choice'] === ['type' => 'auto']
                && $r->data()['tools'][0]['name'] === 'write_review'
                && str_contains($r->data()['system'][0]['text'], 'Answer only by calling the write_review tool')
                && str_contains($text, 'Change: Depreciation method, from Straight-line to Double-declining balance.')
                && str_contains($text, '"'.self::REASON.'"')
                && str_contains($text, 'None. This is the firm\'s first request.')
                && str_contains($text, 'EVALUATION STAGE: none.')
                && str_contains($text, '  Depreciation: Quarter 1 ')
                && ! str_contains($text, (string) $this->student->user->email)
                && ! str_contains($text, (string) $this->student->user->name);
        });
    }

    public function test_a_request_without_a_reason_is_answered_in_code_with_no_call(): void
    {
        Http::fake();
        $request = FirmAccountingElectionRequest::query()->create([
            'firm_id' => $this->firm->id, 'course_id' => $this->course->id, 'course_quarter_id' => $this->q4->id, 'student_id' => $this->student->id,
            'proposed_elections' => self::PROPOSAL, 'justification' => null, 'status' => 'pending',
        ]);

        $review = app(RequestReviews::class)->write($request);

        $this->assertSame('ask', $review->recommendation);
        $this->assertSame('Please file again with your reason: why does double-declining balance describe your firm better than straight-line?', $review->reply);
        $this->assertSame(0.0, $review->cost_usd);
        Http::assertNothingSent();

        // Live review R25: the page doesn't promise a reason that isn't there.
        $this->actingAs($this->course->faculty->user);
        Livewire::test(AccountingElectionRequests::class, ['course' => $this->course])
            ->assertSee('This request has no reason, so the review asks for one.')
            ->assertSee('That quarter&#039;s notes will disclose the change, with no reason given.', false)
            ->assertDontSee('Written when the request was filed, from the team');
    }

    public function test_the_page_shows_the_review_after_the_reason_with_the_draft_reply_ready_to_send_back(): void
    {
        $request = app(FirmAccountingElectionRequestService::class)->submit($this->student, $this->firm, $this->q4, self::PROPOSAL, self::REASON);
        $this->actingAs($this->course->faculty->user);

        Livewire::test(AccountingElectionRequests::class, ['course' => $this->course])
            ->assertSee('data-test="review-switch"', false)
            ->assertSee('Claude is reviewing this request.');

        $this->fakeReview();
        app(RequestReviews::class)->tick();

        $page = Livewire::test(AccountingElectionRequests::class, ['course' => $this->course])
            ->assertSeeInOrder(['The team\'s reason', self::REASON, 'Claude\'s review', 'Suggests: Ask the team for more', "Net income was {$this->netIncome}"], false)
            ->assertDontSee('12,345')
            ->assertSet("notes.{$request->id}", self::REPLY)
            ->call('sendBack', $request->id);

        $request->refresh();
        $this->assertSame('returned', $request->status);
        $this->assertSame(self::REPLY, $request->faculty_note);
        Livewire::actingAs($this->student->user)->test(AccountingElection::class, ['firm' => $this->firm, 'quarter' => $this->q4])
            ->assertSee('Sent back by your instructor')
            ->assertSee(self::REPLY)
            ->assertDontSee('Suggests');

        $this->actingAs($this->course->faculty->user);
        $page->call('toggleReview');
        $this->assertFalse($this->course->fresh()->ai_request_review);
        $this->assertFalse(RequestReviews::enabled($this->course->fresh()));
    }

    public function test_a_review_that_fails_three_times_is_left_out(): void
    {
        $request = app(FirmAccountingElectionRequestService::class)->submit($this->student, $this->firm, $this->q4, self::PROPOSAL, self::REASON);
        Http::fake(['*' => Http::response(['error' => 'overloaded'], 529)]);
        $reviews = app(RequestReviews::class);
        foreach (range(1, 3) as $attempt) {
            $this->assertTrue($reviews->due(10)->contains('id', $request->id), "due on attempt {$attempt}");
            $reviews->tick();
            $this->travel(3)->minutes();
        }
        $this->assertFalse($reviews->due(10)->contains('id', $request->id));

        $this->actingAs($this->course->faculty->user);
        Livewire::test(AccountingElectionRequests::class, ['course' => $this->course])
            ->assertSee("Claude's review of this request couldn't be written.", false)
            ->assertSee('Approve');
    }

    public function test_ask_claude_sees_the_recommendation_and_reasons(): void
    {
        app(FirmAccountingElectionRequestService::class)->submit($this->student, $this->firm, $this->q4, self::PROPOSAL, self::REASON);
        $this->fakeReview();
        app(RequestReviews::class)->tick();

        $out = (new AskClaudeTools($this->course))->run('get_method_requests', ['status' => 'waiting']);

        $this->assertSame('Ask the team for more', $out['requests'][0]['claude_review']['suggests']);
        $this->assertCount(2, $out['requests'][0]['claude_review']['reasons']);
        $this->assertSame(AccountingRequestReview::query()->value('reasons'), $out['requests'][0]['claude_review']['reasons']);
    }

    private function fakeReview(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'write_review', 'input' => [
                'recommendation' => 'ask',
                'reasons' => [
                    "Net income was {$this->netIncome} in Quarter 3, and the team's reason doesn't connect it to the plant.",
                    'Output fell by 12,345 units, which the reason ignores.',
                    'This is the firm\'s first request, so frequency is no concern.',
                ],
                'reply' => self::REPLY,
            ]]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 2500, 'output_tokens' => 300],
        ])]);
    }
}
