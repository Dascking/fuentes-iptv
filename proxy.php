<?php
/**
 * Proxy IPTV - SportsZone
 * Recibe URL del canal PHP y devuelve M3U8 fresco con paths absolutos.
 *
 * Uso: proxy.php?channel=https://v3.sportssonline.click/channels/.../file.php
 */

error_reporting(E_ALL ^ E_DEPRECATED);

$channelUrl = $_GET["channel"] ?? "";

if (empty($channelUrl) || !str_contains($channelUrl, "sportssonline.click")) {
    http_response_code(400);
    header("Content-Type: text/plain");
    die("ERROR: parametro 'channel' invalido o faltante");
}

$ua = "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0";

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
    die("ERROR: canal no accesible (HTTP $httpCode)");
}

// Extraer iframe embed (dominio puede variar: dynmaspect.net, woundsilk.net, etc)
preg_match('/<iframe[^>]+src="(https?:\/\/[^"]+\/embed\/[^"]+)"/i', $channelHtml, $m);
if (empty($m[1])) {
    http_response_code(502);
    die("ERROR: no se encontro iframe embed");
}

$embedUrl = $m[1];

// Referer debe ser el dominio del canal sportsonline
$referer = parse_url($channelUrl, PHP_URL_SCHEME) . "://" . parse_url($channelUrl, PHP_URL_HOST) . "/";

curl_setopt_array($ch, [
    CURLOPT_URL     => $embedUrl,
    CURLOPT_REFERER => $referer,
]);
$embedHtml = curl_exec($ch);
$embedCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($embedCode >= 400 || !$embedHtml) {
    http_response_code(502);
    die("ERROR: embed no accesible (HTTP $embedCode)");
}

// Extraer M3U8 del embed
preg_match('/["\x27](https?:\/\/[^"\x27]+\.m3u8[^"\x27]*)["\x27]/', $embedHtml, $m3u8Match);
if (empty($m3u8Match[1])) {
    http_response_code(502);
    die("ERROR: no se encontro M3U8 en embed");
}

$m3u8Url = $m3u8Match[1];

// Fetch M3U8 (el proxy usa browser UA, sin bloqueos)
curl_setopt_array($ch, [
    CURLOPT_URL     => $m3u8Url,
    CURLOPT_REFERER => $embedUrl,
]);
$m3u8Body = curl_exec($ch);
$m3u8Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($m3u8Code >= 400 || !$m3u8Body) {
    http_response_code(502);
    die("ERROR: M3U8 no accesible (HTTP $m3u8Code)");
}

// Reescribir paths .ts relativos a absolutos
$basePath = dirname($m3u8Url) . "/";
$lines = explode("\n", $m3u8Body);
foreach ($lines as &$line) {
    $line = rtrim($line);
    if (!empty($line) && $line[0] !== "#" && !str_starts_with($line, "http")) {
        $line = $basePath . $line;
    }
}
$m3u8Body = implode("\n", $lines);

header("Content-Type: application/vnd.apple.mpegurl");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");
echo $m3u8Body;
