# Fuentes IPTV

Scrapers para extraer M3U8 desde distintas fuentes PHP y generar playlists `.m3u`.

## Estructura

```
scrapers/          # Scrapers por fuente
  tvtvhd/          # Fuente: tvtvhd.com
  sportszone/      # Fuente: addonbg.co/loves.php?szone
playlists/         # M3U generados (cada fuente genera su archivo)
```

## Uso

```bash
php scrapers/tvtvhd/scraper.php
php scrapers/sportszone/scraper.php
```

Las playlists se generan en `playlists/tvtvhd.m3u` y `playlists/sportszone.m3u`.
