<?php

namespace App\Livewire;

use App\Models\Course;
use App\Services\Ai\AskClaude;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Ask Claude panel on faculty pages (U1). The page it sits on is fixed
 * when the page renders (Locked), so a question is always asked about the
 * course and page the faculty member is looking at; on the dashboard they
 * pick one of their own courses. The conversation stays
 * in the session per course, so it follows them from page to page.
 */
class AskClaudePanel extends Component
{
    /** The course asked about. Fixed by the page, except on the dashboard, where the faculty member picks one of theirs. */
    public string $courseId;

    /** The page's own course; outside the dashboard the panel can't leave it. */
    #[Locked]
    public string $pageCourseId;

    /** @var array<string, string> course id => name, on the dashboard only */
    #[Locked]
    public array $courseOptions = [];

    /** @var array{page: string, label: string, details?: array<string, string>} */
    #[Locked]
    public array $context;

    /** @var list<string> */
    #[Locked]
    public array $suggestions = [];

    public string $question = '';

    public bool $showSeen = false;

    public function mount(Course $course, array $context, array $suggestions = [], array $courseOptions = []): void
    {
        $this->courseId = $this->pageCourseId = $course->id;
        $this->context = $context;
        $this->suggestions = $suggestions;
        $this->courseOptions = $courseOptions;
    }

    public function updatedCourseId(): void
    {
        // Only the dashboard lets the course change, and only to one of the faculty member's own.
        if (! array_key_exists($this->courseId, $this->courseOptions)) {
            $this->courseId = (string) array_key_first($this->courseOptions);
        }
        $this->resetErrorBag();
    }

    public function ask(AskClaude $claude, ?string $suggested = null): void
    {
        $question = trim($suggested ?? $this->question);
        try {
            $result = $claude->ask($this->faculty(), $this->course(), $this->context, $question, $this->history());
        } catch (ValidationException $e) {
            $this->addError('question', collect($e->errors())->flatten()->first());

            return;
        }
        $turns = $this->turns();
        $turns[] = [
            'question' => $question,
            'answer' => $result['answer'],
            'page' => $this->context['label'],
            'lookups' => $result['lookups'],
            'dropped' => $result['dropped'],
            'checked' => $result['checked'],
            'names' => $result['names'],
            'views' => $result['views'] ?? [],
        ];
        session()->put($this->key(), array_slice($turns, -8));
        $this->question = '';
    }

    public function toggle(): void
    {
        $course = $this->course();
        $course->forceFill(['ai_helper_enabled' => ! $course->ai_helper_enabled])->save();
    }

    public function clear(): void
    {
        session()->forget($this->key());
    }

    public function render(AskClaude $claude): View
    {
        $course = $this->course();

        return view('livewire.ask-claude-panel', [
            'course' => $course,
            'enabled' => (bool) $course->ai_helper_enabled,
            'turns' => $this->turns(),
            'left' => $claude->questionsLeft($course),
            'allowance' => $course->ai_questions_per_month ?? (int) config('flexee.ai.questions_per_course_month', 100),
        ]);
    }

    private function course(): Course
    {
        $allowed = $this->courseOptions !== [] ? array_key_exists($this->courseId, $this->courseOptions) : $this->courseId === $this->pageCourseId;
        abort_unless($allowed, 403);
        $course = Course::query()->findOrFail($this->courseId);
        $faculty = $this->faculty();
        abort_unless($course->faculty_id === $faculty->id || $course->facultyRoles()->where('faculty_id', $faculty->id)->exists(), 403);

        return $course;
    }

    private function faculty()
    {
        $faculty = auth()->user()?->faculty;
        abort_if($faculty === null, 403);

        return $faculty;
    }

    /** @return list<array<string, mixed>> */
    private function turns(): array
    {
        return session()->get($this->key(), []);
    }

    /** @return list<array{question: string, answer: string}> */
    private function history(): array
    {
        return array_map(fn ($t) => ['question' => $t['question'], 'answer' => $t['answer']], $this->turns());
    }

    private function key(): string
    {
        return 'ask_claude.'.$this->courseId;
    }
}
