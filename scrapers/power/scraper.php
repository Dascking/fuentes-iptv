<?php
/**
 * Power Scraper
 * Fuente: http://addonbg.co/black/power.php?live
 * Genera playlist con URLs via proxy en playlists/power.m3u
 */

error_reporting(E_ALL ^ E_DEPRECATED);
set_time_limit(0);

$sourceUrl    = "http://addonbg.co/black/power.php?live";
$proxyBaseUrl = "http://74.208.207.247/proxy.php";
$outputFile   = __DIR__ . "/../../playlists/power.m3u";

$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

echo "Fetching: $sourceUrl\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $sourceUrl,
    CURLOPT_USERAGENT      => "Mozilla/5.0",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$xml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode >= 400 || !$xml) die("ERROR: HTTP $httpCode\n");

// Parsear items del XML
preg_match_all('/<item>(.+?)<\/item>/s', $xml, $items);

$m3u  = "#EXTM3U\n";
$m3u .= "#PLAYLIST: Power\n\n";
$count = 0;

foreach ($items[1] as $item) {
    // Extraer titulo
    preg_match('/<title>(.+?)<\/title>/s', $item, $titleMatch);
    $title = $titleMatch[1] ?? "";
    
    // Limpiar tags HTML/COLOR del titulo
    $title = preg_replace('/\[COLOR\s+\w+\]|\[\/COLOR\]|\[B\]|\[\/B\]/i', '', $title);
    $title = strip_tags(html_entity_decode($title, ENT_QUOTES));
    $title = trim($title);

    // Saltar items no-stream (buscar, lista actualizada, etc)
    if (empty($title) || str_contains($title, 'BUSCAR') || str_contains($title, 'Lista Actualizada')) continue;

    // Extraer link
    preg_match('/<link>(.+?)<\/link>/s', $item, $linkMatch);
    $link = $linkMatch[1] ?? "";

    // Saltar links sin M3U8 o con $doregex (requieren Python)
    if (!str_contains($link, 'm3u8') || str_contains($link, '$doregex')) continue;

    // Extraer thumbnail
    preg_match('/<thumbnail>(.+?)<\/thumbnail>/s', $item, $thumbnailMatch);
    $logo = $thumbnailMatch[1] ?? "";

    // Codificar el link completo (URL + headers) como base64 limpio
    $proxyUrl = $proxyBaseUrl . "/power/" . rtrim(strtr(base64_encode($link), '+/', '-_'), '=') . ".m3u8";

    $escaped = str_replace(",", "\ ", $title);
    $m3u .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"$escaped\" tvg-logo=\"$logo\" group-title=\"Power\",$escaped\n";
    $m3u .= "$proxyUrl\n\n";
    $count++;
}

file_put_contents($outputFile, $m3u);
echo "Playlist: $outputFile\n";
echo "Canales: $count\n";
