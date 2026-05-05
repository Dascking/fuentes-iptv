<?php
/**
 * SportsZone Scraper
 * Fuente: https://sportsonline.vc/prog.txt
 * Genera playlist M3U con proxy URLs en playlists/sportszone.m3u
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "https://sportsonline.vc/prog.txt";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$outputFile   = __DIR__ . "/../../playlists/sportszone.m3u";

$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

echo "Fetching: $sourceUrl\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $sourceUrl,
    CURLOPT_USERAGENT      => "Mozilla/5.0 (compatible; IPTV/1.0)",
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

$lines = explode("\n", $content);
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: SportsZone\n\n";
$count = 0;
$currentDay = "";

$days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    // Detectar cambio de dia
    $upper = strtoupper($line);
    if (in_array($upper, $days)) {
        $currentDay = $upper;
        continue;
    }

    // Formato: HH:MM   Event Name | URL
    if (!preg_match('/^(\d{2}:\d{2})\s+(.+?)\s*\|\s*(https?:\/\/\S+)/', $line, $m)) continue;

    $time   = $m[1];
    $event  = trim($m[2]);
    $phpUrl = $m[3];

    if (!str_contains($phpUrl, 'sportssonline.click')) continue;

    $label = "[$time] $event";
    $group = $currentDay ? "SportsZone $currentDay" : "SportsZone";
    $proxyUrl = $proxyBaseUrl . "?channel=" . urlencode($phpUrl);

    $escaped = str_replace(",", "\ ", $label);
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"\" group-title=\"$group\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;
}

file_put_contents($outputFile, $m3u);
echo "Playlist: $outputFile\n";
echo "Canales: $count\n";
