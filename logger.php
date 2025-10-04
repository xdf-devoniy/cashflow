<?php
// logger.php
date_default_timezone_set("Asia/Tashkent");
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { echo json_encode(['ok'=>false,'error'=>'no data']); exit; }

$device = isset($data['device']) ? substr(strip_tags($data['device']),0,1000) : 'unknown';
$lat = $data['lat'] ?? '';
$lon = $data['lon'] ?? '';
$dist = $data['dist'] ?? '';
$status = $data['status'] ?? 'unknown';
$datetime = $data['datetime'] ?? date('Y-m-d H:i:s');

$logfile = __DIR__ . '/logs.html';
if (!file_exists($logfile)) {
  file_put_contents($logfile, "<html><head><meta charset='utf-8'><title>Access Logs</title></head><body><table border='1' cellspacing='0' cellpadding='5'><tr><th>Date/Time (Tashkent)</th><th>Device</th><th>Location</th><th>Distance</th><th>Status</th></tr>\n");
}

$loc = ($lat !== '' && $lon !== '') ? htmlspecialchars($lat . ',' . $lon, ENT_QUOTES) : 'N/A';
$dist_html = ($dist !== '') ? htmlspecialchars($dist . ' m', ENT_QUOTES) : 'N/A';
$device_html = htmlspecialchars($device, ENT_QUOTES);

$line = "<tr><td>{$datetime}</td><td>{$device_html}</td><td>{$loc}</td><td>{$dist_html}</td><td>{$status}</td></tr>\n";
file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);

echo json_encode(['ok'=>true]);
