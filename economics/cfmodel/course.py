"""Play one firm through a course: warm-up by the rules, then a team's decisions."""
from __future__ import annotations

import json
import math
from pathlib import Path

from . import rules, teams
from .engine import Opening, calculate
from .market import market_path

ROOT = Path(__file__).resolve().parent.parent
LAST_QUARTER = 11


def load_scenario() -> dict:
    return json.loads((ROOT / "data" / "scenario.json").read_text())


def load_reference_course() -> dict:
    return json.loads((ROOT / "data" / "reference_course.json").read_text())


def play(team: str, version: int = 2, overrides: dict | None = None, last_quarter: int = LAST_QUARTER,
         firm_id: str | None = None, extra_units_q: tuple[int, float] | None = None,
         schedule: dict | None = None) -> list[dict]:
    """Return one record per quarter: market, decisions, dividend, opening, results.

    extra_units_q: (quarter, units) adds units to Product 1 production in that
    quarter, for the unsold-stock check.
    """
    scen, course = load_scenario(), load_reference_course()
    p = scen["parameters"]
    firm = firm_id or course["warmup_firm_id"]
    market = market_path(version, scen["q0_forecast"], course["seed"], firm, last_quarter, p)
    warm_notes = scen["warmup_notes_elections"]
    opening = Opening.from_parameters(p)
    prior: dict | None = None
    out = []
    for q in range(1, last_quarter + 1):
        on_hand = {"p1": opening.fg_units_p1, "p2": opening.fg_units_p2}
        if q < teams.CHOICES_OPEN_FROM:
            team_payload, notes, areas = {}, warm_notes, []
        else:
            team_payload, notes = teams.decide(team, q, prior, p, warm_notes, overrides, schedule)
            areas = teams.open_areas(q, schedule)
        context = rules.demand_context(version, q, market, prior, p)
        decisions = rules.resolve(version, q, p, context, team_payload, notes, on_hand)
        if extra_units_q and extra_units_q[0] == q:
            decisions["production"]["p1_production"] += extra_units_q[1]
            decisions["production"]["shifts"] = rules.shifts_for(decisions["production"]["p1_production"] + decisions["production"]["p2_production"], p)
        mkt = market.get(q)
        dividend = 0.0
        if q >= teams.CHOICES_OPEN_FROM and teams.DIVIDEND_SHARE[team] > 0:
            probe = calculate(version, q, decisions, p, opening, 0.0, mkt)
            maximum = probe["intermediates"]["maximum_dividend"]
            dividend = math.floor(maximum * teams.DIVIDEND_SHARE[team] * 100 + 1e-6) / 100
        result = calculate(version, q, decisions, p, opening, dividend, mkt)
        out.append({
            "quarter": q, "student_areas": areas, "student_payload": team_payload, "units_on_hand": on_hand,
            "market": mkt, "decisions": decisions,
            "dividend_declared": dividend, "opening": opening.as_dict(), **result,
        })
        prior = result["fields"]
        opening = Opening.from_fields(prior, version)
    return out


def value_created(rows: list[dict], through_quarter: int, from_quarter: int = 4) -> float:
    """Ending equity plus every dividend paid from Quarter 4."""
    row = next(r for r in rows if r["quarter"] == through_quarter)
    f = row["fields"]
    paid = sum(-r["fields"]["cf_equity"] for r in rows if from_quarter <= r["quarter"] <= through_quarter)
    return f["bs_common"] + f["bs_retained"] + f["bs_curr_ni"] + paid
