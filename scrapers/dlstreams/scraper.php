<?php
/**
 * DaddyLive Scraper
 * Fuente: Metroid2023/DaddyLiveHD dlstreams.m3u8
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "https://raw.githubusercontent.com/Metroid2023/DaddyLiveHD/refs/heads/main/dlstreams.m3u8";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$outputFile   = __DIR__ . "/../../playlists/dlstreams.m3u";

$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

echo "Fetching: $sourceUrl\n";

$lines = @file($sourceUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) die("ERROR: no se pudo obtener la fuente\n");

$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: DaddyLive\n\n";
$count = 0;

foreach ($lines as $i => $line) {
    $line = trim($line);
    if (empty($line) || $line[0] === "#") continue;
    
    // Get the EXTINF from previous line
    $prevLine = trim($lines[$i - 1] ?? "");
    if (!str_starts_with($prevLine, "#EXTINF")) continue;
    
    // Parse event info from EXTINF
    preg_match('/group-title="([^"]*)",?(.+)/', $prevLine, $m);
    $group = $m[1] ?? "";
    $name  = trim($m[2] ?? "");
    
    // Parse watch.php?id=X
    preg_match('/id=(\d+)/', $line, $m);
    $eventId = $m[1] ?? null;
    if (!$eventId) continue;
    
    // Skip placeholder
    if ($line === "https://example.com.m3u8") continue;
    
    $proxyUrl = $proxyBaseUrl . "?dlstreams=" . $eventId;
    $escaped = str_replace(",", "\ ", $name);
    
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"\" group-title=\"$group\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;
}

file_put_contents($outputFile, $m3u);
echo "Playlist: $outputFile\n";
echo "Canales: $count\n";
