<?php
/**
 * DaddyLive Scraper - Solo eventos en vivo
 * Fuente: Metroid2023/DaddyLiveHD dlstreams.m3u8
 * Genera: playlists/eventos_dlhd.xml + playlists/dlstreams.m3u
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "https://raw.githubusercontent.com/Metroid2023/DaddyLiveHD/refs/heads/main/dlstreams.m3u8";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$xmlFile      = __DIR__ . "/../../playlists/eventos_dlhd.xml";
$m3uFile      = __DIR__ . "/../../playlists/dlstreams.m3u";
$chileOffset  = -4 * 3600; // UTC-4

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
    
    // Filtrar: solo eventos en vivo, no 24/7
    if (stripos($group, "24/7") !== false) continue;
    if (stripos($group, "DLHD") !== false && stripos($name, "24") !== false) continue;
    
    preg_match('/id=(\d+)/', $line, $m);
    $eventId = $m[1] ?? null;
    if (!$eventId) continue;
    if ($line === "https://example.com.m3u8") continue;
    if (isset($events[$eventId])) continue;
    
    // Extraer hora del nombre: buscar (HH:MM) al final
    preg_match('/\((\d{1,2}):(\d{2})\)\s*$/', $name, $timeM);
    $sortTime = "99:99"; // sin hora = al final
    $chileTime = "";
    
    if ($timeM) {
        $utcHour   = (int)$timeM[1];
        $utcMin    = (int)$timeM[2];
        $utcTotal  = $utcHour * 60 + $utcMin;
        $chileTotal = ($utcTotal + $chileOffset / 60 + 1440) % 1440;
        $chHour    = floor($chileTotal / 60);
        $chMin     = $chileTotal % 60;
        $chileTime = sprintf("%02d:%02d", $chHour, $chMin);
        $sortTime  = $chileTime;
    }
    
    $events[] = [
        "id"        => $eventId,
        "name"      => $name,
        "group"     => $group,
        "time"      => $chileTime,
        "sortTime"  => $sortTime,
    ];
}

// Ordenar por hora
usort($events, function($a, $b) {
    return strcmp($a["sortTime"], $b["sortTime"]);
});

echo "Live events: " . count($events) . "\n";

// === Generar XML ===
$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<eventos>\n";
foreach ($events as $evt) {
    $name  = htmlspecialchars($evt["name"], ENT_XML1);
    $time  = $evt["time"];
    $group = htmlspecialchars($evt["group"], ENT_XML1);
    $xml .= "  <evento id=\"{$evt["id"]}\" hora=\"$time\" grupo=\"$group\">$name</evento>\n";
}
$xml .= "</eventos>\n";
file_put_contents($xmlFile, $xml);
echo "XML: $xmlFile\n";

// === Generar M3U con proxy ===
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: DaddyLive\n\n";
$count = 0;

foreach ($events as $evt) {
    $timeLabel = $evt["time"] ? "[{$evt["time"]}] " : "";
    $label = $timeLabel . $evt["name"];
    $escaped = str_replace(",", "\ ", $label);
    $proxyUrl = $proxyBaseUrl . "?dlstreams=" . $evt["id"];
    
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"\" group-title=\"Live Events\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;
}

file_put_contents($m3uFile, $m3u);
echo "M3U: $m3uFile\n";
echo "Canales: $count\n";
