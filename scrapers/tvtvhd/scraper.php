<?php
/**
 * TVTVHD Scraper
 * Extrae M3U8 de https://tvtvhd.com/vivo/canales.php?stream={channel}
 * Genera playlist M3U en playlists/tvtvhd.m3u
 */

$baseUrl = "https://tvtvhd.com/vivo/canales.php?stream=";

$canales = [
    "espn3_nl"    => "ESPN 3 (NL)",
    // Agregá más canales acá: "slug" => "Nombre en EPG"
];

$outputFile = __DIR__ . "/../../playlists/tvtvhd.m3u";
$outputDir  = dirname($outputFile);

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: TVTVHD\n\n";

function getM3U8($channel, $baseUrl) {
    $url = $baseUrl . urlencode($channel);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_USERAGENT      => "Mozilla/5.0 (compatible; IPTV-Bot/1.0)",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$html) {
        return null;
    }

    preg_match('/var\s+playbackURL\s*=\s*"([^"]+)"/', $html, $m);
    return $m[1] ?? null;
}

foreach ($canales as $slug => $nombre) {
    echo "Procesando: $nombre ($slug)... ";
    $m3u8 = getM3U8($slug, $baseUrl);

    if ($m3u8) {
        $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$nombre\" tvg-logo=\"\" group-title=\"TVTVHD\",$nombre\n";
        $m3u .= "$m3u8\n\n";
        echo "OK\n";
    } else {
        echo "FALLÓ\n";
    }
}

file_put_contents($outputFile, $m3u);
echo "\nPlaylist generada: $outputFile\n";
echo "Canales procesados: " . count($canales) . "\n";
