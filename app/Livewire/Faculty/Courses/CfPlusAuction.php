<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\Faculty;
use App\Services\CfPlus\CfPlusAuctionSettlementService;
use App\Services\CfPlus\CfPlusRevealService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CfPlusAuction extends Component
{
    public Course $course;

    public string $statusMessage = '';

    /** @var array<string, string> */
    public array $overrideWinners = [];

    public string $overrideReason = '';

    public function mount(Course $course, CfPlusAuctionSettlementService $settlement): void
    {
        $settlement->authorizeFaculty($this->faculty(), $course);
        abort_unless($course->cf_plus_enabled, 404);
        $this->course = $course;
    }

    public function settle(CfPlusAuctionSettlementService $settlement): void
    {
        $results = $settlement->settle($this->faculty(), $this->course);
        $this->statusMessage = "Auction settled for {$results->count()} targets.";
    }

    public function overrideTie(string $resultId, CfPlusAuctionSettlementService $settlement): void
    {
        $result = CfPlusAuctionResult::query()->where('course_id', $this->course->id)->findOrFail($resultId);
        $winner = $this->course->firms()->findOrFail($this->overrideWinners[$resultId] ?? '');
        $settlement->overrideTie($this->faculty(), $result, $winner, $this->overrideReason);
        $this->statusMessage = 'Tied top-offer winner overridden with an audit record.';
    }

    public function render(CfPlusRevealService $reveal): View
    {
        $settled = CfPlusAuctionResult::query()->where('course_id', $this->course->id)->with(['targetFirm', 'winner'])->orderBy('target_firm_id')->get();
        $participation = $this->course->firms()->withCount(['incomingAcquisitionOffers as submitted_offer_count' => fn ($query) => $query->where('course_id', $this->course->id)])->orderBy('name')->get();

        $bidderEvidence = $settled->isEmpty() ? collect() : $reveal->forFaculty($this->course, $this->faculty());

        return view('livewire.faculty.courses.cf-plus-auction', ['settled' => $settled, 'participation' => $participation, 'offerCount' => CfPlusOffer::query()->where('course_id', $this->course->id)->count(), 'bidderEvidence' => $bidderEvidence]);
    }

    private function faculty(): Faculty
    {
        $faculty = auth()->user()?->faculty;
        abort_unless($faculty !== null, 403);

        return $faculty;
    }
}
