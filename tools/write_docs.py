#!/usr/bin/env python3
"""Write buyer-facing README.md, DISCLOSURE.md and thanks.html for each pack from its MANIFEST.json.
Run from the repo root after build_packs.py output is copied into dl/<token>/."""
import json, os, datetime as dt
from zoneinfo import ZoneInfo
MT='dl/376d2ddcf2b69986b5583f0b7e2d1aad'; ET='dl/929d58db29ff852b574eff9659e53513'
LEGAL = ("Data is provided \"as is,\" may contain errors, gaps, revisions, or delays, and is not investment, tax, or legal advice. "
 "Nothing in this pack is a recommendation to buy, sell, or hold any security or asset. "
 "Your license is personal and non-commercial: you may not resell, redistribute, or publish the raw files as a data product. "
 "Some underlying series belong to third parties and carry their own terms (listed above). See our full Disclaimer: https://www.halfacreresearch.tech/disclaimer.html")
COPY = "© 2026 Halfacre Research Institute LLC. Compilation and documentation; underlying public-domain government data remains public domain."
_b = dt.datetime.fromisoformat(json.load(open('dl/376d2ddcf2b69986b5583f0b7e2d1aad/MANIFEST.json'))['built_utc'])
_ct = _b.astimezone(ZoneInfo('America/Chicago'))
BUILD_DAY = _ct.strftime('%B %-d, %Y')
BUILD = f"Built {BUILD_DAY} ({_ct.strftime('%-I:%M %p')} CT; {_b.astimezone(dt.timezone.utc).strftime('%Y-%m-%d %H:%M')} UTC). This is a one-time snapshot. It does not update automatically."
def table(m):
    out=["| File (CSV and JSON) | What it is | Rows | First | Last |","|---|---|---|---|---|"]
    for s in m['series']:
        out.append(f"| `{s['slug']}` | {s['title']} | {s['rows']:,} | {s['first_date']} | {s['last_date']} |")
    return "\n".join(out)
def sources(m):
    seen=[];out=[]
    for s in m['series']:
        k=(s['source'],s['license'])
        out.append(f"- **{s['slug']}**: {s['source']} ({s['source_url']}). Terms: {s['license']}")
        if s.get('notes'): out.append(f"  - Note: {s['notes']}")
    return "\n".join(out)
packs = {
 MT: dict(name="Macro Pack", sold="Macro×BTC Pack", m=json.load(open(MT+'/MANIFEST.json')),
   intro="Daily and monthly U.S. macro series plus the Crypto Fear & Greed Index, as clean CSV and JSON files for your own research and models.",
   excluded=["S&P 500 index and VIX: owned by S&P Dow Jones Indices and Cboe; their terms do not allow resale.",
             "Bitcoin price history and gold prices: we have not yet secured a source whose terms allow resale.",
             "Anything from FRED directly: the same Federal Reserve and BLS series are pulled from the original public-domain publishers instead."],
   notes=["Fear & Greed dates are UTC days. Source: Alternative.me Crypto Fear & Greed Index (https://alternative.me/crypto/fear-and-greed-index/).",
          "CPI: BLS did not publish an October 2025 value (federal shutdown). That month is absent, not estimated, so year-over-year for October 2026 will also be blank.",
          "Treasury yields and the dollar index have no rows on market holidays. Nothing is forward-filled or interpolated."]),
 ET: dict(name="ETF Flow Pack", sold="ETF Flow Pack", m=json.load(open(ET+'/MANIFEST.json')),
   intro="Official SEC records for the 12 U.S. spot bitcoin ETFs (IBIT, FBTC, GBTC, BTC, BITB, ARKB, HODL, BRRR, BTCO, EZBC, BTCW, MSBT): every filing on EDGAR, plus every figure the funds tagged in their 10-Q and 10-K reports (net assets, shares outstanding, units held, creations and redemptions, fees).",
   excluded=["Daily fund-by-fund flow history. The usual public daily flow tables are copyrighted (\"all rights reserved\") and we do not have permission to resell them. Flows in this pack are the quarterly and annual creation and redemption figures the funds report to the SEC.",
             "Daily prices and trading volume: exchange and quote-vendor terms do not allow resale.",
             "Third-party holdings trackers: terms unknown, so excluded."],
   notes=["Financial values are exactly as each fund tagged them. Tag names differ between issuers; filter by `ticker` and `xbrl_tag`.",
          "When a figure was restated, the most recently filed value is kept, with its accession number so you can open the source filing.",
          "`document_url` links straight to each filing on sec.gov."]),
}
for d,p in packs.items():
    m=p['m']
    readme=f"""# {p['name']} — Halfacre Research

{('Sold as \"' + p['sold'] + '\". ') if p['sold']!=p['name'] else ''}{p['intro']}

{BUILD}

## What's inside

{table(m)}

Every series comes as `csv/<file>.csv` and `json/<file>.json`. `MANIFEST.json` lists every file with its size and SHA-256 so you can check the download.

## Notes

""" + "\n".join("- "+n for n in p['notes']) + """

## Not included, and why

""" + "\n".join("- "+n for n in p['excluded']) + f"""

## Sources and terms

{sources(m)}

## Disclosure

{LEGAL}

{COPY}

Questions or a problem with your download: matt@halfacreresearch.tech
"""
    disc=f"""# {p['name']} — Disclosure

This data pack is a set of data files (CSV and JSON) for your own research. Included series: {', '.join(s['slug'] for s in m['series'])}. Not included: see README. Update status: frozen as of {BUILD_DAY} (one-time snapshot, no automatic updates). Sources and their terms:

{sources(m)}

{LEGAL}

{COPY}
"""
    open(d+'/README.md','w').write(readme); open(d+'/DISCLOSURE.md','w').write(disc)
    tok=d.split('/')[1]
    html=f"""<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex, nofollow"/>
<title>Thank you — {p['sold']} — Halfacre Research</title>
<style>body{{margin:0;font-family:system-ui,sans-serif;background:#0f1115;color:#e8eaed;line-height:1.55}}
.wrap{{max-width:640px;margin:0 auto;padding:48px 20px}}.muted{{color:#9aa0a6}}a{{color:#8ab4f8}}
.btn{{display:inline-block;margin-top:12px;padding:12px 18px;background:#0070ba;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}}
li{{margin:4px 0}}</style></head>
<body><div class="wrap">
<h1>Thank you</h1>
<p>Thanks for buying the {p['sold']}. Your files are ready. Bookmark this page so you can download them again.</p>
<p><a class="btn" href="/dl/pack.php?t={tok}" download>Download your pack (.zip)</a></p>
<p class="muted">{p['intro']}</p>
<p class="muted">{BUILD}</p>
<p class="muted"><a href="./README.md">What's inside (README)</a> · <a href="./DISCLOSURE.md">Disclosure</a></p>
<p class="muted">{'Fear &amp; Greed data source: <a href="https://alternative.me/crypto/fear-and-greed-index/">Alternative.me Crypto Fear &amp; Greed Index</a>. ' if d==MT else ''}Data files for research. Provided as is. Not investment advice. See our <a href="/disclaimer.html">Disclaimer</a>.</p>
<p class="muted">Problem with your download or receipt? Email matt@halfacreresearch.tech with your PayPal receipt.</p>
<p><a href="/">← Halfacre Research</a></p>
</div></body></html>
"""
    html=html.replace('Fear & Greed Index, as','Fear &amp; Greed Index, as')
    open(d+'/thanks.html','w').write(html)
