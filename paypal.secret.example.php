<?php
/**
 * PayPal REST credentials. Do not commit a filled copy.
 *
 * Install at ONE of these paths (first match wins):
 *   1) dirname(public_html) / halfacre-private / paypal.secret.php
 *      Example: /home/…/domains/halfacreresearch.tech/halfacre-private/paypal.secret.php
 *   2) public_html / paypal.secret.php
 *      Denied by .htaccess FilesMatch "(secret|store|private)\.(php|json)$"
 *
 * These are LIVE REST keys unless PAYPAL_ENV is "sandbox".
 * Leave PACK_DELIVERY_ENABLED false until Matthew unparks pack ZIP delivery.
 */
return [
  "PAYPAL_CLIENT_ID" => "replace-with-paypal-rest-client-id",
  "PAYPAL_CLIENT_SECRET" => "replace-with-paypal-rest-client-secret",
  "PAYPAL_WEBHOOK_ID" => "replace-with-paypal-webhook-id",
  "PAYPAL_ENV" => "live",
  "PACK_DELIVERY_ENABLED" => false
];
