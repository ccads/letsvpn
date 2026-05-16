<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$authed = false;
@ini_set('session.save_path', sys_get_temp_dir());
session_start();
if (!empty($_SESSION['admin_authed'])) {
    $authed = true;
}

if (!$authed) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$period = isset($_GET['period']) ? (string)$_GET['period'] : 'today';
$platform = isset($_GET['platform']) ? (string)$_GET['platform'] : 'all';

$allowedPeriods = ['today', 'yesterday', 'week', 'month', 'all'];
$allowedPlatforms = ['all', 'win', 'android'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'today';
}
if (!in_array($platform, $allowedPlatforms, true)) {
    $platform = 'all';
}

$now = time();
$startTs = null;
$endTs = null;

switch ($period) {
    case 'today':
        $startTs = strtotime(date('Y-m-d 00:00:00', $now));
        $endTs = strtotime(date('Y-m-d 23:59:59', $now));
        break;
    case 'yesterday':
        $startTs = strtotime(date('Y-m-d 00:00:00', strtotime('-1 day', $now)));
        $endTs = strtotime(date('Y-m-d 23:59:59', strtotime('-1 day', $now)));
        break;
    case 'week':
        $weekday = (int)date('N', $now);
        $startTs = strtotime(date('Y-m-d 00:00:00', strtotime('-' . ($weekday - 1) . ' day', $now)));
        $endTs = strtotime(date('Y-m-d 23:59:59', $now));
        break;
    case 'month':
        $startTs = strtotime(date('Y-m-01 00:00:00', $now));
        $endTs = strtotime(date('Y-m-t 23:59:59', $now));
        break;
    case 'all':
    default:
        $startTs = null;
        $endTs = null;
        break;
}

$logPath = __DIR__ . '/click.log';

$result = [
    'ok' => true,
    'generated_at' => date('Y-m-d H:i:s'),
    'filters' => [
        'period' => $period,
        'platform' => $platform,
    ],
    'totals' => [
        'win' => 0,
        'android' => 0,
    ],
    'recent' => [], // 最近若干条：{time, button, count, ip}
    'countsByButton' => [
        'win' => 0,
        'android' => 0,
    ],
];

if (!file_exists($logPath)) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$fp = @fopen($logPath, 'r');
if ($fp === false) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$maxRecent = 200;
while (($line = fgets($fp)) !== false) {
    $line = trim($line);
    if ($line === '') continue;

    $parts = explode("\t", $line);
    if (count($parts) < 4) continue;

    $time = (string)$parts[0];
    $button = (string)$parts[1];
    $count = (int)$parts[2];
    $ip = (string)$parts[3];

    if ($platform !== 'all' && $button !== $platform) {
        continue;
    }

    $eventTs = strtotime($time);
    if ($eventTs === false) {
        continue;
    }
    if ($startTs !== null && $eventTs < $startTs) {
        continue;
    }
    if ($endTs !== null && $eventTs > $endTs) {
        continue;
    }

    if (isset($result['totals'][$button])) {
        $result['totals'][$button]++;
    }
    if (isset($result['countsByButton'][$button])) {
        $result['countsByButton'][$button]++;
    }

    $result['recent'][] = [
        'time' => $time,
        'button' => $button,
        'count' => $count,
        'ip' => $ip,
    ];
    if (count($result['recent']) > $maxRecent) {
        array_shift($result['recent']);
    }
}

fclose($fp);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;

