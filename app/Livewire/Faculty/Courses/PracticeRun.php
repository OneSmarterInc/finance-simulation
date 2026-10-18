<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Services\CfPlus\CfPlusAcquisitionFundingService;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * U9 layer 2: the Practice run page. Three reference teams play a private
 * copy of the course, so faculty can see how it unfolds before teaching it.
 * The page belongs to the course being rehearsed; the run itself is a
 * practice course kept out of every list.
 */
#[Layout('layouts.app')]
class PracticeRun extends Component
{
    public Course $course;

    public int $target = 4;

    public bool $confirmingStartAgain = false;

    public function mount(Course $course): void
    {
        $faculty = auth()->user()?->faculty;
        abort_unless($faculty !== null && $course->faculty_id === $faculty->id && ! $course->trashed(), 403);
        abort_if($course->is_practice, 404);
        $this->course = $course;
        $run = app(PracticeRunService::class)->current($course);
        $this->target = $run ? min($this->lastQuarter($run), max(4, app(PracticeRunService::class)->playedTo($run) + 1)) : 4;
    }

    public function start(PracticeRunService $runs): void
    {
        $this->attempt(fn () => $runs->current($this->course) ?? $runs->create($this->faculty(), $this->course));
        $this->target = 4;
    }

    public function play(PracticeRunService $runs): void
    {
        $run = $this->run($runs);
        $this->attempt(fn () => $runs->playTo($this->faculty(), $run, $this->target));
        $this->target = min($this->lastQuarter($run), $runs->playedTo($run->fresh()) + 1);
    }

    public function playToEnd(PracticeRunService $runs): void
    {
        $run = $this->run($runs);
        $this->target = $this->lastQuarter($run);
        $this->attempt(fn () => $runs->playTo($this->faculty(), $run, $this->target));
    }

    public function startAgain(PracticeRunService $runs): void
    {
        $run = $this->run($runs);
        $this->attempt(fn () => $runs->startAgain($this->faculty(), $run));
        $this->confirmingStartAgain = false;
        $this->target = 4;
    }

    public function render(PracticeRunService $runs): View
    {
        $run = $runs->current($this->course);
        $played = $run ? $runs->playedTo($run) : 0;
        $board = $run ? $runs->scoreboard($run) : [];
        $leaders = $run ? $runs->leaders($run) : [];
        $last = $run ? $this->lastQuarter($run) : (int) $this->course->quarters()->max('quarter_number');

        return view('livewire.faculty.courses.practice-run', [
            'run' => $run,
            'played' => $played,
            'last' => $last,
            'board' => $board,
            'leaders' => $leaders,
            'careful_leads' => $leaders !== [] && collect($leaders)->every(fn (string $team): bool => $team === 'careful'),
            'teams' => ReferenceTeams::TEAMS,
            'names' => ReferenceTeams::NAMES,
            'rules' => ReferenceTeams::RULES,
            'can_rehearse' => $this->course->engineVersion() === 2,
            'evaluation' => $run ? $runs->evaluation($run) : null,
            'bid_rules' => PracticeRunService::BID_RULES,
            'stake' => (int) round(CfPlusAcquisitionFundingService::CONTROL_STAKE * 100),
        ]);
    }

    private function run(PracticeRunService $runs): Course
    {
        $run = $runs->current($this->course);
        abort_if($run === null, 404);

        return $run;
    }

    private function lastQuarter(Course $run): int
    {
        return (int) $run->quarters()->max('quarter_number');
    }

    private function faculty()
    {
        return auth()->user()->faculty;
    }

    private function attempt(callable $work): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            $this->addError('practice', collect($e->errors())->flatten()->first() ?? 'The practice run could not be played.');
        }
    }
}
