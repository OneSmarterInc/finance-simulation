<?php

namespace App\Support\Presentation;

/**
 * Claude's answer as the panel shows it (live review R22, 21 October 2026).
 * Claude sometimes writes a short list with bold labels, and the panel showed
 * the marks raw ("- **Dividend:** ..."). A small safe subset is rendered:
 * **bold**, lines starting "- " or "* " as a list, and "#" headings as a bold
 * line. Anything else stays text.
 *
 * The input is already escaped HTML (the panel escapes Claude's text and puts
 * the students' names back first), so only these tags are ever added.
 */
final class AnswerFormat
{
    public static function render(string $escaped): string
    {
        $out = [];
        foreach (preg_split('/\n{2,}/', trim($escaped)) as $paragraph) {
            $lines = preg_split('/\n/', $paragraph);
            $text = [];
            $items = [];
            $flush = function () use (&$out, &$text, &$items): void {
                if ($text !== []) {
                    $out[] = '<p>'.implode('<br>', $text).'</p>';
                    $text = [];
                }
                if ($items !== []) {
                    $out[] = '<ul class="list-disc space-y-1 ps-5">'.implode('', array_map(fn ($i) => '<li>'.$i.'</li>', $items)).'</ul>';
                    $items = [];
                }
            };
            foreach ($lines as $line) {
                $line = rtrim($line);
                if (preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $m)) {
                    if ($text !== []) {
                        $flush();
                    }
                    $items[] = self::inline($m[1]);

                    continue;
                }
                if ($items !== []) {
                    $flush();
                }
                if (preg_match('/^\s*#{1,6}\s+(.*)$/', $line, $m)) {
                    $text[] = '<strong>'.self::inline(trim($m[1], '* ')).'</strong>';

                    continue;
                }
                if (trim($line) !== '') {
                    $text[] = self::inline($line);
                }
            }
            $flush();
        }

        return implode('', $out);
    }

    private static function inline(string $line): string
    {
        $line = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $line);

        // A stray pair of marks left over is dropped rather than shown.
        return str_replace('**', '', $line);
    }
}
