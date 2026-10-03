# Client exchange-key store (ops)

Exchange keys (sFOX first) are encrypted at rest with libsodium `secretbox`. The store and master key live **outside** the web root, same pattern as PR #38 `halfacre-private/paypal.secret.php`.

Do not commit a filled key. Do not leave the old plaintext store in `public_html`.

## After merge

Git deploy only updates `public_html`. Create the sibling directory by hand.

On this Hostinger account:

`/home/u263512999/domains/halfacreresearch.tech/halfacre-private/`

### 1. Create the master key

```bash
mkdir -p /home/u263512999/domains/halfacreresearch.tech/halfacre-private
chmod 700 /home/u263512999/domains/halfacreresearch.tech/halfacre-private

php -r 'echo base64_encode(random_bytes(32)), "\n";'
```

Write `/home/u263512999/domains/halfacreresearch.tech/halfacre-private/secrets.key.php` (copy `secrets.key.example.php` and paste the 32-byte base64):

```php
<?php
const HALFACRE_SECRETS_KEY = "PASTE-BASE64-32-BYTES-HERE";
```

```bash
chmod 600 /home/u263512999/domains/halfacreresearch.tech/halfacre-private/secrets.key.php
```

Until this file exists with a valid key, `van-sfox.php` and `client-secrets.php` return **503 not configured**. That is intended.

### 2. Run the one-time migration

From `public_html` (CLI only — a web hit is 403):

```bash
cd /home/u263512999/domains/halfacreresearch.tech/public_html
php scripts/migrate-client-secrets.php
```

It prints **counts only** (clients / keys / paths). It never prints key values.

Expected dest:

`/home/u263512999/domains/halfacreresearch.tech/halfacre-private/client-secrets.store.json`

### 3. Delete the old plaintext store

After the dest file exists and a desk save / Van page load looks right:

```bash
rm -f /home/u263512999/domains/halfacreresearch.tech/public_html/client-secrets.store.json
```

The new code never reads that web-root file. Leaving it is leftover plaintext.

## What changed

- `van-sfox.php` uses the same page token as `client.php` / `client-memory.php`. No token → 401. Wrong or other-client token → 403. Missing or invalid `?c=` → 400 (never Charley).
- `van-sfox.php` was removed from the desk Basic Auth `FilesMatch` so the client page can call it with `?c=&t=`. It decrypts the key on the server and never returns it.
- The desk route stays Basic Auth: `admin.html` + `client-secrets.php`. `admin.html` already sends `c=charley-van-halfacre` explicitly, so no client fallback is required. An empty or unknown `c=` on `client-secrets.php` is 400.

## Checks

- `GET /secrets.key.example.php` — 200 (example only; empty key).
- `GET /secrets.key.php` — 404 (file is not in the web root).
- `GET /van-sfox.php` — 400 (no `c=`).
- `GET /van-sfox.php?c=van` — 401 (no token).
- `GET /client-secrets.store.json` — 403 if a leftover web-root file still exists (`*store.json` deny).
