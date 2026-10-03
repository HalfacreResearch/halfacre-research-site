# PayPal setup (Matthew / PaypalBot)

Checkout is **fail closed** until this file exists with real REST keys. After merge, live will show **checkout not available yet** until you install the secret. That is intended.

Do not commit the filled secret. Do not put Stripe keys anywhere.

## What to provide

| Key | What it is |
| --- | --- |
| `PAYPAL_CLIENT_ID` | Live (or sandbox) REST app client id |
| `PAYPAL_CLIENT_SECRET` | Matching REST secret |
| `PAYPAL_WEBHOOK_ID` | Webhook id for `https://halfacreresearch.tech/paypal-webhook.php` |
| `PAYPAL_ENV` | `live` or `sandbox` — use whatever the keys actually are |
| `PACK_DELIVERY_ENABLED` | `false` until Matthew unparks pack ZIP delivery. Default false. |

These are LIVE REST keys unless `PAYPAL_ENV` is `sandbox`. The JS SDK on `/pay.html` uses the same client id.

## Where to put the file

Load order (first file that exists wins):

1. **Preferred — outside the web root**

   `dirname(public_html) / halfacre-private / paypal.secret.php`

   On this Hostinger account that is next to `public_html`:

   `/home/u263512999/domains/halfacreresearch.tech/halfacre-private/paypal.secret.php`

   Git deploy targets `public_html` only, so this path is not part of a Git pull.

2. **Fallback — web root, denied by Apache**

   `public_html/paypal.secret.php`

   Same pattern as `van-grok.secret.php`. `.htaccess` has:

   `FilesMatch "(secret|store|private)\.(php|json)$"` → `Require all denied`

   `.gitignore` already has `*.secret.php` and an explicit `paypal.secret.php` line.

Copy `paypal.secret.example.php` to one of those paths and fill the keys.

## Webhook

In the PayPal developer dashboard, add:

`https://halfacreresearch.tech/paypal-webhook.php`

Subscribe at least:

- `CHECKOUT.ORDER.APPROVED` (logged only; entitlement waits for capture)
- `PAYMENT.CAPTURE.COMPLETED` (records the purchase)
- `PAYMENT.CAPTURE.REFUNDED`
- `PAYMENT.CAPTURE.REVERSED`

Every event is checked with PayPal’s `verify-webhook-signature` API using `PAYPAL_WEBHOOK_ID`. Other event types are ignored.

## Catalog checkout vs no-code links

`/pay.html?sku=&c=&t=` creates an Orders v2 order **server-side**. The amount is read from `van-products.json` ($4.99 / $29.99 / $149). The browser cannot change the price.

Existing no-code payment links cannot carry the client token:

| Link id | Amount | Recorded SKU |
| --- | --- | --- |
| `PLB-DN2KVZRLCUML` | $149 | `pack-etf-mf` |
| `PLB-NGZRXTQA93RE` | $99 | `pack-bitcoin-macro` |

The webhook stores those captures by **payer email + order/capture id**. If the payload has no button id and no `custom_id` sku, the capture is logged and **no SKU is unlocked** (a bare $149 could be the ETF no-code link or BTCTreasuryBot).

## How a no-code buyer gets the file

Same mail path as `signup.php` (`mail()` / `HALFACRE_MAIL_LOG` in tests):

1. Webhook records the capture against the PayPal payer email.
2. Server emails a signed `/van-download.php?sku=&email=&exp=&sig=` link (24 hours).
3. `van-download.php` still requires a verified capture for that email+sku.
4. Pack ZIP bytes are read from the existing `dl/<token>/PACK.zip` via PHP (`readfile` / stream). `packs/` and `dl/.htaccess` are not changed.
5. If `PACK_DELIVERY_ENABLED` is false, the signed link exists but the download returns the sales-hold message.

Whether Hostinger actually delivers that mail is the same unknown as signup mail. Test with a sandbox capture before telling buyers.

## Downloads

- Client page: `van-download.php?sku=&c=&t=`
- Shown as bought only from the verified capture store (`paypal-captures.store.json`, HTTP-denied by the `*.store.json` rule)
- Research SKUs that have no file on disk return **This download is not ready** — no fake file

## Switch-on checklist

1. Create the PayPal REST app (live or sandbox).
2. Write `paypal.secret.php` at path 1 (preferred).
3. Create the webhook pointing at `/paypal-webhook.php` and paste `PAYPAL_WEBHOOK_ID`.
4. GET `/paypal-status.php` → `available: true` (client id only; no secret).
5. GET `/paypal.secret.php` → **403** (or the file is outside the web root and the URL is 404).
6. Sandbox-pay one $4.99 module, confirm it appears under Purchased, and that a no-token POST to `client-memory.php` with `action=purchase` is 401/403.
7. Leave `PACK_DELIVERY_ENABLED` false until Matthew says pack ZIP sales are unparked.
