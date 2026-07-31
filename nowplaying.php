<?php
/**
 * nowplaying.php — moOde Now Playing metadata API
 *
 * FIX 5: local pause had the same staleness problem radio pause did.
 * A local track paused hours ago would still outrank an actively-used
 * Spotify session, since "MPD pause" was treated as always-unambiguous.
 * Generalized the recency comparison: MPD 'play' and Spotify 'playing'
 * are the only truly unambiguous states (mutually exclusive in
 * practice, since Spotify connecting stops MPD); everything else
 * (MPD pause — local or radio-loaded — vs Spotify paused) is decided
 * by comparing which was more recently active.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

define('RADIO_DB', '/var/local/www/db/moode-sqlite3.db');
define('SPOTMETA_FILE', '/var/local/www/spotmeta.json');
define('SPOTSTATE_FILE', '/var/local/www/spotify_playstate.json');
// Generalized from radio-specific — tracks ANY genuine MPD 'play' activity,
// local or radio, so it can be compared against Spotify's own timestamp.
define('MPD_ACTIVE_TS_FILE', '/tmp/mpd_active_ts.txt');
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

function getMpdActiveTs() {
    if (!file_exists(MPD_ACTIVE_TS_FILE)) return 0;
    return (int)trim(@file_get_contents(MPD_ACTIVE_TS_FILE));
}

function touchMpdActiveTs() {
    @file_put_contents(MPD_ACTIVE_TS_FILE, (string)time());
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

function populateMpdPaused(&$out, $mpdState, $song, $file, $isRadio, $status) {
    $out['state']    = 'pause';
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

$spot = getSpotifyState();
$radioStillLoaded = ($mpdState === 'stop' && $isRadio && !empty($file));

// ── Priority chain ──────────────────────────────────────────────
if ($mpdState === 'play') {
    // 1. MPD actively playing right now — unambiguous. Track this
    //    moment so a later stale pause can be correctly out-ranked
    //    by a more recent Spotify session.
    touchMpdActiveTs();
    $out['state']    = 'play';
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
    // 2. Spotify actively playing right now — unambiguous, always wins.
    //    (MPD 'play' and Spotify 'playing' shouldn't co-occur — Spotify
    //     connecting stops MPD — so this ordering is safe.)
    populateSpotify($out, $spot['state']);

} else {
    // 3. Nothing is truly "happening right now" — both candidates are
    //    merely paused/idle. Compare which was more recently active.
    $mpdCandidateActive = ($mpdState === 'pause') || $radioStillLoaded;
    $mpdTs  = getMpdActiveTs();
    $spotTs = $spot['ts'] ?? 0;

    if ($mpdCandidateActive && (!$spot || $mpdTs >= $spotTs)) {
        populateMpdPaused($out, $mpdState, $song, $file, $isRadio, $status);
    } elseif ($spot && $spot['state'] === 'paused') {
        populateSpotify($out, $spot['state']);
    }
    // else: nothing active anywhere, defaults (state=stop) stand
}

echo json_encode($out);
