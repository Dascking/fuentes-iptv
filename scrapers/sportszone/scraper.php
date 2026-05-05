<?php
/**
 * SportsZone Scraper
 * Fuente: http://addonbg.co/loves.php?szone
 * Genera playlist M3U con URLs de proxy en playlists/sportszone.m3u
 *
 * Cadena de extraccion:
 * 1. loves.php?szone → CSV de eventos con links a v3.sportssonline.click
 * 2. sportssonline.click PHP → iframe dynmaspect.net/embed/{ref_id}
 *
 * El proxy.php se encarga del paso 3 on-the-fly (embed → M3U8 fresco)
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

// ===== CONFIGURACION =====
$sourceUrl    = "http://addonbg.co/loves.php?szone";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";   // ← CAMBIAR por tu VPS
$outputFile   = __DIR__ . "/../../playlists/sportszone.m3u";
// =========================

$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

function fetchUrl($url, $referer = "") {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            "Accept: text/html,application/xhtml+xml",
            "Accept-Language: es,en;q=0.9",
        ],
    ]);
    if ($referer) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ($httpCode < 400 && $html) ? $html : null;
}

// --- Paso 1: Obtener lista de eventos ---
echo "[1/2] Obteniendo lista de eventos...\n";
$csv = fetchUrl($sourceUrl);

if (!$csv) {
    die("ERROR: No se pudo obtener la lista de eventos.\n");
}

$lines = explode("\n", trim($csv));
$eventos = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || $line[0] !== "=") continue;

    // Formato: =HH:MM,Event Name,URL,label,domain,slug,
    $parts = explode(",", $line);
    if (count($parts) < 3) continue;

    $time   = trim(ltrim($parts[0], "="));
    $event  = trim($parts[1]);
    $phpUrl = trim($parts[2]);

    if (!filter_var($phpUrl, FILTER_VALIDATE_URL)) continue;

    $eventos[] = [
        "name" => $event,
        "time" => $time,
        "url"  => $phpUrl,
    ];
}

echo "  Eventos encontrados: " . count($eventos) . "\n";

// --- Paso 2: Extraer embed URL de cada evento ---
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: SportsZone\n\n";
$ok = 0;

// Cache de embeds para no repetir (mismo embed para varios eventos)
$embedCache = [];

foreach ($eventos as $i => $evt) {
    $label = "[" . $evt["time"] . "] " . $evt["name"];
    echo "  ($i/" . count($eventos) . ") $label: ";

    // Cache: si ya scrapeamos esta URL, reutilizamos
    if (isset($embedCache[$evt["url"]])) {
        $embedUrl = $embedCache[$evt["url"]];
        echo "cache OK\n";
    } else {
        $channelHtml = fetchUrl($evt["url"]);
        if (!$channelHtml) {
            echo "ERROR (pagina canal)\n";
            continue;
        }

        preg_match('/<iframe[^>]+src="([^"]+dynmaspect\.net[^"]+)"/i', $channelHtml, $m);
        if (empty($m[1])) {
            echo "ERROR (sin iframe)\n";
            continue;
        }

        $embedUrl = $m[1];
        $embedCache[$evt["url"]] = $embedUrl;
        echo "OK\n";
    }

    // URL del proxy con la pagina PHP del canal (el proxy hace el resto on-the-fly)
    $proxyUrl = $proxyBaseUrl . "?channel=" . urlencode($evt["url"]);

    $escapedLabel = str_replace(",", "\ ", $label);
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escapedLabel\" tvg-logo=\"\" group-title=\"SportsZone\",$escapedLabel\n";
    $m3u .= "$proxyUrl\n\n";
    $ok++;

    usleep(150000);
}

file_put_contents($outputFile, $m3u);
echo "\n========================================\n";
echo "Playlist generada: $outputFile\n";
echo "Canales OK: $ok / " . count($eventos) . "\n";
echo "========================================\n";
