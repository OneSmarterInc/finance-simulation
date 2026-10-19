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
 *
 * Students' own words (U11 layer 2, AE1) are a second kind of source: a
 * figure found only in a team's explanation passes only inside quotation
 * marks, so Claude can quote what a team wrote but never state it as fact.
 */
final class FigureCheck
{
    private const NUMBER = '/(?<![\w.])\$?\d[\d,]*(?:\.\d+)?%?/u';

    /**
     * @param  list<string>  $sources  stored results, the guide, the page and the question
     * @param  list<string>  $quotable  students' own words: their figures pass only when quoted
     * @return array{answer: string, dropped: list<array{sentence: string, figures: list<string>}>, checked: int}
     */
    public static function apply(string $answer, array $sources, array $quotable = []): array
    {
        $known = self::figures($sources);
        $quoted = self::figures($quotable);

        $dropped = [];
        $checked = 0;
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', trim($answer)) as $paragraph) {
            $kept = [];
            foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“(])/u', $paragraph) as $sentence) {
                preg_match_all(self::NUMBER, $sentence, $m, PREG_OFFSET_CAPTURE);
                $spans = self::quotedSpans($sentence);
                $unknown = [];
                foreach ($m[0] as [$figure, $at]) {
                    $checked++;
                    $key = self::normalise($figure);
                    $inQuote = collect($spans)->contains(fn ($span) => $at >= $span[0] && $at < $span[1]);
                    if (! isset($known[$key]) && ! ($inQuote && isset($quoted[$key]))) {
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

    /** @param list<string> $sources @return array<string, true> */
    private static function figures(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            preg_match_all(self::NUMBER, $source, $m);
            foreach ($m[0] as $figure) {
                $out[self::normalise($figure)] = true;
            }
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int}> byte ranges inside quotation marks */
    private static function quotedSpans(string $sentence): array
    {
        preg_match_all('/"[^"]*"|“[^”]*”/u', $sentence, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn ($hit) => [$hit[1], $hit[1] + strlen($hit[0])], $m[0]);
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
