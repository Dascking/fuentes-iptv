<?php
/**
 * DaddyLive Scraper - Scraping directo desde Kodi addon
 * Fuente: https://addonbg.co/daddy.php?daddy
 * Genera: playlists/eventos_dlhd.xml + playlists/dlstreams.m3u
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$baseUrl      = "https://addonbg.co/daddy.php?daddy";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$xmlFile      = __DIR__ . "/../../playlists/eventos_dlhd.xml";
$m3uFile      = __DIR__ . "/../../playlists/dlstreams.m3u";
$chileOffset  = -4 * 3600; // UTC-4

$outputDir = dirname($xmlFile);
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

function fetchXml($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_USERAGENT      => "Mozilla/5.0",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $data = curl_exec($ch);
    return $data;
}

echo "Fetching categories...\n";
$catsXml = fetchXml($baseUrl);
if (!$catsXml) die("ERROR: no se pudo obtener categorias\n");

// Extraer categorias (base64 encoded names in cat= param)
preg_match_all('/cat=([A-Za-z0-9%+]+)/', $catsXml, $catMatches);
$categories = array_unique($catMatches[1]);

echo "Categories: " . count($categories) . "\n";

$allEvents = [];
$seenIds = [];

foreach ($categories as $cat) {
    $catName = @base64_decode(urldecode($cat)) ?: $cat;
    echo "  Fetching: $catName\n";
    
    $url = "https://addonbg.co/daddy.php?daddy&cat=" . urlencode($cat);
    $xml = fetchXml($url);
    if (!$xml) continue;
    
    // Parsear items
    preg_match_all('/<item>(.+?)<\/item>/s', $xml, $items);
    
    foreach ($items[1] as $item) {
        // Titulo del evento
        preg_match('/<title>(.+?)<\/title>/s', $item, $titleMatch);
        $title = $titleMatch[1] ?? "";
        
        // Limpiar tags Kodi
        $title = preg_replace('/\[COLOR\s+\w+\]|\[\/COLOR\]|\[B\]|\[\/B\]|\[I\]|\[\/I\]/i', '', $title);
        $title = trim($title);
        
        // Skip headers
        if (empty($title) || preg_match('/^\d+$/', $title)) continue;
        
        // Extraer ID numerico: kodi_stream_utils.daddyhd("576")
        preg_match('/daddyhd\("(\d+)"\)/', $item, $idMatch);
        $eventId = $idMatch[1] ?? null;
        if (!$eventId) continue;
        
        // Skip duplicates
        if (isset($seenIds[$eventId])) continue;
        $seenIds[$eventId] = true;
        
        // Extraer hora del titulo (formato: "HH:MM Event Name")
        preg_match('/^(\d{1,2}:\d{2})\s+(.+)/', $title, $timeMatch);
        if ($timeMatch) {
            $time = $timeMatch[1];
            $name = trim($timeMatch[2]);
        } else {
            // Eventos sin hora (ej: canales 24/7) - skip
            continue;
        }
        
        // Convertir UTC → Chile (UTC-4)
        list($h, $m) = explode(":", $time);
        $utcTotal = (int)$h * 60 + (int)$m;
        $chileTotal = ($utcTotal + $chileOffset / 60 + 1440) % 1440;
        $chileTime = sprintf("%02d:%02d", floor($chileTotal / 60), $chileTotal % 60);
        
        $allEvents[] = [
            "id"       => $eventId,
            "name"     => $name,
            "time"     => $time,       // hora original
            "chileTime" => $chileTime, // hora Chile
        ];
    }
}

// Ordenar por hora Chile
usort($allEvents, function($a, $b) {
    return strcmp($a["chileTime"], $b["chileTime"]);
});

echo "\nLive events: " . count($allEvents) . "\n";

// === Generar XML ===
$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<eventos>\n";
foreach ($allEvents as $evt) {
    $name = htmlspecialchars($evt["name"], ENT_XML1);
    $xml .= "  <evento id=\"{$evt["id"]}\" hora=\"{$evt["chileTime"]}\">$name</evento>\n";
}
$xml .= "</eventos>\n";
file_put_contents($xmlFile, $xml);
echo "XML: $xmlFile\n";

// === Generar M3U ===
$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: DaddyLive\n\n";
$count = 0;

foreach ($allEvents as $evt) {
    $label = "[{$evt["chileTime"]}] {$evt["name"]}";
    $escaped = str_replace(",", "\ ", $label);
    $proxyUrl = $proxyBaseUrl . "?dlstreams=" . $evt["id"];
    
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"\" group-title=\"Live Events\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;
}

file_put_contents($m3uFile, $m3u);
echo "M3U: $m3uFile\n";
echo "Canales: $count\n";
