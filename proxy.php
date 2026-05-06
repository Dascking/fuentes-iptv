<?php
/**
 * Proxy IPTV - Power
 * Maneja el formato Kodi: M3U8_URL|header1=val1&header2=val2
 */

error_reporting(E_ALL ^ E_DEPRECATED);

$ua = "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0";

// ===== SEGMENT PROXY =====
$segB64 = $_GET["seg"] ?? "";
if (!empty($segB64)) {
    $segUrl = base64_decode(strtr($segB64, '-_', '+/'));
    if (!$segUrl || !str_starts_with($segUrl, "https://")) {
        http_response_code(400); die("invalid seg");
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $segUrl,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $segData = curl_exec($ch);
    $segCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($segCode >= 400 || !$segData) {
        http_response_code(502); die("seg error");
    }
    
    // Always return as video/mp2t regardless of source content-type
    header("Content-Type: video/mp2t");
    header("Access-Control-Allow-Origin: *");
    header("Cache-Control: public, max-age=30");
    echo $segData;
    exit;
}

// ===== DLSTREAMS SOURCE (via EasyProxy extractor) =====
$dlstreamsId = $_GET["dlstreams"] ?? "";
if (!empty($dlstreamsId)) {
    // Llamar al extractor de EasyProxy en el VPS
    $extractorUrl = "http://127.0.0.1:8080/extractor?url=https://dlstreams.com/watch.php?id=" . intval($dlstreamsId) . "&extractor=daddylivehd";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $extractorUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $extractJson = curl_exec($ch);
    $extractData = json_decode($extractJson, true);
    
    if (!$extractData || empty($extractData["destination_url"])) {
        http_response_code(502);
        die("ERROR: extractor failed");
    }
    
    $destUrl = $extractData["destination_url"];
    $headers = $extractData["request_headers"] ?? [];
    
    // Fetch M3U8 con los headers requeridos
    $httpHeaders = [];
    foreach ($headers as $k => $v) {
        if (!in_array(strtolower($k), ["cookie", "host", "content-length"])) {
            $httpHeaders[] = "$k: $v";
        }
    }
    
    curl_setopt_array($ch, [
        CURLOPT_URL            => $destUrl,
        CURLOPT_USERAGENT      => $headers["User-Agent"] ?? $ua,
        CURLOPT_REFERER        => $headers["Referer"] ?? "",
        CURLOPT_HTTPHEADER     => $httpHeaders,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $m3u8Body = curl_exec($ch);
    $m3u8Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    
    if ($m3u8Code >= 400 || !$m3u8Body) {
        http_response_code(502);
        die("ERROR: stream no accesible (HTTP $m3u8Code)");
    }
    
    // Rewrite relative segments to absolute CDN URLs (sin proxy = sin ancho de banda VPS)
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

// ===== TS SEGMENT PROXY =====
$path = $_SERVER["REQUEST_URI"] ?? $_SERVER["PATH_INFO"] ?? "";
if (preg_match('#/ts/([A-Za-z0-9_-]+)\.ts#', $path, $tm)) {
    $info = json_decode(base64_decode(strtr($tm[1], '-_', '+/')), true);
    if (!$info) { http_response_code(400); die("ERROR: TS info invalida"); }

    $tsUrl = "https://" . $info["host"] . $info["path"] . $info["ts"];
    $isM3u8 = str_ends_with(strtolower($info["ts"]), ".m3u8");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tsUrl,
        CURLOPT_USERAGENT      => $info["ua"] ?? $ua,
        CURLOPT_REFERER        => $info["ref"] ?? "",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $tsData = curl_exec($ch);
    $tsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($tsCode >= 400 || !$tsData) {
        http_response_code(502);
        die("ERROR: TS segment (HTTP $tsCode)");
    }

    // Si es un M3U8 (variant playlist), reescribir sus segmentos tambien
    if ($isM3u8) {
        $cdnHost = $info["host"];
        $cdnPath = dirname($info["path"]) . "/" . basename($info["path"]) . "/";
        $lines = explode("\n", $tsData);
        foreach ($lines as &$line) {
            $line = rtrim($line);
            if (!empty($line) && $line[0] !== "#" && !str_starts_with($line, "http")) {
                $segInfo = base64_encode(json_encode([
                    "ts"   => basename($line),
                    "host" => $cdnHost,
                    "path" => $cdnPath,
                    "ref"  => $info["ref"],
                    "ua"   => $info["ua"],
                ]));
                $line = "http://74.208.207.247/proxy.php/ts/" . rtrim(strtr($segInfo, '+/', '-_'), '=') . ".ts";
            }
        }
        $tsData = implode("\n", $lines);
        header("Content-Type: application/vnd.apple.mpegurl");
    } else {
        header("Content-Type: video/mp2t");
    }

    header("Access-Control-Allow-Origin: *");
    header("Cache-Control: public, max-age=30");
    echo $tsData;
    exit;
}

// ===== POWER SOURCE =====
$powerLink = $_GET["power"] ?? "";

// Also support path format: /proxy.php/power/BASE64.m3u8
if (empty($powerLink)) {
    if (preg_match('#/power/([A-Za-z0-9_-]+)\.m3u8#', $path, $pm)) {
        $powerLink = base64_decode(strtr($pm[1], '-_', '+/'));
    }
}

if (!empty($powerLink)) {
    // El link viene en formato: URL|header1=val1&header2=val2&...
    $link = $powerLink;

    // Parsear URL base y headers
    $parts = explode("|", $link, 2);
    $m3u8Url = $parts[0];
    $m3u8Url = str_replace("&amp;", "&", $m3u8Url);  // fix encoded ampersands
    $headersStr = $parts[1] ?? "";

    // Parsear headers tipo clave=valor
    $headers = [];
    if (!empty($headersStr)) {
        // Reemplazar &amp; por &
        $headersStr = str_replace("&amp;", "&", $headersStr);
        parse_str($headersStr, $headers);
    }

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => $m3u8Url,
        CURLOPT_USERAGENT      => $headers["user-agent"] ?? $ua,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => !(($headers["verifypeer"] ?? "") === "false"),
        CURLOPT_SSL_VERIFYHOST => ($headers["verifyhost"] ?? "") === "false" ? 0 : 2,
    ];

    if (!empty($headers["referer"])) {
        $curlOpts[CURLOPT_REFERER] = $headers["referer"];
    }

    // Headers adicionales
    $httpHeaders = [];
    if (!empty($headers["origin"])) {
        $httpHeaders[] = "Origin: " . $headers["origin"];
    }
    if (!empty($httpHeaders)) {
        $curlOpts[CURLOPT_HTTPHEADER] = $httpHeaders;
    }

    curl_setopt_array($ch, $curlOpts);
    $m3u8Body = curl_exec($ch);
    $m3u8Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    if ($m3u8Code >= 400 || !$m3u8Body) {
        http_response_code(502);
        die("ERROR: M3U8 no accesible (HTTP $m3u8Code)");
    }

    // Reescribir segmentos como URLs absolutas al CDN (no pasan por el proxy = sin carga en VPS)
    $basePath = dirname($finalUrl) . "/";
    $query = parse_url($finalUrl, PHP_URL_QUERY);
    $queryAppend = $query ? "?" . $query : "";

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
    exit;
}

// Sin parametros
http_response_code(400);
die("ERROR: use ?power=URL|headers");
