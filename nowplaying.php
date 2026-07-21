<?php
/**
 * nowplaying.php — moOde Now Playing metadata API
 *
 * FIX 4: pausing Spotify after it had priority showed stale radio
 * instead — both "radio loaded" and "Spotify paused" looked equally
 * stale to a fixed priority order. Fixed by tracking an actual
 * recency timestamp for radio (written only while radio is truly
 * playing) and comparing it against Spotify's own event timestamp —
 * whichever source was more recently active wins, rather than a
 * static guess.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

define('RADIO_DB', '/var/local/www/db/moode-sqlite3.db');
define('SPOTMETA_FILE', '/var/local/www/spotmeta.json');
define('SPOTSTATE_FILE', '/var/local/www/spotify_playstate.json');
define('RADIO_ACTIVE_TS_FILE', '/tmp/radio_active_ts.txt'); // /tmp is always writable by www-data, unlike /var/local/www (root-owned)
define('SPOT_STALE_SECS', 21600);

function mpd_cmd($sock, $cmd) {
    fwrite($sock, $cmd . "\n");
    $out = '';
    $start = microtime(true);
    while (!feof($sock)) {
        if (microtime(true) - $start > 1.5) break;
        $line = fgets($sock, 1024);
        if ($line === false) break;
        $out .= $line;
        if (strpos($line, 'OK') === 0 || strpos($line, 'ACK') === 0) break;
    }
    return $out;
}

function parse($raw) {
    $d = [];
    foreach (explode("\n", $raw) as $line) {
        if (strpos($line, ': ') !== false) {
            [$k, $v] = explode(': ', $line, 2);
            $d[strtolower(trim($k))] = trim($v);
        }
    }
    return $d;
}

function lookupStationNameFromDB($streamUrl) {
    if (!$streamUrl || !class_exists('SQLite3') || !file_exists(RADIO_DB)) return null;
    try {
        $db = new SQLite3(RADIO_DB, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(500);
        $stmt = $db->prepare('SELECT name FROM cfg_radio WHERE station = :url LIMIT 1');
        $stmt->bindValue(':url', $streamUrl, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $db->close();
        return $row['name'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

function normalize($s) {
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $s));
}

// Returns ['state' => ..., 'ts' => ...] or null
function getSpotifyState() {
    if (!file_exists(SPOTSTATE_FILE)) return null;
    $raw = @file_get_contents(SPOTSTATE_FILE);
    if (!$raw) return null;
    $d = json_decode($raw, true);
    if (!$d || empty($d['state'])) return null;
    $ts = (int)($d['ts'] ?? 0);
    if ($ts && (time() - $ts) > SPOT_STALE_SECS) return null;
    return ['state' => $d['state'], 'ts' => $ts];
}

function getRadioActiveTs() {
    if (!file_exists(RADIO_ACTIVE_TS_FILE)) return 0;
    return (int)trim(@file_get_contents(RADIO_ACTIVE_TS_FILE));
}

function touchRadioActiveTs() {
    @file_put_contents(RADIO_ACTIVE_TS_FILE, (string)time());
}

function populateRadio(&$out, $song, $file) {
    $rawTitle  = $song['title'] ?? '';
    $rawName   = $song['name']  ?? '';
    $streamTag = pathinfo(parse_url($file, PHP_URL_PATH) ?: '', PATHINFO_FILENAME);
    $friendlyStation = lookupStationNameFromDB($file) ?: $rawName;
    $titleLooksReal = $rawTitle
        && normalize($rawTitle) !== normalize($streamTag)
        && normalize($rawTitle) !== normalize($rawName);
    if ($titleLooksReal) {
        $out['title']  = $rawTitle;
        $out['artist'] = $friendlyStation;
    } else {
        $out['title']  = $friendlyStation ?: basename($file);
        $out['artist'] = '';
    }
    $out['station'] = $friendlyStation;
}

function populateSpotify(&$out, $state) {
    if (!file_exists(SPOTMETA_FILE)) return false;
    $meta = json_decode(@file_get_contents(SPOTMETA_FILE), true);
    if (!$meta || empty($meta['title'])) return false;
    $out['state']    = ($state === 'playing') ? 'play' : 'pause';
    $out['source']   = 'spotify';
    $out['title']    = $meta['title']  ?? '';
    $out['artist']   = $meta['artist'] ?? '';
    $out['album']    = $meta['album']  ?? '';
    $out['duration'] = floatval($meta['duration'] ?? 0) / 1000;
    $out['elapsed']  = 0;
    $out['file']     = 'spotify';
    return true;
}

$out = [
    'state'    => 'stop',
    'title'    => '',
    'artist'   => '',
    'album'    => '',
    'station'  => '',
    'file'     => '',
    'source'   => 'local',
    'elapsed'  => 0,
    'duration' => 0,
];

$mpdState = 'stop';
$file = '';
$isRadio = false;
$song = [];
$status = [];

$sock = @fsockopen('127.0.0.1', 6600, $errno, $errstr, 1.5);
if ($sock) {
    stream_set_timeout($sock, 2);
    fgets($sock, 256);
    $status = parse(mpd_cmd($sock, 'status'));
    $song   = parse(mpd_cmd($sock, 'currentsong'));
    @fwrite($sock, "close\n");
    @fclose($sock);

    $mpdState = $status['state'] ?? 'stop';
    $file     = $song['file'] ?? '';
    $isRadio  = (strpos($file, 'http://') === 0 || strpos($file, 'https://') === 0);
}

// Track genuine radio activity — only written while radio is truly
// playing, giving us a real recency signal for the "is this stale"
// comparison against Spotify's own event timestamp.
if ($mpdState === 'play' && $isRadio) {
    touchRadioActiveTs();
}

$spot = getSpotifyState(); // ['state'=>.., 'ts'=>..] or null
$radioStillLoaded = ($mpdState === 'stop' && $isRadio && !empty($file));
$radioActiveTs = getRadioActiveTs();

// ── Priority chain ──────────────────────────────────────────────
if ($mpdState === 'play' || $mpdState === 'pause') {
    // 1. Unambiguous MPD-active state always wins
    $out['state']    = $mpdState;
    $out['file']     = $file;
    $out['elapsed']  = floatval($status['elapsed']  ?? 0);
    $out['duration'] = floatval($status['duration'] ?? 0);
    $out['source']   = $isRadio ? 'radio' : 'local';
    if ($isRadio) { populateRadio($out, $song, $file); }
    else {
        $out['title']  = $song['title']  ?? basename($file);
        $out['artist'] = $song['artist'] ?? ($song['albumartist'] ?? '');
        $out['album']  = $song['album']  ?? '';
    }

} elseif ($spot && $spot['state'] === 'playing') {
    // 2. Spotify actively playing — unambiguous, always wins
    populateSpotify($out, $spot['state']);

} elseif ($radioStillLoaded && (!$spot || $radioActiveTs >= $spot['ts'])) {
    // 3. Radio paused, and it was MORE (or equally) recently active
    //    than whatever Spotify last reported — radio wins
    $out['state']    = 'pause';
    $out['file']     = $file;
    $out['source']   = 'radio';
    populateRadio($out, $song, $file);

} elseif ($spot && $spot['state'] === 'paused') {
    // 4. Spotify paused, and either radio isn't loaded or Spotify's
    //    event is more recent than radio's last known activity
    populateSpotify($out, $spot['state']);
}
// 5. else: nothing active anywhere, defaults (state=stop) stand

echo json_encode($out);
