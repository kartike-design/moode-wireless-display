<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

function mpd_cmd($sock, $cmd) {
    fwrite($sock, $cmd . "\n");
    $out = '';
    while (!feof($sock)) {
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
    'cover_url'=> '',
];

// ── Read MPD state first ──────────────────────────────────────
$sock = @fsockopen('127.0.0.1', 6600, $errno, $errstr, 2);
if ($sock) {
    fgets($sock, 256);
    $status = parse(mpd_cmd($sock, 'status'));
    $song   = parse(mpd_cmd($sock, 'currentsong'));
    fwrite($sock, "close\n");
    fclose($sock);

    $file    = $song['file'] ?? '';
    $isRadio = (strpos($file, 'http://') === 0 || strpos($file, 'https://') === 0);

    $out['state']    = $status['state']    ?? 'stop';
    $out['title']    = $song['title']      ?? basename($file);
    $out['artist']   = $song['artist']     ?? ($song['albumartist'] ?? '');
    $out['album']    = $song['album']      ?? '';
    $out['station']  = $song['name']       ?? '';
    $out['file']     = $file;
    $out['elapsed']  = floatval($status['elapsed']  ?? 0);
    $out['duration'] = floatval($status['duration'] ?? 0);
    $out['source']   = $isRadio ? 'radio' : 'local';

    if ($isRadio && empty($out['artist']) && !empty($out['station'])) {
        $out['artist'] = $out['station'];
    }
}

// ── Spotify override ──────────────────────────────────────────
// Rules:
//   1. MPD must NOT be actively playing — if MPD is playing local
//      files or radio, that takes priority and Spotify is skipped.
//
//   2. Spotify play state is read from /var/local/www/spot_state.txt,
//      written by spotevent.sh on every librespot play/pause/stop event.
//      This correctly handles librespot running as a persistent service.
//
//   3. spotmeta.json must be recent (< 600s).

$spotfile = '/var/local/www/spotmeta.json';

$mpdIsIdle = ($out['state'] === 'stop' || $out['state'] === '');

if ($mpdIsIdle) {
    // Check Spotify play state via spotevent.sh state file
    $spot_state_file = '/var/local/www/spot_state.txt';
    $spot_state = file_exists($spot_state_file) ? trim(file_get_contents($spot_state_file)) : 'stopped';
    $spot_state_age = file_exists($spot_state_file) ? (time() - filemtime($spot_state_file)) : 9999;

    if ($spot_state === 'playing' &&
        $spot_state_age < 600 &&
        file_exists($spotfile) &&
        (time() - filemtime($spotfile)) < 600) {

        $spot = json_decode(@file_get_contents($spotfile), true);
        if (!empty($spot['title'])) {
            $out['state']     = 'play';
            $out['source']    = 'spotify';
            $out['title']     = $spot['title']  ?? '';
            $out['artist']    = $spot['artist'] ?? '';
            $out['album']     = $spot['album']  ?? '';
            $out['duration']  = floatval($spot['duration'] ?? 0) / 1000;
            $out['elapsed']   = 0;
            $urls = explode(';', $spot['cover_url'] ?? '');
            $out['cover_url'] = trim($urls[0]);
            $out['file']      = 'spotify';
        }
    }
}

echo json_encode($out);
