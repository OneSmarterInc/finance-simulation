"""Sim rules: the decisions the simulation makes for areas a team doesn't control.

Mirrors CfManufacturingSimRulesService. Version 2 changes two things:

  ED2 b  while production is closed, the plant makes the forecast less the
         units already in stock (version 1 makes the forecast);
  Q4+    a closed forecast is last quarter's orders adjusted for the season
         (version 1 never reaches this: forecasting is open from Quarter 4,
         and in the warm-up the forecast is the market itself).

Version 2 also leaves out the inputs it no longer reads (engine.V2_UNUSED_INPUTS).
"""
from __future__ import annotations

import math

from .engine import PRODUCTS, REGIONS, V2_UNUSED_INPUTS
from .market import DEMAND_FIELDS, season
from .numbers import php_round

OPERATIONAL = ("forecasting", "sourcing", "pricing", "production", "marketing", "quality", "logistics")


def demand_context(version: int, quarter: int, market: dict[int, dict], prior_fields: dict | None, p: dict) -> dict:
    """What the rules plan from when forecasting is closed."""
    if quarter <= 3 or version == 1:
        return dict(market[quarter])
    s = season(p, quarter) / season(p, quarter - 1)
    return {f"forecast_{k}_{r}": int(php_round(prior_fields[f"n2_ord_{r}_{k}"] * s)) for k in PRODUCTS for r in REGIONS}


def forecasting(version: int, context: dict) -> dict:
    out = {k: context[k] for k in DEMAND_FIELDS}
    if version == 1:
        out["forecast_confidence"] = "medium"
    return out


def pricing(version: int, p: dict) -> dict:
    out = {f"price_{k}_{r}": float(p[f"{k}_base_price"]) for k in PRODUCTS for r in REGIONS}
    if version == 1:
        out["discount_policy"] = "none"
    return out


def shifts_for(units: float, p: dict) -> int:
    return min(3, max(1, int(math.ceil(units / max(1.0, float(p["capacity_per_shift"]))))))


def production(version: int, plan: dict, p: dict, on_hand: dict | None = None) -> dict:
    want = {k: sum(float(plan[f"forecast_{k}_{r}"]) for r in REGIONS) for k in PRODUCTS}
    if version >= 2:
        on_hand = on_hand or {}
        units = {k: max(0.0, php_round(want[k] - on_hand.get(k, 0.0))) for k in PRODUCTS}
    else:
        units = {k: php_round(want[k]) for k in PRODUCTS}
    # Version 1 sizes shifts by the forecast; version 2 by the units it will make.
    basis = sum(units.values()) if version >= 2 else want["p1"] + want["p2"]
    out = {"p1_production": units["p1"], "p2_production": units["p2"], "shifts": shifts_for(basis, p)}
    if version == 1:
        out.update(postponed_flag="NO", postponed_pct=0)
    return out


def sourcing() -> dict:
    return {"primary_supplier": "SUP001", "primary_alloc": 1.0, "secondary_supplier": "NONE"}


def marketing(version: int, p: dict) -> dict:
    out = {"marketing_budget_pct": 0.05}
    if version == 1:
        for seg in ("champions", "growth", "at_risk", "other"):
            out[f"marketing_alloc_{seg}"] = p[f"marketing_{seg}_base_revenue_pct"]
    return out


def resolve(version: int, quarter: int, p: dict, context: dict, team: dict, notes: dict,
            on_hand: dict | None = None) -> dict:
    """Complete decisions: the team's payload for open areas, the rules for the rest.

    team: {category: payload} for the areas open this quarter.
    notes: the accounting elections in force this quarter.
    """
    d: dict = {}
    d["forecasting"] = dict(team["forecasting"]) if "forecasting" in team else forecasting(version, context)
    d["pricing"] = dict(team["pricing"]) if "pricing" in team else pricing(version, p)
    d["production"] = dict(team["production"]) if "production" in team else production(version, d["forecasting"], p, on_hand)
    d["marketing"] = dict(team["marketing"]) if "marketing" in team else marketing(version, p)
    d["quality"] = dict(team["quality"]) if "quality" in team else {"inspection_level": "BASIC"}
    d["logistics"] = dict(team["logistics"]) if "logistics" in team else {"logistics_mode": "EXPRESS"}
    d["sourcing"] = dict(team["sourcing"]) if "sourcing" in team else sourcing()
    out = {c: d[c] for c in OPERATIONAL}
    out["notes_elections"] = dict(notes)
    if version == 1:
        out["certification"] = {"attestation_scope": "all_statements_attested"}
    else:
        for cat, keys in V2_UNUSED_INPUTS.items():
            for k in keys:
                out.get(cat, {}).pop(k, None)
    return out
