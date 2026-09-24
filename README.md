# Halfacre Research site

Public static website for Halfacre Research.

The public homepage is signup at `/` (same page as `/signup.html`). Name, email, and phone. After create, the client lands on their own page at `/page.html?c=…`, same layout as Charley’s page (`/van.html`). Grok is the coach. Each account has its own page. Google sign-in is off.

Client pages have a nav and sidebar: **Uploaded** (files already given) and **Purchased** (Avatar upgrades). Those lists persist in `client-memory.store.json` on Hostinger.

`van-grok.secret.php`, `clients.store.json`, `van-unlocks.store.json`, and `client-memory.store.json` stay on Hostinger only. Do not commit them.

Practice work is on `cursor/van-product-page-70af`. Do not merge to `main` until Matthew approves the pages.

## Run locally

Open the HTML files from this folder, or serve them with any static / PHP host. Live site: https://www.halfacreresearch.tech

## GitHub Pages

Intended official URL: https://halfacreresearch.github.io/halfacre-research-site/

The workflow is on `main` with `pages: write` and `enablement: true`. Creating the Pages site still needs an org admin once: **Settings → Pages → Source: GitHub Actions → Save**. After that, pushes to `main` deploy. No CNAME and no DNS change, so Hostinger email stays as-is.
