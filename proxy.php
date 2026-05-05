<?php
/**
 * Proxy IPTV
 *
 * SportsZone: proxy.php?channel=URL_DEL_CANAL
 * StreamTP:   proxy.php?streamtp=SLUG
 */

error_reporting(E_ALL ^ E_DEPRECATED);

$ua = "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0";

// ===== STREAMTP SOURCE =====
$streamtpSlug = $_GET["streamtp"] ?? "";
if (!empty($streamtpSlug)) {
    $channelUrl = "https://streamtp10.com/global1.php?stream=" . urlencode($streamtpSlug);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $channelUrl,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode >= 400 || !$html) {
        http_response_code(502);
        die("ERROR: streamtp no accesible (HTTP $httpCode)");
    }

    // Extraer nombre del array y la key dinamica
    preg_match('~playbackURL="",(\w+)=\[\]~', $html, $arrMatch);
    $arrName = $arrMatch[1] ?? "";
    if (!$arrName) { http_response_code(502); die("ERROR: no se encontro array"); }

    // Extraer datos del array
    $start = strpos($html, $arrName . "=[[");
    $sortPos = strpos($html, $arrName . ".sort", $start);
    if ($start === false || $sortPos === false) { http_response_code(502); die("ERROR: array no encontrado"); }

    $arrStr = substr($html, $start + strlen($arrName) + 1);
    $lastBracket = strrpos($arrStr, "];");
    $arrData = substr($arrStr, 0, $lastBracket + 1);

    // Extraer k
    preg_match('~var k=(\w+)\(\)\+(\w+)\(\)~', $html, $km);
    $fn1 = $km[1] ?? ""; $fn2 = $km[2] ?? "";
    preg_match('~function ' . $fn1 . '\(\)\{return (\d+)~', $html, $km1);
    preg_match('~function ' . $fn2 . '\(\)\{return (\d+)~', $html, $km2);
    $k = (int)($km1[1] ?? 0) + (int)($km2[1] ?? 0);
    if (!$k) { http_response_code(502); die("ERROR: no se pudo extraer key"); }

    // Parsear y decodificar
    preg_match_all('~\[(\d+),"([^"]+)"\]~', $arrData, $entries, PREG_SET_ORDER);
    usort($entries, function($a, $b) { return $a[1] - $b[1]; });

    $playbackUrl = "";
    foreach ($entries as $e) {
        $decoded = base64_decode($e[2]);
        $digits = preg_replace("/\D/", "", $decoded);
        $playbackUrl .= chr((int)$digits - $k);
    }

    // Fetch M3U8 con redirect
    curl_setopt_array($ch, [
        CURLOPT_URL            => $playbackUrl,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_REFERER        => "https://streamtp10.com/",
    ]);
    $m3u8Body = curl_exec($ch);
    $m3u8Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    if ($m3u8Code >= 400 || !$m3u8Body) {
        http_response_code(502);
        die("ERROR: M3U8 no accesible (HTTP $m3u8Code)");
    }

    // Reescribir paths relativos
    $basePath = dirname($finalUrl) . "/";
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
    exit;
}

// ===== SPORTSZONE SOURCE =====

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
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

if ($m3u8Code >= 400 || !$m3u8Body) {
    http_response_code(502);
    die("ERROR: M3U8 no accesible (HTTP $m3u8Code)");
}

// Reescribir paths .ts relativos a absolutos, incluyendo el token del M3U8
$basePath = dirname($finalUrl) . "/";
$queryString = parse_url($finalUrl, PHP_URL_QUERY);
$queryAppend = $queryString ? "?" . $queryString : "";

$lines = explode("\n", $m3u8Body);
foreach ($lines as &$line) {
    $line = rtrim($line);
    if (!empty($line) && $line[0] !== "#" && !str_starts_with($line, "http")) {
        $line = $basePath . $line . $queryAppend;
    }
}
$m3u8Body = implode("\n", $lines);

header("Content-Type: application/vnd.apple.mpegurl");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate");
echo $m3u8Body;
