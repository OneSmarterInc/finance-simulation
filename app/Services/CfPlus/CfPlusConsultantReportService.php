<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusConsultantReport;
use App\Models\CfPlusDisclosureSnapshot;

class CfPlusConsultantReportService
{
    public function __construct(private readonly ConsultantValuationPrototype $prototype) {}

    public function generateApproved(CfPlusDisclosureSnapshot $snapshot): CfPlusConsultantReport
    {
        $config = CfPlusValuationConfiguration::approved();

        return $this->store($snapshot, $this->prototype->evaluateApproved($snapshot), $config);
    }

    public function generateCandidate(CfPlusDisclosureSnapshot $snapshot, float $requiredReturn, float $growthCap, float $terminalGrowth, int $periods = 12): CfPlusConsultantReport
    {
        $detail = $this->prototype->evaluate($snapshot, $requiredReturn, $growthCap, $terminalGrowth, $periods);

        return $this->store($snapshot, $detail, ['annual_required_return' => $requiredReturn, 'quarterly_growth_cap' => $growthCap, 'annual_terminal_growth' => $terminalGrowth, 'forecast_quarters' => $periods]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array{annual_required_return: float, quarterly_growth_cap: float, annual_terminal_growth: float, forecast_quarters: int}  $config
     */
    private function store(CfPlusDisclosureSnapshot $snapshot, array $detail, array $config): CfPlusConsultantReport
    {
        return CfPlusConsultantReport::query()->updateOrCreate(['course_id' => $snapshot->course_id, 'target_firm_id' => $snapshot->target_firm_id], ['disclosure_snapshot_id' => $snapshot->id, 'institution_id' => $snapshot->institution_id, 'reported_quarters' => $detail['reported_quarters'], 'median_reported_base' => $detail['median_reported_base'], 'observed_growth' => $detail['observed_growth'], 'growth_cap_candidate' => $config['quarterly_growth_cap'], 'required_return_candidate' => $config['annual_required_return'], 'terminal_method' => $detail['terminal_method'], 'terminal_growth_candidate' => $config['annual_terminal_growth'], 'projected_periods' => $config['forecast_quarters'], 'valuation_detail' => $detail, 'consultant_value' => $detail['consultant_value'], 'template_sections' => $detail['template_sections'], 'generated_at' => now()]);
    }
}
