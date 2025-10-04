<?php
// photo_logger.php
date_default_timezone_set("Asia/Tashkent");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No photo uploaded']); exit;
}

$uploads_dir = __DIR__ . '/uploads';
if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

$device = isset($_POST['device']) ? substr(strip_tags($_POST['device']),0,1000) : 'unknown';
$lat = $_POST['lat'] ?? '';
$lon = $_POST['lon'] ?? '';
$dist = $_POST['dist'] ?? '';

$ts = date('Ymd_His');
$orig = $_FILES['photo']['name'];
$ext = pathinfo($orig, PATHINFO_EXTENSION);
if (!$ext) $ext = 'jpg';
$fname = "photo_{$ts}_" . bin2hex(random_bytes(4)) . "." . preg_replace('/[^a-z0-9]/i','', $ext);
$target = $uploads_dir . '/' . $fname;

if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)){
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to save photo']); exit;
}

$logfile = __DIR__ . '/logs.html';
if (!file_exists($logfile)) {
    file_put_contents($logfile, "<html><head><meta charset='utf-8'><title>Access Logs</title></head><body><table border='1' cellspacing='0' cellpadding='5'><tr><th>Date/Time (Tashkent)</th><th>Device</th><th>Location</th><th>Distance</th><th>Status</th><th>Photo</th></tr>\n");
}

$datetime = date('Y-m-d H:i:s');
$device_html = htmlspecialchars($device, ENT_QUOTES);
$loc = ($lat !== '' && $lon !== '') ? htmlspecialchars($lat . ',' . $lon, ENT_QUOTES) : 'N/A';
$dist_html = ($dist !== '') ? htmlspecialchars($dist . ' m', ENT_QUOTES) : 'N/A';
$photo_url = 'uploads/' . basename($target);

$line = "<tr><td>{$datetime}</td><td>{$device_html}</td><td>{$loc}</td><td>{$dist_html}</td><td>photo-captured</td><td><a href=\"{$photo_url}\" target=\"_blank\"><img src=\"{$photo_url}\" style=\"max-width:120px;max-height:80px;\"/></a></td></tr>\n";
file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);

echo json_encode(['ok'=>true,'file'=>$photo_url,'datetime'=>$datetime]);
exit;
