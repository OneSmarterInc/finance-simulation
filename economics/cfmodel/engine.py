"""Reference model of ManufacturingCFEngine, versions 1 and 2.

Version 1 is today's PHP engine, line for line (checked against stored
results by economics/check.py). Version 2 is the engine the design note
decided (claude/CF_V3_Engine_Design_Note.md, ED1 to ED11):

  ED1/E3  demand comes from the market, adjusted for price; the forecast is
          only the team's estimate (it drives production through the rules)
  ED4/E4  unsold units carry into the next quarter, are sold first, and the
          stock a quarter starts with enters cost of goods sold (FIFO)
  ED5/E5  double-declining balance on book value; units of production
          follows the plant's output
  ED6/E2  under FOB shipping point, goods in transit are inventory, with the
          payable to the supplier
  ED7/E1  the PPE note carries accumulated depreciation by class and its
          net total equals the balance sheet
  ED8/E6  the marketing budget lifts demand with diminishing returns;
          segment shares are gone
  ED10    postponed production is ignored
  ED3     the revenue and inventory notes report units ordered, sold, not
          filled and left in stock

Every amount is rounded where the PHP engine rounds it, so the PHP port of
version 2 can be held to the fixtures at relative 1e-6, absolute 1e-4.
"""
from __future__ import annotations

import math
from dataclasses import asdict, dataclass

from .market import DEMAND_FIELDS
from .numbers import cents, php_round

REGIONS = ("r1", "r2", "r3")
PRODUCTS = ("p1", "p2")
CLASSES = ("mfg", "tech", "dc")

V1_FIELDS = (
    "is_p1_rev", "is_p2_rev", "is_returns", "is_rebates", "is_raw_mat", "is_labor", "is_overhead", "is_end_inv",
    "is_lifo_adj", "is_carry", "is_excise_cogs", "is_marketing", "is_freight", "is_sl_penalties", "is_excise", "is_inspection",
    "is_admin", "is_warranty", "is_interest", "is_disposal", "is_other", "is_tax",
    "bs_cash", "bs_ar", "bs_inventory", "bs_prepaid", "bs_ppe_gross", "bs_accum_depr", "bs_rou", "bs_tech_net",
    "bs_ap", "bs_wages", "bs_mkt", "bs_warranty_res", "bs_sl_accrual", "bs_excise", "bs_curr_ltd", "bs_def_rev",
    "bs_ltd", "bs_lease", "bs_dtl", "bs_bank_overdraft", "bs_common", "bs_retained", "bs_curr_ni",
    "cf_net_income", "cf_depr", "cf_rou", "cf_lifo", "cf_ar_chg", "cf_inv_chg", "cf_prepaid_chg", "cf_ap_chg",
    "cf_accrued_chg", "cf_warranty_chg", "cf_def_rev_chg", "cf_excise_chg", "cf_capex_mfg", "cf_capex_tech",
    "cf_capex_dc", "cf_disposal", "cf_borrow_proc", "cf_borrow_repay", "cf_lease_pmt", "cf_equity", "cf_beg_cash",
    "cf_cash_interest", "cf_cash_tax", "cf_overdraft_chg",
    "n2_r1_p1", "n2_r1_p2", "n2_r2_p1", "n2_r2_p2", "n2_r3_p1", "n2_r3_p2", "n2_rebate_open",
    "n2_rebate_added", "n2_rebate_paid",
    "n3_raw_mat", "n3_wip", "n3_fg", "n3_in_transit", "n3_lifo_res", "n3_lifo_chg", "n3_overhead",
    "n4_mfg_gross", "n4_mfg_depr", "n4_mfg_net", "n4_tech_gross", "n4_tech_depr", "n4_tech_net", "n4_dc_gross",
    "n4_dc_depr", "n4_dc_net", "n4_disposal_nbv", "n4_disposal_proc", "n4_disposal_gain", "n4_util_rate",
    "n5_balance", "n5_rate", "n5_limit", "n5_auto_draw", "n5_draw_rate", "n5_overdraft", "n5_shortfall_indicator",
    "n5_mat_y1", "n5_mat_y2", "n5_mat_beyond", "n5_cov_curr", "n5_cov_de", "n5_cov_margin", "n5_cov_interest",
    "n5_cov_cash", "n5_int_recon",
    "n6_supplier", "n6_po_fob", "n6_lease", "n6_war_open", "n6_war_exp", "n6_war_paid", "n6_war_close",
    "n6_sl_open", "n6_sl_exp", "n6_sl_paid", "n6_sl_close", "n6_exc_open", "n6_exc_acc", "n6_exc_paid", "n6_exc_close",
)

# ED3, ED4, ED7, ED8: fields version 2 adds. Units are whole numbers, not money.
V2_NEW_FIELDS = (
    *(f"n2_ord_{r}_{p}" for p in PRODUCTS for r in REGIONS),
    *(f"n2_sold_{r}_{p}" for p in PRODUCTS for r in REGIONS),
    "n2_short_p1", "n2_short_p2", "n2_mkt_lift",
    "n3_units_p1", "n3_units_p2", "n3_value_p1", "n3_value_p2", "n3_unit_cost",
    "is_beg_inv",
    "n4_mfg_accum", "n4_tech_accum", "n4_dc_accum",
)
V2_FIELDS = V1_FIELDS + V2_NEW_FIELDS

V1_NON_MONETARY = {
    "n4_util_rate", "n5_rate", "n5_draw_rate", "n5_cov_curr", "n5_cov_de", "n5_cov_margin",
    "n5_cov_interest", "n5_cov_cash", "n5_shortfall_indicator", "n6_po_fob",
}
V2_NON_MONETARY = V1_NON_MONETARY | {k for k in V2_NEW_FIELDS if k.startswith(("n2_ord_", "n2_sold_", "n2_short_", "n3_units_"))} | {"n2_mkt_lift"}

# ED8: m(b) = 1 + slope * ln((b + offset) / (reference + offset)); m(5%) = 1.
MARKETING_LIFT_SLOPE = 0.06
MARKETING_LIFT_OFFSET = 0.01
MARKETING_REFERENCE_BUDGET = 0.05

# Inputs version 2 no longer reads (item 76, ED8, ED10).
V2_UNUSED_INPUTS = {
    "forecasting": ("forecast_confidence",),
    "pricing": ("discount_policy",),
    "production": ("postponed_flag", "postponed_pct"),
    "marketing": ("marketing_alloc_champions", "marketing_alloc_growth", "marketing_alloc_at_risk", "marketing_alloc_other"),
    "certification": ("attestation_scope",),
}


class DividendTooLarge(ValueError):
    def __init__(self, requested: float, maximum: float, quarter: int):
        super().__init__(f"Requested dividend {requested:.2f} exceeds the maximum {maximum:.2f} for Quarter {quarter}")
        self.requested, self.maximum = requested, maximum


def fields_for(version: int) -> tuple[str, ...]:
    return V1_FIELDS if version == 1 else V2_FIELDS


def marketing_lift(budget_pct: float) -> float:
    ref = MARKETING_REFERENCE_BUDGET + MARKETING_LIFT_OFFSET
    return max(0.0, 1.0 + MARKETING_LIFT_SLOPE * math.log((max(0.0, budget_pct) + MARKETING_LIFT_OFFSET) / ref))


@dataclass
class Opening:
    """What a quarter starts from: the prior quarter's closing position."""
    cash: float
    ar: float
    inventory: float
    ap: float
    debt: float
    retained: float
    accum_depr: float
    plant: float
    capital: float
    ppe_mfg: float
    ppe_tech: float
    ppe_dc: float
    warranty: float = 0.0
    sl_accrual: float = 0.0
    excise: float = 0.0
    overdraft: float = 0.0
    # version 2 only
    fg_units_p1: float = 0.0
    fg_units_p2: float = 0.0
    fg_value_p1: float = 0.0  # stock at cost before the LIFO reserve (prior n3_value_p1)
    fg_value_p2: float = 0.0
    lifo_reserve: float = 0.0
    accum_mfg: float = 0.0
    accum_tech: float = 0.0
    accum_dc: float = 0.0

    def as_dict(self) -> dict:
        return asdict(self)

    @staticmethod
    def from_parameters(p: dict) -> "Opening":
        total = p["gross_ppe_mfg"] + p["gross_ppe_tech"] + p["gross_ppe_dc"]
        scale = p["opening_plant_investment"] / total
        return Opening(
            cash=float(p["opening_cash"]), ar=0.0, inventory=0.0, ap=0.0,
            debt=float(p["opening_liabilities"]), retained=0.0, accum_depr=0.0,
            plant=float(p["opening_plant_investment"]), capital=float(p["opening_corporate_capitalization"]),
            ppe_mfg=php_round(p["gross_ppe_mfg"] * scale, 2), ppe_tech=php_round(p["gross_ppe_tech"] * scale, 2),
            ppe_dc=php_round(p["gross_ppe_dc"] * scale, 2),
        )

    @staticmethod
    def from_fields(f: dict, version: int) -> "Opening":
        o = Opening(
            cash=f["bs_cash"], ar=f["bs_ar"], inventory=f["bs_inventory"], ap=f["bs_ap"],
            debt=f["bs_curr_ltd"] + f["bs_ltd"], retained=f["bs_retained"] + f["bs_curr_ni"],
            accum_depr=f["bs_accum_depr"], plant=f["bs_ppe_gross"], capital=f["bs_common"],
            ppe_mfg=f["n4_mfg_gross"], ppe_tech=f["n4_tech_gross"], ppe_dc=f["n4_dc_gross"],
            warranty=f["bs_warranty_res"], sl_accrual=f["bs_sl_accrual"], excise=f["bs_excise"],
            overdraft=f["bs_bank_overdraft"],
        )
        if version >= 2:
            o.fg_units_p1, o.fg_units_p2 = f["n3_units_p1"], f["n3_units_p2"]
            o.fg_value_p1, o.fg_value_p2 = f["n3_value_p1"], f["n3_value_p2"]
            o.lifo_reserve = f["n3_lifo_res"]
            o.accum_mfg, o.accum_tech, o.accum_dc = f["n4_mfg_accum"], f["n4_tech_accum"], f["n4_dc_accum"]
        return o


ELECTION_KEYS = ("inventory_method", "depreciation_method", "shipping_terms", "bad_debt_method", "warranty_method", "excise_tax_treatment")


def adapt(decisions: dict, version: int = 1) -> dict:
    """DecisionAdapter: maps student elections to the engine's internal terms.

    Version 1 lets any key in notes_elections override an internal term (so a
    stray carry_cost_treat would switch on capitalised carrying cost).
    Version 2 reads the six elections only.
    """
    d = {k: dict(v) for k, v in decisions.items()}
    n = decisions.get("notes_elections", {})
    terms = "FOB Destination" if n.get("shipping_terms") == "fob_destination" else "FOB Shipping Point"
    inv = {"lifo": "LIFO", "weighted_average": "Weighted Average"}.get(n.get("inventory_method"), "FIFO")
    depr = "Double Declining Balance" if n.get("depreciation_method") == "double_declining_balance" else "Straight-Line"
    base = {
        "fob_terms": terms, "inv_method": inv, "depr_method": depr, "life_mfg": 10, "life_tech": 5, "life_dc": 20,
        "overhead_rate": 0.75, "carry_cost_treat": "Period Cost (Expense)", "proc_terms": terms,
        "allowance_pct": 0.02, "warranty_rate": 0.01,
    }
    base.update(n if version == 1 else {k: n[k] for k in ELECTION_KEYS if k in n})
    d["notes_elections"] = base
    return d


def _elastic(units: float, price: float, base: float, e: float, lift: float = 1.0) -> float:
    if units <= 0 or price <= 0 or base <= 0:
        return 0.0
    return float(php_round(units * ((price / base) ** e) * lift))


def _allocate(regional: dict, sold: float) -> dict:
    total = sum(regional.values())
    if total <= 0 or sold <= 0:
        return {r: 0.0 for r in REGIONS}
    r1 = php_round(sold * regional["r1"] / total)
    r2 = php_round(sold * regional["r2"] / total)
    return {"r1": r1, "r2": r2, "r3": sold - r1 - r2}


def _depreciation(version: int, notes: dict, o: Opening, p: dict, total_prod: float) -> dict:
    lives = {"mfg": notes["life_mfg"], "tech": notes["life_tech"], "dc": notes["life_dc"]}
    gross = {"mfg": o.ppe_mfg, "tech": o.ppe_tech, "dc": o.ppe_dc}
    if version == 1:
        mult = 1.0 if notes["depr_method"] == "Straight-Line" else 2.0
        return {k: gross[k] * (mult / max(lives[k], 1) / 4) for k in CLASSES}
    accum = {"mfg": o.accum_mfg, "tech": o.accum_tech, "dc": o.accum_dc}
    method = notes.get("depreciation_method", "straight_line")
    out = {}
    for k in CLASSES:
        book = max(0.0, gross[k] - accum[k])
        straight = gross[k] / max(lives[k], 1) / 4
        if method == "double_declining_balance":
            charge = book * (2.0 / max(lives[k], 1) / 4)
        elif method == "units_of_production" and k == "mfg":
            # the plant wears with use: one full shift of output is one straight-line quarter
            charge = straight * (total_prod / float(p["capacity_per_shift"]))
        else:
            charge = straight
        # rounded per class, so cost - accumulated = net to the cent
        out[k] = cents(min(charge, book))
    return out


def _roll(opening: float, addition: float, paid: float) -> dict:
    return {"open": opening, "addition": addition, "paid": paid, "close": opening + addition - paid}


def _credit_tier(score: float, p: dict) -> tuple[float, float]:
    for t in ("excellent", "good", "fair", "poor"):
        if score >= p[f"credit_tier_{t}_min_score"]:
            return p[f"credit_tier_{t}_rate"], p[f"credit_tier_{t}_limit"]
    return p["credit_tier_distressed_rate"], p["credit_tier_distressed_limit"]


def _supplier(p: dict, code: str, attr: str) -> float:
    return p[f"supplier_{code.lower()}_{attr}"]


def calculate(version: int, quarter: int, decisions: dict, p: dict, o: Opening, dividend: float,
              market: dict | None = None) -> dict:
    """One firm-quarter. Returns {fields, income_statement, intermediates}.

    decisions: the complete resolved decisions (every operational category plus
    notes_elections). dividend: the amount the team declared; it is capped at
    the maximum. market (version 2 only): units customers would order at base
    price this quarter, keyed forecast_p1_r1 ... forecast_p2_r3.
    """
    v2 = version >= 2
    if v2 and market is None:
        raise ValueError("engine version 2 needs the quarter's market")
    d = adapt(decisions, version)
    pr, prod, notes = d["pricing"], d["production"], d["notes_elections"]
    e = p["price_elasticity"]
    cap = prod["shifts"] * p["capacity_per_shift"]
    postponed = (not v2) and prod.get("postponed_flag", "NO") == "YES"

    # --- demand and revenue
    lift = marketing_lift(d["marketing"]["marketing_budget_pct"]) if v2 else 1.0
    source = market if v2 else d["forecasting"]
    dem = {
        prd: {r: _elastic(source[f"forecast_{prd}_{r}"], pr[f"price_{prd}_{r}"], p[f"{prd}_base_price"], e, lift) for r in REGIONS}
        for prd in PRODUCTS
    }
    made = {"p1": min(prod["p1_production"], cap)}
    made["p2"] = min(prod["p2_production"], max(0, cap - made["p1"]))
    on_hand = {"p1": o.fg_units_p1, "p2": o.fg_units_p2} if v2 else {"p1": 0.0, "p2": 0.0}
    avail = {k: made[k] + on_hand[k] for k in PRODUCTS}
    sold = {k: min(sum(dem[k].values()), avail[k]) for k in PRODUCTS}
    reg = {k: _allocate(dem[k], sold[k]) for k in PRODUCTS}
    n2 = {f"n2_{r}_{k}": reg[k][r] * pr[f"price_{k}_{r}"] for k in PRODUCTS for r in REGIONS}
    gross_rev = sum(n2.values())
    catch = p["inspection_" + d["quality"]["inspection_level"].lower() + "_catch_rate"]
    defect = _supplier(p, d["sourcing"]["primary_supplier"], "defect_rate")
    units_sold = sold["p1"] + sold["p2"]
    defective = php_round(units_sold * defect * (1 - catch))
    avg_price = gross_rev / units_sold if units_sold > 0 else 0.0
    returns = defective * avg_price * p["returns_rate_on_defects"]
    rebates = gross_rev * p["rebate_rate"]
    p1_rev = n2["n2_r1_p1"] + n2["n2_r2_p1"] + n2["n2_r3_p1"]
    p2_rev = n2["n2_r1_p2"] + n2["n2_r2_p2"] + n2["n2_r3_p2"]
    left = {k: max(0.0, avail[k] - sold[k]) for k in PRODUCTS}
    total_prod = made["p1"] + made["p2"]

    # --- cost of goods sold and inventory
    src = d["sourcing"]
    primary_cost = _supplier(p, src["primary_supplier"], "cost")
    sec = str(src.get("secondary_supplier", "NONE")).upper()
    blended = primary_cost if sec == "NONE" else primary_cost * src["primary_alloc"] + _supplier(p, sec, "cost") * (1 - src["primary_alloc"])
    raw = total_prod * blended
    ot = 1 + p["overtime_premium"] if int(prod["shifts"]) == 3 else 1.0
    labor = total_prod * p["base_labor_per_unit"] * ot
    depr = _depreciation(version, notes, o, p, total_prod)
    fixed_oh = p["fixed_overhead_per_period"] * notes["overhead_rate"]
    overhead = fixed_oh + depr["mfg"]
    unit_cost = blended + p["base_labor_per_unit"] * ot + (fixed_oh + depr["mfg"]) / max(total_prod, 1)
    ending_units = left["p1"] + left["p2"]
    stock_value = {"p1": 0.0, "p2": 0.0}
    if v2:
        # FIFO by product: units on hand are sold first, so what is left is this
        # quarter's production, plus old units only when more were left than
        # were made. Stock carried in is one batch at its average cost.
        opening_value = {"p1": o.fg_value_p1, "p2": o.fg_value_p2}
        for k in PRODUCTS:
            made_left = min(left[k], made[k])
            old_left = left[k] - made_left
            old_cost = opening_value[k] / on_hand[k] if on_hand[k] > 0 else 0.0
            stock_value[k] = cents(made_left * unit_cost + old_left * old_cost)
        ending_inv = stock_value["p1"] + stock_value["p2"]
        beginning_inv = opening_value["p1"] + opening_value["p2"]
    else:
        ending_inv = ending_units * unit_cost
        beginning_inv = 0.0
    lifo_reserve = ending_units * blended * p["inflation_rate"] if notes["inv_method"] == "LIFO" else 0.0
    # v1 charges the whole reserve every quarter; v2 charges its change, so the
    # inventory that leaves the balance sheet is exactly what enters COGS.
    lifo_adj = lifo_reserve - o.lifo_reserve if v2 else lifo_reserve
    carrying = ending_inv * p["carrying_cost_rate"]
    capitalized = carrying if notes["carry_cost_treat"] == "Product Cost (Capitalize)" else 0.0
    carry_exp = 0.0 if capitalized > 0 else carrying
    raw_inv = ending_units * blended * p["inventory_raw_mat_ratio"] if ending_units > 0 else 0.0
    wip = ending_units * unit_cost * p["inventory_wip_ratio"] if postponed else 0.0
    in_transit = blended * total_prod * p["inventory_in_transit_ratio"] if notes["proc_terms"] == "FOB Shipping Point" else 0.0

    # --- operating expenses
    net_rev = p1_rev + p2_rev - returns - rebates
    mkt = (p1_rev + p2_rev) * d["marketing"]["marketing_budget_pct"]
    mode = d["logistics"]["logistics_mode"].lower()
    freight = units_sold * p[f"logistics_{mode}_cost"] + depr["dc"]
    late = units_sold * (1 - p[f"logistics_{mode}_on_time_rate"])
    sl_pen = late * p["sl_penalty_per_unit"]
    excise = (n2["n2_r2_p1"] + n2["n2_r2_p2"]) * p["excise_rate_r2"] + (n2["n2_r3_p1"] + n2["n2_r3_p2"]) * p["excise_rate_r3"]
    if postponed:
        excise *= 1 - prod["postponed_pct"] * p["postponed_duty_saving"]
    insp = total_prod * p["inspection_" + d["quality"]["inspection_level"].lower() + "_cost"]
    period_depr = depr["mfg"] + depr["tech"] + depr["dc"]
    admin = p["admin_overhead"] + depr["tech"]
    war_econ = net_rev * notes["warranty_rate"]
    war_paid = war_econ * p["warranty_claims_rate"]
    war_exp = war_paid if notes.get("warranty_method", "percentage_of_sales") == "expense_as_incurred" else war_econ
    war = _roll(o.warranty, war_exp, o.warranty + war_paid)
    sl = _roll(o.sl_accrual, sl_pen, o.sl_accrual + sl_pen * p["sl_payment_rate"])
    exc = _roll(o.excise, excise, o.excise + excise * p["excise_payment_rate"])
    exc_exp = excise if notes.get("excise_tax_treatment", "cogs_inclusion") == "separate_operating_expense" else 0.0
    exc_cogs = excise - exc_exp
    total_cogs = beginning_inv + raw + labor + overhead - ending_inv + lifo_adj + carry_exp
    gp = p1_rev + p2_rev - returns - rebates - total_cogs
    current_credit = net_rev * 0.20
    gross_ar = o.ar + current_credit
    allowance = notes["allowance_pct"]
    if notes.get("bad_debt_method", "percentage_of_receivables") == "percentage_of_sales":
        bad_debt = net_rev * allowance
    else:
        bad_debt = max(0.0, gross_ar * allowance - o.ar * allowance)
    op_inc = gp - exc_cogs - (mkt + freight + sl_pen + exc_exp + insp + admin + war_exp + bad_debt)
    total_opex = mkt + freight + sl_pen + excise + insp + admin + war_exp + bad_debt

    # --- below the line
    nr = max(net_rev, 1)
    score = min(100, max(0, (op_inc / nr) * 200 * 0.5 + ((gp - exc_cogs) / nr) * 200 * 0.3 + (100 - (total_opex / nr) * 100) * 0.2))
    rate, limit = _credit_tier(score, p)
    interest = o.debt * (rate / 4)

    partial = {
        "is_p1_rev": p1_rev, "is_p2_rev": p2_rev, "is_returns": returns, "is_rebates": rebates, **n2,
        "n2_rebate_added": rebates, "is_raw_mat": raw, "is_labor": labor, "is_overhead": overhead, "is_end_inv": ending_inv,
        "is_lifo_adj": lifo_adj, "is_carry": carry_exp, "n3_raw_mat": raw_inv, "n3_wip": wip,
        "n3_fg": max(0, ending_inv - wip - raw_inv), "n3_in_transit": in_transit, "n3_lifo_res": lifo_reserve,
        "n3_lifo_chg": lifo_adj, "n3_overhead": overhead,
        "is_marketing": mkt, "is_freight": freight, "is_sl_penalties": sl_pen,
        "is_excise_cogs": exc_cogs, "is_excise": exc_exp, "is_inspection": insp, "is_admin": admin, "is_warranty": war_exp,
        "n6_supplier": total_prod * blended, "n6_po_fob": 1.0 if notes["proc_terms"] == "FOB Shipping Point" else 0.0,
        "n6_lease": 0.0, "n6_war_open": war["open"], "n6_war_exp": war["addition"], "n6_war_paid": war["paid"], "n6_war_close": war["close"],
        "n6_sl_open": sl["open"], "n6_sl_exp": sl["addition"], "n6_sl_paid": sl["paid"], "n6_sl_close": sl["close"],
        "n6_exc_open": exc["open"], "n6_exc_acc": exc["addition"], "n6_exc_paid": exc["paid"], "n6_exc_close": exc["close"],
        "is_interest": interest, "is_other": bad_debt, "n5_rate": rate, "n5_limit": limit, "n5_draw_rate": rate,
    }
    if v2:
        partial["is_beg_inv"] = beginning_inv
    non_monetary = V2_NON_MONETARY if v2 else V1_NON_MONETARY
    norm = lambda part: {k: (float(v) if k in non_monetary else cents(v)) for k, v in part.items()}
    stmt = income_statement(norm(partial), p["tax_rate"], v2)
    ni, tax = stmt["net_income"], stmt["income_tax_expense"]

    # --- balance sheet before cash, and the PPE note
    capex = {k: float(p[f"capex_{k}"]) for k in CLASSES}
    gross = {"mfg": o.ppe_mfg + capex["mfg"], "tech": o.ppe_tech + capex["tech"], "dc": o.ppe_dc + capex["dc"]}
    accum_class = {"mfg": o.accum_mfg + depr["mfg"], "tech": o.accum_tech + depr["tech"], "dc": o.accum_dc + depr["dc"]}
    transit_on_books = in_transit if v2 else 0.0
    inventory = max(0.0, ending_inv + capitalized - (lifo_reserve if v2 else lifo_adj)) + transit_on_books
    ap = total_prod * blended * 0.30 + transit_on_books
    ar = max(0.0, current_credit - bad_debt)
    cur_debt = php_round(o.debt * 0.10, 2)
    lt_debt = php_round(o.debt - cur_debt, 2)

    # --- cash flow and debt
    ar_chg = cents(ar) - o.ar
    inv_chg = cents(inventory) - o.inventory
    ap_chg = cents(ap) - o.ap
    war_chg = cents(war["close"]) - o.warranty
    sl_chg = cents(sl["close"]) - o.sl_accrual
    exc_chg = cents(exc["close"]) - o.excise
    ocf = ni + cents(period_depr) - ar_chg - inv_chg + ap_chg + war_chg + sl_chg + exc_chg
    icf = -sum(capex.values())
    cur_debt_m, lt_debt_m = cents(cur_debt), cents(lt_debt)
    repay = php_round(cur_debt_m * p["borrow_repay_rate"], 2)
    pre = o.cash - o.overdraft + ocf + icf - repay
    distributable = php_round(max(0.0, o.retained + ni), 2)
    available = php_round(max(0.0, pre - p["minimum_cash_buffer"]), 2)
    maximum = php_round(min(distributable, available), 2) if quarter >= 4 else 0.0
    if dividend < 0:
        raise ValueError("dividend must be nonnegative")
    requested = php_round(dividend, 2)
    if requested > maximum:
        # DividendDecisionValidator refuses the close; it does not cap
        raise DividendTooLarge(requested, maximum, quarter)
    paid_dividend = requested
    draw = php_round(min(p["minimum_cash_buffer"] - pre, limit), 2) if pre < p["minimum_cash_buffer"] else 0.0
    after = php_round(pre - paid_dividend + draw, 2)
    od = php_round(max(0.0, -after), 2)
    od_move = php_round(od - o.overdraft, 2)
    cash = php_round(max(0.0, after), 2)
    end_cur = php_round(max(0.0, cur_debt_m - repay), 2)
    end_lt = php_round(lt_debt_m + draw, 2)
    closing_debt = php_round(end_cur + end_lt, 2)
    retained = php_round(o.retained - paid_dividend, 2)
    current_assets = cash + ar + inventory
    current_liab = ap + war["close"] + sl["close"] + exc["close"] + end_cur + od
    total_equity = o.capital + retained + ni

    zero = 0.0
    fields = dict(partial)
    fields.update({
        "is_tax": tax, "is_disposal": zero,
        "bs_cash": cash, "bs_ar": ar, "bs_inventory": inventory, "bs_prepaid": zero, "bs_ppe_gross": sum(gross.values()),
        "bs_accum_depr": sum(accum_class.values()) if v2 else o.accum_depr + period_depr, "bs_rou": zero, "bs_tech_net": zero,
        "bs_ap": ap, "bs_wages": zero, "bs_mkt": zero, "bs_warranty_res": war["close"], "bs_sl_accrual": sl["close"],
        "bs_excise": exc["close"], "bs_curr_ltd": end_cur, "bs_def_rev": zero, "bs_ltd": end_lt, "bs_lease": zero,
        "bs_dtl": zero, "bs_bank_overdraft": od, "bs_common": o.capital, "bs_retained": retained, "bs_curr_ni": ni,
        "n2_rebate_open": zero, "n2_rebate_paid": rebates * p["rebate_payment_rate"],
        "n4_mfg_gross": gross["mfg"], "n4_tech_gross": gross["tech"], "n4_dc_gross": gross["dc"],
        "n4_mfg_depr": depr["mfg"], "n4_tech_depr": depr["tech"], "n4_dc_depr": depr["dc"],
        "n4_disposal_nbv": zero, "n4_disposal_proc": zero, "n4_disposal_gain": zero,
        "n4_util_rate": min(1.0, total_prod / max(cap, 1)),
        "cf_net_income": ni, "cf_depr": cents(period_depr), "cf_rou": zero, "cf_lifo": zero, "cf_ar_chg": ar_chg,
        "cf_inv_chg": inv_chg, "cf_prepaid_chg": zero, "cf_ap_chg": ap_chg, "cf_accrued_chg": sl_chg,
        "cf_warranty_chg": war_chg, "cf_def_rev_chg": zero, "cf_excise_chg": exc_chg,
        "cf_capex_mfg": capex["mfg"], "cf_capex_tech": capex["tech"], "cf_capex_dc": capex["dc"], "cf_disposal": zero,
        "cf_borrow_proc": draw, "cf_borrow_repay": repay, "cf_lease_pmt": zero, "cf_equity": -paid_dividend,
        "cf_beg_cash": o.cash, "cf_cash_interest": interest, "cf_cash_tax": tax, "cf_overdraft_chg": od_move,
        "n5_balance": closing_debt, "n5_auto_draw": draw, "n5_overdraft": od, "n5_shortfall_indicator": 1.0 if od > 0 else 0.0,
        "n5_mat_y1": cur_debt_m, "n5_mat_y2": lt_debt_m * 0.40, "n5_mat_beyond": lt_debt_m * 0.60,
        "n5_cov_curr": current_assets / current_liab if current_liab > 0 else 0.0,
        "n5_cov_de": closing_debt / total_equity if total_equity > 0 else 0.0,
        "n5_cov_margin": op_inc / net_rev if net_rev > 0 else 0.0,
        "n5_cov_interest": op_inc / interest if interest > 0 else 0.0,
        "n5_cov_cash": cash / p["minimum_cash_buffer"] if p["minimum_cash_buffer"] > 0 else 0.0,
        "n5_int_recon": interest,
    })
    if v2:
        fields.update({
            "n4_mfg_net": gross["mfg"] - accum_class["mfg"], "n4_tech_net": gross["tech"] - accum_class["tech"],
            "n4_dc_net": gross["dc"] - accum_class["dc"],
            "n4_mfg_accum": accum_class["mfg"], "n4_tech_accum": accum_class["tech"], "n4_dc_accum": accum_class["dc"],
            "n2_mkt_lift": lift,
            "n3_units_p1": left["p1"], "n3_units_p2": left["p2"],
            "n3_value_p1": stock_value["p1"], "n3_value_p2": stock_value["p2"],
            "n3_unit_cost": unit_cost if total_prod > 0 else 0.0,
            "n2_short_p1": max(0.0, sum(dem["p1"].values()) - sold["p1"]),
            "n2_short_p2": max(0.0, sum(dem["p2"].values()) - sold["p2"]),
        })
        for k in PRODUCTS:
            for r in REGIONS:
                fields[f"n2_ord_{r}_{k}"] = dem[k][r]
                fields[f"n2_sold_{r}_{k}"] = reg[k][r]
    else:
        fields.update({f"n4_{k}_net": gross[k] - depr[k] for k in CLASSES})

    keys = fields_for(version)
    missing = [k for k in keys if k not in fields]
    extra = [k for k in fields if k not in keys]
    if missing or extra:
        raise AssertionError(f"field set mismatch: missing {missing}, extra {extra}")
    fields = {k: (float(fields[k]) if k in non_monetary else cents(fields[k])) for k in keys}
    statement = income_statement(fields, p["tax_rate"], v2)

    mid = {
        "quarter_number": quarter, "market_lift": lift,
        "p1_demand": sum(dem["p1"].values()), "p2_demand": sum(dem["p2"].values()),
        "p1_made": made["p1"], "p2_made": made["p2"], "p1_on_hand": on_hand["p1"], "p2_on_hand": on_hand["p2"],
        "p1_sold": sold["p1"], "p2_sold": sold["p2"], "p1_unsold": left["p1"], "p2_unsold": left["p2"],
        "total_production": total_prod, "total_units_sold": units_sold, "unit_cost": unit_cost,
        "blended_cost": blended, "credit_score": score, "interest_rate": rate, "borrowing_limit": limit,
        "dividend_requested": requested, "dividend": paid_dividend, "maximum_dividend": maximum,
        "distributable_retained_earnings": distributable, "cash_available_for_dividend": available,
        "pre_dividend_cash": pre, "operating_cash_flow": ocf,
    }
    return {"fields": fields, "income_statement": statement, "intermediates": mid}


def income_statement(f: dict, tax_rate: float, v2: bool) -> dict:
    nrev = cents(f["is_p1_rev"] + f["is_p2_rev"] - f["is_returns"] - f["is_rebates"])
    beg = f.get("is_beg_inv", 0.0) if v2 else 0.0
    cogs = cents(beg + f["is_raw_mat"] + f["is_labor"] + f["is_overhead"] + f["is_excise_cogs"] - f["is_end_inv"] + f["is_lifo_adj"] + f["is_carry"])
    gp = cents(nrev - cogs)
    opex = cents(f["is_marketing"] + f["is_freight"] + f["is_sl_penalties"] + f["is_excise"] + f["is_inspection"] + f["is_admin"] + f["is_warranty"] + f["is_other"])
    opi = cents(gp - opex)
    interest = cents(f["is_interest"])
    ibt = cents(opi - interest)
    tax = 0.0 if ibt <= 0 else cents(ibt * tax_rate)
    return {
        "net_revenue": nrev, "cost_of_goods_sold": cogs, "gross_profit": gp, "operating_expenses": opex,
        "operating_income": opi, "bad_debt_expense": f["is_other"], "interest_expense": interest,
        "income_before_income_taxes": ibt, "income_tax_expense": tax, "net_income": cents(ibt - tax),
    }
