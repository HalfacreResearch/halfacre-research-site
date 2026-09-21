# Halfacre Research — Macro + BTC Market Pack

**Freeze date:** 2026-09-09

What you get: machine-readable CSV and JSON for BTC/USD daily market history, Fear & Greed, FRED macro series (CPI, fed funds, Treasuries, VIX, dollar, S&P 500), and gold futures — extracted from the verified Halfacre Research MAIN database dump.

## Cadence honesty

This pack is a **frozen snapshot** as of the freeze date above. It will not update until a live updater ships. Do not expect day-by-day refreshes from this zip alone.

## Series included

- `btc-usd-daily-market` — BTC/USD Daily Market History (4882 points; 2013-04-28 → 2026-09-07)
- `btc-fear-greed-daily` — Bitcoin Fear and Greed History (3139 points; 2018-02-01 → 2026-09-09)
- `btc-fred-cpi-inflation` — US Consumer Price Inflation (209 points; 2009-02-01 → 2026-07-01) — note: Sold CSV/JSON for `btc-fred-cpi-inflation` **excludes** observation `2025-10-01` (dump stored YoY = -100). Documented in DISCLOSURE.md. Dump had 210 points; sold series has 209.
- `btc-fred-federal-funds-rate` — US Federal Funds Rate (210 points; 2009-02-01 → 2026-07-01)
- `btc-fred-treasury-10-year-yield` — US 10-Year Treasury Yield (16344 points; 1962-01-02 → 2026-09-04)
- `btc-fred-treasury-2-year-yield` — US 2-Year Treasury Yield (12752 points; 1976-06-01 → 2026-09-04)
- `btc-fred-vix-index` — CBOE Volatility Index (9401 points; 1990-01-02 → 2026-09-07)
- `btc-fred-us-dollar-index` — US Dollar Index (5393 points; 2006-01-02 → 2026-09-04)
- `btc-fred-sp-500-index` — S&P 500 Index (2616 points; 2016-08-29 → 2026-09-08)
- `btc-gold-price-history` — Gold Futures Price History (6535 points; 2000-08-30 → 2026-09-04)

## CPI disclosure

Sold CSV/JSON for `btc-fred-cpi-inflation` **excludes** observation `2025-10-01` (dump stored YoY = -100). Documented in DISCLOSURE.md. Dump had 210 points; sold series has 209.

See also `DISCLOSURE.md` in this pack for the excluded anomalous row.

## What is NOT included

Quality-ledger OPEN series are excluded:

- Bitcoin Daily Technical Factors
- Global Stablecoin Supply
- Global Money Supply
- STH/LTH Holder Supply
- Supply Age Bands
- Miner Net Position Change
- Lightning Daily Channel/Capacity
- Binance BTCUSD Perp Liquidations

## Files

- `MANIFEST.json` — freeze date, dump audit SHA256, per-series field lists and point counts
- `DISCLOSURE.md` — CPI anomalous row documentation
- `csv/` — one CSV per series (date column first)
- `json/` — one JSON per series (`{meta, points}`)

## Audit

Source dump SHA256 (audit label, not a product title): `43836c100ec2f02fb62e49ade4d98e3721b14040995c82342d872494067fe211`

## Disclaimer

**Not investment advice.** Data is provided for research and tooling. Macro prints and market history do not predict future performance. You are responsible for how you use this pack.
