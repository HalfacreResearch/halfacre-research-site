# Halfacre Research site

Public static website for Halfacre Research.

Copied from the `web/` tree on HalfacreResearch/halfacre-research-data-blob PR #4 (head `8dad486`). That PR was not merged. No datablob SoT data is in this repo.

## View today

Official URL (HTTP 200, our `index.html`):

https://halfacreresearch.github.io/halfacre-research-site/

That path is served from the user Pages repo `HalfacreResearch/HalfacreResearch.github.io` (`main` `/`, subdirectory `halfacre-research-site/`). Same public URL Matthew asked for. Hostinger and datablob SoT were not touched.

Working copy on this repo (also HTTP 200, correct HTML/CSS types):

https://raw.githack.com/HalfacreResearch/halfacre-research-site/main/index.html

## GitHub Pages on this repo

`.github/workflows/pages.yml` is on `main` with `pages: write` and `enablement: true`. Creating the **project** Pages site still returns `403 Resource not accessible by integration` for both `GITHUB_TOKEN` and the cloud-agent token. An account admin still needs one click: **Settings → Pages → Source: GitHub Actions → Save**. After that, pushes to `main` will deploy from this repo too. No CNAME and no DNS change.
