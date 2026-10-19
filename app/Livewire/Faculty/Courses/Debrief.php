<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Services\Debrief\DebriefBuilder;
use App\Services\Debrief\DebriefCaptions;
use App\Services\Interpretations\InterpretationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * U4 layer 1 (DB1 to DB4): the class debrief, presented as slides in class.
 * Every figure comes from the course's stored results; Claude only words
 * the slides, and a plain sentence built in code stands in until it has.
 */
#[Layout('layouts.app')]
class Debrief extends Component
{
    public Course $course;

    public string $statusMessage = '';

    public function mount(Course $course, InterpretationService $service): void
    {
        $faculty = auth()->user()?->faculty;
        abort_unless($faculty !== null, 403);
        try {
            $service->authorizeFaculty($faculty, $course);
        } catch (AuthorizationException) {
            abort(403);
        }
        // A practice run has a debrief too, so faculty can see what the class would get; it is never shared.
        $this->course = $course;
    }

    /** DB3: write Claude's captions when the figures have changed since they were last written (wire:init). */
    public function writeCaptions(DebriefBuilder $builder, DebriefCaptions $captions): void
    {
        $built = $builder->build($this->course);
        if ($captions->due($this->course, $built)) {
            $captions->write($this->course, $built);
        }
    }

    public function toggleHidden(string $key): void
    {
        abort_unless(array_key_exists($key, DebriefBuilder::SLIDES), 404);
        $record = DebriefCaptions::record($this->course);
        $hidden = collect($record->hidden ?? []);
        $record->forceFill(['hidden' => $hidden->contains($key) ? $hidden->reject(fn ($k) => $k === $key)->values()->all() : $hidden->push($key)->values()->all()])->save();
    }

    /** DB4: share with students, once the course has finished. */
    public function toggleShared(): void
    {
        abort_if($this->course->is_practice, 404);
        $record = DebriefCaptions::record($this->course);
        if (! $record->shared && ! DebriefBuilder::finished($this->course)) {
            $this->statusMessage = 'You can share the debrief once every quarter has closed'.($this->course->cf_plus_enabled ? ' and the evaluation stage is settled.' : '.');

            return;
        }
        $record->forceFill(['shared' => ! $record->shared])->save();
        $this->statusMessage = $record->shared
            ? 'Students can now see the debrief from My courses. Hidden slides stay hidden.'
            : 'Students can no longer see the debrief.';
    }

    public function render(DebriefBuilder $builder, DebriefCaptions $captions): View
    {
        $built = $builder->build($this->course);
        $record = DebriefCaptions::record($this->course);
        $hidden = (array) ($record->hidden ?? []);

        return view('livewire.faculty.courses.debrief', [
            'built' => $built,
            'captions' => $captions->captions($this->course, $built),
            'hidden' => $hidden,
            'shared' => (bool) $record->shared,
            'writing' => $captions->due($this->course, $built),
        ]);
    }
}
