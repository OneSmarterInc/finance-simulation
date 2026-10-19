# Economics package

This folder is the reference for how the Flexee Financial Accounting engine computes a quarter. It holds a Python model of `ManufacturingCFEngine`, the data it reads, golden fixtures, and named checks. The PHP engine is held to it, never the other way round. The design behind engine version 2 is in the project note `CF_V3_Engine_Design_Note.md`, decisions ED1 to ED11, all decided on 17 October 2026.

Nothing here runs in production. Python is only needed to rebuild fixtures and run the checks; the PHP test suite reads the files directly.

## Running it

```
python3 economics/check.py           # 37 named checks; exits non-zero on any failure
python3 economics/build.py           # rebuilds the fixtures
python3 economics/build.py --check   # fails if the fixtures are out of date
php artisan test tests/Feature/Economics
```

Python 3.10 or later, standard library only.

## What's here

| Path | What it is |
|---|---|
| `cfmodel/engine.py` | The engine, versions 1 and 2, one function: `calculate(version, quarter, decisions, parameters, opening, dividend, market)` |
| `cfmodel/market.py` | The warm-up demand process (DeterministicDemandRandomizer) and its version 2 continuation |
| `cfmodel/rules.py` | Sim rules for areas a team doesn't control (CfManufacturingSimRulesService) |
| `cfmodel/teams.py` | The three reference teams and the reference course's area schedule |
| `cfmodel/course.py` | Plays one firm through Quarters 1 to 11 |
| `data/scenario.json` | Parameters, Quarter 0 forecast and fixed warm-up elections, exported from `CfManufacturingScenarioDefinition` |
| `data/reference_course.json` | Seed and warm-up firm for the fixtures (demo course P8, firm P8-A) |
| `fixtures/v1_stored_results.json` | Real results stored by the PHP engine: three firms, eleven firm-quarters |
| `fixtures/v2_reference.json` | Golden fixtures for version 2, built by `build.py`. Never edit by hand |
| `fixtures/rounding_cases.json` | 2,200 money values and how they round to the cent, 200 of them values PHP 8.3 rounds differently |

## How we know the model is right

Version 1 of the model reproduces every value the PHP engine stored, all 129 fields and the income statement for each of eleven firm-quarters (1,529 values), with no difference. It also reproduces the stored warm-up market and the sim rules' warm-up decisions. The PHP test `EconomicsPackageTest` runs the same stored results through today's PHP engine, so a change to version 1 on either side fails a test. That matters because running courses keep version 1 to the end (ED11). The same test checks that `data/scenario.json` still matches the scenario definition, and that PHP rounds money exactly as the model does. That last one needs PHP 8.4: earlier versions round some values a cent differently. One limit: every stored result uses the default accounting methods, so version 1's LIFO, declining balance, FOB destination and postponed production are matched to the PHP code by reading it, not by stored values.

## What version 2 changes

Version 2 is for new courses only. It changes the engine in nine places, each decided in the design note or the open-items register (ED12 to ED16):

1. **Demand comes from the market (ED1 b, ED2 b).** Quarters 1 to 3 are the warm-up market, unchanged. From Quarter 4 each firm's market keeps its size but grows with draws shared by the whole course (`cf-market-v2|seed|quarter|field`), with the season re-based so it doesn't compound. Orders are the market at the firm's price (elasticity −1.2) times the marketing lift. The forecast is the team's estimate; while production is closed, the plant makes the forecast less the units already in stock. A closed forecast after Quarter 3 is last quarter's orders adjusted for the season.
2. **Unsold stock carries over (ED4).** Units left at quarter end are sold first next quarter. The stock a quarter starts with enters cost of goods sold (`is_beg_inv`), and ending stock is valued first-in, first-out, product by product (`n3_value_p1`, `n3_value_p2`). The stock a quarter starts with is treated as one batch at its average cost, so the engine never has to keep more than one old batch; tracking every batch separately would move a value in the fixtures by at most $9,568.20. Inventory is always first-in, first-out in version 2, whatever method is submitted (ED12).
3. **Depreciation methods work (ED5).** Double-declining balance applies twice the straight-line rate to book value, so the charge falls every quarter. Units of production charges the plant in proportion to output, one straight-line quarter for every 250,000 units made (a full shift). Each class's charge is rounded to the cent, and no class is depreciated below its book value.
4. **Goods in transit are counted (ED6, ED13).** Under FOB shipping point they're in inventory, with the payable to the supplier. FOB destination leaves them with the supplier. How much is on the way follows the supplier's lead time: a quarter's purchases times lead days divided by 90, so a 45-day supplier has half a quarter's purchases in transit and a USA supplier none.
5. **The PPE note reconciles (ED7).** Accumulated depreciation is carried by class (`n4_*_accum`), net is cost less accumulated, and the note totals the balance sheet.
6. **Marketing lifts demand (ED8).** `m(b) = 1 + 0.06 ln((b + 0.01) / 0.06)`, so 5% leaves demand unchanged, 0% costs about 11%, 10% adds about 4% and 20% about 7.5%. The four segment shares are gone.
7. **Warranty follows quality (ED14).** Every defect that gets past inspection comes back, either as a return or as a warranty claim at the scenario's $150 a claim. Under the percentage-of-sales method the expense is an estimate of 0.4% of net revenue, and the reserve carries from quarter to quarter. When claims outrun the reserve, the shortfall is expensed that quarter. Under expense-as-incurred the expense is the claims paid.
8. **The season applies to the market's level from Quarter 1 (ED16)**, so it never compounds and the season table holds from the warm-up on.
9. **Postponed production is ignored (ED10)**, and so are the other inputs with no effect: forecast confidence, discount policy and attestation scope. The decision adapter reads only the six accounting elections, so no other key in the elections can switch on an internal setting such as capitalised carrying cost.

The revenue and inventory notes gain what teams need to forecast (ED3): units ordered and sold by product and region, orders not filled, units left in stock and their value, and this quarter's cost to make a unit. The full list of new fields is `V2_NEW_FIELDS` in `engine.py`.

## The reference course and teams

All three teams share one warm-up and then play Quarters 4 to 11. Forecasting and sourcing open in Quarter 4, pricing in 5, marketing in 6, production in 7, and quality and logistics in 8. Accounting methods and the dividend are the team's from Quarter 4. The teams' rules are at the top of `cfmodel/teams.py`.

| Team | Value created by Q11 | Profit Q4–Q11 | Dividends | Cash at Q11 | Units in stock at Q11 | Orders not filled |
|---|---|---|---|---|---|---|
| Careful | $222.9M | $96.7M | $76.0M | $59.7M | 7,606 | 3,351 |
| Average | $189.7M | $63.5M | $0 | $102.1M | 0 | 282,249 |
| Careless | $144.3M | $18.2M | $51.0M | $1.0M | 318,978 | 765 |

Value created is ending equity plus dividends paid from Quarter 4. Dividends follow ED17 (decided 18 October 2026): a team's limit is the smaller of its opening retained earnings and its opening cash above the minimum buffer, known when the quarter opens, and at the close a dividend the quarter can't fund is cut to what it can. Each team row records `dividend_declared` and `dividend_paid`. Careful leads in every quarter. Average misses the high seasons it doesn't plan for. Careless reports a profit while its cash sits at the $1M floor and up to $72M is tied up in inventory, which is the lesson the course promises. It also stops inspecting in Quarter 8, and its warranty claims run past its estimate from then on.

The fixtures also replay the careful team with each accounting method and several inputs changed (fifteen variants, Quarters 4 to 9): every accounting method except inventory, which is FIFO only, two suppliers, a USA supplier with no lead time, a German supplier without inspection, no marketing, full inspection with air freight, a third shift, a price rise, a quarter with no production, and forecasting left closed until Quarter 6. Two edge cases start from a crafted opening for what no course reaches in eleven quarters: a class at the end of its life, and a bill larger than cash plus the credit line (the bank overdraft).

## The PHP port (engine version 2)

Version 2 lives in `app/Services/Engines/CorporateFinance/`. `ManufacturingCFEngineV2` is the engine, `MarketPath` the market, and `CfManufacturingSimRulesService::deriveCompleteDecisionsV2` and `demandContextV2` the rules. `VersionedManufacturingEngine` runs whichever version a course uses; version 1 (`ManufacturingCFEngine`) is untouched. `tests/Feature/Economics/EngineV2FixtureTest` holds the port to every value in `fixtures/v2_reference.json` (relative 1e-6, absolute 1e-4), and to the fixture market and rules. `EngineV2CourseTest` runs a version 2 course from warm-up through two closes.

A course's version is `courses.engine_version`. Existing courses are version 1. New courses take `config('flexee.engine_version_for_new_courses')` (env `FLEXEE_ENGINE_VERSION`), which is 2 now that the version 2 screens have shipped. The test suite pins 1 in `phpunit.xml`, so the older tests keep covering the courses that still run version 1. A course can't change version once any firm has results, and a quarter refuses to open from a result stored by the other version.

To change version 2: change the Python model, rebuild the fixtures with `build.py`, run `check.py`, then change the PHP to match and run the tests. Expressions in the PHP keep the model's order of operations, because floating-point results depend on it.

## Open items, decided

The five open items found while building were decided on 18 October 2026 (claude/CF_V3_Engine_Open_Items_Register.md):

- ED12: inventory is FIFO only.
- ED13: lead time sets goods in transit.
- ED14: warranty follows quality.
- ED15: delivery speed stays cost-only, because the service-level accrual is the accounting lesson.
- ED16: the season applies to the market's level.

In this engine a second shift costs nothing extra. Only a third shift pays the overtime premium, so busy quarters test whether teams plan for them.
