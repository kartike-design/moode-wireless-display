# moOde Now Playing Display

A full-screen, portrait-mode now-playing display for [moOde Audio Player](https://moodeaudio.org/), designed for a dedicated Android device (phone or tablet) running in kiosk mode. No Python, no extra daemons — everything runs through moOde's existing nginx on port 80.

![Display showing album art, track info, VU meters and literary clock](screenshot-placeholder.png)

---

## Features

- **Album art** with blurred ambient background
- **Track metadata** — title, artist, album with smooth fade transitions
- **Progress bar** with elapsed/total time (local files and radio)
- **Simulated VU meters** — analogue-style needle meters, active during playback
- **Source badge** — Local / Radio / Spotify, with animated pulse for live radio
- **Literary clock** — appears 10 seconds after playback stops or pauses; shows a quote from literature containing the current time, with the time phrase highlighted in red. 1,877 quotes covering every minute of the day.
- **OLED burn-in protection** — pixel shift (±7 px random translate every 3.5 min) and brightness cycling (subtle dimming every 2 min)
- **True black idle** — `#000000` background on literary clock screen

---

## Sources supported

| Source | Art | Progress | VU meters | Pause → clock |
|---|---|---|---|---|
| Local files (FLAC, MP3, etc.) | ✅ moOde thumbnail cache | ✅ | ✅ | ✅ |
| Internet radio | ✅ station logo files | ➖ (live stream) | ✅ | ✅ |
| Spotify Connect (librespot) | ✅ Spotify CDN | ✅ | ✅ | ✅ |
| AirPlay | ⚠️ untested — no Apple device available | ⚠️ | ⚠️ | ⚠️ |

---

## Hardware tested on

- **Server**: Raspberry Pi 5 with audio HAT, moOde Audio Player 10.x (headless)
- **Display**: OnePlus 3T (Android, portrait), [Fully Kiosk Browser](https://www.fully-kiosk.com/)
- Should scale to any Android phone or tablet in portrait orientation. The layout uses `max-width: 420px` and `clamp()` font sizes, so it adapts gracefully to larger screens.

---

## Files

```
nowplaying.html        — display UI (serves at http://YOUR_PI_IP/nowplaying.html)
nowplaying.php         — metadata API (reads MPD + Spotify state)
nowplaying-art.php     — cover art server (local cache / radio logos / Spotify CDN proxy)
patch_spotevent.py     — one-time patch for moOde's spotevent.sh (Spotify pause fix)
quotes.csv             — 1,877 literary clock quotes (pipe-delimited)
```

---

## Installation

### 1. Copy files to your Pi

```bash
# From your computer:
scp nowplaying.html nowplaying.php nowplaying-art.php quotes.csv pi@YOUR_PI_IP:/tmp/

# On the Pi:
sudo cp /tmp/nowplaying.html /tmp/nowplaying.php /tmp/nowplaying-art.php /tmp/quotes.csv /var/www/
sudo cp /var/www/nowplaying.* /var/www/html/
sudo chown www-data:www-data /var/www/nowplaying.* /var/www/html/nowplaying.*
```

### 2. Apply the Spotify pause fix

This is required for the literary clock to appear correctly when Spotify is paused. moOde runs librespot as a persistent service, so without this patch the display never knows Spotify has paused.

```bash
sudo python3 patch_spotevent.py
```

Verify the patch:
```bash
grep -A10 "MATCH == 0" /var/local/www/commandw/spotevent.sh
```

You should see a block that writes `$PLAYER_EVENT` to `/var/local/www/spot_state.txt`.

### 3. Open on your display device

Navigate to `http://YOUR_PI_IP/nowplaying.html` on your phone or tablet. Any modern browser works, but a kiosk browser is strongly recommended for always-on use so the screen stays on and the URL bar stays hidden.

**Fully Kiosk Browser (Android) — recommended**
The best option for a dedicated always-on display. [Free version](https://www.fully-kiosk.com/) is sufficient.
- Web Auto Reload: enabled (recovers automatically if the Pi reboots)
- Screen Orientation: Portrait
- Keep Screen On: enabled
- Screensaver: disabled (the literary clock is the screensaver)
- Start URL: `http://YOUR_PI_IP/nowplaying.html`

**Regular Chrome or Firefox (Android/iOS) — works fine**
A simpler option if you don't want to install a dedicated kiosk app. Open the URL, tap the browser menu and select "Add to Home Screen" to create a shortcut. On Android you can use Chrome's "Immersive mode" or a free app like "Full Screen Caller" to hide the status bar. Remember to disable auto-lock in your phone's display settings.

**Safari on iPhone or iPad**
Open the URL in Safari, tap the Share button and choose "Add to Home Screen". Launch it from the home screen icon and it opens full-screen without the browser chrome. Go to Settings → Display & Brightness → Auto-Lock and set it to Never while using as a display. Note: AirPlay metadata behaviour is untested on this project.

---

## How it works

### Metadata flow

```
Browser (polls every 3s)
    └─► nowplaying.php
            ├─ MPD socket (127.0.0.1:6600) — local files, radio
            ├─ /var/local/www/spot_state.txt — Spotify play/pause state
            └─ /var/local/www/spotmeta.json  — Spotify track metadata
```

### Spotify pause detection

moOde runs librespot as a persistent service that never exits, so the traditional "is librespot running?" check always returns true — even when Spotify is paused. The fix: moOde's `spotevent.sh` is called by librespot on every event. We intercept the `playing`/`paused`/`stopped` events (which moOde otherwise discards) and write a one-line state file. `nowplaying.php` reads this file instead of relying on `pgrep`.

### Cover art

- **Local files**: MD5 hash of the folder path, looked up in moOde's thumbnail cache (`/var/local/www/imagesw/thmcache/`)
- **Radio**: fuzzy filename match against `/var/local/www/imagesw/radio-logos/`
- **Spotify**: proxied from the Spotify CDN URL in `spotmeta.json`

### Literary clock

Quotes are loaded from `quotes.csv` once on page load and indexed by `HH:MM`. On idle (10 seconds after stop/pause), the clock displays a quote for the current minute. If no exact match exists, it searches ±10 minutes before falling back to a random quote. The quote refreshes at each minute boundary.

---

## Radio station logo mapping

If your station logos don't display, add entries to the `$manualMap` array in `nowplaying-art.php`:

```php
$manualMap = [
    'BBC Radio 4'    => 'BBC Radio 4',      // stream name => logo filename (no extension)
    'SomaFM Drone Zone' => 'Soma FM - Drone Zone',
];
```

Logo files live in `/var/local/www/imagesw/radio-logos/` on the Pi. The matcher tries multiple filename variants (spaces, underscores, case) before falling back to the manual map.

---

## Backup and restore

After installation, back up all modified files:

```bash
sudo mkdir -p /var/backups/moode
sudo cp /var/www/nowplaying.php     /var/backups/moode/nowplaying.php.bak
sudo cp /var/www/nowplaying-art.php /var/backups/moode/nowplaying-art.php.bak
sudo cp /var/www/nowplaying.html    /var/backups/moode/nowplaying.html.bak
sudo cp /var/local/www/commandw/spotevent.sh /var/backups/moode/spotevent.sh.bak
```

To restore:

```bash
sudo cp /var/backups/moode/nowplaying.php.bak     /var/www/nowplaying.php
sudo cp /var/backups/moode/nowplaying-art.php.bak /var/www/nowplaying-art.php
sudo cp /var/backups/moode/nowplaying.html.bak    /var/www/nowplaying.html
sudo cp /var/backups/moode/spotevent.sh.bak       /var/local/www/commandw/spotevent.sh
sudo cp /var/www/nowplaying.* /var/www/html/
sudo chown www-data:www-data /var/www/nowplaying.* /var/www/html/nowplaying.*
```

---

## Credits and attribution

### Literary clock quotes

The quotes database (`quotes.csv`) is sourced from the **[epaper-watch](https://github.com/solarkennedy/epaper-watch)** project by Kyle Anderson, which in turn draws from the **[Literature Clock](https://github.com/JohannesNE/literature-clock)** project. The original quote collection was compiled from **The Guardian's books coverage**. All literary quotes remain the property of their respective authors and publishers.

### moOde Audio Player

This project is a display overlay for [moOde Audio Player](https://moodeaudio.org/) by Tim Curtis. It uses moOde's existing nginx, MPD socket, thumbnail cache, and Spotify event hook — no modifications to moOde's core are required beyond the one-line spotevent.sh patch.

---

## Notes and limitations

- **AirPlay**: untested — no Apple device was available during development. The MPD state polling should work in principle, but artwork and metadata behaviour are unknown.
- **VU meters**: simulated (synthesised waveform), not a real audio capture. This avoids browser audio permission prompts on kiosk devices.
- **Spotify elapsed time**: librespot does not expose elapsed position to `spotevent.sh`, so the progress bar for Spotify counts up locally from 0 on each track change.
- **moOde version**: developed and tested on moOde 10.x. Earlier versions may have different paths for `spotmeta.json` or `spotevent.sh`.
