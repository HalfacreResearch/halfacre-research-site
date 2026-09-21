# Halfacre Research — BTC Spot ETF Flow Pack

**Freeze date:** 2026-09-09

What you get: machine-readable CSV and JSON for U.S. spot Bitcoin ETF flow, IBIT daily volume, a holdings snapshot, and an IBIT SEC filing snapshot — extracted from the verified Halfacre Research MAIN database dump.

## Cadence honesty

This pack is a **frozen snapshot** as of the freeze date above. It will not update until a live updater ships. Do not expect day-by-day refreshes from this zip alone.

## Series included

- `btc-spot-etf-flow-history` — United States Spot Bitcoin ETF Flow History (682 points; 2024-01-11 → 2026-09-08)
- `btc-ibit-daily-volume-history` — iShares Bitcoin Trust ETF Daily Trading Volume (665 points; 2024-01-11 → 2026-09-04)
- `btc-spot-etf-holdings-snapshot` — Spot Bitcoin ETF Holdings Snapshot (1 points; 2026-08-27 → 2026-08-27)
- `btc-sec-ibit-filing-history` — Bitcoin ETF SEC Filing History (1 points; 2026-08-06 → 2026-08-06)

## What is NOT included

Quality-ledger OPEN series are excluded from this pack (and are not investment products here either):

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
- `csv/` — one CSV per series (date column first)
- `json/` — one JSON per series (`{meta, points}`)

## Audit

Source dump SHA256 (audit label, not a product title): `43836c100ec2f02fb62e49ade4d98e3721b14040995c82342d872494067fe211`

## Disclaimer

**Not investment advice.** Data is provided for research and tooling. Past flows and filings do not predict future performance. You are responsible for how you use this pack.
