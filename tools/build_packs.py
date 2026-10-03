#!/usr/bin/env python3
"""Build the two Halfacre Research data packs from public sources.

Run: python3 build_packs.py <out_dir>
Writes <out_dir>/macro/ and <out_dir>/etf/ (csv/, json/, MANIFEST.json).
Fail-closed: no interpolation, no forward-fill, no synthetic rows.
Only sources whose terms allow commercial redistribution are used.
"""
import csv, datetime as dt, hashlib, io, json, os, re, sys, time, urllib.request, zipfile

UA = "Halfacre Research data pack builder (matt@halfacreresearch.tech)"
BUILT = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)

def get(url, binary=False):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept-Encoding": "identity"})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                b = r.read()
                return b if binary else b.decode("utf-8")
        except Exception as e:
            if attempt == 3:
                raise
            time.sleep(2 + attempt * 3)

def write_series(base, slug, header, rows, meta):
    os.makedirs(os.path.join(base, "csv"), exist_ok=True)
    os.makedirs(os.path.join(base, "json"), exist_ok=True)
    cpath = os.path.join(base, "csv", slug + ".csv")
    with open(cpath, "w", newline="") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(header)
        w.writerows(rows)
    jpath = os.path.join(base, "json", slug + ".json")
    pts = [dict(zip(header, r)) for r in rows]
    with open(jpath, "w") as f:
        json.dump({"meta": meta, "points": pts}, f, indent=1, ensure_ascii=False)
        f.write("\n")
    dates = [r[0] for r in rows if r and r[0]]
    meta = dict(meta)
    meta.update({"slug": slug, "fields": header, "rows": len(rows),
                 "first_date": min(dates) if dates else None,
                 "last_date": max(dates) if dates else None,
                 "csv": "csv/%s.csv" % slug, "json": "json/%s.json" % slug})
    return meta

# ---------- Federal Reserve Board (public domain) ----------
_fed_cache = {}
def fed_release(rel):
    if rel not in _fed_cache:
        b = get("https://www.federalreserve.gov/datadownload/Output.aspx?rel=%s&filetype=zip" % rel, binary=True)
        z = zipfile.ZipFile(io.BytesIO(b))
        name = [n for n in z.namelist() if n.endswith("_data.xml")][0]
        _fed_cache[rel] = z.read(name).decode("utf-8")
    return _fed_cache[rel]

def fed_series(rel, name):
    s = fed_release(rel)
    i = s.find('SERIES_NAME="%s"' % name)
    if i < 0:
        raise SystemExit("missing Fed series %s" % name)
    j = s.find("</kf:Series>", i)
    out = []
    for tag in re.findall(r"<frb:Obs [^>]*/>", s[i:j]):
        a = dict(re.findall(r'(\w+)="([^"]*)"', tag))
        if a.get("OBS_STATUS") != "A":
            continue  # ND / NA days are gaps, not zeros
        out.append((a["TIME_PERIOD"], a["OBS_VALUE"]))
    out.sort()
    return out

# ---------- BLS CPI-U (public domain) ----------
def bls_cpi_sa():
    txt = get("https://download.bls.gov/pub/time.series/cu/cu.data.1.AllItems")
    vals = {}
    for line in txt.splitlines()[1:]:
        p = [x.strip() for x in line.split("\t")]
        if len(p) < 4 or p[0] != "CUSR0000SA0" or not p[2].startswith("M") or p[2] == "M13":
            continue
        if p[3] in ("", "-"):
            continue
        vals["%s-%s" % (p[1], p[2][1:])] = float(p[3])
    return vals

# ---------- alternative.me Fear & Greed (commercial use allowed with attribution) ----------
def fear_greed():
    d = json.loads(get("https://api.alternative.me/fng/?limit=0&format=json"))
    if d.get("metadata", {}).get("error"):
        raise SystemExit("F&G error")
    rows = {}
    for p in d["data"]:
        day = dt.datetime.fromtimestamp(int(p["timestamp"]), dt.timezone.utc).date().isoformat()
        rows[day] = (day, int(p["value"]), p["value_classification"])
    return [rows[k] for k in sorted(rows)]

def build_macro(base):
    series = []
    FED_CITE = "Board of Governors of the Federal Reserve System, Data Download Program (public domain; please cite the Board)"
    fg = fear_greed()
    series.append(write_series(base, "crypto-fear-greed-index-daily", ["date", "fear_greed_index", "classification"], fg, {
        "title": "Crypto Fear & Greed Index (daily, 0 = extreme fear, 100 = extreme greed)",
        "source": "Alternative.me Crypto Fear & Greed Index", "source_url": "https://alternative.me/crypto/fear-and-greed-index/",
        "license": "Alternative.me allows commercial use with attribution shown next to the data. Attribution: Source: Alternative.me Crypto Fear & Greed Index.",
        "frequency": "daily (UTC dates)"}))
    for slug, name, title in [
        ("us-treasury-10-year-yield-daily", "RIFLGFCY10_N.B", "U.S. Treasury 10-year constant maturity yield, percent"),
        ("us-treasury-2-year-yield-daily", "RIFLGFCY02_N.B", "U.S. Treasury 2-year constant maturity yield, percent")]:
        rows = fed_series("H15", name)
        series.append(write_series(base, slug, ["date", "yield_percent"], rows, {
            "title": title, "source": "Federal Reserve H.15 Selected Interest Rates, series %s" % name,
            "source_url": "https://www.federalreserve.gov/releases/h15/", "license": FED_CITE, "frequency": "business days"}))
    rows = [(d[:7], v) for d, v in fed_series("H15", "RIFSPFF_N.M")]
    series.append(write_series(base, "fed-funds-effective-rate-monthly", ["month", "effective_fed_funds_percent"], rows, {
        "title": "Effective federal funds rate, monthly average, percent",
        "source": "Federal Reserve H.15 Selected Interest Rates, series RIFSPFF_N.M",
        "source_url": "https://www.federalreserve.gov/releases/h15/", "license": FED_CITE, "frequency": "monthly"}))
    rows = fed_series("H10", "JRXWTFB_N.B")
    series.append(write_series(base, "us-dollar-broad-index-daily", ["date", "nominal_broad_dollar_index"], rows, {
        "title": "Nominal broad U.S. dollar index (goods and services), Jan 2006 = 100",
        "source": "Federal Reserve H.10 Foreign Exchange Rates, series JRXWTFB_N.B",
        "source_url": "https://www.federalreserve.gov/releases/h10/", "license": FED_CITE, "frequency": "business days"}))
    cpi = bls_cpi_sa()
    rows = []
    for m in sorted(cpi):
        y, mo = m.split("-")
        prev = "%d-%s" % (int(y) - 1, mo)
        yoy = round((cpi[m] / cpi[prev] - 1) * 100, 4) if prev in cpi else ""
        rows.append((m, cpi[m], yoy))
    series.append(write_series(base, "us-cpi-inflation-monthly", ["month", "cpi_u_index_sa", "inflation_yoy_percent"], rows, {
        "title": "U.S. CPI-U all items, seasonally adjusted index (1982-84 = 100) and year-over-year change, percent",
        "source": "U.S. Bureau of Labor Statistics, series CUSR0000SA0",
        "source_url": "https://www.bls.gov/cpi/", "license": "U.S. government work, public domain (please cite BLS)",
        "frequency": "monthly", "notes": "Months BLS did not publish are absent, not filled. Year-over-year is blank when the month a year earlier is absent."}))
    return series

# ---------- SEC EDGAR (public domain) ----------
ETFS = [("IBIT", 1980994), ("FBTC", 1852317), ("GBTC", 1588489), ("BTC", 2015034), ("BITB", 1763415),
        ("ARKB", 1869699), ("HODL", 1838028), ("BRRR", 1841175), ("BTCO", 1855781), ("EZBC", 1992870),
        ("BTCW", 1850391), ("MSBT", 2103612)]

def build_etf(base):
    filings, facts = [], []
    for ticker, cik in ETFS:
        sub = json.loads(get("https://data.sec.gov/submissions/CIK%010d.json" % cik)); time.sleep(0.2)
        name = sub.get("name", "")
        blocks = [sub["filings"]["recent"]]
        for f in sub["filings"].get("files", []):
            blocks.append(json.loads(get("https://data.sec.gov/submissions/" + f["name"]))); time.sleep(0.2)
        for b in blocks:
            for i in range(len(b["accessionNumber"])):
                acc = b["accessionNumber"][i]
                doc = b["primaryDocument"][i]
                url = "https://www.sec.gov/Archives/edgar/data/%d/%s/%s" % (cik, acc.replace("-", ""), doc) if doc else ""
                filings.append((b["filingDate"][i], ticker, name, "%010d" % cik, b["form"][i], b.get("reportDate", [""] * 99999)[i] or "", acc, url))
        try:
            cf = json.loads(get("https://data.sec.gov/api/xbrl/companyfacts/CIK%010d.json" % cik)); time.sleep(0.2)
        except Exception:
            cf = {"facts": {}}
        best = {}
        for ns, tags in cf.get("facts", {}).items():
            for tag, body in tags.items():
                for unit, obs in body.get("units", {}).items():
                    for o in obs:
                        if o.get("form") not in ("10-Q", "10-K", "10-Q/A", "10-K/A"):
                            continue
                        key = (tag, unit, o.get("start", ""), o["end"])
                        if key not in best or o["filed"] > best[key]["filed"]:
                            best[key] = dict(o, ns=ns, label=body.get("label") or tag)
        for (tag, unit, start, end), o in sorted(best.items(), key=lambda kv: (kv[0][3], kv[0][0])):
            facts.append((end, ticker, "%010d" % cik, o["ns"], tag, o["label"], unit, start, o["val"], o["form"], o.get("fp", ""), o.get("fy", ""), o["filed"], o["accn"]))
    filings.sort(key=lambda r: (r[0], r[1], r[6]))
    facts.sort(key=lambda r: (r[0], r[1], r[4], r[7]))
    LIC = "SEC EDGAR, U.S. government data, public domain"
    series = [
        write_series(base, "spot-bitcoin-etf-sec-filings", ["filing_date", "ticker", "registrant", "cik", "form", "report_date", "accession_number", "document_url"], filings, {
            "title": "SEC filing history for the 12 U.S. spot bitcoin ETFs (every form on EDGAR)",
            "source": "SEC EDGAR submissions API", "source_url": "https://www.sec.gov/edgar/search/", "license": LIC, "frequency": "event"}),
        write_series(base, "spot-bitcoin-etf-reported-financials", ["period_end", "ticker", "cik", "taxonomy", "xbrl_tag", "label", "unit", "period_start", "value", "form", "fiscal_period", "fiscal_year", "filed", "accession_number"], facts, {
            "title": "As-reported financial data from 10-Q and 10-K filings (shares outstanding, net assets, bitcoin held, creations and redemptions, fees, and every other tagged figure)",
            "source": "SEC EDGAR XBRL company facts API", "source_url": "https://www.sec.gov/search-filings/edgar-application-programming-interfaces", "license": LIC,
            "frequency": "quarterly and annual (as filed)",
            "notes": "Values are exactly as tagged by each issuer. When a figure was restated, the most recently filed value is kept and its accession number is shown. Tags differ between issuers."}),
    ]
    return series

def main(out):
    for name, fn in (("macro", build_macro), ("etf", build_etf)):
        base = os.path.join(out, name)
        os.makedirs(base, exist_ok=True)
        series = fn(base)
        files = []
        for root, _, fs in os.walk(base):
            for f in sorted(fs):
                p = os.path.join(root, f); rel = os.path.relpath(p, base)
                if rel == "MANIFEST.json" or not rel.startswith(("csv/", "json/")):
                    continue
                files.append({"path": rel, "bytes": os.path.getsize(p), "sha256": hashlib.sha256(open(p, "rb").read()).hexdigest()})
        json.dump({"built_utc": BUILT.isoformat(), "series": series, "files": sorted(files, key=lambda x: x["path"])},
                  open(os.path.join(base, "MANIFEST.json"), "w"), indent=1)
        print(name, [(s["slug"], s["rows"], s["first_date"], s["last_date"]) for s in series])

if __name__ == "__main__":
    main(sys.argv[1])
