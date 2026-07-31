<?php
/**
 * nowplaying-art.php — Cover art server
 *
 * FIX 5: matches nowplaying.php's generalized recency logic — MPD
 * 'play' and Spotify 'playing' are the only unambiguous states;
 * everything else (MPD pause — local or radio-loaded — vs Spotify
 * paused) is decided by comparing which was more recently active.
 * Previously only radio's staleness was compared against Spotify;
 * a long-paused LOCAL file could still incorrectly outrank an
 * actively-used Spotify session.
 */

header('Cache-Control: no-store');

define('RADIO_DB', '/var/local/www/db/moode-sqlite3.db');
define('LOGO_DIR', '/var/local/www/imagesw/radio-logos/');
define('SPOTSTATE_FILE', '/var/local/www/spotify_playstate.json');
define('SPOTCOVER_FILE', '/var/local/www/spotify_cover.jpg');
define('MPD_ACTIVE_TS_FILE', '/tmp/mpd_active_ts.txt');
define('SPOT_STALE_SECS', 21600);

function mpd_query() {
    $sock = @fsockopen('127.0.0.1', 6600, $e, $s, 1.5);
    if (!$sock) return ['file' => '', 'name' => '', 'state' => 'stop'];
    stream_set_timeout($sock, 2);
    fgets($sock, 256);

    fwrite($sock, "status\n");
    $statusOut = '';
    $start = microtime(true);
    while (!feof($sock)) {
        if (microtime(true) - $start > 1.5) break;
        $l = fgets($sock, 1024);
        if ($l === false) break;
        $statusOut .= $l;
        if (strpos($l,'OK')===0 || strpos($l,'ACK')===0) break;
    }

    fwrite($sock, "currentsong\n");
    $songOut = '';
    $start = microtime(true);
    while (!feof($sock)) {
        if (microtime(true) - $start > 1.5) break;
        $l = fgets($sock, 1024);
        if ($l === false) break;
        $songOut .= $l;
        if (strpos($l,'OK')===0 || strpos($l,'ACK')===0) break;
    }

    @fwrite($sock, "close\n");
    @fclose($sock);

    $file = ''; $name = ''; $state = 'stop';
    if (preg_match('/^file:\s*(.+)$/mi', $songOut, $m)) $file = trim($m[1]);
    if (preg_match('/^Name:\s*(.+)$/mi', $songOut, $m))  $name = trim($m[1]);
    if (preg_match('/^state:\s*(\w+)$/mi', $statusOut, $m)) $state = trim($m[1]);

    return ['file' => $file, 'name' => $name, 'state' => $state];
}

function serve($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
    readfile($path);
    exit;
}

function normalize($s) {
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $s));
}

function findLogoFile($stationName) {
    if (!$stationName) return null;
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        $p = LOGO_DIR . $stationName . '.' . $ext;
        if (file_exists($p)) return $p;
    }
    return null;
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

function fuzzyFindLogo($stationName, $streamFile) {
    if (!is_dir(LOGO_DIR)) return null;
    $candidates = [];
    if ($stationName) $candidates[] = normalize($stationName);
    if ($streamFile) {
        $base = pathinfo(parse_url($streamFile, PHP_URL_PATH) ?: '', PATHINFO_FILENAME);
        if ($base) $candidates[] = normalize($base);
    }
    $candidates = array_unique(array_filter($candidates));
    if (!$candidates) return null;
    $files = scandir(LOGO_DIR);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $logoNorm = normalize(pathinfo($f, PATHINFO_FILENAME));
        if (!$logoNorm) continue;
        foreach ($candidates as $c) {
            if ($logoNorm === $c) return LOGO_DIR . $f;
        }
    }
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $logoNorm = normalize(pathinfo($f, PATHINFO_FILENAME));
        if (!$logoNorm) continue;
        foreach ($candidates as $c) {
            if (strlen($c) >= 6 && (strpos($c, $logoNorm) !== false || strpos($logoNorm, $c) !== false)) {
                return LOGO_DIR . $f;
            }
        }
    }
    return null;
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

function serveRadioOrLocal($file, $mpdName, $isRadio) {
    if ($isRadio) {
        $dbName = lookupStationNameFromDB($file);
        if ($dbName) {
            $logo = findLogoFile($dbName);
            if ($logo) serve($logo);
        }
        $logo = fuzzyFindLogo($mpdName, $file);
        if ($logo) serve($logo);
    } elseif ($file) {
        $cached = '/var/local/www/imagesw/thmcache/' . md5(dirname($file)) . '.jpg';
        if (file_exists($cached) && filesize($cached) > 500) serve($cached);
    }
}

function serveSpotifyCover() {
    if (file_exists(SPOTCOVER_FILE) && filesize(SPOTCOVER_FILE) > 500) serve(SPOTCOVER_FILE);
}

$q = mpd_query();
$file     = $q['file'];
$mpdName  = $q['name'];
$mpdState = $q['state'];
$isRadio  = (strpos($file, 'http://') === 0 || strpos($file, 'https://') === 0);

$spot = getSpotifyState();
$radioStillLoaded = ($mpdState === 'stop' && $isRadio && !empty($file));

// Same generalized priority chain as nowplaying.php
if ($mpdState === 'play') {
    touchMpdActiveTs();
    serveRadioOrLocal($file, $mpdName, $isRadio);

} elseif ($spot && $spot['state'] === 'playing') {
    serveSpotifyCover();

} else {
    $mpdCandidateActive = ($mpdState === 'pause') || $radioStillLoaded;
    $mpdTs  = getMpdActiveTs();
    $spotTs = $spot['ts'] ?? 0;

    if ($mpdCandidateActive && (!$spot || $mpdTs >= $spotTs)) {
        serveRadioOrLocal($file, $mpdName, $isRadio);
    } elseif ($spot && $spot['state'] === 'paused') {
        serveSpotifyCover();
    }
}

header('Location: /images/default-album-cover.png');
