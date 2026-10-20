<?php

namespace App\Support\Export;

use App\Livewire\InheritedHistory;
use App\Models\Course;
use App\Models\FirmEngineResult;
use App\Support\Dates;
use App\Support\Presentation\StatementPresenter;
use App\Support\Product;

/**
 * Live walkthrough L16 (decided 18 October 2026): the results download.
 * One sheet per statement and note, every firm and every closed quarter,
 * with the labels the screens use. Faculty only.
 */
final class CourseResultsWorkbook
{
    private const STYLES = [
        'percent' => XlsxWriter::PERCENT,
        'ratio' => XlsxWriter::RATIO,
        'units' => XlsxWriter::UNITS,
    ];

    public static function fileName(Course $course): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $course->name), '-');

        return ($slug !== '' ? $slug : 'course').'-results-'.now()->format('Y-m-d').'.xlsx';
    }

    public static function build(Course $course): XlsxWriter
    {
        $firms = $course->firms()->orderBy('code')->get();
        $results = FirmEngineResult::query()->where('course_id', $course->id)->orderBy('quarter_number')->get()->groupBy('firm_id');

        // Column heading => result, firm by firm, quarter by quarter.
        $columns = [];
        foreach ($firms as $firm) {
            foreach ($results->get($firm->id, collect()) as $result) {
                $columns[$firm->name.' · '.InheritedHistory::columnLabel($result)] = $result;
            }
        }

        $book = new XlsxWriter;
        $book->addSheet('About', [
            [[$course->name, XlsxWriter::BOLD]],
            [[Product::fullName($course), XlsxWriter::TEXT]],
            [['Downloaded '.Dates::dateTime(now()), XlsxWriter::TEXT]],
            [],
            // Two short lines: Numbers and Preview clip a long line at the column edge instead of running it on.
            [['Every firm and every closed quarter, one sheet per statement and note, with the labels the screens use.', XlsxWriter::TEXT]],
            [['Money is in dollars; negative amounts are in brackets.', XlsxWriter::TEXT]],
            [[count($firms).' '.str('firm')->plural(count($firms)).', '.count($columns).' firm-quarters.', XlsxWriter::TEXT]],
        ], [110]);

        $sheets = ['summary' => 'Summary'] + StatementPresenter::STATEMENTS + StatementPresenter::NOTES;
        foreach ($sheets as $key => $title) {
            $book->addSheet(self::sheetName($title), self::rows($key, $columns), [46, ...array_fill(0, count($columns), 20)]);
        }

        return $book;
    }

    /**
     * @param  array<string, FirmEngineResult>  $columns
     * @return list<list<array{0: string|float|null, 1: int}>>
     */
    private static function rows(string $statement, array $columns): array
    {
        $out = [[['', XlsxWriter::BOLD], ...array_map(fn (string $heading) => [$heading, XlsxWriter::BOLD], array_keys($columns))]];
        if ($columns === []) {
            return $out;
        }
        foreach (StatementPresenter::rows($statement, $columns) as $row) {
            $bold = in_array($row['type'], ['heading', 'subtotal', 'total'], true);
            $cells = [[$row['label'], $bold ? XlsxWriter::BOLD : XlsxWriter::TEXT]];
            foreach ($row['values'] as $value) {
                $cells[] = match (true) {
                    $value === null => [null, XlsxWriter::TEXT],
                    in_array($row['format'], ['flag', 'shipping'], true) => [StatementPresenter::format($value, $row['format']), XlsxWriter::TEXT],
                    default => [round((float) $value, $row['format'] === 'units' ? 0 : 6), self::STYLES[$row['format']] ?? XlsxWriter::MONEY],
                };
            }
            $out[] = $cells;
        }

        return $out;
    }

    private static function sheetName(string $title): string
    {
        return match ($title) {
            'Revenue by region and product' => 'Revenue note',
            'Property, plant and equipment' => 'PPE note',
            'Borrowing and financial ratios' => 'Borrowing note',
            'Commitments, reserves and accruals' => 'Commitments note',
            'Inventory' => 'Inventory note',
            default => $title,
        };
    }
}
