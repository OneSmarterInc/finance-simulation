<?php

namespace App\Services\Ai;

/**
 * The figure check (U8 b), run in code after every answer: each figure in
 * the answer must appear in what Claude was given (its lookups, the guide,
 * the page and the question). A sentence quoting a figure that appears in
 * none of them is dropped, the rest is shown, and the panel says so.
 *
 * Figures are compared as numbers, so "$21,613,457.97", "21613457.97" and
 * "21,613,457.97" match, as do "5%" and "5". A date's day and year are
 * figures too, which is why the lookups hand dates over already formatted.
 */
final class FigureCheck
{
    private const NUMBER = '/(?<![\w.])\$?\d[\d,]*(?:\.\d+)?%?/u';

    /**
     * @param  list<string>  $sources
     * @return array{answer: string, dropped: list<array{sentence: string, figures: list<string>}>, checked: int}
     */
    public static function apply(string $answer, array $sources): array
    {
        $known = [];
        foreach ($sources as $source) {
            preg_match_all(self::NUMBER, $source, $m);
            foreach ($m[0] as $figure) {
                $known[self::normalise($figure)] = true;
            }
        }

        $dropped = [];
        $checked = 0;
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', trim($answer)) as $paragraph) {
            $kept = [];
            foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“(])/u', $paragraph) as $sentence) {
                preg_match_all(self::NUMBER, $sentence, $m);
                $unknown = [];
                foreach ($m[0] as $figure) {
                    $checked++;
                    if (! isset($known[self::normalise($figure)])) {
                        $unknown[] = rtrim($figure, ',');
                    }
                }
                if ($unknown === []) {
                    $kept[] = $sentence;
                } else {
                    $dropped[] = ['sentence' => trim($sentence), 'figures' => $unknown];
                }
            }
            if ($kept !== []) {
                $paragraphs[] = implode(' ', $kept);
            }
        }

        return ['answer' => implode("\n\n", $paragraphs), 'dropped' => $dropped, 'checked' => $checked];
    }

    private static function normalise(string $figure): string
    {
        $n = str_replace(['$', ',', '%'], '', rtrim($figure, ','));
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }

        return ltrim($n, '0') === '' ? '0' : ltrim($n, '0');
    }
}
