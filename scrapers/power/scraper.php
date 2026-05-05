<?php
/**
 * Power Scraper
 * Fuente: http://addonbg.co/black/power.php
 * Genera playlist M3U con proxy URLs en playlists/power.m3u
 *
 * La fuente entrega M3U con M3U8 + Referer.
 * El proxy.php rescrapea power.php on-demand para tokens frescos.
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "http://addonbg.co/black/power.php";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$outputFile   = __DIR__ . "/../../playlists/power.m3u";

$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

echo "Fetching: $sourceUrl\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $sourceUrl,
    CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode >= 400 || !$content) {
    die("ERROR: HTTP $httpCode\n");
}

$lines = explode("\n", trim($content));
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: Power\n\n";
$count = 0;

for ($i = 0; $i < count($lines); $i++) {
    $line = trim($lines[$i]);

    if (empty($line) || !str_starts_with($line, "#EXTINF")) continue;
    if (str_contains($line, "Lista Actualizada")) continue;

    // Parsear metadata: #EXTINF:-1 attr1="v1" attr2="v2",[Category] Name
    preg_match('/tvg-id="([^"]*)"\s*tvg-logo="([^"]*)"\s*group-title="([^"]*)",(.+)/', $line, $m);
    if (count($m) < 5) continue;

    $tvgId   = $m[1];
    $tvgLogo = $m[2];
    $group   = $m[3];
    $name    = trim($m[4]);

    // Siguiente linea debe ser la URL
    $nextLine = trim($lines[$i + 1] ?? "");
    if (!str_starts_with($nextLine, "https://") || !str_contains($nextLine, "mainstreams.pro")) continue;

    // Proxy URL unificado
    $proxyUrl = $proxyBaseUrl . "?power=" . urlencode($name);

    $escaped = str_replace(",", "\ ", $name);
    $m3u .= "#EXTINF:-1 tvg-id=\"$tvgId\" tvg-logo=\"$tvgLogo\" group-title=\"$group\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;

    $i++; // saltar la URL ya procesada
}

file_put_contents($outputFile, $m3u);
echo "Playlist: $outputFile\n";
echo "Canales: $count\n";
