<?php
/**
 * SportsZone Scraper
 * Fuente: http://addonbg.co/loves.php?szone
 * Genera playlist M3U en playlists/sportszone.m3u
 *
 * Cadena de extraccion:
 * 1. loves.php?szone → CSV de eventos con links a v3.sportssonline.click
 * 2. sportssonline.click PHP → iframe dynmaspect.net/embed/{ref_id}
 * 3. embed page → M3U8 directo en 54434687.net
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl  = "http://addonbg.co/loves.php?szone";
$outputFile = __DIR__ . "/../../playlists/sportszone.m3u";

$outputDir  = dirname($outputFile);
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
        CURLOPT_TIMEOUT        => 20,
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

    if ($httpCode >= 400 || !$html) {
        return null;
    }
    return $html;
}

// --- Paso 1: Obtener lista de eventos ---
echo "[1/3] Obteniendo lista de eventos...\n";
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

    $time   = trim(ltrim($parts[0], "="));          // HH:MM
    $event  = trim($parts[1]);                       // Nombre del evento
    $phpUrl = trim($parts[2]);                       // URL del canal PHP

    if (!filter_var($phpUrl, FILTER_VALIDATE_URL)) continue;

    $eventos[] = [
        "name" => $event,
        "time" => $time,
        "url"  => $phpUrl,
    ];
}

echo "  Eventos encontrados: " . count($eventos) . "\n";

// --- Paso 2 y 3: Extraer M3U8 de cada evento ---
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: SportsZone\n\n";
$ok = 0;

foreach ($eventos as $i => $evt) {
    $label = "[" . $evt["time"] . "] " . $evt["name"];
    echo "\n[2/3] ($i/" . count($eventos) . ") $label\n";

    // Paso 2: Obtener la pagina del canal
    $channelHtml = fetchUrl($evt["url"]);
    if (!$channelHtml) {
        echo "  ERROR: No se pudo cargar pagina del canal\n";
        continue;
    }

    // Extraer iframe embed
    preg_match('/<iframe[^>]+src="([^"]+dynmaspect\.net[^"]+)"/i', $channelHtml, $iframeMatch);
    if (empty($iframeMatch[1])) {
        echo "  ERROR: No se encontro iframe embed\n";
        continue;
    }

    $embedUrl = $iframeMatch[1];
    echo "  Embed: $embedUrl\n";

    // Paso 3: Obtener M3U8 desde la pagina embed
    $embedHtml = fetchUrl($embedUrl, $evt["url"]);
    if (!$embedHtml) {
        echo "  ERROR: No se pudo cargar pagina embed\n";
        continue;
    }

    preg_match('/"((?:https:)?\/\/[^"]+\.m3u8[^"]*)"/', $embedHtml, $m3u8Match);
    if (empty($m3u8Match[1])) {
        echo "  ERROR: No se encontro M3U8 en embed\n";
        continue;
    }

    $m3u8Url = $m3u8Match[1];
    echo "  M3U8: $m3u8Url\n";

    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$label\" tvg-logo=\"\" group-title=\"SportsZone\",$label\n";
    $m3u .= "$m3u8Url\n\n";
    $ok++;

    // Pequena pausa para no saturar
    usleep(300000);
}

file_put_contents($outputFile, $m3u);
echo "\n========================================\n";
echo "Playlist generada: $outputFile\n";
echo "Canales OK: $ok / " . count($eventos) . "\n";
echo "========================================\n";
