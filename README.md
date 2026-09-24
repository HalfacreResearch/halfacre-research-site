# Halfacre Research site

Public static website for Halfacre Research.

The public homepage is signup at `/` (same page as `/signup.html`). Name, email, and phone. After create, the client lands on `/van.html`, where Grok is the coach. Google sign-in is off.

`van-grok.secret.php` and `clients.store.json` stay on Hostinger only. Do not commit them.

Practice work is on `cursor/van-product-page-70af`. Do not merge to `main` until Matthew approves the pages.

## Run locally

Open the HTML files from this folder, or serve them with any static / PHP host. Live site: https://www.halfacreresearch.tech

## GitHub Pages

Intended official URL: https://halfacreresearch.github.io/halfacre-research-site/

The workflow is on `main` with `pages: write` and `enablement: true`. Creating the Pages site still needs an org admin once: **Settings → Pages → Source: GitHub Actions → Save**. After that, pushes to `main` deploy. No CNAME and no DNS change, so Hostinger email stays as-is.
