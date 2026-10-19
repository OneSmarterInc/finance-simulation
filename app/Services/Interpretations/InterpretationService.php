<?php

namespace App\Services\Interpretations;

use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\FirmQuarterInterpretation;
use App\Models\Student;
use App\Services\Quarters\QuarterScheduler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * U11 (IN1 to IN3, decided 19 October 2026): the team's explanation of its
 * last closed quarter, written before it submits the next quarter's
 * decisions. One explanation per team per quarter, shared like the
 * decisions: any member saves or submits, everyone sees the same text.
 */
class InterpretationService
{
    public const MIN_LENGTH = 40;

    public const MAX_LENGTH = 3000;

    /**
     * The quarter a team explains before deciding $decisionQuarter, or null
     * when no explanation is asked for (switched off, a practice run, or a
     * warm-up quarter).
     */
    public function explainedQuarter(Firm $firm, CourseQuarter $decisionQuarter): ?CourseQuarter
    {
        $course = $firm->course;
        if (! $this->required($course) || $decisionQuarter->quarter_number < 4) {
            return null;
        }

        return CourseQuarter::query()
            ->where('course_id', $course->id)
            ->where('quarter_number', $decisionQuarter->quarter_number - 1)
            ->first();
    }

    public function required(Course $course): bool
    {
        return (bool) $course->interpretations_required && ! $course->is_practice;
    }

    public function find(Firm $firm, CourseQuarter $explained): ?FirmQuarterInterpretation
    {
        return FirmQuarterInterpretation::query()
            ->where('firm_id', $firm->id)
            ->where('course_quarter_id', $explained->id)
            ->with('submittedBy.user')
            ->first();
    }

    /** IN1: has the team submitted what it must before deciding $decisionQuarter? */
    public function satisfied(Firm $firm, CourseQuarter $decisionQuarter): bool
    {
        $explained = $this->explainedQuarter($firm, $decisionQuarter);

        return $explained === null || (bool) $this->find($firm, $explained)?->isSubmitted();
    }

    /** The explanation can be written while the decision quarter is open and its deadline hasn't passed. */
    public function editable(CourseQuarter $decisionQuarter): bool
    {
        if ($decisionQuarter->state !== CourseQuarter::OPEN) {
            return false;
        }

        return ! ($decisionQuarter->decision_deadline_at?->lte(now()) && ! QuarterScheduler::needsNewDeadline($decisionQuarter));
    }

    /** @param array<string, mixed> $answers */
    public function saveDraft(Student $student, Firm $firm, CourseQuarter $decisionQuarter, array $answers): FirmQuarterInterpretation
    {
        [$explained, $clean] = $this->prepare($student, $firm, $decisionQuarter, $answers, false);
        $row = $this->find($firm, $explained) ?? new FirmQuarterInterpretation(['firm_id' => $firm->id, 'course_id' => $firm->course_id, 'course_quarter_id' => $explained->id]);
        // A draft never takes back a submitted explanation: it stays submitted, with the new words.
        $row->forceFill(['answers' => $clean, 'state' => $row->exists ? $row->state : FirmQuarterInterpretation::DRAFT])->save();

        return $row->refresh();
    }

    /** @param array<string, mixed> $answers */
    public function submit(Student $student, Firm $firm, CourseQuarter $decisionQuarter, array $answers): FirmQuarterInterpretation
    {
        [$explained, $clean] = $this->prepare($student, $firm, $decisionQuarter, $answers, true);
        $row = $this->find($firm, $explained) ?? new FirmQuarterInterpretation(['firm_id' => $firm->id, 'course_id' => $firm->course_id, 'course_quarter_id' => $explained->id]);
        $row->forceFill([
            'answers' => $clean,
            'state' => FirmQuarterInterpretation::SUBMITTED,
            'submitted_by_student_id' => $student->id,
            'submitted_at' => now(),
        ])->save();

        return $row->refresh();
    }

    /** IN3: the instructor's comment, which the team sees. An empty comment removes it. */
    public function comment(Faculty $faculty, FirmQuarterInterpretation $row, ?string $comment): FirmQuarterInterpretation
    {
        $this->authorizeFaculty($faculty, $row->firm->course);
        $text = trim((string) $comment);
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['comment' => 'Keep the comment under '.number_format(self::MAX_LENGTH).' characters.']);
        }
        $row->forceFill([
            'faculty_comment' => $text === '' ? null : $text,
            'commented_by_faculty_id' => $text === '' ? null : $faculty->id,
            'commented_at' => $text === '' ? null : now(),
        ])->save();

        return $row->refresh();
    }

    public function setRequired(Faculty $faculty, Course $course, bool $required): void
    {
        $this->authorizeFaculty($faculty, $course);
        $course->forceFill(['interpretations_required' => $required])->save();
    }

    /** @return Collection<int, FirmQuarterInterpretation> every explanation in the course, oldest quarter first */
    public function forCourse(Course $course): Collection
    {
        return FirmQuarterInterpretation::query()
            ->where('course_id', $course->id)
            ->with(['firm', 'courseQuarter', 'submittedBy.user'])
            ->get()
            ->sortBy(fn ($row) => [$row->courseQuarter?->quarter_number, $row->firm?->code])
            ->values();
    }

    public function authorizeFaculty(Faculty $faculty, Course $course): void
    {
        $allowed = $course->faculty_id === $faculty->id || CourseFacultyRole::query()
            ->where('course_id', $course->id)->where('faculty_id', $faculty->id)->where('role', 'co_faculty')->exists();
        if (! $allowed) {
            throw new AuthorizationException('This course is outside your teaching assignments.');
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{0: CourseQuarter, 1: array<string, string>}
     */
    private function prepare(Student $student, Firm $firm, CourseQuarter $decisionQuarter, array $answers, bool $complete): array
    {
        $member = FirmMembership::query()->where('firm_id', $firm->id)->where('student_id', $student->id)->exists();
        if (! $member || $decisionQuarter->course_id !== $firm->course_id) {
            throw new AuthorizationException('This firm is outside your membership.');
        }
        $explained = $this->explainedQuarter($firm, $decisionQuarter)
            ?? throw ValidationException::withMessages(['interpretation' => 'There is nothing to explain before this quarter.']);
        if (! $this->editable($decisionQuarter)) {
            throw ValidationException::withMessages(['interpretation' => "Quarter {$decisionQuarter->quarter_number}'s deadline has passed, so the explanation can't be changed."]);
        }

        $clean = [];
        $errors = [];
        foreach (FirmQuarterInterpretation::PROMPTS as $key) {
            $text = trim((string) ($answers[$key] ?? ''));
            if (mb_strlen($text) > self::MAX_LENGTH) {
                $errors["explanation.{$key}"] = 'Keep this answer under '.number_format(self::MAX_LENGTH).' characters.';
            } elseif ($complete && mb_strlen($text) < self::MIN_LENGTH) {
                $errors["explanation.{$key}"] = 'Write at least a full sentence here before you submit.';
            }
            $clean[$key] = $text;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$explained, $clean];
    }
}
