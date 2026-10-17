<?php

namespace App\Services\CfPlus;

use App\Models\Firm;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Validation\ValidationException;

class FirmAccountingElectionService
{
    /** @return array<int, array<string, mixed>> */
    public function studentVisibleFields(): array
    {
        return array_values(array_filter(
            CfManufacturingScenarioDefinition::inputFieldsForCategory('notes_elections')['fields'],
            fn (array $field): bool => $field['key'] !== 'inventory_method',
        ));
    }

    /**
     * @param  array<string, mixed>  $elections
     * @return array<string, mixed>
     */
    public function validate(array $elections): array
    {
        $fields = CfManufacturingScenarioDefinition::inputFieldsForCategory('notes_elections')['fields'];
        $expected = array_column($fields, 'key');
        $studentExpected = array_column($this->studentVisibleFields(), 'key');
        if (array_keys($elections) === $studentExpected) {
            $elections = ['inventory_method' => 'fifo', ...$elections];
        } elseif (array_keys($elections) !== $expected) {
            throw ValidationException::withMessages(['notes_elections' => 'Submit the complete student-visible accounting-election field set in its defined order.']);
        }
        if ($elections['inventory_method'] !== 'fifo') {
            throw ValidationException::withMessages(['notes_elections.inventory_method' => 'Inventory method is fixed and locked to FIFO.']);
        }
        foreach ($fields as $field) {
            if (! in_array($elections[$field['key']], $field['allowed_values'], true)) {
                throw ValidationException::withMessages(["notes_elections.{$field['key']}" => 'This accounting election is not allowed.']);
            }
        }

        return $elections;
    }

    /** @param array<string, mixed> $elections */
    public function lock(Firm $firm, array $elections): Firm
    {
        $validated = $this->validate($elections);
        $firm->refresh();
        if ($firm->accounting_elections_locked_at !== null && $firm->accounting_elections !== $validated) {
            throw ValidationException::withMessages(['notes_elections' => 'Accounting elections were locked in Quarter 4 and cannot be changed.']);
        }
        if ($firm->accounting_elections_locked_at === null) {
            $firm->forceFill(['accounting_elections' => $validated, 'accounting_elections_locked_at' => now()])->save();
        }

        return $firm->refresh();
    }
}
