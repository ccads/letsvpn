<?php
declare(strict_types=1);

/**
 * 下载入口：
 * - /_dl.php?t=win      -> 下载 Windows 版客户端
 * - /_dl.php?t=android  -> 下载 Android 版客户端
 */

function getClientIp(): string
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $candidates = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
    }
    if (empty($candidates) && !empty($_SERVER['REMOTE_ADDR'])) {
        $candidates = [trim((string)$_SERVER['REMOTE_ADDR'])];
    }
    foreach ($candidates as $ip) {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) continue;
        return $ip;
    }
    return '0.0.0.0';
}

function logClick(string $button, string $ip): void
{
    $logPath = __DIR__ . '/admin/click.log';
    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $fp = @fopen($logPath, 'a+');
    if ($fp === false) return;

    if (flock($fp, LOCK_EX)) {
        $buttonCount = 0;
        rewind($fp);
        while (($line = fgets($fp)) !== false) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 2 && $parts[1] === $button) {
                $buttonCount++;
            }
        }
        
        $nextCount = $buttonCount + 1;
        $ts = date('Y-m-d H:i:s');
        $record = $ts . "\t" . $button . "\t" . $nextCount . "\t" . $ip . "\n";
        
        fseek($fp, 0, SEEK_END);
        fwrite($fp, $record);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';

// 配置映射表
$map = [
    'win' => [
        'mode'          => 'redirect', // 推荐：改为 redirect 模式，实现 CDN 极速下载
        'url'           => 'https://pub-69d11743b91e4742a59585e77fee9a13.r2.dev/letsvpn-latest.zip',
        'download_name' => 'letsvpn-latest.zip',
        'button'        => 'win',
    ],
    'android' => [
        'mode'          => 'redirect', // 推荐：改为 redirect 模式，实现 CDN 极速下载
        'url'           => 'https://pub-69d11743b91e4742a59585e77fee9a13.r2.dev/letsvpn-latest.zip',
        'download_name' => 'letsvpn-latest.zip',
        'button'        => 'android',
    ],
    /* 如果以后需要下载本地文件，可以参考以下配置：
    'local_example' => [
        'mode'          => 'local',
        'file'          => __DIR__ . '/admin/2.apk', 
        'download_name' => '2.apk',
        'content_type'  => 'application/vnd.android.package-archive',
        'button'        => 'android',
    ],
    */
];

if (!isset($map[$t])) {
    http_response_code(404);
    die('Invalid platform parameter.');
}

$info = $map[$t];
$ip = getClientIp();

// 1. 记录日志
logClick($info['button'], $ip);

// 2. 清理缓冲区，防止干扰下载流输出
if (function_exists('ob_end_clean')) {
    while (ob_get_level() > 0) @ob_end_clean();
}

// 3. 根据不同模式执行下载逻辑
if ($info['mode'] === 'redirect') {
    // 【优化方案 A】302 重定向模式
    // 允许服务器只做日志记录，将实际下载流量直接卸载给 Cloudflare R2，速度最快且不占服务器带宽
    header('Location: ' . $info['url']);
    exit;

} elseif ($info['mode'] === 'stream') {
    // 【优化方案 B】高效分块流式下载（如果你必须通过服务器中转、隐藏真实 R2 链接时使用）
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $info['download_name'] . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    // 优化：增加超时控制与 SSL 忽略（防止部分服务器由于 SSL 握手导致卡顿）
    $context = stream_context_create([
        "ssl"  => ["verify_peer" => false, "verify_peer_name" => false],
        "http" => ["timeout" => 120]
    ]);
    
    $handle = @fopen($info['url'], 'rb', false, $context);
    if ($handle !== false) {
        // 每次读取 64KB 缓冲区，并使用 flush 强制发送，避免 readfile 导致的服务器内存溢出和卡顿
        while (!feof($handle)) {
            echo fread($handle, 65536);
            flush(); 
        }
        fclose($handle);
    } else {
        http_response_code(500);
        die('Fetch remote file failed.');
    }
    exit;

} elseif ($info['mode'] === 'local') {
    // 【方案 C】本地文件流式下载逻辑
    $filePath = $info['file'];
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('Download file not found: ' . basename($filePath));
    }

    $size = filesize($filePath) ?: 0;

    header('Content-Type: ' . ($info['content_type'] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($info['download_name']) . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    @readfile($filePath);
    exit;
}