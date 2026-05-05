<?php
/**
 * Proxy IPTV - SportsZone
 * Sirve M3U8 + segmentos .ts usando sesion persistente via cookies
 */

error_reporting(E_ALL ^ E_DEPRECATED);

$ua = "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0";
$cookieJar = sys_get_temp_dir() . "/iptv_proxy_cookies.txt";

// Asegurar que el M3U8 se pueda fetch (establecer sesion si no existe)
function ensureSession($channelUrl, $ua, $cookieJar) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $channelUrl,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_COOKIEJAR      => $cookieJar,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code >= 400 || !$html) return null;

    preg_match('/<iframe[^>]+src="(https?:\/\/[^"]+\/embed\/[^"]+)"/i', $html, $m);
    if (empty($m[1])) return null;

    $embedUrl = $m[1];
    $referer = parse_url($channelUrl, PHP_URL_SCHEME) . "://" . parse_url($channelUrl, PHP_URL_HOST) . "/";

    curl_setopt($ch, CURLOPT_URL, $embedUrl);
    curl_setopt($ch, CURLOPT_REFERER, $referer);
    $embedHtml = curl_exec($ch);
    $embedCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($embedCode >= 400 || !$embedHtml) return null;

    preg_match('/["\x27](https?:\/\/[^"\x27]+\.m3u8[^"\x27]*)["\x27]/', $embedHtml, $m3u8Match);
    if (empty($m3u8Match[1])) return null;

    $m3u8Url = $m3u8Match[1];
    curl_setopt($ch, CURLOPT_URL, $m3u8Url);
    curl_setopt($ch, CURLOPT_REFERER, $embedUrl);
    $m3u8Body = curl_exec($ch);
    $m3u8Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    if ($m3u8Code >= 400 || !$m3u8Body) return null;

    return [
        "body"     => $m3u8Body,
        "cdn_host" => parse_url($finalUrl, PHP_URL_HOST),
        "cdn_path" => dirname(parse_url($finalUrl, PHP_URL_PATH)) . "/",
        "embed"    => $embedUrl,
        "m3u8_url" => $m3u8Url,
    ];
}

// ============================================================
// PROXY DE SEGMENTOS .ts
// ============================================================
$tsFile = $_GET["ts"] ?? "";
if (!empty($tsFile)) {
    $cdnHost  = $_GET["h"] ?? "";
    $cdnPath  = $_GET["p"] ?? "/hls/";
    $channel  = $_GET["c"] ?? "";

    if (!$cdnHost || !$tsFile || !$channel) {
        http_response_code(400);
        die("ERROR: parametros ts/h/c requeridos");
    }

    $channelUrl = urldecode($channel);
    $tsUrl = "https://" . $cdnHost . ":8443" . $cdnPath . $tsFile;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tsUrl,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEFILE     => $cookieJar,  // reuse session cookies
        CURLOPT_COOKIEJAR      => $cookieJar,
    ]);

    // Establecer la sesion antes de fetch el segmento
    ensureSession($channelUrl, $ua, $cookieJar);

    $tsData = curl_exec($ch);
    $tsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tsType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($tsCode >= 400 || !$tsData) {
        http_response_code(502);
        die("ERROR: segmento no accesible (HTTP $tsCode)");
    }

    header("Content-Type: " . ($tsType ?: "video/mp2t"));
    header("Access-Control-Allow-Origin: *");
    header("Cache-Control: public, max-age=30");
    echo $tsData;
    exit;
}

// ============================================================
// PROXY M3U8
// ============================================================
$channelUrl = $_GET["channel"] ?? "";

if (empty($channelUrl) || !str_contains($channelUrl, "sportssonline.click")) {
    http_response_code(400);
    header("Content-Type: text/plain");
    die("ERROR: parametro 'channel' invalido o faltante");
}

$session = ensureSession($channelUrl, $ua, $cookieJar);

if (!$session) {
    http_response_code(502);
    die("ERROR: no se pudo establecer sesion con el CDN");
}

$m3u8Body = $session["body"];
$cdnHost  = $session["cdn_host"];
$cdnPath  = $session["cdn_path"];
$channelEncoded = urlencode($channelUrl);

// Reescribir segmentos para que pasen por el proxy (con sesion persistente)
$lines = explode("\n", $m3u8Body);
foreach ($lines as &$line) {
    $line = rtrim($line);
    if (!empty($line) && $line[0] !== "#" && !str_starts_with($line, "http")) {
        $tsName = basename($line);
        $proxyUrl = "http://74.208.207.247/proxy.php/stream.ts"
                  . "?ts=" . urlencode($tsName)
                  . "&h=" . urlencode($cdnHost)
                  . "&p=" . urlencode($cdnPath)
                  . "&c=" . $channelEncoded;
        $line = $proxyUrl;
    }
}
$m3u8Body = implode("\n", $lines);

header("Content-Type: application/vnd.apple.mpegurl");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");
echo $m3u8Body;
