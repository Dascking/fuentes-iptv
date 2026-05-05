# Fuentes IPTV

Scrapers para extraer M3U8 desde distintas fuentes PHP y generar playlists `.m3u`.

## Estructura

```
scrapers/          # Scrapers por fuente
  sportszone/      # Fuente: addonbg.co/loves.php?szone
playlists/         # M3U generados (cada fuente genera su archivo)
```

## Uso

```bash
php scrapers/sportszone/scraper.php
```

La playlist se genera en `playlists/sportszone.m3u`.
