"""Rounding that matches PHP exactly.

PHP's round() and the engine's CurrencyRounding::cents() round half away
from zero on the decimal value. Python's round() rounds half to even on the
binary value, so the model never uses it for money.
"""
from __future__ import annotations

from decimal import ROUND_HALF_UP, Decimal


def php_round(x: float, places: int = 0) -> float:
    q = Decimal(1).scaleb(-places)
    return float(Decimal(repr(float(x))).quantize(q, rounding=ROUND_HALF_UP))


def cents(x: float) -> float:
    return php_round(x, 2)
