"""Where demand comes from.

Quarters 1 to 3 (the warm-up) follow DeterministicDemandRandomizer exactly:
each firm's market grows from the Quarter 0 forecast with its own random
draws, and the season multiplies the prior quarter's demand.

Engine version 2 continues the market past Quarter 3 (ED1 b, "own slice,
one economy"): each firm keeps its own market's size, but from Quarter 4 on
every firm in the course grows and swings with the same draws. In version 2
the season applies to the market's level from Quarter 1 on, so it never
compounds (ED16).
"""
from __future__ import annotations

import hashlib
import math

from .numbers import php_round

DEMAND_FIELDS = (
    "forecast_p1_r1", "forecast_p1_r2", "forecast_p1_r3",
    "forecast_p2_r1", "forecast_p2_r2", "forecast_p2_r3",
)
WARMUP_STREAM = "cf-warmup-v1"
MARKET_STREAM = "cf-market-v2"
MEAN_GROWTH = 0.02


def course_seed(course_id: str, scenario_id: str) -> str:
    return hashlib.sha256(f"cf-course-instance-v1|{course_id}|{scenario_id}".encode()).hexdigest()


def uniform(stream: str) -> float:
    """A uniform draw in (0, 1) from the first 52 bits of a SHA-256 hash."""
    return (int(hashlib.sha256(stream.encode()).hexdigest()[:13], 16) + 0.5) / 4503599627370496.0


def _normal_growth(stream: str, sd: float) -> float:
    u, v = uniform(stream + "|u"), uniform(stream + "|v")
    z = math.sqrt(-2.0 * math.log(u)) * math.cos(2.0 * math.pi * v)
    return 1.0 + MEAN_GROWTH + sd * z


def warmup_growth(seed: str, firm: str, quarter: int, field: str, sd: float) -> float:
    return _normal_growth("|".join([WARMUP_STREAM, seed, firm, str(quarter), field]), sd)


def class_growth(seed: str, quarter: int, field: str, sd: float) -> float:
    """Engine v2, Quarter 4 on: one draw per quarter and field for the whole course."""
    return _normal_growth("|".join([MARKET_STREAM, seed, str(quarter), field]), sd)


def season(p: dict, quarter: int) -> float:
    return float(p[f"seasonality_q{(quarter - 1) % 4 + 1}"])


def market_path(version: int, q0: dict, seed: str, firm: str, last_quarter: int, p: dict) -> dict[int, dict]:
    """Units customers would order at base price, by quarter, product and region.

    Version 1 has no market after Quarter 3 (demand is the team's forecast),
    so it returns the warm-up quarters only.
    """
    sd = float(p["demand_growth_standard_deviation"])
    path: dict[int, dict] = {}
    prior = {k: float(q0[k]) for k in DEMAND_FIELDS}
    top = min(last_quarter, 3) if version == 1 else last_quarter
    for q in range(1, top + 1):
        growth = (lambda k: warmup_growth(seed, firm, q, k, sd)) if q <= 3 else (lambda k: class_growth(seed, q, k, sd))
        if version == 1 or q == 1:
            # Version 1 multiplies the season onto the prior quarter, so seasons compound.
            path[q] = {k: max(0, int(php_round(prior[k] * growth(k) * season(p, q)))) for k in DEMAND_FIELDS}
        else:
            # Version 2 (ED16): the season applies to the market's level, from Quarter 1 on.
            path[q] = {k: max(0, int(php_round(prior[k] / season(p, q - 1) * growth(k) * season(p, q)))) for k in DEMAND_FIELDS}
        prior = {k: float(v) for k, v in path[q].items()}
    return path
