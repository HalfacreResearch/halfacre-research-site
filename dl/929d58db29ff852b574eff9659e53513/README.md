# ETF Flow Pack — Halfacre Research

Official SEC records for the 12 U.S. spot bitcoin ETFs (IBIT, FBTC, GBTC, BTC, BITB, ARKB, HODL, BRRR, BTCO, EZBC, BTCW, MSBT): every filing on EDGAR, plus every figure the funds tagged in their 10-Q and 10-K reports (net assets, shares outstanding, units held, creations and redemptions, fees).

Built October 2, 2026 (8:21 PM CT; 2026-10-03 01:21 UTC). This is a one-time snapshot. It does not update automatically.

## What's inside

| File (CSV and JSON) | What it is | Rows | First | Last |
|---|---|---|---|---|
| `spot-bitcoin-etf-sec-filings` | SEC filing history for the 12 U.S. spot bitcoin ETFs (every form on EDGAR) | 1,259 | 2013-10-07 | 2026-08-27 |
| `spot-bitcoin-etf-reported-financials` | As-reported financial data from 10-Q and 10-K filings (shares outstanding, net assets, bitcoin held, creations and redemptions, fees, and every other tagged figure) | 7,319 | 2017-12-31 | 2026-08-03 |

Every series comes as `csv/<file>.csv` and `json/<file>.json`. `MANIFEST.json` lists every file with its size and SHA-256 so you can check the download.

## Notes

- Financial values are exactly as each fund tagged them. Tag names differ between issuers; filter by `ticker` and `xbrl_tag`.
- When a figure was restated, the most recently filed value is kept, with its accession number so you can open the source filing.
- `document_url` links straight to each filing on sec.gov.

## Not included, and why

- Daily fund-by-fund flow history. The usual public daily flow tables are copyrighted ("all rights reserved") and we do not have permission to resell them. Flows in this pack are the quarterly and annual creation and redemption figures the funds report to the SEC.
- Daily prices and trading volume: exchange and quote-vendor terms do not allow resale.
- Third-party holdings trackers: terms unknown, so excluded.

## Sources and terms

- **spot-bitcoin-etf-sec-filings**: SEC EDGAR submissions API (https://www.sec.gov/edgar/search/). Terms: SEC EDGAR, U.S. government data, public domain
- **spot-bitcoin-etf-reported-financials**: SEC EDGAR XBRL company facts API (https://www.sec.gov/search-filings/edgar-application-programming-interfaces). Terms: SEC EDGAR, U.S. government data, public domain
  - Note: Values are exactly as tagged by each issuer. When a figure was restated, the most recently filed value is kept and its accession number is shown. Tags differ between issuers.

## Disclosure

Data is provided "as is," may contain errors, gaps, revisions, or delays, and is not investment, tax, or legal advice. Nothing in this pack is a recommendation to buy, sell, or hold any security or asset. Your license is personal and non-commercial: you may not resell, redistribute, or publish the raw files as a data product. Some underlying series belong to third parties and carry their own terms (listed above). See our full Disclaimer: https://www.halfacreresearch.tech/disclaimer.html

© 2026 Halfacre Research Institute LLC. Compilation and documentation; underlying public-domain government data remains public domain.

Questions or a problem with your download: matt@halfacreresearch.tech
