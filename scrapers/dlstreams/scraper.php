<?php
/**
 * DaddyLive Scraper - desde GitHub M3U
 * Solo eventos en vivo, hora Chile, ordenados
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "https://raw.githubusercontent.com/Metroid2023/DaddyLiveHD/refs/heads/main/dlstreams.m3u8";
$proxyBaseUrl = "http://74.208.207.247/iptv/proxy.php";
$xmlFile      = "/srv/iptv/playlists/eventos_dlhd.xml";
$m3uFile      = "/srv/iptv/playlists/dlstreams.m3u";
$chileOffset  = -4 * 3600;

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
    if (stripos($group, "24/7") !== false) continue;
    preg_match('/id=(\d+)/', $line, $m);
    $eventId = $m[1] ?? null;
    if (!$eventId) continue;
    if ($line === "https://example.com.m3u8") continue;
    if (isset($events[$eventId])) continue;
    preg_match('/\((\d{1,2}):(\d{2})\)\s*$/', $name, $timeM);
    $sortTime = "99:99";
    $chileTime = "";
    if ($timeM) {
        $utcTotal = (int)$timeM[1] * 60 + (int)$timeM[2];
        $chileTotal = ($utcTotal + $chileOffset / 60 + 1440) % 1440;
        $chileTime = sprintf("%02d:%02d", floor($chileTotal / 60), $chileTotal % 60);
        $sortTime = $chileTime;
    }
    $events[] = ["id" => $eventId, "name" => $name, "group" => $group, "time" => $chileTime, "sortTime" => $sortTime];
}

usort($events, function($a, $b) { return strcmp($a["sortTime"], $b["sortTime"]); });

$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<eventos>\n";
foreach ($events as $e) {
    $name = htmlspecialchars($e["name"], ENT_XML1);
    $xml .= "  <evento id=\"{$e["id"]}\" hora=\"{$e["time"]}\">$name</evento>\n";
}
$xml .= "</eventos>\n";
file_put_contents($xmlFile, $xml);

$m3u = "#EXTM3U\n#PLAYLIST: DaddyLive\n\n";
$count = 0;
foreach ($events as $e) {
    $label = $e["time"] ? "[{$e["time"]}] {$e["name"]}" : $e["name"];
    $escaped = str_replace(",", "\\ ", $label);
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"\" group-title=\"Live Events\",$escaped\n";
    $m3u .= "{$proxyBaseUrl}?dlstreams={$e["id"]}\n\n";
    $count++;
}
file_put_contents($m3uFile, $m3u);
echo "Live events: $count\n";
