<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// 把 session 存到临时目录，避免 open_basedir/权限问题
@ini_set('session.save_path', sys_get_temp_dir());
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$inputPassword = (string)($_POST['pw'] ?? $_POST['password'] ?? '');
if ($inputPassword === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Password required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pwPath = __DIR__ . '/pw.json';
if (!file_exists($pwPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'pw.json not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = @file_get_contents($pwPath);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'pw.json read failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = json_decode($raw, true);
if (!is_array($cfg)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'pw.json invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$valid = false;

// 兼容 3 种格式：
// 1) { "password": "123456" }
// 2) { "pw": "123456" }
// 3) { "sha256": "..." }  (输入做 sha256 比较)
if (isset($cfg['password']) && is_string($cfg['password'])) {
    $valid = hash_equals((string)$cfg['password'], $inputPassword);
} elseif (isset($cfg['pw']) && is_string($cfg['pw'])) {
    $valid = hash_equals((string)$cfg['pw'], $inputPassword);
} elseif (isset($cfg['sha256']) && is_string($cfg['sha256'])) {
    $valid = hash_equals((string)$cfg['sha256'], hash('sha256', $inputPassword));
}

if (!$valid) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid password'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_regenerate_id(true);
$_SESSION['admin_authed'] = true;

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
exit;

