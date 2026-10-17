<?php

namespace App\Services\Accounting;

use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Support\Dates;
use App\Support\Presentation\DecisionPresenter;

/**
 * Decision N7: the quarter in which an approved method change takes effect
 * carries a note disclosing the change and the team's reason, as a change
 * in accounting principle requires.
 */
class AccountingChangeDisclosures
{
    /** @return list<string> */
    public function forQuarter(Firm $firm, int $quarterNumber): array
    {
        if ($quarterNumber < 4) {
            return [];
        }

        return FirmAccountingElectionRequest::query()
            ->where('firm_id', $firm->id)
            ->where('status', 'approved')
            ->where('effective_quarter_number', $quarterNumber)
            ->orderBy('reviewed_at')
            ->get()
            ->map(fn (FirmAccountingElectionRequest $request) => $this->text($firm, $request))
            ->filter()
            ->values()
            ->all();
    }

    public function text(Firm $firm, FirmAccountingElectionRequest $request): ?string
    {
        $changes = self::changes($request->previous_elections ?? [], $request->proposed_elections ?? []);
        if ($changes === []) {
            return null;
        }

        $parts = array_map(
            fn (array $change) => strtolower($change['label']).' from '.$change['from'].' to '.$change['to'],
            $changes,
        );
        $sentence = 'Beginning this quarter, '.$firm->name.' changed its '.self::joinList($parts).'.';
        if (filled($request->justification)) {
            $sentence .= ' The team\'s reason: "'.trim($request->justification).'"';
        }
        if ($request->reviewed_at) {
            $sentence .= ' Approved '.Dates::day($request->reviewed_at).'.';
        }

        return $sentence;
    }

    /**
     * Methods that differ between two election sets, in plain words.
     *
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public static function changes(array $current, array $proposed): array
    {
        $changes = [];
        foreach ($proposed as $key => $value) {
            if ($key === 'inventory_method' || ! array_key_exists($key, $current) || $current[$key] === $value) {
                continue;
            }
            $changes[] = [
                'key' => $key,
                'label' => DecisionPresenter::fieldLabel($key),
                'from' => DecisionPresenter::valueLabel($key, $current[$key]),
                'to' => DecisionPresenter::valueLabel($key, $value),
            ];
        }

        return $changes;
    }

    /** @param list<string> $items */
    private static function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
