<?php
/**
 * Proxy IPTV unificado
 * Maneja multiples fuentes:
 *
 *   SportsZone: proxy.php?channel=URL_DEL_CANAL
 *   Power:      proxy.php?power=EVENT_NAME (urlencoded)
 */

error_reporting(E_ALL ^ E_DEPRECATED);

$ua = "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0";

// ===== POWER SOURCE =====
$powerName = $_GET["power"] ?? "";
if (!empty($powerName)) {
    $sourceUrl = "http://addonbg.co/black/power.php";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $sourceUrl,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode >= 400 || !$content) {
        http_response_code(502);
        die("ERROR: no se pudo cargar power.php");
    }

    $lines = explode("\n", trim($content));
    $foundM3u8 = null;
    $foundReferer = null;

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (!str_starts_with($line, "#EXTINF")) continue;

        preg_match('/,(\[.+\]\s*.+)$/', $line, $m);
        if (trim($m[1] ?? "") !== $powerName) continue;

        $next = trim($lines[$i + 1] ?? "");
        if (!str_contains($next, "mainstreams.pro")) continue;

        $parts = explode("|Referer=", $next);
        $foundM3u8 = $parts[0];
        $foundReferer = $parts[1] ?? "";
        break;
    }

    if (!$foundM3u8) {
        http_response_code(502);
        die("ERROR: evento no encontrado");
    }

    curl_setopt_array($ch, [
        CURLOPT_URL     => $foundM3u8,
        CURLOPT_REFERER => $foundReferer,
    ]);
    $m3u8Body = curl_exec($ch);
    $m3u8Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($m3u8Code >= 400 || !$m3u8Body) {
        http_response_code(502);
        die("ERROR: M3U8 no accesible (HTTP $m3u8Code)");
    }

    header("Content-Type: application/vnd.apple.mpegurl");
    header("Access-Control-Allow-Origin: *");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    echo $m3u8Body;
    exit;
}

// ===== SPORTSZONE SOURCE =====
$channelUrl = $_GET["channel"] ?? "";

if (empty($channelUrl) || !str_contains($channelUrl, "sportssonline.click")) {
    http_response_code(400);
    die("ERROR: parametro 'channel' invalido o faltante. Use ?channel= o ?power=");
}

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
    die("ERROR: no se pudo cargar pagina del canal (HTTP $httpCode)");
}

preg_match('/<iframe[^>]+src="([^"]+dynmaspect\.net[^"]+)"/i', $channelHtml, $m);
if (empty($m[1])) {
    http_response_code(502);
    die("ERROR: no se encontro iframe embed");
}

$embedUrl = $m[1];
$referer = parse_url($channelUrl, PHP_URL_SCHEME) . "://" . parse_url($channelUrl, PHP_URL_HOST) . "/";

curl_setopt_array($ch, [
    CURLOPT_URL     => $embedUrl,
    CURLOPT_REFERER => $referer,
]);
$embedHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode >= 400 || !$embedHtml) {
    http_response_code(502);
    die("ERROR: no se pudo cargar pagina embed (HTTP $httpCode)");
}

preg_match('/["\x27](https?:\/\/[^"\x27]+\.m3u8[^"\x27]*)["\x27]/', $embedHtml, $m3u8Match);
if (empty($m3u8Match[1])) {
    http_response_code(502);
    die("ERROR: no se encontro M3U8 en pagina embed");
}

// Fetch M3U8 con browser UA y devolver contenido directo
$m3u8Url = $m3u8Match[1];
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

// Reescribir URLs relativas a absolutas en el M3U8
$basePath = dirname($m3u8Url) . "/";
$lines = explode("\n", $m3u8Body);
foreach ($lines as &$line) {
    $line = rtrim($line);
    // Si no es comentario ni tag y no empieza con http, es un segmento relativo
    if (!empty($line) && $line[0] !== "#" && !str_starts_with($line, "http")) {
        $line = $basePath . $line;
    }
}
$m3u8Body = implode("\n", $lines);

header("Content-Type: application/vnd.apple.mpegurl");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");
echo $m3u8Body;
