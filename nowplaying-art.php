<?php
header('Cache-Control: no-store');

// Fetch current state from the API
$stateJson = @file_get_contents('http://127.0.0.1/nowplaying.php');
$state = $stateJson ? json_decode($stateJson, true) : [];
$source = $state['source'] ?? '';

// ── Spotify: serve cover from cover_url ────────────────────────
if ($source === 'spotify' && !empty($state['cover_url'])) {
    $urls = explode(';', $state['cover_url']);
    $coverUrl = trim($urls[0]);
    $img = @file_get_contents($coverUrl, false, stream_context_create([
        'http' => ['timeout' => 5],
        'ssl'  => ['verify_peer' => false]
    ]));
    if ($img && strlen($img) > 2000) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($img) ?: 'image/jpeg';
        header('Content-Type: ' . $mime);
        echo $img;
        exit;
    }
}

// ── MPD helpers ────────────────────────────────────────────────
function mpd_cmd($cmd) {
    $sock = @fsockopen('127.0.0.1', 6600, $errno, $errstr, 2);
    if (!$sock) return '';
    fgets($sock, 256);
    $out = '';
    fwrite($sock, $cmd . "\n");
    while (!feof($sock)) {
        $l = fgets($sock, 1024);
        if ($l === false) break;
        $out .= $l;
        if (strpos($l, 'OK') === 0 || strpos($l, 'ACK') === 0) break;
    }
    fwrite($sock, "close\n");
    fclose($sock);
    return $out;
}
function parseKV($raw) {
    $data = [];
    foreach (explode("\n", $raw) as $line) {
        if (strpos($line, ': ') !== false) {
            list($k, $v) = explode(': ', $line, 2);
            $data[strtolower(trim($k))] = trim($v);
        }
    }
    return $data;
}

$status = parseKV(mpd_cmd('status'));
$song   = parseKV(mpd_cmd('currentsong'));
$file   = $song['file'] ?? '';
$isRadio = (strpos($file, 'http://') === 0 || strpos($file, 'https://') === 0);
$stationName = $song['name'] ?? '';

// ── Radio logo name normalisation ─────────────────────────────
// Add your own station name mappings here if moOde's stream name
// doesn't match the logo filename in radio-logos/.
// Format: 'Stream name in MPD' => 'Logo filename (without extension)'
$manualMap = [
    // Examples — edit or extend as needed:
    // 'SomaFM Drone Zone' => 'Soma FM - Drone Zone',
    // 'BBC Radio 4'       => 'BBC Radio 4',
];
if (isset($manualMap[$stationName])) {
    $stationName = $manualMap[$stationName];
}

function findRadioLogo($stationName) {
    $logoDir = '/var/local/www/imagesw/radio-logos/';
    if (!is_dir($logoDir)) return null;
    $clean = preg_replace('/\s*\([^)]*\)/', '', $stationName);
    $clean = trim($clean);
    $variants = [
        $stationName, $clean,
        str_replace(' ', '_', $stationName), str_replace(' ', '_', $clean),
        str_replace(' ', '', $stationName), str_replace(' ', '', $clean),
        preg_replace('/[^a-zA-Z0-9]/', '_', $stationName),
        preg_replace('/[^a-zA-Z0-9]/', '_', $clean),
    ];
    foreach ($variants as $v) { $variants[] = strtolower($v); }
    $variants = array_unique($variants);
    $files = scandir($logoDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $base = pathinfo($file, PATHINFO_FILENAME);
        foreach ($variants as $variant) {
            if (strcasecmp($base, $variant) === 0) {
                return $logoDir . $file;
            }
        }
    }
    return null;
}

// ── Local files: use moOde thumbnail cache ─────────────────────
if (!$isRadio && $file && strpos($file, 'http') !== 0) {
    $hash = md5(dirname($file));
    $cached = '/var/local/www/imagesw/thmcache/' . $hash . '.jpg';
    if (file_exists($cached) && filesize($cached) > 2000) {
        header('Content-Type: image/jpeg');
        readfile($cached);
        exit;
    }
}

// ── Radio logos ────────────────────────────────────────────────
if ($isRadio && !empty($stationName)) {
    $logoPath = findRadioLogo($stationName);
    if ($logoPath && file_exists($logoPath)) {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        readfile($logoPath);
        exit;
    }
}

// ── Default cover (1×1 transparent GIF fallback) ───────────────
$defaultPaths = [
    '/var/www/images/default-album-cover.png',
    '/var/www/html/images/default-album-cover.png'
];
foreach ($defaultPaths as $default) {
    if (file_exists($default)) {
        header('Content-Type: ' . mime_content_type($default));
        readfile($default);
        exit;
    }
}
header('Content-Type: image/gif');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
