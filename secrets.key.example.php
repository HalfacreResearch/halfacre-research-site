<?php
/**
 * Copy to ../halfacre-private/secrets.key.php on the server (do not commit).
 * HALFACRE_SECRETS_KEY is base64 of 32 random bytes:
 *   php -r 'echo base64_encode(random_bytes(32)), "\n";'
 *
 * Fail closed: an empty or missing key makes van-sfox.php / client-secrets.php
 * return HTTP 503 "not configured".
 */
const HALFACRE_SECRETS_KEY = "";
