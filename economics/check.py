"""Named checks for the economics package.

    python3 economics/check.py

Each check has a plain name and either passes or says what failed. The
script exits non-zero if any check fails, so CI can run it.
"""
from __future__ import annotations

import json
import math
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from cfmodel import rules, teams  # noqa: E402
from cfmodel.course import ROOT, load_reference_course, load_scenario, play, value_created  # noqa: E402
from cfmodel.engine import (  # noqa: E402
    CLASSES, PRODUCTS, REGIONS, V2_UNUSED_INPUTS, DividendTooLarge, Opening, calculate, fields_for, marketing_lift,
)
from cfmodel.market import DEMAND_FIELDS, class_growth, market_path, season, warmup_growth  # noqa: E402
from cfmodel.numbers import php_round  # noqa: E402

TOL = 0.011  # a cent, as AccountingIdentityValidator allows
results: list[tuple[str, bool, str]] = []


def check(name: str):
    def wrap(fn):
        try:
            detail = fn() or ""
            results.append((name, True, detail))
        except AssertionError as e:
            results.append((name, False, str(e)))
        except Exception as e:  # a crash is a failure, with its reason
            results.append((name, False, f"{type(e).__name__}: {e}"))
        return fn
    return wrap


SCEN = load_scenario()
P = SCEN["parameters"]
COURSE = load_reference_course()
V1 = json.loads((ROOT / "fixtures" / "v1_stored_results.json").read_text())
FIX = json.loads((ROOT / "fixtures" / "v2_reference.json").read_text())
RUNS = FIX["teams"]
CHAINS = {**{t: rows for t, rows in RUNS.items()}, **{f"variant {v}": rows for v, rows in FIX["variants"].items()}}
ALL_V2 = [(f"{c} Q{r['quarter']}", r) for c, rows in CHAINS.items() for r in rows] + \
         [(f"edge case {n}", r) for n, r in FIX["edge_cases"].items()]
# consecutive quarters of one firm: (label, prior row, row)
PAIRS = [(f"{c} Q{b['quarter']}", a, b) for c, rows in CHAINS.items() for a, b in zip(rows, rows[1:])]


def close(a: float, b: float, tol: float = TOL) -> bool:
    return abs(a - b) <= tol


def each(rows, test, what):
    bad = [label for label, r in rows if not test(r)]
    assert not bad, f"{what}: {', '.join(bad[:6])}{' ...' if len(bad) > 6 else ''}"
    return f"{len(rows)} firm-quarters"


# --- version 1: the model is today's engine -----------------------------------

@check("Version 1 model reproduces every value the PHP engine stored")
def _():
    n = 0
    for firm in V1["firms"]:
        p = firm["parameters"]
        o = Opening.from_parameters(p)
        for q in firm["quarters"]:
            dec = {k: v for k, v in q["decisions"].items() if k != "dividend"}
            r = calculate(1, q["quarter"], dec, p, o, q["dividend"] or 0.0)
            assert set(q["fields"]) == set(fields_for(1)), "stored field set differs from the model's"
            for k, s in q["fields"].items():
                n += 1
                assert abs(r["fields"][k] - s) <= max(1e-4, 1e-6 * abs(s)), f"{firm['code']} Q{q['quarter']} {k}: stored {s}, model {r['fields'][k]}"
            for k, s in q["income_statement"].items():
                n += 1
                assert abs(r["income_statement"][k] - s) <= 1e-4, f"{firm['code']} Q{q['quarter']} {k}"
            o = Opening.from_fields(q["fields"], 1)
    return f"{n} values, 3 firms, 11 firm-quarters, no difference"


@check("Version 1 rules reproduce the stored warm-up market and decisions")
def _():
    firm = next(f for f in V1["firms"] if f["code"] == COURSE["stored_v1_firm_code"])
    path = market_path(1, SCEN["q0_forecast"], COURSE["seed"], COURSE["warmup_firm_id"], 3, P)
    for q in firm["quarters"][:3]:
        n = q["quarter"]
        want = {k: v for k, v in q["decisions"].items() if k != "dividend"}
        got = rules.resolve(1, n, P, rules.demand_context(1, n, path, None, P), {}, SCEN["warmup_notes_elections"])
        assert got == want, f"Q{n}: rules {got} vs stored {want}"
    return "Quarters 1-3 of P8-A"


@check("Scenario data matches the parameters stored with real results")
def _():
    for firm in V1["firms"]:
        diff = {k for k in set(P) | set(firm["parameters"]) if P.get(k) != firm["parameters"].get(k)}
        assert not diff, f"{firm['code']}: {sorted(diff)[:5]}"


@check("Fixtures are up to date with the model")
def _():
    r = subprocess.run([sys.executable, str(ROOT / "build.py"), "--check"], capture_output=True, text=True)
    assert r.returncode == 0, r.stdout.strip() or r.stderr.strip()


# --- version 2: accounting identities, every firm-quarter ---------------------

def _op(r):
    return Opening(**r["opening"])


@check("Every field is present and a finite number")
def _():
    keys = set(FIX["fields"])
    return each(ALL_V2, lambda r: set(r["fields"]) == keys and all(math.isfinite(v) for v in r["fields"].values()), "missing or non-finite")


@check("Balance sheets balance")
def _():
    def ok(r):
        f = r["fields"]
        assets = f["bs_cash"] + f["bs_ar"] + f["bs_inventory"] + f["bs_prepaid"] + f["bs_ppe_gross"] - f["bs_accum_depr"] + f["bs_rou"]
        liab = sum(f[k] for k in ("bs_ap", "bs_wages", "bs_mkt", "bs_warranty_res", "bs_sl_accrual", "bs_excise", "bs_curr_ltd",
                                  "bs_def_rev", "bs_ltd", "bs_lease", "bs_dtl", "bs_bank_overdraft"))
        return close(assets, liab + f["bs_common"] + f["bs_retained"] + f["bs_curr_ni"])
    return each(ALL_V2, ok, "out of balance")


@check("Cash ties: opening cash plus the cash flow statement equals ending cash")
def _():
    def ok(r):
        f, o = r["fields"], _op(r)
        ocf = (f["cf_net_income"] + f["cf_depr"] + f["cf_rou"] + f["cf_lifo"] - f["cf_ar_chg"] - f["cf_inv_chg"] - f["cf_prepaid_chg"]
               + f["cf_ap_chg"] + f["cf_accrued_chg"] + f["cf_warranty_chg"] + f["cf_def_rev_chg"] + f["cf_excise_chg"])
        icf = -f["cf_capex_mfg"] - f["cf_capex_tech"] - f["cf_capex_dc"] + f["cf_disposal"]
        fcf = f["cf_borrow_proc"] - f["cf_borrow_repay"] - f["cf_lease_pmt"] + f["cf_equity"] + f["cf_overdraft_chg"]
        overdraft_ok = not (f["bs_cash"] > TOL and f["bs_bank_overdraft"] > TOL) and close(f["bs_bank_overdraft"], f["n5_overdraft"])
        return close(f["bs_cash"] - o.cash, ocf + icf + fcf) and overdraft_ok and close(f["cf_beg_cash"], o.cash)
    return each(ALL_V2, ok, "cash does not tie")


@check("Inventory on the balance sheet moves by the cash flow inventory line")
def _():
    return each(ALL_V2, lambda r: close(r["fields"]["bs_inventory"] - _op(r).inventory, r["fields"]["cf_inv_chg"]), "inventory movement differs")


@check("Stock rolls forward: units on hand + made - sold = units left")
def _():
    def ok(r):
        m, f = r["intermediates"], r["fields"]
        for k in PRODUCTS:
            sold = sum(f[f"n2_sold_{x}_{k}"] for x in REGIONS)
            if not (m[f"{k}_on_hand"] + m[f"{k}_made"] - sold == f[f"n3_units_{k}"] and sold == m[f"{k}_sold"]):
                return False
        return True
    return each(ALL_V2, ok, "units don't roll forward")


@check("Orders: nobody sells more than was ordered, and orders not filled are never negative")
def _():
    def ok(r):
        f = r["fields"]
        for k in PRODUCTS:
            ordered = sum(f[f"n2_ord_{x}_{k}"] for x in REGIONS)
            sold = sum(f[f"n2_sold_{x}_{k}"] for x in REGIONS)
            if not (0 <= sold <= ordered and f[f"n2_short_{k}"] == ordered - sold >= 0):
                return False
            if any(f[f"n2_sold_{x}_{k}"] > f[f"n2_ord_{x}_{k}"] + 1 for x in REGIONS):
                return False
        return True
    return each(ALL_V2, ok, "orders broken")


@check("Each quarter opens where the last one closed")
def _():
    bad = [label for label, a, b in PAIRS if b["opening"] != Opening.from_fields(a["fields"], 2).as_dict()]
    assert not bad, ", ".join(bad[:6])
    return f"{len(PAIRS)} quarter-to-quarter links"


@check("Equity changes only by net income and dividends")
def _():
    def equity(f):
        return f["bs_common"] + f["bs_retained"] + f["bs_curr_ni"]
    bad = [label for label, a, b in PAIRS
           if not close(equity(b["fields"]) - equity(a["fields"]), b["income_statement"]["net_income"] + b["fields"]["cf_equity"])]
    assert not bad, ", ".join(bad[:6])
    return f"{len(PAIRS)} quarter-to-quarter links"


@check("The stock a quarter starts with enters cost of goods sold")
def _():
    bad, used = [], 0
    for label, a, b in PAIRS:
        f, s_ = b["fields"], b["income_statement"]
        prior_stock = a["fields"]["n3_value_p1"] + a["fields"]["n3_value_p2"]
        cogs = f["is_beg_inv"] + f["is_raw_mat"] + f["is_labor"] + f["is_overhead"] + f["is_excise_cogs"] - f["is_end_inv"] + f["is_lifo_adj"] + f["is_carry"]
        if not (close(f["is_beg_inv"], prior_stock) and close(s_["cost_of_goods_sold"], cogs)):
            bad.append(label)
        used += prior_stock > 0
    assert not bad, ", ".join(bad[:6])
    assert used > 0, "no quarter starts with stock, so the check proves nothing"
    return f"{len(PAIRS)} quarters; {used} start with stock"


@check("Stock is valued first-in, first-out, product by product")
def _():
    """Sells each quarter from the stock it started with first, then from what
    it made, and values what is left. The engine treats the stock a quarter
    starts with as one batch at its average cost; the gap from tracking every
    quarter's batch separately is reported, not tested."""
    n, worst = 0, 0.0
    for chain, rows in CHAINS.items():
        true_layers = None
        for r in rows:
            m, f, o = r["intermediates"], r["fields"], _op(r)
            if true_layers is None:
                true_layers = {k: ([[getattr(o, f"fg_units_{k}"), getattr(o, f"fg_value_{k}") / getattr(o, f"fg_units_{k}")]]
                                   if getattr(o, f"fg_units_{k}") else []) for k in PRODUCTS}
            for k in PRODUCTS:
                units = getattr(o, f"fg_units_{k}")
                for layers in ([[units, getattr(o, f"fg_value_{k}") / units]] if units else [], true_layers[k]):
                    if m[f"{k}_made"] > 0:
                        layers.append([m[f"{k}_made"], m["unit_cost"]])
                    need = m[f"{k}_sold"]
                    while need > 1e-9 and layers:
                        take = min(need, layers[0][0])
                        layers[0][0] -= take
                        need -= take
                        if layers[0][0] <= 1e-9:
                            layers.pop(0)
                    assert abs(sum(u for u, _ in layers) - f[f"n3_units_{k}"]) < 1e-6, f"{chain} Q{r['quarter']} {k}: units"
                    if layers is not true_layers[k]:
                        value = sum(u * c for u, c in layers)
                        assert abs(f[f"n3_value_{k}"] - value) <= 0.011, f"{chain} Q{r['quarter']} {k}: model {f[f'n3_value_{k}']:,.2f}, FIFO {value:,.2f}"
                        n += 1
                worst = max(worst, abs(f[f"n3_value_{k}"] - sum(u * c for u, c in true_layers[k])))
    return f"{n} product-quarters; tracking every batch separately would move a value by at most ${worst:,.2f}"


@check("Unsold stock is sold next quarter, not turned into cash")
def _():
    base = play("careful")
    extra = play("careful", extra_units_q=(4, 50_000))
    b4, e4 = base[3]["fields"], extra[3]["fields"]
    b5, e5 = base[4], extra[4]
    assert e4["n3_units_p1"] - b4["n3_units_p1"] == 50_000, "the extra units were not left in stock"
    cost = e4["is_end_inv"] - b4["is_end_inv"]
    assert cost > 0, "the extra stock has no cost"
    assert close(e5["fields"]["is_beg_inv"] - b5["fields"]["is_beg_inv"], cost), "next quarter's COGS doesn't start from the stock"
    fewer = b5["intermediates"]["p1_made"] - e5["intermediates"]["p1_made"]
    assert fewer == 50_000, f"next quarter's plan should make 50,000 fewer units, made {fewer:,.0f} fewer"
    return f"50,000 extra units cost ${cost:,.0f}; next quarter makes 50,000 fewer and sells them"


@check("Inventory note ties to the balance sheet")
def _():
    def ok(r):
        f = r["fields"]
        return close(f["n3_raw_mat"] + f["n3_wip"] + f["n3_fg"] + f["n3_in_transit"] - f["n3_lifo_res"], f["bs_inventory"])
    return each(ALL_V2, ok, "inventory note differs")


@check("PPE note ties to the balance sheet")
def _():
    def ok(r):
        f = r["fields"]
        net = sum(f[f"n4_{k}_net"] for k in CLASSES)
        acc = sum(f[f"n4_{k}_accum"] for k in CLASSES)
        each_class = all(close(f[f"n4_{k}_net"], f[f"n4_{k}_gross"] - f[f"n4_{k}_accum"], 0.005) for k in CLASSES)
        return each_class and close(net, f["bs_ppe_gross"] - f["bs_accum_depr"], 0.005) and close(acc, f["bs_accum_depr"], 0.005)
    return each(ALL_V2, ok, "PPE note differs")


@check("Goods in transit are counted only under FOB shipping point, with the payable")
def _():
    def ok(r):
        f, notes = r["fields"], r["decisions"]["notes_elections"]
        purchases = f["n6_supplier"]
        if notes["shipping_terms"] == "fob_destination":
            return f["n3_in_transit"] == 0 and close(f["bs_ap"], purchases * 0.30)
        return close(f["bs_ap"], purchases * 0.30 + f["n3_in_transit"])
    detail = each([(l, r) for l, r in ALL_V2 if r["intermediates"]["total_production"] > 0], ok, "in-transit wrong")
    fobd = sum(1 for _, r in ALL_V2 if r["decisions"]["notes_elections"]["shipping_terms"] == "fob_destination")
    assert fobd > 0, "no firm-quarter uses FOB destination"
    return detail


@check("A dividend above the maximum is refused, as the PHP validator refuses it")
def _():
    r = RUNS["careful"][5]
    maximum = r["intermediates"]["maximum_dividend"]
    try:
        calculate(2, r["quarter"], r["decisions"], P, _op(r), maximum + 0.01, r["market"])
    except DividendTooLarge:
        return f"Q6 maximum ${maximum:,.2f}; one cent more is refused"
    raise AssertionError("an oversized dividend was accepted")


@check("ED17: the dividend limit is known when the quarter opens, and the close cuts what it can't fund")
def _():
    from cfmodel.course import opening_dividend_limit
    cut = 0
    for team, rows in RUNS.items():
        for r in rows:
            if r["quarter"] < 4:
                continue
            limit = opening_dividend_limit(_op(r), P)
            assert r["dividend_declared"] <= limit + 0.005, f"{team} Q{r['quarter']} declared above the opening limit"
            assert close(r["dividend_paid"], min(r["dividend_declared"], r["intermediates"]["maximum_dividend"])), f"{team} Q{r['quarter']} paid wrong"
            assert close(r["intermediates"]["dividend"], r["dividend_paid"]), f"{team} Q{r['quarter']} engine paid a different amount"
            cut += r["dividend_paid"] < r["dividend_declared"] - 0.005
    assert cut > 0, "no quarter shows the cut at the close"
    return f"{cut} firm-quarter(s) cut at the close"


@check("Postponed production and the other unused inputs change nothing")
def _():
    r = RUNS["careful"][6]
    dec = json.loads(json.dumps(r["decisions"]))
    dec["production"].update(postponed_flag="YES", postponed_pct=1.0)
    dec["forecasting"]["forecast_confidence"] = "low"
    dec["pricing"]["discount_policy"] = "aggressive"
    dec["marketing"].update(marketing_alloc_champions=1.0, marketing_alloc_growth=0, marketing_alloc_at_risk=0, marketing_alloc_other=0)
    dec["certification"] = {"attestation_scope": "not_attested"}
    dec["notes_elections"]["carry_cost_treat"] = "Product Cost (Capitalize)"
    got = calculate(2, r["quarter"], dec, P, _op(r), r.get("dividend_paid", r["dividend_declared"]), r["market"])
    assert got["fields"] == r["fields"], "an unused input changed a result"
    clean = each(ALL_V2, lambda x: all(k not in x["decisions"].get(c, {}) for c, ks in V2_UNUSED_INPUTS.items() for k in ks)
                 and "certification" not in x["decisions"], "fixture decisions carry an unused input")
    return f"postponed production, segment shares, confidence, discount, attestation and internal terms ignored; {clean} clean"


@check("Depreciation stops at book value")
def _():
    r = FIX["edge_cases"]["book_value_cap"]
    o, f = _op(r), r["fields"]
    assert close(f["n4_tech_depr"], o.ppe_tech - o.accum_tech), f"charged {f['n4_tech_depr']}, book value {o.ppe_tech - o.accum_tech}"
    assert all(f[f"n4_{k}_gross"] - f[f"n4_{k}_accum"] >= -0.005 for k in CLASSES)
    return f"technology: ${f['n4_tech_depr']:,.2f} charged, the book value left"


# --- version 2: the market ----------------------------------------------------

@check("Warm-up sells everything it makes")
def _():
    rows = [(f"{t} Q{r['quarter']}", r) for t, rr in RUNS.items() for r in rr if r["quarter"] <= 3]
    return each(rows, lambda r: r["fields"]["n2_short_p1"] == r["fields"]["n2_short_p2"] == r["fields"]["n3_units_p1"] == r["fields"]["n3_units_p2"] == 0, "warm-up left orders or stock")


@check("Every team starts Quarter 4 from the same warm-up")
def _():
    first = RUNS["careful"]
    for t, rows in RUNS.items():
        for a, b in zip(first[:3], rows[:3]):
            assert a["fields"] == b["fields"], f"{t} Q{a['quarter']} differs"


@check("The market follows the season table from Quarter 1, without compounding")
def _():
    sd = P["demand_growth_standard_deviation"]
    path = market_path(2, SCEN["q0_forecast"], COURSE["seed"], COURSE["warmup_firm_id"], 11, P)
    for q in range(2, 12):
        for k in DEMAND_FIELDS:
            g = warmup_growth(COURSE["seed"], COURSE["warmup_firm_id"], q, k, sd) if q <= 3 else class_growth(COURSE["seed"], q, k, sd)
            want = round(path[q - 1][k] / season(P, q - 1) * g * season(P, q))
            assert abs(path[q][k] - want) <= 1, f"Q{q} {k}"
    level3 = sum(path[3].values()) / season(P, 3)
    level0 = sum(SCEN["q0_forecast"].values())
    assert level3 > level0, "the Quarter 3 market should sit above the Quarter 0 level after two quarters of growth"
    return f"Quarter 3 market {sum(path[3].values()):,} units against {level0:,} at Quarter 0; peak {max(sum(v.values()) for v in path.values()):,}"


@check("From Quarter 4 every firm's market moves with the same draws")
def _():
    sd = P["demand_growth_standard_deviation"]
    a = market_path(2, SCEN["q0_forecast"], COURSE["seed"], COURSE["warmup_firm_id"], 11, P)
    b = market_path(2, SCEN["q0_forecast"], COURSE["seed"], COURSE["second_firm_id"], 11, P)
    assert any(a[q] != b[q] for q in (1, 2, 3)), "warm-up markets should differ between firms"
    for path in (a, b):
        for q in range(4, 12):
            for k in DEMAND_FIELDS:
                want = round(path[q - 1][k] / season(P, q - 1) * class_growth(COURSE["seed"], q, k, sd) * season(P, q))
                assert abs(path[q][k] - want) <= 1, f"Q{q} {k}"
    spread = [a[11][k] / a[3][k] / (b[11][k] / b[3][k]) for k in DEMAND_FIELDS]
    assert max(abs(s - 1) for s in spread) < 1e-3, f"growth from Q3 to Q11 differs between firms: {spread}"
    return "warm-up luck differs by firm; Quarters 4-11 growth is identical"


@check("Marketing at the default 5% leaves demand unchanged")
def _():
    assert marketing_lift(0.05) == 1.0
    rows = [(l, r) for l, r in ALL_V2 if r["decisions"]["marketing"]["marketing_budget_pct"] == 0.05]
    return each(rows, lambda r: r["fields"]["n2_mkt_lift"] == 1.0, "lift not 1 at 5%")


@check("Marketing lifts demand with diminishing returns")
def _():
    m = [marketing_lift(b / 100) for b in range(0, 31)]
    assert all(x < y for x, y in zip(m, m[1:])), "lift must rise with the budget"
    gains = [y - x for x, y in zip(m, m[1:])]
    assert all(x > y for x, y in zip(gains, gains[1:])), "each extra point must add less"
    return f"0% {m[0]:.4f}, 10% {m[10]:.4f}, 20% {m[20]:.4f}"


# --- version 2: the methods ---------------------------------------------------

@check("Declining balance charges less each quarter")
def _():
    rows = [r for r in RUNS["careless"] if r["decisions"]["notes_elections"]["depreciation_method"] == "double_declining_balance"]
    assert len(rows) >= 3
    for k in CLASSES:
        d = [r["fields"][f"n4_{k}_depr"] for r in rows]
        assert all(x > y for x, y in zip(d, d[1:])), f"{k}: {d}"
    sl = RUNS["careful"][5]["fields"]["n4_mfg_depr"]
    first = rows[0]["fields"]["n4_mfg_depr"]
    assert first > sl, "the first declining-balance charge should exceed straight-line"
    return f"plant: ${first:,.0f} in Q6 down to ${rows[-1]['fields']['n4_mfg_depr']:,.0f} in Q11 (straight-line ${sl:,.0f})"


@check("Units of production follows the plant's output")
def _():
    rows = [r for r in RUNS["average"] if r["decisions"]["notes_elections"]["depreciation_method"] == "units_of_production"]
    assert rows
    for r in rows:
        o = _op(r)
        want = o.ppe_mfg / 10 / 4 * r["intermediates"]["total_production"] / P["capacity_per_shift"]
        assert close(r["fields"]["n4_mfg_depr"], want), f"Q{r['quarter']}"
    d = {r["fields"]["n4_mfg_depr"] for r in rows}
    assert len(d) == len(rows), "the charge should change as output changes"


@check("Every accounting choice and variant changes the statements")
def _():
    careful = {r["quarter"]: r["fields"] for r in RUNS["careful"]}
    same = [name for name, rows in FIX["variants"].items() if all(r["fields"] == careful[r["quarter"]] for r in rows)]
    assert not same, f"variants with no effect: {same}"
    return f"{len(FIX['variants'])} variants"


@check("Inventory is first-in, first-out whatever method is submitted (ED12)")
def _():
    r = RUNS["careless"][5]
    for method in ("lifo", "weighted_average"):
        dec = json.loads(json.dumps(r["decisions"]))
        dec["notes_elections"]["inventory_method"] = method
        got = calculate(2, r["quarter"], dec, P, _op(r), r.get("dividend_paid", r["dividend_declared"]), r["market"])
        assert got["fields"] == r["fields"], f"{method} changed a result"
    return each(ALL_V2, lambda x: x["fields"]["n3_lifo_res"] == 0 and x["fields"]["is_lifo_adj"] == 0, "a LIFO reserve appeared")


@check("Goods in transit follow each supplier's lead time (ED13)")
def _():
    def want(r):
        if r["decisions"]["notes_elections"]["shipping_terms"] == "fob_destination":
            return 0.0
        src, made = r["decisions"]["sourcing"], r["intermediates"]["total_production"]
        sec = src["secondary_supplier"]
        share = 1.0 if sec == "NONE" else src["primary_alloc"]
        sup = lambda code, attr: P[f"supplier_{code.lower()}_{attr}"]
        value = made * share * sup(src["primary_supplier"], "cost") * sup(src["primary_supplier"], "lead_days") / 90
        if sec != "NONE":
            value += made * (1 - share) * sup(sec, "cost") * sup(sec, "lead_days") / 90
        return value
    detail = each(ALL_V2, lambda r: close(r["fields"]["n3_in_transit"], want(r)), "in-transit differs from lead time")
    usa = FIX["variants"]["usa_supplier"]
    assert all(r["fields"]["n3_in_transit"] == 0 for r in usa[1:]), "a supplier with no lead time leaves nothing in transit"
    china = RUNS["average"][2]["fields"]
    return f"{detail}; China, 45 days: ${china['n3_in_transit'] / 1e6:,.1f}M in transit in Quarter 3"


@check("Warranty claims follow defects that get past inspection, and the reserve never goes below zero (ED14)")
def _():
    def ok(r):
        d, m, f = r["decisions"], r["intermediates"], r["fields"]
        catch = P["inspection_" + d["quality"]["inspection_level"].lower() + "_catch_rate"]
        defect = P["supplier_" + d["sourcing"]["primary_supplier"].lower() + "_defect_rate"]
        escaped = php_round(m["total_units_sold"] * defect * (1 - catch))
        claims = escaped * (1 - P["returns_rate_on_defects"]) * P["warranty_cost_per_claim"]
        return close(f["n6_war_paid"], claims) and f["bs_warranty_res"] >= 0
    detail = each(ALL_V2, ok, "warranty claims wrong")
    topped = [r["quarter"] for r in RUNS["careless"] if r["fields"]["n6_war_exp"] > r["income_statement"]["net_revenue"] * 0.004 + 0.01]
    assert topped, "skipping inspection should push claims past the estimate"
    return f"{detail}; the careless team, without inspection, tops up its reserve in Quarters {', '.join(map(str, topped))}"


# --- version 2: the lesson ----------------------------------------------------

@check("The careful team creates the most value in every quarter")
def _():
    lines = []
    for q in range(4, 12):
        v = {t: value_created(rows, q) for t, rows in RUNS.items()}
        assert v["careful"] > v["average"] and v["careful"] > v["careless"], f"Q{q}: {v}"
        lines.append(q)
    final = {t: value_created(rows, 11) / 1e6 for t, rows in RUNS.items()}
    return "Q11 value created: " + ", ".join(f"{t} ${v:,.1f}M" for t, v in final.items())


@check("Forecast error shows up as orders not filled or unsold stock")
def _():
    short = sum(r["fields"]["n2_short_p1"] + r["fields"]["n2_short_p2"] for r in RUNS["average"][3:])
    stock = RUNS["careless"][-1]["fields"]["n3_units_p1"] + RUNS["careless"][-1]["fields"]["n3_units_p2"]
    assert short > 0 and stock > 0
    return f"average leaves {short:,.0f} orders not filled; careless ends with {stock:,.0f} units in stock"


@check("A firm can report a profit while its cash drains into unsold stock")
def _():
    rows = RUNS["careless"][3:]
    profit = sum(r["income_statement"]["net_income"] for r in rows)
    cash = rows[-1]["fields"]["bs_cash"]
    stock = rows[-1]["fields"]["bs_inventory"]
    assert profit > 0 and cash <= P["minimum_cash_buffer"] + 1 and stock > cash * 10
    return f"careless: profit ${profit / 1e6:,.1f}M over Q4-Q11, cash ${cash / 1e6:,.1f}M, inventory ${stock / 1e6:,.1f}M"


if __name__ == "__main__":
    width = max(len(n) for n, _, _ in results)
    failed = 0
    for name, ok, detail in results:
        failed += not ok
        print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"\n      {detail}" if detail else ""))
    print(f"\n{len(results) - failed} of {len(results)} checks pass")
    sys.exit(1 if failed else 0)
