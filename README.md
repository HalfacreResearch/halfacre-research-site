# Halfacre Research site

Public static website for Halfacre Research.

Copied from the `web/` tree on HalfacreResearch/halfacre-research-data-blob PR #4 (head `8dad486`). That PR was not merged. No datablob SoT data is in this repo.

## View today

Every page is on `main` and returns HTTP 200 here (correct HTML/CSS types):

https://raw.githack.com/HalfacreResearch/halfacre-research-site/main/index.html

## GitHub Pages

Intended official URL: https://halfacreresearch.github.io/halfacre-research-site/

The workflow is on `main` with `pages: write` and `enablement: true`. Creating the Pages site still needs an org admin once: **Settings → Pages → Source: GitHub Actions → Save**. After that, pushes to `main` deploy. No CNAME and no DNS change, so Hostinger email stays as-is.
