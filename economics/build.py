"""Build the golden fixtures for engine version 2.

    python3 economics/build.py          # writes economics/fixtures/v2_reference.json
    python3 economics/build.py --check  # fails if the file on disk is out of date

Fixtures are rebuilt from this model only, never edited by hand. The PHP
port of engine version 2 must reproduce every value in them.
"""
from __future__ import annotations

import hashlib
import json
import math
import random
import sys
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from cfmodel import teams  # noqa: E402
from cfmodel.course import ROOT, load_reference_course, load_scenario, play  # noqa: E402
from cfmodel.engine import Opening, calculate  # noqa: E402
from cfmodel.numbers import cents  # noqa: E402
from cfmodel.engine import (  # noqa: E402
    MARKETING_LIFT_OFFSET, MARKETING_LIFT_SLOPE, MARKETING_REFERENCE_BUDGET, V2_FIELDS, V2_NEW_FIELDS, V2_NON_MONETARY,
)

OUT = ROOT / "fixtures" / "v2_reference.json"
ROUNDING_OUT = ROOT / "fixtures" / "rounding_cases.json"

# Each variant replays the careful team with one accounting method or input
# changed, so every branch of the version 2 engine appears in a fixture.
# There are no inventory-method variants: version 2 is FIFO only (ED12).
VARIANTS = {
    "fob_destination": {"notes_elections": {"shipping_terms": "fob_destination"}},
    "declining_balance": {"notes_elections": {"depreciation_method": "double_declining_balance"}},
    "units_of_production": {"notes_elections": {"depreciation_method": "units_of_production"}},
    "bad_debt_percentage_of_sales": {"notes_elections": {"bad_debt_method": "percentage_of_sales"}},
    "warranty_expense_as_incurred": {"notes_elections": {"warranty_method": "expense_as_incurred"}},
    "excise_separate_expense": {"notes_elections": {"excise_tax_treatment": "separate_operating_expense"}},
    "two_suppliers": {"sourcing": {"primary_supplier": "SUP006", "primary_alloc": 0.7, "secondary_supplier": "SUP005"}},
    "usa_supplier": {"sourcing": {"primary_supplier": "SUP002", "primary_alloc": 1.0, "secondary_supplier": "NONE"}},
    "germany_no_inspection": {"sourcing": {"primary_supplier": "SUP003", "primary_alloc": 1.0, "secondary_supplier": "NONE"}, "quality": {"inspection_level": "NONE"}},
    "no_marketing": {"marketing": {"marketing_budget_pct": 0.0}},
    "full_inspection_air": {"quality": {"inspection_level": "FULL"}, "logistics": {"logistics_mode": "AIR"}},
    "third_shift": {"production": lambda q, cur: {"p1_production": float(round(cur["p1_production"] * 2.6)), "shifts": 3}},
    "price_rise": {"pricing": lambda q, cur: {k: round(v * 1.15, 2) for k, v in cur.items()}},
    "no_production_q7": {"production": lambda q, cur: {"p1_production": 0.0, "p2_production": 0.0, "shifts": 1} if q == 7 else {}},
}
# Variants that change when areas open instead of what the team decides.
SCHEDULE_VARIANTS = {
    "forecasting_closed_to_q6": {**teams.AREA_SCHEDULE, "forecasting": 6},
}
VARIANT_LAST_QUARTER = 9


def _sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build() -> dict:
    scen, course = load_scenario(), load_reference_course()
    return {
        "about": "Golden fixtures for ManufacturingCFEngine version 2, built by economics/build.py from the Python reference model. Do not edit by hand.",
        "engine_version": 2,
        "tolerance": {"relative": 1e-6, "absolute": 1e-4},
        "scenario_sha256": _sha(ROOT / "data" / "scenario.json"),
        "reference_course": course,
        "q0_forecast": scen["q0_forecast"],
        "area_schedule": teams.AREA_SCHEDULE,
        "choices_open_from": teams.CHOICES_OPEN_FROM,
        "marketing_lift": {"slope": MARKETING_LIFT_SLOPE, "offset": MARKETING_LIFT_OFFSET, "reference_budget": MARKETING_REFERENCE_BUDGET},
        "fields": list(V2_FIELDS),
        "new_fields": list(V2_NEW_FIELDS),
        "non_monetary_fields": sorted(V2_NON_MONETARY),
        "teams": {t: play(t) for t in teams.TEAMS},
        "variants": {
            **{name: play("careful", overrides=o, last_quarter=VARIANT_LAST_QUARTER)[3:] for name, o in VARIANTS.items()},
            **{name: play("careful", schedule=sch, last_quarter=VARIANT_LAST_QUARTER)[3:] for name, sch in SCHEDULE_VARIANTS.items()},
        },
        "edge_cases": edge_cases(),
    }


def edge_cases() -> dict:
    """Single quarters from a crafted opening, for branches no course reaches."""
    p = load_scenario()["parameters"]
    base = play("careful", last_quarter=4)[3]
    cases = {}

    # A class at the end of its life: depreciation stops at book value.
    o = Opening(**base["opening"])
    extra = (o.ppe_tech - 1000.0) - o.accum_tech
    o.accum_tech += extra
    o.accum_depr += extra
    o.retained -= extra  # keep the opening balance sheet in balance
    cases["book_value_cap"] = (o, base["decisions"], 0.0)

    # A bill larger than cash plus the credit line: the bank overdraft.
    o = Opening(**base["opening"])
    o.ap += 150_000_000.0
    o.retained -= 150_000_000.0
    cases["overdraft"] = (o, base["decisions"], 0.0)

    out = {}
    for name, (o, dec, div) in cases.items():
        r = calculate(2, base["quarter"], dec, p, o, div, base["market"])
        out[name] = {"quarter": base["quarter"], "market": base["market"], "decisions": dec,
                     "dividend_declared": div, "opening": o.as_dict(), **r}
    return out


def _pre_rounded(x: float, places: int) -> float:
    """PHP 8.3 and earlier first rounded to 15 significant digits."""
    if x == 0 or not math.isfinite(x):
        return x
    d = Decimal(repr(x))
    mag = math.floor(math.log10(abs(x)))
    if mag - 14 < -places:
        d = d.quantize(Decimal(1).scaleb(mag - 14), rounding=ROUND_HALF_UP)
    return float(d.quantize(Decimal(1).scaleb(-places), rounding=ROUND_HALF_UP))


def rounding_cases() -> dict:
    """Money values the PHP runtime must round exactly as this model does.

    Includes values where PHP 8.3 and earlier round differently (they
    pre-round to 15 significant digits; PHP 8.4 doesn't), so the PHP test
    fails on a server too old to reproduce the fixtures.
    """
    rng = random.Random(20261017)
    values, older_differs = [], []
    while len(older_differs) < 200 or len(values) < 2000:
        whole = rng.randrange(0, 10**rng.choice([3, 5, 7, 9]))
        x = (whole + rng.choice([0.005, 0.015, 0.125, 0.375, 0.995])) * rng.choice([1.0, 1 + 2**-52, 1 - 2**-53])
        x = float(f"{x:.17g}")
        if cents(x) != _pre_rounded(x, 2):
            if len(older_differs) < 200:
                older_differs.append(x)
        elif len(values) < 2000:
            values.append(x)
    cases = values + older_differs
    return {"about": "x -> CurrencyRounding::cents(x). Built by economics/build.py.",
            "cases": [[repr(x), cents(x)] for x in cases], "differ_before_php_8_4": len(older_differs)}


def render(data: dict) -> str:
    return json.dumps(data, indent=1, sort_keys=False) + "\n"


if __name__ == "__main__":
    outputs = {OUT: render(build()), ROUNDING_OUT: render(rounding_cases())}
    if "--check" in sys.argv:
        stale = [p.name for p, text in outputs.items() if not p.exists() or p.read_text() != text]
        if stale:
            print(f"out of date: {', '.join(stale)}; run python3 economics/build.py")
            sys.exit(1)
        print("fixtures are up to date")
        sys.exit(0)
    for path, text in outputs.items():
        path.write_text(text)
        print(f"wrote {path.relative_to(ROOT.parent)} ({len(text) / 1e6:.2f} MB)")
