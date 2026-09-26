<?php
// ThemGoTalk — expression-of-interest collector.
// Drop this next to the proposal page on your server. Submissions are
// appended to submissions.json as a JSON array.
//
// Protect the data file in production, e.g. Apache:
//   <Files "submissions.json">
//     Require all denied
//   </Files>

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'POST only']);
  exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
  exit;
}

function clean($v, $max = 2000) {
  return mb_substr(trim(strip_tags((string)$v)), 0, $max);
}

$email = clean($body['email'] ?? '', 254);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid email']);
  exit;
}

$entry = [
  'id'           => bin2hex(random_bytes(8)),
  'received_at'  => gmdate('c'),
  'name'         => clean($body['name'] ?? '', 120),
  'organisation' => clean($body['organisation'] ?? '', 200),
  'email'        => $email,
  'interest'     => clean($body['interest'] ?? '', 80),
  'note'         => clean($body['note'] ?? '', 4000),
  'ip'           => clean($_SERVER['REMOTE_ADDR'] ?? '', 64),
  'user_agent'   => clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
];

$file = __DIR__ . '/submissions.json';
$fh = fopen($file, 'c+');
if (!$fh) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Cannot open store']);
  exit;
}

flock($fh, LOCK_EX);
$existing = stream_get_contents($fh);
$list = json_decode($existing ?: '[]', true);
if (!is_array($list)) { $list = []; }
$list[] = $entry;

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

echo json_encode(['ok' => true, 'id' => $entry['id'], 'count' => count($list)]);
