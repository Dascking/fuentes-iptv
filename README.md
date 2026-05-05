# Fuentes IPTV

Scrapers para extraer M3U8 desde distintas fuentes PHP y generar playlists `.m3u`.

## Estructura

```
scrapers/          # Scrapers por fuente
  sportszone/      # Fuente: addonbg.co/loves.php?szone
playlists/         # M3U generados
```

## Uso

```bash
php scrapers/sportszone/scraper.php
```

Playlist: `playlists/sportszone.m3u`
