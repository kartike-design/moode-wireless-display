# moode-nowplaying

**An always-on Now Playing wireless display for moOde Audio Player**

Turn any Android phone or tablet into a dedicated wireless music display for your [moOde Audio Player](https://moodeaudio.org) — showing album art, track info, retro analog VU meters, and a literary clock screensaver, all updating in real time over your local network.

> Works with local file playback, internet radio, and Spotify Connect (with correct play/pause detection). No app to install, no extra hardware — just a browser pointed at your Pi.

---

## What it looks like

- Full-screen portrait layout for phones and tablets
- Album art with correct behaviour across local files, radio station logos, and Spotify cover art
- Track title that shrinks to fit rather than truncating
- Retro analog needle VU meters — warm backlit brass styling, dual scale (linear + dB)
- A literary clock screensaver that replaces the idle screen after 10 seconds of pause/stop, showing a time-matched quote with the time phrase highlighted
- OLED burn-in protection built in (pixel drift, brightness cycling, true-black idle)

---

## Architecture

```
moOde (existing, untouched)
  |-- MPD on port 6600                    <- local file + radio metadata
  |-- librespot event hook (modified)     <- Spotify play/pause/track events
  \-- cfg_radio SQLite table               <- authoritative radio station names

         read by three small files, no external network calls in PHP

nowplaying.php      -> JSON API, arbitrates between local/radio/Spotify
nowplaying-art.php  -> cover art (local thumbnail cache, radio logos, cached Spotify art)
nowplaying.html     -> the display itself
```

Everything runs through moOde's own nginx on port 80 — no separate services, no Python daemons, no background watchdog processes. Access the display at `http://YOUR_PI_IP/nowplaying.html`.

### Why a modified librespot hook is needed

librespot (Spotify's playback engine) stays running through a pause — it doesn't disconnect, so there's no simple way to tell "paused" from "playing" by process state alone. moOde's own event hook (`spotevent.sh`) only listens for session connect/disconnect and track-change events; it silently ignores librespot's own `playing`/`paused`/`stopped` events. This project extends that hook to capture those events and write a small local state file, which `nowplaying.php` reads directly — no polling, no guessing.

The same hook also caches Spotify's cover art to a local file *synchronously*, before announcing the new track — this avoids a race where the display could show a new track's title before its artwork had finished downloading.

### A quirk worth knowing: radio "pause" is really "stop"

MPD can't truly pause a live stream (there's nothing to buffer). moOde maps a radio pause to an actual MPD `stop` command, while leaving the station still loaded in the queue. This project detects that specific case (`state: stop` but a radio URL is still loaded) and treats it as paused rather than idle — otherwise a paused radio stream would incorrectly look identical to genuinely nothing playing.

---

## Installation

### Step 1 — Copy the files to your Pi

Upload to `/tmp` first (avoids permission issues), then move into place:

```bash
scp var/www/nowplaying.php                              pi@YOUR_PI_IP:/tmp/
scp var/www/nowplaying-art.php                           pi@YOUR_PI_IP:/tmp/
scp var/www/nowplaying.html                              pi@YOUR_PI_IP:/tmp/
scp var/local/www/commandw/spotevent.sh                  pi@YOUR_PI_IP:/tmp/
```

```bash
sudo cp /tmp/nowplaying.php      /var/www/nowplaying.php
sudo cp /tmp/nowplaying-art.php  /var/www/nowplaying-art.php
sudo cp /tmp/nowplaying.html     /var/www/nowplaying.html
sudo chown www-data:www-data /var/www/nowplaying.php /var/www/nowplaying-art.php /var/www/nowplaying.html
sudo chmod 644 /var/www/nowplaying.php /var/www/nowplaying-art.php /var/www/nowplaying.html

sudo cp /tmp/spotevent.sh /var/local/www/commandw/spotevent.sh
sudo chown root:root /var/local/www/commandw/spotevent.sh
sudo chmod +x /var/local/www/commandw/spotevent.sh
```

**If you don't use Spotify Connect**, you can skip the `spotevent.sh` step entirely — local and radio playback work independently of it.

### Step 2 — Add the literary clock quotes

A `quotes.csv` is included in `var/www/quotes.csv` — see [QUOTES_FORMAT.md](QUOTES_FORMAT.md) for the format if you'd like to edit or extend it.

```bash
scp var/www/quotes.csv pi@YOUR_PI_IP:/tmp/
```
```bash
sudo cp /tmp/quotes.csv /var/www/quotes.csv
sudo chown www-data:www-data /var/www/quotes.csv
sudo chmod 644 /var/www/quotes.csv
```

### Step 3 — Test from the Pi

```bash
curl -s http://127.0.0.1/nowplaying.php | python3 -m json.tool
```

Should return JSON with the current track's title, artist, source, and state.

### Step 4 — Open the display

On any device on your network:
```
http://YOUR_PI_IP/nowplaying.html
```

---

## Setting up a dedicated wireless display

Any Android phone or tablet works. **Fully Kiosk Browser** (free tier) is recommended.

| Setting | Value |
|---|---|
| Start URL | `http://YOUR_PI_IP/nowplaying.html` |
| Keep Screen On | On |
| Start on Boot | On |
| Reload on Reconnect | On |
| Enable Kiosk Mode | On |
| Enable Screensaver | Off |

Set the phone's own screen timeout to Never, keep it plugged in, and disable auto-rotate so it stays in portrait.

---

## Radio station art

Radio logos are looked up via moOde's own `cfg_radio` SQLite table (the same source moOde's UI uses) by exact stream URL match — not fuzzy string matching — so this is reliable for any station already in your moOde library. Falls back to a normalized fuzzy match against the logo folder if a station isn't in the database, then to moOde's default cover if nothing matches.

## Spotify

Enable Spotify Connect normally in moOde (Renderers settings). Once the modified `spotevent.sh` is in place, play/pause/track-change all correctly reflect on the display — including distinguishing a genuinely paused Spotify session from one that's fully disconnected.

## Source priority

When more than one source has recent state (e.g. you paused radio, then started Spotify), the display picks whichever was **most recently active** — an actively-playing source always wins outright; between two paused/idle sources, the one with the more recent timestamp wins. This handles the full matrix of switching between local, radio, and Spotify without stale information bleeding through.

---

## OLED burn-in protection

Built in, no configuration needed:

| Technique | Behaviour |
|---|---|
| Pixel drift | Main content nudges up to 7px in a random direction every ~3.5 min, over a slow 14s transition — invisible in normal use |
| Brightness cycling | Screen brightness varies 88-100% every ~2 min |
| True black idle | The literary clock (or blank idle screen) is pure `#000000` — OLED pixels are fully off whenever nothing is playing |

---

## Troubleshooting

**Some radio stations show no art**
Run `Regenerate -> Album cover thumbnail cache` in moOde's Music Library settings (helps local files) — for radio, confirm the station exists in moOde's own station list with a logo assigned.

**Spotify shows the wrong play/pause state**
Confirm the modified `spotevent.sh` is actually in place and executable:
```bash
ls -la /var/local/www/commandw/spotevent.sh
```
Check the state file directly while testing:
```bash
cat /var/local/www/spotify_playstate.json
```

**Display gets stuck showing a different source after switching**
This project handles the switching logic carefully, but if you hit an edge case, check:
```bash
curl -s http://127.0.0.1/nowplaying.php | python3 -m json.tool
```
and compare `source`/`state` against what's actually playing. Please open an issue with this output if something looks wrong.

**Nothing loads at all**
```bash
sudo systemctl status nginx
sudo systemctl status php8.4-fpm
```

---

## File reference

| File | Installed to | Purpose |
|---|---|---|
| `nowplaying.php` | `/var/www/` | Metadata API — arbitrates local/radio/Spotify |
| `nowplaying-art.php` | `/var/www/` | Cover art server |
| `nowplaying.html` | `/var/www/` | The display itself |
| `spotevent.sh` | `/var/local/www/commandw/` | Modified librespot event hook (Spotify play/pause detection + art caching) |

---

## Contributing

Tested against moOde 10.3.x on Raspberry Pi 5. Pull requests welcome.

If you hit an issue, please include:
- Your moOde version
- Output of `curl -s http://127.0.0.1/nowplaying.php | python3 -m json.tool`
- What you expected vs. what you saw

---

## Licence

MIT — use freely, modify freely, share freely.
