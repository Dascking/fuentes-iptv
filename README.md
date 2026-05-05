# Fuentes IPTV

Scrapers para extraer M3U8 desde distintas fuentes PHP y generar playlists `.m3u`.

## Estructura

```
scrapers/          # Scrapers por fuente
  tvtvhd/          # Fuente: tvtvhd.com
playlists/         # M3U generados (cada fuente genera su archivo)
```

## Uso

```bash
php scrapers/tvtvhd/scraper.php
```

La playlist se genera en `playlists/tvtvhd.m3u`.
