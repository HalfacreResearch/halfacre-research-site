# Halfacre Research: "Know more. Bank less." platform set (BrandBot, Oct 2, 2026, 11:44 PM CT)

Order: Matthew, Oct 2, 2026, 11:37 PM CT. The slogan is "Know more. Bank less." everywhere: always the full phrase, never "Bank less" alone. "Where you run your own retirement." is retired, and so is the folder ../new-slogan-2026-10-02/ (do not use it).
Source artwork: ../halfacre-logo-slogan-APPROVED.png (586x608) and the clean vector logo trace (work/logo_vec.json). The teal DEPRECATED file was not used.

## Master lockup (vector rebuild of the APPROVED PNG)
- Canvas 586x608, the same as the original. The logo is in the same position.
- Slogan: Montserrat SemiBold (wght 600), 30 px, baseline y=580, "Know more. " in #FFFFFF and "Bank less." in #F7931A. The glyphs are real outlines converted to paths (HarfBuzz shaping with kerning). Horizontal offset 118.75 was found by sub-pixel search against the original.
- **Diff vs the original PNG at 586x608: mean absolute error 0.767/255.** By region: logo 0.682, slogan strip 1.548. 0.94% of pixels differ by more than 32, all of them anti-aliased edges.
  - Side-by-side check: work/sbs_original_vector_diff.png (original | vector | diff x4).
- The SVG uses no <text>, no <image> and no font references.
- The orange in the 2000 px slogan samples as exactly #F7931A, and the white as exactly #FFFFFF.

## Files
| File | Size | Notes |
|---|---|---|
| halfacre-logo-know-more-bank-less.svg | 586x608 viewBox | all paths, black background |
| halfacre-logo-know-more-bank-less-2000.png | 2000x2075 | black background |
| halfacre-logo-know-more-bank-less-transparent-2000.png | 2000x2075 RGBA | **dark backgrounds only.** The black bulb and seams are kept as an opaque underlay. On white, the white parts (HALFACRE's light end, "Know more.", the white dots) disappear. |
| favicon.ico | 16/32/48 | icon-only (bulb mark) on black |
| favicon.svg | 512 viewBox | icon-only on black |
| apple-touch-icon.png | 180x180 | icon-only, opaque black |
| icon-192.png, icon-512.png | 192, 512 | icon-only |
| halfacre-x-header-1500x500.png | 1500x500 | lockup centered; visible art measured at rows 65–434 (spec 60–440) |
| halfacre-facebook-cover-851x315.png | 851x315 | Facebook's official recommended size. Lockup art at x 322–528, y 45–269. |
| halfacre-facebook-cover-1702x630@2x.png | 1702x630 | the same layout at 2x, for sharper display |
| halfacre-youtube-banner-2560x1440.png | 2560x1440 | lockup art x 1102–1458, y 525–914, inside the centered 1546x423 safe area (x 507–2053, y 508–932) |
| halfacre-linkedin-personal-1584x396.png | 1584x396 | lockup centered (x 636–948), clear of the profile photo at the lower left |
| halfacre-linkedin-company-cover-1512x256.png | 1512x256 | LinkedIn's official recommended size. Lockup centered (x 657–854), away from the edges and the lower corners. The slogan is small here (about 8 px cap height). |
| halfacre-profile-square-2000.png | 2000x2000 | full lockup; its bounding-box diagonal fits within 88% of the circle |
| halfacre-icon-only-square-2000.png | 2000x2000 | icon only, circle-safe |
| halfacre-contact-sheet.png | 1880x1970 | all outputs |

The favicons and app icons are icon-only because the slogan can't be read at those sizes. Even in the 2000 px profile square, the slogan is only about 5 px tall when shown at 200 px.

## Platform specs and sources
- **Facebook Page cover:** Facebook Help Center, "Facebook Page profile picture and cover photo dimensions", https://www.facebook.com/help/125379114252045 (read Oct 2, 2026). It says:
  - The cover "loads fastest as an sRGB JPG file that's 851 pixels wide, 315 pixels tall"; minimum 400x150.
  - On computers it "left aligns with a full bleed and a 16:9 aspect ratio"; on mobile it "left aligns with a full bleed and a 2.4:1 aspect ratio".
  - "The profile picture overlaps the cover photo by approximately 40 pixels on mobile devices."
  - Logos and text may look better as PNG.
  - **This differs from the requested 1640x624, so I followed the official 851x315** and added a 2x version. Facebook publishes no explicit safe-area box. I used a conservative one: the overlap of 16:9 and 2.4:1 crops, both left-aligned and centered, gives x 146–560; 40 px top and bottom margins give y 40–275. The lockup sits inside it.
- **LinkedIn Company Page cover:** LinkedIn Help, "Image specifications for your LinkedIn Pages and Career Pages", https://www.linkedin.com/help/linkedin/answer/a563309 (read Oct 2, 2026; page says "Last updated: 1 month ago").
  - Cover image minimum and recommended size: 1512 (w) x 256 (h); PNG/JPEG, 3 MB max.
  - "place key details away from the edges… especially in the lower-right corner."
  - Some third-party sites and an older translated copy still list 4200x700. I followed the current official page.
- **LinkedIn personal banner** 1584x396, **X header** 1500x500 (art inside rows 60–440) and **YouTube** 2560x1440 with a 1546x423 safe area: as specified in the order. I didn't re-verify these against platform help pages.

## Rebuild
work/fit_slogan.py (slogan offset fit), then work/build.py (all outputs). work/logo_vec.json is the traced logo. The contact sheet was made with a small inline script.
