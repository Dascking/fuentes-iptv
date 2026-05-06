<?php
/**
 * DaddyLive Scraper
 * Fuente: Metroid2023/DaddyLiveHD dlstreams.m3u8
 * Genera: playlists/eventos_dlhd.xml + playlists/dlstreams.m3u
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "https://raw.githubusercontent.com/Metroid2023/DaddyLiveHD/refs/heads/main/dlstreams.m3u8";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$xmlFile      = __DIR__ . "/../../playlists/eventos_dlhd.xml";
$m3uFile      = __DIR__ . "/../../playlists/dlstreams.m3u";

$outputDir = dirname($xmlFile);
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

echo "Fetching: $sourceUrl\n";

$lines = @file($sourceUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) die("ERROR: no se pudo obtener la fuente\n");

$events = [];

foreach ($lines as $i => $line) {
    $line = trim($line);
    if (empty($line) || $line[0] === "#") continue;
    
    $prevLine = trim($lines[$i - 1] ?? "");
    if (!str_starts_with($prevLine, "#EXTINF")) continue;
    
    preg_match('/group-title="([^"]*)",?(.+)/', $prevLine, $m);
    $group = $m[1] ?? "";
    $name  = trim($m[2] ?? "");
    
    preg_match('/id=(\d+)/', $line, $m);
    $eventId = $m[1] ?? null;
    if (!$eventId) continue;
    if ($line === "https://example.com.m3u8") continue;

    // Dedeuplicar por ID
    if (isset($events[$eventId])) continue;
    
    $events[$eventId] = [
        "id"    => $eventId,
        "name"  => $name,
        "group" => $group,
    ];
}

// Orden alfabetico
uasort($events, function($a, $b) { return strcasecmp($a["name"], $b["name"]); });

echo "Unique events: " . count($events) . "\n";

// === Generar XML ===
$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<eventos>\n";
foreach ($events as $evt) {
    $name  = htmlspecialchars($evt["name"], ENT_XML1);
    $group = htmlspecialchars($evt["group"], ENT_XML1);
    $xml .= "  <evento id=\"{$evt["id"]}\" grupo=\"$group\">$name</evento>\n";
}
$xml .= "</eventos>\n";
file_put_contents($xmlFile, $xml);
echo "XML: $xmlFile\n";

// === Generar M3U con proxy ===
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: DaddyLive\n\n";
$count = 0;

foreach ($events as $evt) {
    $escaped = str_replace(",", "\ ", $evt["name"]);
    $proxyUrl = $proxyBaseUrl . "?dlstreams=" . $evt["id"];
    
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"\" group-title=\"{$evt["group"]}\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;
}

file_put_contents($m3uFile, $m3u);
echo "M3U: $m3uFile\n";
echo "Canales: $count\n";
