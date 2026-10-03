<?php
/**
 * Load PayPal REST config. Include-only.
 * Preferred path is outside the web root; public_html fallback is .htaccess-denied.
 */
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
  $script = basename((string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
  if ($script === "paypal-config.php") {
    http_response_code(403);
    exit;
  }
}

function paypal_secret_path(): string
{
  $override = getenv("HALFACRE_PAYPAL_SECRET");
  if (is_string($override) && $override !== "" && is_file($override)) {
    return $override;
  }
  $outside = dirname(__DIR__) . "/halfacre-private/paypal.secret.php";
  if (is_file($outside)) {
    return $outside;
  }
  $webroot = __DIR__ . "/paypal.secret.php";
  if (is_file($webroot)) {
    return $webroot;
  }
  return "";
}

function paypal_secret_raw(): ?array
{
  $path = paypal_secret_path();
  if ($path === "") {
    return null;
  }
  $data = include $path;
  return is_array($data) ? $data : null;
}

function paypal_env_name(array $raw): string
{
  $env = strtolower(trim((string) ($raw["PAYPAL_ENV"] ?? "")));
  return $env === "sandbox" ? "sandbox" : ($env === "live" ? "live" : "");
}

/**
 * Validated config or null (fail closed).
 *
 * @return array{
 *   client_id:string,
 *   client_secret:string,
 *   webhook_id:string,
 *   env:string,
 *   pack_delivery:bool,
 *   path:string
 * }|null
 */
function paypal_config(): ?array
{
  $raw = paypal_secret_raw();
  if ($raw === null) {
    return null;
  }
  $clientId = trim((string) ($raw["PAYPAL_CLIENT_ID"] ?? ""));
  $secret = trim((string) ($raw["PAYPAL_CLIENT_SECRET"] ?? ""));
  $webhook = trim((string) ($raw["PAYPAL_WEBHOOK_ID"] ?? ""));
  $env = paypal_env_name($raw);
  if ($clientId === "" || $secret === "" || $env === "") {
    return null;
  }
  $pack = $raw["PACK_DELIVERY_ENABLED"] ?? false;
  return [
    "client_id" => $clientId,
    "client_secret" => $secret,
    "webhook_id" => $webhook,
    "env" => $env,
    "pack_delivery" => $pack === true || $pack === 1 || $pack === "1" || $pack === "true",
    "path" => paypal_secret_path()
  ];
}

function paypal_available(): bool
{
  return paypal_config() !== null;
}

function paypal_api_base(?array $cfg = null): string
{
  $cfg = $cfg ?? paypal_config();
  if ($cfg === null || $cfg["env"] === "sandbox") {
    return "https://api-m.sandbox.paypal.com";
  }
  return "https://api-m.paypal.com";
}
