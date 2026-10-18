"""The three reference teams and the reference course they play.

Each team follows plain rules a student could follow, using only what the
screens show: last quarter's revenue note (units ordered, sold, not filled)
and inventory note (units left in stock), and the season table.

  Careful   plans from last quarter's orders, adjusted for the season, with
            2% growth; nets the stock it holds and keeps a 3% buffer; picks
            the cheapest supplier with low defects; keeps prices and
            marketing at the defaults; ships standard; pays a quarter of
            the dividend allowed.
  Average   repeats last quarter's sales; ignores the season and, once it
            controls production, its stock; stays with the default supplier;
            uses units of production and FOB destination; pays nothing.
  Careless  plans 25% above last quarter's orders and makes all of it; cuts
            prices 10%; raises marketing to 12%; switches to declining
            balance; skips inspection and ships by air; pays the largest
            dividend allowed.
"""
from __future__ import annotations

from .engine import PRODUCTS, REGIONS
from .market import season
from .numbers import php_round
from .rules import shifts_for

# Reference course: which quarter each area opens (the warm-up is Quarters 1-3).
AREA_SCHEDULE = {
    "forecasting": 4, "sourcing": 4, "pricing": 5, "marketing": 6,
    "production": 7, "quality": 8, "logistics": 8,
}
CHOICES_OPEN_FROM = 4  # accounting methods and the dividend

TEAMS = ("careful", "average", "careless")
DIVIDEND_SHARE = {"careful": 0.25, "average": 0.0, "careless": 1.0}


def open_areas(quarter: int, schedule: dict | None = None) -> list[str]:
    return [a for a, q in (schedule or AREA_SCHEDULE).items() if quarter >= q]


def _forecast(team: str, q: int, prior: dict, p: dict) -> dict:
    out = {}
    for k in PRODUCTS:
        for r in REGIONS:
            ordered, sold = prior[f"n2_ord_{r}_{k}"], prior[f"n2_sold_{r}_{k}"]
            if team == "careful":
                v = ordered / season(p, q - 1) * season(p, q) * 1.02
            elif team == "average":
                v = sold
            else:
                v = ordered * 1.25
            out[f"forecast_{k}_{r}"] = int(php_round(v))
    return out


def decide(team: str, q: int, prior: dict, p: dict, warmup_notes: dict, overrides: dict | None = None,
           schedule: dict | None = None) -> tuple[dict, dict]:
    """Return (payloads for the open areas, accounting elections) for Quarter q >= 4.

    overrides: {category: dict, or function(quarter, current payload) -> dict}
    applied on top of the team's own rules (used by the fixture variants).
    """
    areas = open_areas(q, schedule)
    fc = _forecast(team, q, prior, p)
    payload: dict = {"forecasting": fc}
    payload["sourcing"] = {
        "careful": {"primary_supplier": "SUP006", "primary_alloc": 1.0, "secondary_supplier": "NONE"},
        "average": {"primary_supplier": "SUP001", "primary_alloc": 1.0, "secondary_supplier": "NONE"},
        "careless": {"primary_supplier": "SUP004", "primary_alloc": 1.0, "secondary_supplier": "NONE"},
    }[team]
    price_mult = 0.90 if team == "careless" else 1.0
    payload["pricing"] = {f"price_{k}_{r}": php_round(float(p[f"{k}_base_price"]) * price_mult, 2) for k in PRODUCTS for r in REGIONS}
    payload["marketing"] = {"marketing_budget_pct": 0.12 if team == "careless" else 0.05}
    want = {k: sum(fc[f"forecast_{k}_{r}"] for r in REGIONS) for k in PRODUCTS}
    if team == "careful":
        units = {k: max(0.0, php_round(want[k] * 1.03 - prior[f"n3_units_{k}"])) for k in PRODUCTS}
    else:
        units = {k: float(want[k]) for k in PRODUCTS}
    payload["production"] = {"p1_production": units["p1"], "p2_production": units["p2"], "shifts": shifts_for(units["p1"] + units["p2"], p)}
    payload["quality"] = {"inspection_level": {"careful": "BASIC", "average": "BASIC", "careless": "NONE"}[team]}
    payload["logistics"] = {"logistics_mode": {"careful": "STANDARD", "average": "EXPRESS", "careless": "AIR"}[team]}

    notes = dict(warmup_notes)
    if team == "average":
        notes.update(depreciation_method="units_of_production", shipping_terms="fob_destination")
    if team == "careless" and q >= 6:
        notes.update(depreciation_method="double_declining_balance")

    for cat, change in (overrides or {}).items():
        if cat == "notes_elections":
            notes.update(change(q, notes) if callable(change) else change)
        elif cat in payload:
            payload[cat].update(change(q, payload[cat]) if callable(change) else change)
    return {a: payload[a] for a in areas}, notes
