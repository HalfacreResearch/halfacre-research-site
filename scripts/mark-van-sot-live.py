#!/usr/bin/env python3
"""Mark Van research SKUs live when Halfacre SoT already has series/fulfillment."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "van-products.json"
FULFILL = ROOT / "van-fulfillment"

MACRO_DIR = "dl/376d2ddcf2b69986b5583f0b7e2d1aad"
ETF_DIR = "dl/929d58db29ff852b574eff9659e53513"

LIVE = {
    "macro-indicators-research": {
        "description": (
            "Ready now. FRED CPI, federal funds, 10-year and 2-year Treasury yields "
            "from the Macro×BTC live slice. Pay $4.99, download the series, power up "
            "the avatar. Not the historical $99 Macro pack NCP."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "macro-btc-fg-fred-live-slice",
            "pack_dir": MACRO_DIR,
            "series": [
                "btc-fred-cpi-inflation",
                "btc-fred-federal-funds-rate",
                "btc-fred-treasury-10-year-yield",
                "btc-fred-treasury-2-year-yield",
            ],
            "avatar_knowledge": (
                "FRED CPI, federal funds rate, 10-year and 2-year Treasury yields — "
                "the macro rates stack BTCTreasuryBot considers."
            ),
        },
    },
    "social-sentiment-research": {
        "description": (
            "Ready now. Bitcoin Fear & Greed daily from the Macro×BTC live slice. "
            "Pay $4.99, download the series, power up the avatar."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "macro-btc-fg-fred-live-slice",
            "pack_dir": MACRO_DIR,
            "series": ["btc-fear-greed-daily"],
            "avatar_knowledge": "Bitcoin Fear & Greed daily sentiment history.",
        },
    },
    "cross-asset-research": {
        "description": (
            "Ready now. FRED U.S. dollar index from the Macro×BTC live slice. "
            "Pay $4.99, download the series, power up the avatar. Gold futures "
            "are not in this slice."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "macro-btc-fg-fred-live-slice",
            "pack_dir": MACRO_DIR,
            "series": ["btc-fred-us-dollar-index"],
            "avatar_knowledge": "FRED U.S. dollar index versus the Bitcoin/macro stack.",
        },
    },
    "market-breadth-research": {
        "description": (
            "Ready now. FRED VIX and S&P 500 from the Macro×BTC live slice. "
            "Pay $4.99, download the series, power up the avatar."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "macro-btc-fg-fred-live-slice",
            "pack_dir": MACRO_DIR,
            "series": ["btc-fred-vix-index", "btc-fred-sp-500-index"],
            "avatar_knowledge": "FRED VIX and S&P 500 index history for market breadth.",
        },
    },
    "capital-flows-research": {
        "description": (
            "Ready now. U.S. spot Bitcoin ETF flow history from the built ETF Flow pack. "
            "Pay $4.99, download the series, power up the avatar. Not the historical $149 ETF NCP."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "etf-flow-v2026-09-09",
            "pack_dir": ETF_DIR,
            "series": ["btc-spot-etf-flow-history"],
            "avatar_knowledge": (
                "United States spot Bitcoin ETF flow history (IBIT / FBTC / GBTC totals)."
            ),
        },
    },
    "market-liquidity-research": {
        "description": (
            "Ready now. iShares Bitcoin Trust (IBIT) daily volume from the built ETF Flow pack. "
            "Pay $4.99, download the series, power up the avatar."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "etf-flow-v2026-09-09",
            "pack_dir": ETF_DIR,
            "series": ["btc-ibit-daily-volume-history"],
            "avatar_knowledge": "IBIT daily close, share volume, and trading value.",
        },
    },
    "public-positioning-research": {
        "description": (
            "Ready now. Spot Bitcoin ETF holdings snapshot from the built ETF Flow pack. "
            "Pay $4.99, download the series, power up the avatar."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "etf-flow-v2026-09-09",
            "pack_dir": ETF_DIR,
            "series": ["btc-spot-etf-holdings-snapshot"],
            "avatar_knowledge": "Spot Bitcoin ETF holdings snapshot (BTC, USD, supply percent).",
        },
    },
    "rules-restrictions-research": {
        "description": (
            "Ready now. Bitcoin ETF SEC filing snapshot from the built ETF Flow pack. "
            "Pay $4.99, download the series, power up the avatar."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "etf-flow-v2026-09-09",
            "pack_dir": ETF_DIR,
            "series": ["btc-sec-ibit-filing-history"],
            "avatar_knowledge": "Bitcoin ETF SEC filing snapshot (issuer, form, latest filing).",
        },
    },
    "pack-bitcoin-macro": {
        "description": (
            "Ready now. $29.99 theme pack of the Macro×BTC live slice already built "
            "(Fear & Greed + FRED CPI, fed funds, Treasuries, VIX, dollar, S&P 500). "
            "À la carte of those series at $4.99 is more. Not the historical $99 Macro NCP. "
            "BTC/USD candles and gold futures are not in this slice."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "macro-btc-fg-fred-live-slice",
            "pack_dir": MACRO_DIR,
            "series": [
                "btc-fear-greed-daily",
                "btc-fred-cpi-inflation",
                "btc-fred-federal-funds-rate",
                "btc-fred-treasury-10-year-yield",
                "btc-fred-treasury-2-year-yield",
                "btc-fred-vix-index",
                "btc-fred-us-dollar-index",
                "btc-fred-sp-500-index",
            ],
            "avatar_knowledge": (
                "Full Macro×BTC live slice: Fear & Greed plus FRED CPI, fed funds, "
                "Treasuries, VIX, dollar index, and S&P 500."
            ),
        },
    },
    "pack-etf-mf": {
        "name": "BTC spot ETF flow pack",
        "avatar_upgrade_label": "BTC spot ETF flow pack",
        "description": (
            "Ready now. $29.99 theme pack of the built BTC spot ETF Flow SoT "
            "(flow history, IBIT volume, holdings snapshot, SEC filing snapshot). "
            "Not the historical $149 ETF NCP. Mutual-fund names stay Coming soon / Not ready."
        ),
        "fulfillment": {
            "kind": "download",
            "pack_id": "etf-flow-v2026-09-09",
            "pack_dir": ETF_DIR,
            "series": [
                "btc-spot-etf-flow-history",
                "btc-ibit-daily-volume-history",
                "btc-spot-etf-holdings-snapshot",
                "btc-sec-ibit-filing-history",
            ],
            "avatar_knowledge": (
                "BTC spot ETF flow pack: flows, IBIT volume, holdings, and SEC filings."
            ),
        },
    },
}


def main() -> None:
    data = json.loads(CATALOG.read_text())
    FULFILL.mkdir(exist_ok=True)
    live_ids = set(LIVE)
    live_ids.add("btc-treasury-bot")

    for row in data.get("modules", []):
        sku = row.get("id")
        if sku in LIVE:
            spec = LIVE[sku]
            row["status"] = "live"
            row["live"] = True
            row["description"] = spec["description"]
            if spec.get("name"):
                row["name"] = spec["name"]
            if spec.get("avatar_upgrade_label"):
                row["avatar_upgrade_label"] = spec["avatar_upgrade_label"]
            fulfill = dict(spec["fulfillment"])
            fulfill["download_href"] = f"van-download.php?sku={sku}"
            fulfill["receipt_href"] = f"van-fulfillment/{sku}.json"
            row["fulfillment"] = fulfill
            receipt = {
                "user_scoped": True,
                "sku": sku,
                "name": row.get("name"),
                "price_usd": row.get("price_usd"),
                "status": "live",
                "fulfillment": fulfill,
                "note": (
                    "Downloadable after PayPal unlock. The unlock list is source of "
                    "truth for what the avatar knows. Re-fetch anytime."
                ),
            }
            (FULFILL / f"{sku}.json").write_text(
                json.dumps(receipt, indent=2) + "\n", encoding="utf-8"
            )
        elif sku == "btc-treasury-bot":
            row["status"] = "live"
            row["live"] = True
            row["fulfillment"] = {
                "kind": "working_ui",
                "ui_href": "#btctreasury",
                "download_href": "",
                "avatar_knowledge": (
                    "BTCTreasuryBot (formerly Codex): working autotrades / sFOX UI "
                    "for BTC treasury / bigger-gains crypto."
                ),
            }
        elif row.get("kind") == "bot":
            row["status"] = "coming_soon"
            row["live"] = False
            row["description"] = (
                f"Assembled $149 bot. Coming soon until built — not ready to buy yet."
            )
        elif row.get("kind") in ("research", "pack") and not row.get("live"):
            if "Coming soon" not in str(row.get("description", "")):
                row["description"] = (
                    str(row.get("description", "")).rstrip(".")
                    + " Coming soon / Not ready — data or fulfillment is not built yet."
                )

    modules = data.get("modules", [])
    live_count = sum(1 for row in modules if row.get("live"))
    coming = len(modules) - live_count
    catalog = data.setdefault("catalog", {})
    catalog["live_count"] = live_count
    catalog["coming_soon_count"] = coming
    catalog["live_research_from_sot"] = sorted(LIVE)
    catalog["tax_usd"] = 199
    catalog["note"] = (
        "Matthew 2026-09-23 ~06:12–06:15 CT: research modules that exist in SoT "
        "are clickable — Van pays, downloads, and powers up his avatar. Unlocks "
        "persist on userId charlie-van-halfacre. Non-ready research is Coming soon / "
        "Not ready, not vision-only fluff. Only BTCTreasuryBot is live among the 18 "
        "assembled bots. Prices: research $4.99, packs $29.99, bots $149, tax "
        "immediate-outcome $199. PayPal only. No Stripe."
    )
    account = data.setdefault("account", {})
    account["userId"] = "charlie-van-halfacre"
    account["identity"] = {
        "display_name": "Charlie Van Halfacre",
        "email": "cvhalfacre@msn.com",
        "phone": "601-408-8342",
        "hivemind_client_id": "charlie-van-halfacre",
        "pay_identity": "cvhalfacre@msn.com",
    }
    account["owns_all_modules"] = False
    account["starting_owned"] = []
    account["note"] = (
        "Unlocks are user-scoped to charlie-van-halfacre. Starting owned is empty. "
        "PayPal success appends moduleId. Coming-soon items cannot unlock."
    )
    pay = data.setdefault("pay", {})
    pay["allowed_paid_usd"] = [4.99, 29.99, 149, 199]
    pay["tax_usd"] = 199
    pay["mint_note"] = (
        "Mint PayPal NCPs at $4.99 for live research, $29.99 for live theme packs, "
        "and $149 for BTCTreasuryBot. Set success URL to van.html?paid={SKU}. "
        "Do not reuse historical Macro $99 / ETF $149 pack NCP IDs."
    )
    CATALOG.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    print(f"live={live_count} coming={coming} fulfillment={len(LIVE)}")


if __name__ == "__main__":
    main()
