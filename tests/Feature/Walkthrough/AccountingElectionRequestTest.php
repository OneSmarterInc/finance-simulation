<?php

namespace Tests\Feature\Walkthrough;

use App\Livewire\Faculty\Courses\AccountingElectionRequests;
use App\Livewire\InheritedHistory;
use App\Livewire\Student\AccountingElection;
use App\Models\Student;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** Walkthrough items 56 to 62 and 82 to 84; decision N7. */
class AccountingElectionRequestTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_one_team_request_per_quarter_with_a_required_reason_and_withdraw_to_replace(): void
    {
        [$course, $firm, $student, $q4] = $this->course();
        $teammate = Student::factory()->create(['institution_id' => $course->institution_id]);
        $this->addMember($firm, $teammate, $course->faculty);
        $service = app(FirmAccountingElectionRequestService::class);

        $this->expectRefused(fn () => $service->submit($student, $firm, $q4, self::PROPOSAL, 'Because.'));
        $this->expectRefused(fn () => $service->submit($student, $firm, $q4, [...self::PROPOSAL, 'depreciation_method' => 'straight_line'], self::REASON));

        $request = $service->submit($student, $firm, $q4, self::PROPOSAL, self::REASON);
        $this->expectRefused(fn () => $service->submit($teammate, $firm, $q4, self::PROPOSAL, self::REASON));

        $service->withdraw($teammate, $request);
        $replacement = $service->submit($teammate, $firm, $q4, self::PROPOSAL, self::REASON);
        $this->assertSame('pending', $replacement->status);

        Livewire::actingAs($student->user)->test(AccountingElection::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertSee('Waiting for your instructor')
            ->assertSee('Straight-line')->assertSee('Double-declining balance')
            ->assertSee('Puts more depreciation in the early years');
    }

    public function test_faculty_see_current_and_proposed_and_approval_applies_and_is_disclosed(): void
    {
        [$course, $firm, $student, $q4] = $this->course();
        $request = app(FirmAccountingElectionRequestService::class)->submit($student, $firm, $q4, self::PROPOSAL, self::REASON);

        Livewire::actingAs($course->faculty->user)->test(AccountingElectionRequests::class, ['course' => $course])
            ->assertSee($firm->name)
            ->assertSee('Filed for the team by')
            ->assertSee('Straight-line')->assertSee('Double-declining balance')
            ->assertSee('accelerated depreciation matches cost to benefit')
            ->assertSee('applies to Quarter 4')
            ->call('approve', $request->id);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame(4, $request->effective_quarter_number);
        $this->assertSame('straight_line', $request->previous_elections['depreciation_method']);
        $this->assertSame('double_declining_balance', app(FirmAccountingElectionService::class)->effective($firm, $q4)['depreciation_method']);

        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->completePayload(), 0);
        app(QuarterManagementService::class)->queueClose($course->faculty, $q4);

        Livewire::actingAs($student->user)->test(InheritedHistory::class, ['firm' => $firm])
            ->assertSee('Changes in accounting methods')
            ->assertSee('changed its depreciation method from Straight-line to Double-declining balance')
            ->assertSee('accelerated depreciation matches cost to benefit');
    }

    public function test_decline_sends_the_team_a_note(): void
    {
        [$course, $firm, $student, $q4] = $this->course();
        $request = app(FirmAccountingElectionRequestService::class)->submit($student, $firm, $q4, self::PROPOSAL, self::REASON);

        Livewire::actingAs($course->faculty->user)->test(AccountingElectionRequests::class, ['course' => $course])
            ->set("notes.{$request->id}", 'Show me the productivity data first.')
            ->call('reject', $request->id);

        $this->assertSame('rejected', $request->fresh()->status);
        Livewire::actingAs($student->user)->test(AccountingElection::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertSee('Declined')->assertSee('Show me the productivity data first.')
            ->assertSee('file another request next quarter');
    }

    private function course(): array
    {
        [$course, $firm, $student, $q4, $q5] = $this->phase7bCourse('Election requests');
        $course->forceFill(['accounting_election_enabled' => true])->save();
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        return [$course->fresh(['faculty']), $firm->fresh(), $student, $q4->fresh(), $q5];
    }

    private function expectRefused(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the request to be refused.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
