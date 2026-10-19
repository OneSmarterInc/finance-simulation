<?php

namespace App\Services\Interpretations;

use App\Models\CfPlusAuctionResult;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\FirmRivalAnalysis;
use App\Models\Student;
use App\Services\CfPlus\CfPlusOfferService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * U11 layer 3 (RA1 to RA3, decided 19 October 2026): on + courses, a team
 * submits a written analysis of a rival before it can save a positive offer
 * for it. One analysis per team per rival, shared like the explanation.
 * Faculty read the text only after the stage is settled, so offers stay
 * sealed (RA3). Rivals never see each other's analyses.
 */
class RivalAnalysisService
{
    public function __construct(private readonly InterpretationService $interpretations) {}

    /** Asked for on + courses with the course's explanation switch on; never in practice runs. */
    public function required(Course $course): bool
    {
        return (bool) $course->cf_plus_enabled && $this->interpretations->required($course);
    }

    public function find(Firm $bidder, Firm $target): ?FirmRivalAnalysis
    {
        return FirmRivalAnalysis::query()->where('bidder_firm_id', $bidder->id)->where('target_firm_id', $target->id)
            ->with('submittedBy.user')->first();
    }

    /** RA1: may the team save a positive offer for this rival? */
    public function satisfied(Firm $bidder, Firm $target): bool
    {
        return ! $this->required($bidder->course) || (bool) $this->find($bidder, $target)?->isSubmitted();
    }

    public function message(Firm $target): string
    {
        return "Submit your team's analysis of {$target->name} first. Then you can save a positive offer for it. A \$0 offer needs no analysis.";
    }

    public function settled(Course $course): bool
    {
        return CfPlusAuctionResult::query()->where('course_id', $course->id)->exists();
    }

    /** The analysis can be written while offers can be placed: an evaluation-stage quarter is open and nothing is settled. */
    public function editable(Course $course): bool
    {
        try {
            app(CfPlusOfferService::class)->assertWindowOpen($course);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /** @param array<string, mixed> $answers */
    public function saveDraft(Student $student, Firm $bidder, Firm $target, array $answers, mixed $estimate): FirmRivalAnalysis
    {
        [$clean, $value] = $this->prepare($student, $bidder, $target, $answers, $estimate, false);
        $row = $this->find($bidder, $target) ?? new FirmRivalAnalysis(['course_id' => $bidder->course_id, 'bidder_firm_id' => $bidder->id, 'target_firm_id' => $target->id]);
        // A draft never takes back a submitted analysis: it stays submitted, with the new words.
        $row->forceFill(['answers' => $clean, 'estimated_stake_value' => $value, 'state' => $row->exists ? $row->state : FirmRivalAnalysis::DRAFT])->save();

        return $row->refresh();
    }

    /** @param array<string, mixed> $answers */
    public function submit(Student $student, Firm $bidder, Firm $target, array $answers, mixed $estimate): FirmRivalAnalysis
    {
        [$clean, $value] = $this->prepare($student, $bidder, $target, $answers, $estimate, true);
        $row = $this->find($bidder, $target) ?? new FirmRivalAnalysis(['course_id' => $bidder->course_id, 'bidder_firm_id' => $bidder->id, 'target_firm_id' => $target->id]);
        $row->forceFill([
            'answers' => $clean,
            'estimated_stake_value' => $value,
            'state' => FirmRivalAnalysis::SUBMITTED,
            'submitted_by_student_id' => $student->id,
            'submitted_at' => now(),
        ])->save();

        return $row->refresh();
    }

    /** RA3: faculty comment once the stage is settled. An empty comment removes it. */
    public function comment(Faculty $faculty, FirmRivalAnalysis $row, ?string $comment): FirmRivalAnalysis
    {
        $course = $row->bidder->course;
        $this->interpretations->authorizeFaculty($faculty, $course);
        if (! $this->settled($course)) {
            throw ValidationException::withMessages(['comment' => 'Rival analyses open to you once you settle the evaluation stage.']);
        }
        $text = trim((string) $comment);
        if (mb_strlen($text) > InterpretationService::MAX_LENGTH) {
            throw ValidationException::withMessages(['comment' => 'Keep the comment under '.number_format(InterpretationService::MAX_LENGTH).' characters.']);
        }
        $row->forceFill([
            'faculty_comment' => $text === '' ? null : $text,
            'commented_by_faculty_id' => $text === '' ? null : $faculty->id,
            'commented_at' => $text === '' ? null : now(),
        ])->save();

        return $row->refresh();
    }

    /** @return Collection<int, FirmRivalAnalysis> every analysis in the course, by bidder then rival */
    public function forCourse(Course $course): Collection
    {
        return FirmRivalAnalysis::query()->where('course_id', $course->id)
            ->with(['bidder', 'target', 'submittedBy.user'])->get()
            ->sortBy(fn ($row) => [$row->bidder?->name, $row->target?->name])->values();
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{0: array<string, string>, 1: float|null}
     */
    private function prepare(Student $student, Firm $bidder, Firm $target, array $answers, mixed $estimate, bool $complete): array
    {
        $member = FirmMembership::query()->where('firm_id', $bidder->id)->where('student_id', $student->id)->exists();
        if (! $member || $target->course_id !== $bidder->course_id || $target->id === $bidder->id) {
            throw new AuthorizationException('This firm is outside your membership.');
        }
        if (! $this->required($bidder->course)) {
            throw ValidationException::withMessages(['analysis' => 'This course doesn\'t ask for rival analyses.']);
        }
        if (! $this->editable($bidder->course)) {
            throw ValidationException::withMessages(['analysis' => 'Offers are closed, so the analysis can\'t be changed.']);
        }

        $clean = [];
        $errors = [];
        foreach (array_keys(FirmRivalAnalysis::PROMPTS) as $key) {
            $text = trim((string) ($answers[$key] ?? ''));
            if (mb_strlen($text) > InterpretationService::MAX_LENGTH) {
                $errors["analysis.{$key}"] = 'Keep this answer under '.number_format(InterpretationService::MAX_LENGTH).' characters.';
            } elseif ($complete && mb_strlen($text) < InterpretationService::MIN_LENGTH) {
                $errors["analysis.{$key}"] = 'Write at least a full sentence here before you submit.';
            }
            $clean[$key] = $text;
        }

        $raw = is_string($estimate) ? str_replace([',', '$', ' '], '', $estimate) : $estimate;
        $value = null;
        if ($raw !== null && $raw !== '') {
            if (! is_numeric($raw) || (float) $raw < 0) {
                $errors['analysis.estimate'] = 'Enter a dollar figure of zero or more.';
            } else {
                $value = round((float) $raw, 2);
            }
        } elseif ($complete) {
            $errors['analysis.estimate'] = 'Give your own figure for 51% of '.$target->name.' before you submit.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$clean, $value];
    }
}
