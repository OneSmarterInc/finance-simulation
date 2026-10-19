<?php

namespace App\Services\Ai;

/**
 * L15's second rule, run in code: the reading describes what happened and
 * never tells students what to decide. A sentence that gives advice is
 * dropped, the way the figure check drops a sentence with an unmatched figure.
 */
final class AdviceCheck
{
    private const ADVICE = [
        '/\b(you|your team|the team|the firm|students?|they)\s+(should|ought to|need to|needs to|might want to|may want to|would do well to)\b/i',
        '/\b(consider|considering|recommend|recommended|advisable|it would be wise|it would help to|try to|aim to|make sure)\b/i',
        '/\b(next quarter|in future|going forward|from now on),?\s+(try|aim|keep|cut|raise|lower|increase|reduce|order|produce|buy|spend|hold|pay)\b/i',
        '/^(keep|cut|raise|lower|increase|reduce|order|produce|buy|spend|hold|pay|watch|avoid|focus)\b/i',
    ];

    /** @return array{text: string, dropped: list<string>} */
    public static function apply(string $text): array
    {
        $kept = [];
        $dropped = [];
        foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“(])/u', trim($text)) as $sentence) {
            if ($sentence === '') {
                continue;
            }
            $advice = false;
            foreach (self::ADVICE as $pattern) {
                if (preg_match($pattern, trim($sentence))) {
                    $advice = true;
                    break;
                }
            }
            if ($advice) {
                $dropped[] = trim($sentence);
            } else {
                $kept[] = $sentence;
            }
        }

        return ['text' => implode(' ', $kept), 'dropped' => $dropped];
    }
}
