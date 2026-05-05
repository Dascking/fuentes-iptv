<?php
/**
 * Proxy IPTV - SportsZone
 * Recibe URL del canal PHP y obtiene M3U8 fresco.
 *
 * Uso: proxy.php?channel=https://v3.sportssonline.click/channels/.../file.php
 *
 * Cadena:
 * 1. Fetch PHP del canal → extraer iframe embed (dynmaspect.net)
 * 2. Fetch embed con Referer del canal → extraer M3U8
 * 3. 302 redirect al M3U8 fresco
 */

error_reporting(E_ALL ^ E_DEPRECATED);

$channelUrl = $_GET["channel"] ?? "";

if (empty($channelUrl) || !str_contains($channelUrl, "sportssonline.click")) {
    http_response_code(400);
    header("Content-Type: text/plain");
    die("ERROR: parametro 'channel' invalido o faltante");
}

$ua = "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0";

// Paso 1: Fetch pagina del canal, extraer iframe embed
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $channelUrl,
    CURLOPT_USERAGENT      => $ua,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$channelHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode >= 400 || !$channelHtml) {
    http_response_code(502);
    header("Content-Type: text/plain");
    die("ERROR: no se pudo cargar pagina del canal (HTTP $httpCode)");
}

preg_match('/<iframe[^>]+src="([^"]+dynmaspect\.net[^"]+)"/i', $channelHtml, $iframeMatch);
if (empty($iframeMatch[1])) {
    http_response_code(502);
    header("Content-Type: text/plain");
    die("ERROR: no se encontro iframe embed en pagina del canal");
}

$embedUrl = $iframeMatch[1];

// Paso 2: Fetch embed con Referer del canal sportsonline
$referer = parse_url($channelUrl, PHP_URL_SCHEME) . "://" . parse_url($channelUrl, PHP_URL_HOST) . "/";

curl_setopt_array($ch, [
    CURLOPT_URL     => $embedUrl,
    CURLOPT_REFERER => $referer,
]);
$embedHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode >= 400 || !$embedHtml) {
    http_response_code(502);
    header("Content-Type: text/plain");
    die("ERROR: no se pudo cargar pagina embed (HTTP $httpCode)");
}

// Extraer M3U8
preg_match('/["\x27](https?:\/\/[^"\x27]+\.m3u8[^"\x27]*)["\x27]/', $embedHtml, $m3u8Match);
if (empty($m3u8Match[1])) {
    http_response_code(502);
    header("Content-Type: text/plain");
    die("ERROR: no se encontro M3U8 en pagina embed");
}

$m3u8 = $m3u8Match[1];

http_response_code(302);
header("Location: " . $m3u8);
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");
