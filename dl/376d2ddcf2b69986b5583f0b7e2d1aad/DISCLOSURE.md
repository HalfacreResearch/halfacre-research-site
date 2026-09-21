# CPI anomalous observation disclosure

Pack freeze date: 2026-09-09
Series: `btc-fred-cpi-inflation` (US Consumer Price Inflation)

## Excluded from sold CSV/JSON

Observation date `2025-10-01` was present in the verified MAIN dump with `consumerPriceInflationYearOverYearPercent = -100`, which is an impossible year-over-year inflation reading. Per pack policy this row is **omitted** from the sold chart/CSV/JSON series so buyers do not chart a trash spike.

## Raw dump row(s) for that date (as extracted)

```json
[
  {
    "date": "2025-10-01",
    "observedAt": "2025-10-01 00:00:00",
    "consumerPriceInflationYearOverYearPercent": -100
  }
]
```

Sold point_count for this series = dump count (210) minus excluded row(s) (1) = 209.

This is a dump-level bad cell, not one of the eight quality-ledger OPEN series.
