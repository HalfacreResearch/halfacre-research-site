# Macro Pack — Halfacre Research

Sold as "Macro×BTC Pack". Daily and monthly U.S. macro series plus the Crypto Fear & Greed Index, as clean CSV and JSON files for your own research and models.

Built October 2, 2026 (8:21 PM CT; 2026-10-03 01:21 UTC). This is a one-time snapshot. It does not update automatically.

## What's inside

| File (CSV and JSON) | What it is | Rows | First | Last |
|---|---|---|---|---|
| `crypto-fear-greed-index-daily` | Crypto Fear & Greed Index (daily, 0 = extreme fear, 100 = extreme greed) | 3,163 | 2018-02-01 | 2026-10-03 |
| `us-treasury-10-year-yield-daily` | U.S. Treasury 10-year constant maturity yield, percent | 16,173 | 1962-01-02 | 2026-10-01 |
| `us-treasury-2-year-yield-daily` | U.S. Treasury 2-year constant maturity yield, percent | 12,581 | 1976-06-01 | 2026-10-01 |
| `fed-funds-effective-rate-monthly` | Effective federal funds rate, monthly average, percent | 867 | 1954-07 | 2026-09 |
| `us-dollar-broad-index-daily` | Nominal broad U.S. dollar index (goods and services), Jan 2006 = 100 | 5,198 | 2006-01-02 | 2026-09-25 |
| `us-cpi-inflation-monthly` | U.S. CPI-U all items, seasonally adjusted index (1982-84 = 100) and year-over-year change, percent | 955 | 1947-01 | 2026-08 |

Every series comes as `csv/<file>.csv` and `json/<file>.json`. `MANIFEST.json` lists every file with its size and SHA-256 so you can check the download.

## Notes

- Fear & Greed dates are UTC days. Source: Alternative.me Crypto Fear & Greed Index (https://alternative.me/crypto/fear-and-greed-index/).
- CPI: BLS did not publish an October 2025 value (federal shutdown). That month is absent, not estimated, so year-over-year for October 2026 will also be blank.
- Treasury yields and the dollar index have no rows on market holidays. Nothing is forward-filled or interpolated.

## Not included, and why

- S&P 500 index and VIX: owned by S&P Dow Jones Indices and Cboe; their terms do not allow resale.
- Bitcoin price history and gold prices: we have not yet secured a source whose terms allow resale.
- Anything from FRED directly: the same Federal Reserve and BLS series are pulled from the original public-domain publishers instead.

## Sources and terms

- **crypto-fear-greed-index-daily**: Alternative.me Crypto Fear & Greed Index (https://alternative.me/crypto/fear-and-greed-index/). Terms: Alternative.me allows commercial use with attribution shown next to the data. Attribution: Source: Alternative.me Crypto Fear & Greed Index.
- **us-treasury-10-year-yield-daily**: Federal Reserve H.15 Selected Interest Rates, series RIFLGFCY10_N.B (https://www.federalreserve.gov/releases/h15/). Terms: Board of Governors of the Federal Reserve System, Data Download Program (public domain; please cite the Board)
- **us-treasury-2-year-yield-daily**: Federal Reserve H.15 Selected Interest Rates, series RIFLGFCY02_N.B (https://www.federalreserve.gov/releases/h15/). Terms: Board of Governors of the Federal Reserve System, Data Download Program (public domain; please cite the Board)
- **fed-funds-effective-rate-monthly**: Federal Reserve H.15 Selected Interest Rates, series RIFSPFF_N.M (https://www.federalreserve.gov/releases/h15/). Terms: Board of Governors of the Federal Reserve System, Data Download Program (public domain; please cite the Board)
- **us-dollar-broad-index-daily**: Federal Reserve H.10 Foreign Exchange Rates, series JRXWTFB_N.B (https://www.federalreserve.gov/releases/h10/). Terms: Board of Governors of the Federal Reserve System, Data Download Program (public domain; please cite the Board)
- **us-cpi-inflation-monthly**: U.S. Bureau of Labor Statistics, series CUSR0000SA0 (https://www.bls.gov/cpi/). Terms: U.S. government work, public domain (please cite BLS)
  - Note: Months BLS did not publish are absent, not filled. Year-over-year is blank when the month a year earlier is absent.

## Disclosure

Data is provided "as is," may contain errors, gaps, revisions, or delays, and is not investment, tax, or legal advice. Nothing in this pack is a recommendation to buy, sell, or hold any security or asset. Your license is personal and non-commercial: you may not resell, redistribute, or publish the raw files as a data product. Some underlying series belong to third parties and carry their own terms (listed above). See our full Disclaimer: https://www.halfacreresearch.tech/disclaimer.html

© 2026 Halfacre Research Institute LLC. Compilation and documentation; underlying public-domain government data remains public domain.

Questions or a problem with your download: matt@halfacreresearch.tech
