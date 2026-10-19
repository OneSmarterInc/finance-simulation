<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Services\CfPlus\CfPlusAcquisitionFundingService;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Services\Practice\TeamSettings;
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

    /** Layer 6: the in-page check before removing the run. */
    public bool $confirmingRemove = false;

    /** Layer 5: the team settings form is open. */
    public bool $editingTeams = false;

    /** @var array<string, array<string, mixed>> the settings as typed */
    public array $settings = [];

    /** @var array<string, bool> teams whose "More settings" are showing */
    public array $moreOpen = [];

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

    public function remove(PracticeRunService $runs): void
    {
        $run = $this->run($runs);
        $runs->remove($this->faculty(), $run);
        $this->confirmingRemove = false;
        $this->confirmingStartAgain = false;
        $this->editingTeams = false;
        $this->target = 4;
    }

    public function editTeams(PracticeRunService $runs): void
    {
        $this->settings = $runs->settings($this->course->fresh());
        $this->editingTeams = true;
        $this->moreOpen = [];
        $this->resetErrorBag();
    }

    public function toggleMore(string $team): void
    {
        abort_unless(in_array($team, ReferenceTeams::TEAMS, true), 404);
        $this->moreOpen[$team] = ! ($this->moreOpen[$team] ?? false);
    }

    public function standardTeam(string $team): void
    {
        abort_unless(in_array($team, ReferenceTeams::TEAMS, true), 404);
        $this->settings[$team] = TeamSettings::STANDARD[$team];
    }

    public function cancelTeams(): void
    {
        $this->editingTeams = false;
        $this->resetErrorBag();
    }

    public function saveTeams(PracticeRunService $runs): void
    {
        $this->resetErrorBag();
        try {
            $run = $runs->saveSettings($this->faculty(), $this->course, $this->settings);
        } catch (ValidationException $e) {
            $more = array_merge(...array_values(TeamSettings::MORE_GROUPS));
            foreach ($e->errors() as $key => $messages) {
                $this->addError(str_starts_with($key, 'settings.') ? $key : 'practice', $messages[0]);
                // An error in a setting under "More settings" opens them, so it can be seen.
                if (preg_match('/^settings\.(\w+)\.(\w+)$/', $key, $m) && in_array($m[2], $more, true)) {
                    $this->moreOpen[$m[1]] = true;
                }
            }

            return;
        }
        $this->course->refresh();
        $this->editingTeams = false;
        if ($run) {
            $this->target = min($this->lastQuarter($run), $runs->playedTo($run) + 1);
        }
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
            'rules' => $runs->rules($run ?? $this->course),
            'custom' => $runs->customTeams($run ?? $this->course),
            'summaries' => collect($runs->settings($run ?? $this->course))->map(fn (array $s, string $team): string => TeamSettings::changeSummary($team, $s))->all(),
            'leader_spans' => $this->leaderSpans($leaders),
            'can_rehearse' => $this->course->engineVersion() === 2,
            'evaluation' => $run ? $runs->evaluation($run) : null,
            'removed_on' => $run ? $runs->removedOn($run) : null,
            'keep_days' => PracticeRunService::KEEP_DAYS,
            'bid_rules' => $runs->bidRules($run ?? $this->course),
            'stake' => (int) round(CfPlusAcquisitionFundingService::CONTROL_STAKE * 100),
        ]);
    }

    /**
     * FF9: who led on value created, as runs of quarters, for example
     * "Average led in Quarters 4 to 6, Careful in Quarters 7 to 11."
     *
     * @param  array<int, string>  $leaders
     */
    private function leaderSpans(array $leaders): string
    {
        $spans = [];
        foreach ($leaders as $quarter => $team) {
            $last = array_key_last($spans);
            if ($last !== null && $spans[$last]['team'] === $team && $spans[$last]['to'] === $quarter - 1) {
                $spans[$last]['to'] = $quarter;
            } else {
                $spans[] = ['team' => $team, 'from' => $quarter, 'to' => $quarter];
            }
        }
        $words = [];
        foreach ($spans as $i => $span) {
            $quarters = $span['from'] === $span['to'] ? 'Quarter '.$span['from'] : 'Quarters '.$span['from'].' to '.$span['to'];
            $words[] = ReferenceTeams::NAMES[$span['team']].($i === 0 ? ' led in ' : ' in ').$quarters;
        }

        return $words === [] ? '' : implode(', ', $words).'.';
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
