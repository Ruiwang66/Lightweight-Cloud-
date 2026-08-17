<?php
/**
 * Simple Single-File PHP Cloud Disk
 * 单文件、免登录的轻量云盘
 */

// ================= 配置区域 =================
$siteTitle = "锐王的极速云盘";
$maxFileSize = 50 * 1024 * 1024;
$maxStoredFiles = 500;
$maxStorageSize = 5 * 1024 * 1024 * 1024;

// 推荐在服务器环境变量 CLOUD_DISK_ADMIN_PASSWORD_HASH 中配置 password_hash() 生成的值。
// 下方仅保留原管理密码的不可逆哈希，浏览器端和 HTML 中不会出现密码或哈希。
$adminPasswordHash = getenv('CLOUD_DISK_ADMIN_PASSWORD_HASH') ?: '$2y$10$RA.wKZzXht/P7YvcJT1fp.WF0rec.pTGG7RhMXXOtAL6VqkJLcnSC';

// 采用白名单而非黑名单；文件会以无扩展名随机名称私有存储，并始终作为附件下载。
$allowedExtensions = array_fill_keys([
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'avif', 'svg', 'ico',
    'pdf', 'txt', 'md', 'csv', 'rtf', 'epub', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'odt', 'ods', 'odp', 'pages', 'numbers', 'key',
    'mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'wma',
    'mp4', 'mov', 'mkv', 'avi', 'webm', 'mpeg', 'mpg', 'm4v',
    'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
    'psd', 'ai', 'xd', 'sketch', 'fig', 'blend', 'obj', 'fbx', 'stl', 'dwg', 'dxf',
    'ttf', 'otf', 'woff', 'woff2', 'json', 'xml', 'yaml', 'yml'
], true);
// ===========================================

date_default_timezone_set('Asia/Shanghai');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('rw_cloud_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$cspNonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; style-src 'nonce-{$cspNonce}'; script-src 'nonce-{$cspNonce}'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if ($isHttps) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Cache-Control: no-store, max-age=0');

$dataRoot = __DIR__ . DIRECTORY_SEPARATOR . '.cloud_disk_data' . DIRECTORY_SEPARATOR;
$storageDir = $dataRoot . 'files' . DIRECTORY_SEPARATOR;
$rateLimitDir = $dataRoot . 'rate_limits' . DIRECTORY_SEPARATOR;
$metadataFile = $dataRoot . 'metadata.php';
$metadataLockFile = $dataRoot . 'metadata.lock';
$legacyUploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

foreach ([$dataRoot, $storageDir, $rateLimitDir, $legacyUploadDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
        http_response_code(500);
        die('<h3>服务器存储目录初始化失败，请检查写入权限。</h3>');
    }
}

// Apache / IIS 会直接拒绝访问私有存储；随机无扩展名存储则作为额外的纵深保护。
@file_put_contents($dataRoot . '.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
@file_put_contents($dataRoot . 'web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>', LOCK_EX);
@file_put_contents($dataRoot . 'index.html', '', LOCK_EX);
@file_put_contents($storageDir . '.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
@file_put_contents($storageDir . 'index.html', '', LOCK_EX);
@file_put_contents($rateLimitDir . '.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
@file_put_contents($rateLimitDir . 'index.html', '', LOCK_EX);
@file_put_contents($legacyUploadDir . '.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n", LOCK_EX);
@file_put_contents($legacyUploadDir . 'web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>', LOCK_EX);
@file_put_contents($legacyUploadDir . 'index.html', '', LOCK_EX);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatSize($bytes)
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function loadMetadata($metadataFile)
{
    if (!is_file($metadataFile)) return [];
    $json = file_get_contents($metadataFile);
    if ($json === false || trim($json) === '') return [];
    $separator = strpos($json, "\n");
    if ($separator !== false && strpos($json, '<?php') === 0) {
        $json = substr($json, $separator + 1);
    }
    $records = json_decode($json, true);
    return is_array($records) ? $records : [];
}

function saveMetadata($metadataFile, array $records)
{
    $json = json_encode(array_values($records), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $protectedJson = "<?php http_response_code(404); exit; ?>\n" . $json;
    if ($json === false || file_put_contents($metadataFile, $protectedJson, LOCK_EX) === false) {
        throw new RuntimeException('无法更新文件索引。');
    }
    @chmod($metadataFile, 0640);
}

function withExclusiveLock($lockFile, callable $callback)
{
    $handle = fopen($lockFile, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('无法获取存储锁。');
    }
    try {
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function detectedMimeType($path)
{
    if (!class_exists('finfo')) return 'application/octet-stream';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
}

function safeOriginalName($name)
{
    $name = trim(basename(str_replace('\\', '/', (string) $name)));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    return trim((string) $name, ". \t\n\r\0\x0B");
}

function rateLimitAllowed($rateLimitDir, $bucket, $limit, $windowSeconds)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $key = hash('sha256', $bucket . '|' . $ip);
    $file = $rateLimitDir . $key . '.json';
    $handle = fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) return false;

    $raw = stream_get_contents($handle);
    $timestamps = json_decode($raw ?: '[]', true);
    if (!is_array($timestamps)) $timestamps = [];
    $now = time();
    $timestamps = array_values(array_filter($timestamps, function ($timestamp) use ($now, $windowSeconds) {
        return is_numeric($timestamp) && (int) $timestamp > $now - $windowSeconds;
    }));
    $allowed = count($timestamps) < $limit;
    if ($allowed) $timestamps[] = $now;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($timestamps));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $allowed;
}

function findRecordById(array $records, $id)
{
    foreach ($records as $index => $record) {
        if (isset($record['id']) && hash_equals((string) $record['id'], (string) $id)) {
            return [$index, $record];
        }
    }
    return [null, null];
}

function downloadDisposition($fileName)
{
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
    if ($fallback === '') $fallback = 'download';
    return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
}

function flashAndRedirect($message, $type = 'success')
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . ($redirect ?: $_SERVER['PHP_SELF']));
    exit;
}

function uploadErrorMessage($code)
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => '文件超过了服务器允许的上传大小。',
        UPLOAD_ERR_FORM_SIZE => '文件超过了页面允许的上传大小。',
        UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试。',
        UPLOAD_ERR_NO_FILE => '请先选择需要上传的文件。',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录。',
        UPLOAD_ERR_CANT_WRITE => '服务器无法写入文件。',
        UPLOAD_ERR_EXTENSION => '上传被服务器扩展程序中止。',
    ];
    return isset($messages[$code]) ? $messages[$code] : '上传出错，错误码：' . $code;
}

function fileKind($extension)
{
    $extension = strtolower($extension);
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic', 'avif', 'ico'], true)) return 'image';
    if (in_array($extension, ['mp4', 'mov', 'mkv', 'avi', 'webm', 'mpeg', 'mpg', 'm4v'], true)) return 'video';
    if (in_array($extension, ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'wma'], true)) return 'audio';
    if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'], true)) return 'archive';
    if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'rtf', 'epub', 'odt', 'ods', 'odp', 'pages', 'numbers', 'key'], true)) return 'document';
    return 'other';
}

// 将旧版公开 uploads 目录中的文件一次性迁移到随机命名的私有存储中。
withExclusiveLock($metadataLockFile, function () use ($metadataFile, $legacyUploadDir, $storageDir) {
    $records = loadMetadata($metadataFile);
    $changed = false;
    foreach (scandir($legacyUploadDir) as $legacyName) {
        if (in_array($legacyName, ['.', '..', '.htaccess', 'web.config', 'index.html'], true)) continue;
        $legacyPath = $legacyUploadDir . $legacyName;
        if (!is_file($legacyPath)) continue;

        $publicId = bin2hex(random_bytes(20));
        $storageName = bin2hex(random_bytes(32));
        $target = $storageDir . $storageName;
        if (!@rename($legacyPath, $target)) {
            if (!@copy($legacyPath, $target) || !@unlink($legacyPath)) continue;
        }
        @chmod($target, 0640);
        $safeName = safeOriginalName($legacyName);
        $records[] = [
            'id' => $publicId,
            'name' => $safeName !== '' ? $safeName : 'legacy-file',
            'storage' => $storageName,
            'size' => filesize($target),
            'time' => filemtime($target),
            'extension' => strtolower(pathinfo($safeName, PATHINFO_EXTENSION)),
            'mime' => detectedMimeType($target),
        ];
        $changed = true;
    }
    if ($changed) saveMetadata($metadataFile, $records);
});

$records = loadMetadata($metadataFile);

// 所有下载均由 PHP 校验随机 ID 后输出，不暴露私有存储路径。
if (isset($_GET['download'])) {
    $downloadId = strtolower((string) $_GET['download']);
    if (!preg_match('/^[a-f0-9]{40}$/', $downloadId) || !rateLimitAllowed($rateLimitDir, 'download', 180, 60)) {
        http_response_code(429);
        exit('请求过于频繁或链接无效。');
    }
    list(, $record) = findRecordById($records, $downloadId);
    $storageName = is_array($record) && isset($record['storage']) ? basename($record['storage']) : '';
    $target = $storageDir . $storageName;
    if (!$record || !preg_match('/^[a-f0-9]{64}$/', $storageName) || !is_file($target)) {
        http_response_code(404);
        exit('文件不存在。');
    }

    $originalName = safeOriginalName($record['name']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: ' . downloadDisposition($originalName));
    header('Content-Length: ' . filesize($target));
    header('Cache-Control: private, no-store, max-age=0');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($target);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!hash_equals($csrfToken, $postedToken)) {
        flashAndRedirect('页面已过期，请刷新后重试。', 'danger');
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'upload' && isset($_FILES['file'])) {
        if (!rateLimitAllowed($rateLimitDir, 'upload', 20, 3600)) {
            flashAndRedirect('上传过于频繁，请稍后再试。', 'danger');
        }
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            flashAndRedirect(uploadErrorMessage($file['error']), 'danger');
        }

        $originalName = safeOriginalName($file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $nameLength = function_exists('mb_strlen') ? mb_strlen($originalName, 'UTF-8') : strlen($originalName);
        if ($originalName === '' || $nameLength > 180) {
            flashAndRedirect('文件名无效，请重命名后再试。', 'danger');
        }
        if ($extension === '' || !isset($allowedExtensions[$extension])) {
            flashAndRedirect('此格式不在安全白名单中，请使用常见文档、图片、媒体、设计或压缩格式。', 'danger');
        }
        if (!is_uploaded_file($file['tmp_name']) || $file['size'] <= 0 || $file['size'] > $maxFileSize) {
            flashAndRedirect('文件过大，单个文件不能超过 ' . formatSize($maxFileSize) . '。', 'danger');
        }

        $mime = detectedMimeType($file['tmp_name']);
        $blockedMimes = ['application/x-httpd-php', 'text/x-php', 'application/x-dosexec', 'application/x-msdownload', 'application/x-sh', 'text/x-shellscript'];
        $head = file_get_contents($file['tmp_name'], false, null, 0, 4);
        if (in_array($mime, $blockedMimes, true) || $head === "\x7FELF" || substr((string) $head, 0, 2) === 'MZ') {
            flashAndRedirect('检测到可执行文件内容，已拒绝上传。', 'danger');
        }

        $currentCount = count($records);
        $currentSize = array_sum(array_map(function ($record) {
            return isset($record['size']) ? (int) $record['size'] : 0;
        }, $records));
        if ($currentCount >= $maxStoredFiles || $currentSize + $file['size'] > $maxStorageSize) {
            flashAndRedirect('网盘存储空间已达到安全上限，请先清理旧文件。', 'danger');
        }

        $publicId = bin2hex(random_bytes(20));
        $storageName = bin2hex(random_bytes(32));
        $target = $storageDir . $storageName;
        if (move_uploaded_file($file['tmp_name'], $target)) {
            @chmod($target, 0640);
            try {
                withExclusiveLock($metadataLockFile, function () use ($metadataFile, $publicId, $storageName, $originalName, $extension, $mime, $target) {
                    $latestRecords = loadMetadata($metadataFile);
                    $latestRecords[] = [
                        'id' => $publicId,
                        'name' => $originalName,
                        'storage' => $storageName,
                        'size' => filesize($target),
                        'time' => time(),
                        'extension' => $extension,
                        'mime' => $mime,
                    ];
                    saveMetadata($metadataFile, $latestRecords);
                });
                flashAndRedirect('“' . $originalName . '”上传成功。');
            } catch (Throwable $error) {
                @unlink($target);
                flashAndRedirect('上传索引写入失败，请稍后重试。', 'danger');
            }
        }
        flashAndRedirect('上传失败，请检查私有存储目录的写入权限。', 'danger');
    }

    if ($action === 'delete' && !empty($adminPasswordHash)) {
        $fileId = isset($_POST['file_id']) ? strtolower((string) $_POST['file_id']) : '';
        $pass = isset($_POST['admin_pass']) ? (string) $_POST['admin_pass'] : '';

        if (!rateLimitAllowed($rateLimitDir, 'delete', 10, 900) || !password_verify($pass, $adminPasswordHash)) {
            usleep(random_int(180000, 420000));
            flashAndRedirect('管理密码错误，未能删除文件。', 'danger');
        }

        if (!preg_match('/^[a-f0-9]{40}$/', $fileId)) {
            flashAndRedirect('文件标识无效。', 'danger');
        }

        try {
            $deletedName = withExclusiveLock($metadataLockFile, function () use ($metadataFile, $storageDir, $fileId) {
                $latestRecords = loadMetadata($metadataFile);
                list($index, $record) = findRecordById($latestRecords, $fileId);
                if ($record === null) return null;
                $storageName = isset($record['storage']) ? basename($record['storage']) : '';
                if (!preg_match('/^[a-f0-9]{64}$/', $storageName)) return null;
                $target = $storageDir . $storageName;
                if (is_file($target) && !@unlink($target)) {
                    throw new RuntimeException('文件删除失败。');
                }
                unset($latestRecords[$index]);
                saveMetadata($metadataFile, $latestRecords);
                return $record['name'];
            });
            if ($deletedName !== null) flashAndRedirect('“' . $deletedName . '”已删除。');
            flashAndRedirect('文件不存在，可能已经被删除。', 'danger');
        } catch (Throwable $error) {
            flashAndRedirect('删除失败，请检查私有存储权限。', 'danger');
        }
    }
}

$message = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : '';
$messageType = isset($_SESSION['flash_type']) ? $_SESSION['flash_type'] : '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$records = loadMetadata($metadataFile);
$files = [];
$totalSize = 0;
foreach ($records as $record) {
    if (!isset($record['id'], $record['name'], $record['storage'])) continue;
    $storageName = basename($record['storage']);
    if (!preg_match('/^[a-f0-9]{64}$/', $storageName)) continue;
    $path = $storageDir . $storageName;
    if (!is_file($path)) continue;
    $size = filesize($path);
    $extension = isset($record['extension']) ? strtolower($record['extension']) : strtolower(pathinfo($record['name'], PATHINFO_EXTENSION));
    $files[] = [
        'id' => $record['id'],
        'name' => $record['name'],
        'size' => $size,
        'time' => isset($record['time']) ? (int) $record['time'] : filemtime($path),
        'extension' => $extension,
        'kind' => fileKind($extension),
        'url' => '?download=' . rawurlencode($record['id']),
    ];
    $totalSize += $size;
}

usort($files, function ($a, $b) {
    return $b['time'] - $a['time'];
});

$latestTime = count($files) ? $files[0]['time'] : null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#5b5ce2">
    <meta name="description" content="简单、快速、免登录的文件分享空间">
    <title><?php echo h($siteTitle); ?></title>
    <style nonce="<?php echo h($cspNonce); ?>">
        :root {
            --brand: #5b5ce2;
            --brand-dark: #4445c7;
            --brand-soft: #eeeeff;
            --cyan: #39b8d6;
            --ink: #172033;
            --muted: #6f7890;
            --line: #e8eaf1;
            --surface: rgba(255, 255, 255, .92);
            --danger: #df4d67;
            --danger-soft: #fff0f3;
            --success: #168b6a;
            --success-soft: #eafaf4;
            --shadow: 0 18px 55px rgba(41, 46, 92, .10);
            --radius-lg: 24px;
            --radius-md: 16px;
        }

        * { box-sizing: border-box; }

        [hidden] { display: none !important; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            background:
                radial-gradient(circle at 8% 4%, rgba(91, 92, 226, .12), transparent 28rem),
                radial-gradient(circle at 92% 18%, rgba(57, 184, 214, .10), transparent 24rem),
                #f7f8fc;
            font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        button, input { font: inherit; }
        a { color: inherit; }

        .shell {
            width: min(1120px, calc(100% - 40px));
            margin: 0 auto;
        }

        .site-header {
            position: relative;
            overflow: hidden;
            padding: 28px 0 116px;
            color: #fff;
            background: linear-gradient(128deg, #4b4dc9 0%, #6466e8 48%, #32afcc 115%);
        }

        .site-header::before,
        .site-header::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            pointer-events: none;
        }

        .site-header::before {
            width: 420px;
            height: 420px;
            right: -120px;
            top: -230px;
            border: 1px solid rgba(255, 255, 255, .18);
            box-shadow: 0 0 0 70px rgba(255, 255, 255, .035), 0 0 0 140px rgba(255, 255, 255, .025);
        }

        .site-header::after {
            width: 260px;
            height: 260px;
            left: 6%;
            bottom: -210px;
            background: rgba(255, 255, 255, .07);
            filter: blur(2px);
        }

        .topbar {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 750;
            letter-spacing: -.02em;
        }

        .brand-mark {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border: 1px solid rgba(255, 255, 255, .28);
            border-radius: 13px;
            background: rgba(255, 255, 255, .15);
            box-shadow: inset 0 1px rgba(255, 255, 255, .18);
            backdrop-filter: blur(10px);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 13px;
            border: 1px solid rgba(255, 255, 255, .25);
            border-radius: 999px;
            color: rgba(255, 255, 255, .92);
            background: rgba(255, 255, 255, .11);
            font-size: 13px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #77f1c2;
            box-shadow: 0 0 0 4px rgba(119, 241, 194, .15);
        }

        .hero {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 48px;
            margin-top: 70px;
        }

        .eyebrow {
            margin: 0 0 13px;
            color: rgba(255, 255, 255, .74);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .hero h1 {
            max-width: 680px;
            margin: 0;
            font-size: clamp(34px, 5vw, 54px);
            line-height: 1.12;
            letter-spacing: -.045em;
        }

        .hero-copy {
            max-width: 610px;
            margin: 20px 0 0;
            color: rgba(255, 255, 255, .76);
            font-size: 16px;
            line-height: 1.8;
        }

        .hero-stats {
            display: flex;
            gap: 10px;
        }

        .stat {
            min-width: 120px;
            padding: 17px 18px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 17px;
            background: rgba(255, 255, 255, .10);
            backdrop-filter: blur(12px);
        }

        .stat-value {
            display: block;
            margin-bottom: 5px;
            font-size: 19px;
            font-weight: 760;
        }

        .stat-label {
            color: rgba(255, 255, 255, .65);
            font-size: 12px;
        }

        main {
            position: relative;
            z-index: 2;
            margin-top: -76px;
            padding-bottom: 42px;
        }

        .panel {
            border: 1px solid rgba(229, 231, 240, .9);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .upload-panel { padding: 12px; }

        .upload-zone {
            position: relative;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 22px;
            min-height: 134px;
            padding: 26px 30px;
            overflow: hidden;
            border: 1.5px dashed #cfd3e7;
            border-radius: 17px;
            background: linear-gradient(110deg, #fbfbff, #f6fbfd);
            transition: border-color .2s ease, background .2s ease, transform .2s ease;
            cursor: pointer;
        }

        .upload-zone:hover,
        .upload-zone.is-dragging {
            border-color: var(--brand);
            background: linear-gradient(110deg, #f6f5ff, #f0fbfd);
        }

        .upload-zone.is-dragging { transform: scale(.995); }

        .upload-zone.is-uploading { pointer-events: none; }

        .upload-zone.is-uploading::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: 0;
            width: 35%;
            height: 3px;
            border-radius: 99px;
            background: linear-gradient(90deg, var(--brand), var(--cyan));
            animation: uploading 1.1s ease-in-out infinite;
        }

        @keyframes uploading {
            from { transform: translateX(-100%); }
            to { transform: translateX(385%); }
        }

        .upload-input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .upload-icon {
            display: grid;
            place-items: center;
            width: 66px;
            height: 66px;
            border-radius: 20px;
            color: var(--brand);
            background: linear-gradient(145deg, #eaeaff, #f2f2ff);
            box-shadow: inset 0 0 0 1px rgba(91, 92, 226, .07);
        }

        .upload-title {
            display: block;
            margin: 0 0 8px;
            font-size: 17px;
            font-weight: 730;
            letter-spacing: -.01em;
        }

        .upload-hint {
            display: block;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .choose-button {
            padding: 11px 18px;
            border-radius: 12px;
            color: #fff;
            background: var(--brand);
            box-shadow: 0 8px 20px rgba(91, 92, 226, .22);
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            transition: background .2s ease, transform .2s ease;
        }

        .upload-zone:hover .choose-button {
            background: var(--brand-dark);
            transform: translateY(-1px);
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            margin: 0 0 18px;
            padding: 14px 16px;
            border: 1px solid;
            border-radius: 14px;
            font-size: 14px;
            line-height: 1.5;
            box-shadow: 0 8px 24px rgba(41, 46, 92, .07);
        }

        .alert-success { color: var(--success); border-color: #c8ebdf; background: var(--success-soft); }
        .alert-danger { color: #be334e; border-color: #f5ccd4; background: var(--danger-soft); }

        .alert-close {
            margin: -2px -3px 0 auto;
            padding: 2px 6px;
            border: 0;
            color: currentColor;
            background: transparent;
            font-size: 20px;
            line-height: 1;
            opacity: .65;
            cursor: pointer;
        }

        .files-panel { margin-top: 22px; overflow: hidden; }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 23px 26px;
            border-bottom: 1px solid var(--line);
        }

        .panel-title-wrap { min-width: 0; }

        .panel-title {
            margin: 0;
            font-size: 18px;
            letter-spacing: -.02em;
        }

        .panel-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .search {
            position: relative;
            width: min(290px, 42vw);
        }

        .search svg {
            position: absolute;
            left: 13px;
            top: 50%;
            color: #9299ab;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            height: 42px;
            padding: 0 14px 0 40px;
            border: 1px solid var(--line);
            border-radius: 12px;
            outline: none;
            color: var(--ink);
            background: #f8f9fc;
            font-size: 13px;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .search-input:focus {
            border-color: rgba(91, 92, 226, .45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(91, 92, 226, .08);
        }

        .file-list { padding: 7px 14px 12px; }

        .list-header,
        .file-row {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 110px 165px 162px;
            align-items: center;
            gap: 20px;
        }

        .list-header {
            min-height: 45px;
            padding: 0 14px;
            color: #9399aa;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .file-row {
            min-height: 72px;
            padding: 9px 14px;
            border-radius: 14px;
            transition: background .18s ease, transform .18s ease;
        }

        .file-row:hover { background: #f8f8fc; }

        .file-main {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 13px;
        }

        .file-icon {
            position: relative;
            flex: 0 0 auto;
            display: grid;
            place-items: center;
            width: 43px;
            height: 47px;
            border-radius: 10px 10px 12px 12px;
            color: #6b6dda;
            background: #ededff;
        }

        .file-icon::after {
            content: "";
            position: absolute;
            right: 0;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 0 9px 0 6px;
            background: rgba(255, 255, 255, .75);
        }

        .file-icon.image { color: #2f9e73; background: #e8f8f1; }
        .file-icon.video { color: #db5871; background: #fff0f3; }
        .file-icon.audio { color: #b87924; background: #fff5e5; }
        .file-icon.archive { color: #9465cb; background: #f5edff; }
        .file-icon.document { color: #437ed1; background: #eaf3ff; }

        .file-info { min-width: 0; }

        .file-name {
            display: block;
            overflow: hidden;
            color: #242c3e;
            font-size: 14px;
            font-weight: 660;
            line-height: 1.45;
            text-decoration: none;
            text-overflow: ellipsis;
            white-space: nowrap;
            transition: color .18s ease;
        }

        .file-name:hover { color: var(--brand); }

        .file-ext {
            display: block;
            margin-top: 3px;
            color: #a0a6b5;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .file-meta {
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .file-actions {
            display: flex;
            justify-content: flex-end;
            gap: 7px;
        }

        .icon-button {
            display: grid;
            place-items: center;
            width: 35px;
            height: 35px;
            padding: 0;
            border: 1px solid var(--line);
            border-radius: 10px;
            outline: none;
            color: #737b91;
            background: #fff;
            text-decoration: none;
            cursor: pointer;
            transition: color .18s ease, border-color .18s ease, background .18s ease, transform .18s ease;
        }

        .icon-button:hover {
            color: var(--brand);
            border-color: #cfd0f4;
            background: var(--brand-soft);
            transform: translateY(-1px);
        }

        .icon-button.danger:hover {
            color: var(--danger);
            border-color: #f3cbd3;
            background: var(--danger-soft);
        }

        .empty-state {
            padding: 72px 20px 76px;
            color: var(--muted);
            text-align: center;
        }

        .empty-visual {
            display: grid;
            place-items: center;
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            border-radius: 22px;
            color: var(--brand);
            background: var(--brand-soft);
        }

        .empty-state h3 { margin: 0 0 8px; color: var(--ink); font-size: 16px; }
        .empty-state p { margin: 0; font-size: 13px; }
        .search-empty { display: none; }
        .search-empty.is-visible { display: block; }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 26px 2px 0;
            color: #9299aa;
            font-size: 12px;
        }

        .footer-note { display: flex; align-items: center; gap: 7px; }
        .footer-note svg { color: var(--success); }

        dialog {
            width: min(430px, calc(100% - 32px));
            padding: 0;
            border: 0;
            border-radius: 22px;
            color: var(--ink);
            background: #fff;
            box-shadow: 0 28px 90px rgba(23, 32, 51, .25);
        }

        dialog::backdrop {
            background: rgba(24, 28, 45, .48);
            backdrop-filter: blur(5px);
        }

        .dialog-body { padding: 26px; }

        .dialog-icon {
            display: grid;
            place-items: center;
            width: 48px;
            height: 48px;
            margin-bottom: 17px;
            border-radius: 15px;
            color: var(--danger);
            background: var(--danger-soft);
        }

        .dialog-title { margin: 0 0 8px; font-size: 19px; }
        .dialog-copy { margin: 0 0 20px; color: var(--muted); font-size: 13px; line-height: 1.7; }
        .dialog-file { color: var(--ink); font-weight: 700; word-break: break-all; }

        .field-label {
            display: block;
            margin-bottom: 7px;
            color: #4d5569;
            font-size: 12px;
            font-weight: 700;
        }

        .password-input {
            width: 100%;
            height: 45px;
            padding: 0 13px;
            border: 1px solid var(--line);
            border-radius: 11px;
            outline: none;
            background: #fafafd;
        }

        .password-input:focus {
            border-color: rgba(91, 92, 226, .45);
            box-shadow: 0 0 0 4px rgba(91, 92, 226, .08);
        }

        .dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        .button {
            min-width: 82px;
            height: 41px;
            padding: 0 16px;
            border: 1px solid var(--line);
            border-radius: 11px;
            color: #596075;
            background: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .button-danger { border-color: var(--danger); color: #fff; background: var(--danger); }

        .copy-toast {
            position: fixed;
            z-index: 20;
            left: 50%;
            bottom: 26px;
            padding: 11px 16px;
            border-radius: 11px;
            color: #fff;
            background: #202638;
            box-shadow: 0 12px 32px rgba(23, 32, 51, .22);
            font-size: 13px;
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 12px);
            transition: opacity .2s ease, transform .2s ease;
        }

        .copy-toast.show { opacity: 1; transform: translate(-50%, 0); }

        .clipboard-helper {
            position: fixed;
            left: -9999px;
            top: 0;
            opacity: 0;
            pointer-events: none;
        }

        .announcement {
            position: fixed;
            z-index: 12;
            right: 20px;
            top: 50%;
            width: 224px;
            padding: 17px 18px;
            border: 1px solid rgba(91, 92, 226, .14);
            border-radius: 17px;
            background: rgba(255, 255, 255, .94);
            box-shadow: 0 18px 48px rgba(41, 46, 92, .16);
            backdrop-filter: blur(16px);
            transform: translateY(-50%);
        }

        .announcement-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: var(--brand);
            font-size: 12px;
            font-weight: 760;
            letter-spacing: .08em;
        }

        .announcement-dot {
            position: relative;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 0 5px rgba(91, 92, 226, .10);
        }

        .announcement p {
            margin: 0;
            color: #41495d;
            font-size: 14px;
            font-weight: 650;
            line-height: 1.7;
        }

        @media (max-width: 1280px) {
            .announcement {
                right: 14px;
                top: auto;
                bottom: 18px;
                width: 210px;
                transform: none;
            }
        }

        @media (max-width: 860px) {
            .site-header { padding-bottom: 104px; }
            .hero { grid-template-columns: 1fr; gap: 28px; margin-top: 54px; }
            .hero-stats { width: 100%; }
            .stat { flex: 1; }
            .list-header { display: none; }
            .file-list { padding-top: 12px; }
            .file-row { grid-template-columns: minmax(0, 1fr) auto; gap: 10px 18px; min-height: 82px; border-bottom: 1px solid #f0f1f5; border-radius: 12px; }
            .file-row:last-of-type { border-bottom: 0; }
            .file-row .file-meta { display: none; }
            .file-actions { grid-column: 2; grid-row: 1; }
        }

        @media (max-width: 620px) {
            .shell { width: min(100% - 24px, 1120px); }
            .site-header { padding-top: 20px; padding-bottom: 94px; }
            .brand { font-size: 15px; }
            .brand-mark { width: 38px; height: 38px; border-radius: 12px; }
            .status-pill { padding: 7px 10px; font-size: 11px; }
            .hero { margin-top: 47px; }
            .hero h1 { font-size: 36px; }
            .hero-copy { margin-top: 15px; font-size: 14px; line-height: 1.7; }
            .hero-stats { gap: 8px; }
            .stat { min-width: 0; padding: 13px 12px; }
            .stat-value { font-size: 16px; }
            main { margin-top: -62px; }
            .upload-panel { padding: 9px; border-radius: 20px; }
            .upload-zone { grid-template-columns: auto 1fr; gap: 15px; min-height: 120px; padding: 22px 18px; }
            .upload-icon { width: 52px; height: 52px; border-radius: 16px; }
            .choose-button { display: none; }
            .upload-title { font-size: 15px; }
            .files-panel { margin-top: 16px; border-radius: 20px; }
            .panel-head { align-items: stretch; flex-direction: column; gap: 16px; padding: 20px; }
            .search { width: 100%; }
            .file-list { padding: 8px; }
            .file-row { padding: 12px 9px; }
            .file-icon { width: 39px; height: 43px; }
            .file-actions { gap: 5px; }
            .icon-button.copy-action { display: none; }
            .footer { align-items: flex-start; flex-direction: column; padding-left: 4px; }
            .announcement {
                right: 12px;
                bottom: 12px;
                width: min(210px, calc(100% - 24px));
                padding: 14px 16px;
                border-radius: 15px;
            }
            .announcement-head { margin-bottom: 7px; }
            .announcement p { font-size: 13px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="shell">
        <div class="topbar">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M7.4 18.1h9.2a4.4 4.4 0 0 0 .55-8.76A5.65 5.65 0 0 0 6.42 8.1 4.99 4.99 0 0 0 7.4 18.1Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m9.5 12 2.5-2.5 2.5 2.5M12 9.8v5.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span><?php echo h($siteTitle); ?></span>
            </div>
            <span class="status-pill"><span class="status-dot"></span>服务正常</span>
        </div>

        <div class="hero">
            <div>
                <p class="eyebrow">Simple · Fast · Private</p>
                <h1>让文件分享，<br>简单一点。</h1>
                <p class="hero-copy">无需注册登录，选择文件即可快速上传。一个轻量、清爽、随取随用的个人文件空间。</p>
            </div>
            <div class="hero-stats" aria-label="云盘概况">
                <div class="stat">
                    <span class="stat-value"><?php echo count($files); ?></span>
                    <span class="stat-label">已存文件</span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?php echo formatSize($totalSize); ?></span>
                    <span class="stat-label">已用空间</span>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="shell">
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>" role="alert">
            <?php if ($messageType === 'success'): ?>
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="m8.2 12.2 2.45 2.45 5.2-5.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.7v5.4M12 16.4v.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <?php endif; ?>
            <span><?php echo h($message); ?></span>
            <button class="alert-close" type="button" aria-label="关闭提示">×</button>
        </div>
    <?php endif; ?>

    <section class="panel upload-panel" aria-labelledby="uploadTitle">
        <form method="post" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <label class="upload-zone" id="uploadZone">
                <input type="file" name="file" class="upload-input" id="fileInput" required>
                <span class="upload-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none"><path d="M7.4 18.1h9.2a4.4 4.4 0 0 0 .55-8.76A5.65 5.65 0 0 0 6.42 8.1 4.99 4.99 0 0 0 7.4 18.1Z" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"/><path d="m9.4 12.3 2.6-2.6 2.6 2.6M12 10v5.2" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span>
                    <span class="upload-title" id="uploadTitle">拖拽文件到这里，或点击选择</span>
                    <span class="upload-hint" id="uploadHint">单个文件最大 <?php echo formatSize($maxFileSize); ?>，支持 70+ 种安全格式并采用私有存储</span>
                </span>
                <span class="choose-button">选择文件</span>
            </label>
            <noscript><button type="submit">上传文件</button></noscript>
        </form>
    </section>

    <section class="panel files-panel" aria-labelledby="filesTitle">
        <div class="panel-head">
            <div class="panel-title-wrap">
                <h2 class="panel-title" id="filesTitle">我的文件</h2>
                <p class="panel-subtitle">
                    共 <?php echo count($files); ?> 个文件<?php if ($latestTime): ?> · 最近更新于 <?php echo date('m月d日 H:i', $latestTime); ?><?php endif; ?>
                </p>
            </div>
            <?php if (count($files)): ?>
                <label class="search">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <input class="search-input" id="fileSearch" type="search" placeholder="搜索文件名" autocomplete="off" aria-label="搜索文件">
                </label>
            <?php endif; ?>
        </div>

        <?php if (count($files)): ?>
            <div class="file-list" id="fileList">
                <div class="list-header" aria-hidden="true">
                    <span>文件名</span><span>大小</span><span>上传时间</span><span></span>
                </div>
                <?php foreach ($files as $file): ?>
                    <article class="file-row" data-name="<?php echo h($file['name']); ?>">
                        <div class="file-main">
                            <span class="file-icon <?php echo h($file['kind']); ?>" aria-hidden="true">
                                <?php if ($file['kind'] === 'image'): ?>
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M6 17.2 9.5 13l2.6 2.8 1.8-2 4.1 3.4M8.5 9.5h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php elseif ($file['kind'] === 'video'): ?>
                                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none"><path d="m10 8 6 4-6 4V8Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                                <?php elseif ($file['kind'] === 'audio'): ?>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9.2 16.8V8.1l7-1.7v8.7M9.2 16.8c0 1.1-1.15 2-2.55 2s-2.55-.9-2.55-2 1.14-2 2.55-2c1.4 0 2.55.9 2.55 2Zm7-1.7c0 1.1-1.14 2-2.55 2s-2.55-.9-2.55-2 1.14-2 2.55-2 2.55.9 2.55 2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php elseif ($file['kind'] === 'archive'): ?>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9.5 5.5h5M9.5 9h5M9.5 12.5h5M9.5 16h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                <?php else: ?>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 8h6M9 11.5h6M9 15h4" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="file-info">
                                <a class="file-name" href="<?php echo h($file['url']); ?>" target="_blank" rel="noopener" title="<?php echo h($file['name']); ?>"><?php echo h($file['name']); ?></a>
                                <span class="file-ext"><?php echo $file['extension'] !== '' ? h($file['extension']) : 'FILE'; ?></span>
                            </span>
                        </div>
                        <span class="file-meta"><?php echo formatSize($file['size']); ?></span>
                        <time class="file-meta" datetime="<?php echo date('c', $file['time']); ?>"><?php echo date('Y-m-d H:i', $file['time']); ?></time>
                        <div class="file-actions">
                            <button class="icon-button copy-action" type="button" data-copy="<?php echo h($file['url']); ?>" title="复制链接" aria-label="复制 <?php echo h($file['name']); ?> 的链接">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="8" y="8" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M15 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2" stroke="currentColor" stroke-width="1.7"/></svg>
                            </button>
                            <a class="icon-button" href="<?php echo h($file['url']); ?>" download title="下载" aria-label="下载 <?php echo h($file['name']); ?>">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                            <?php if (!empty($adminPasswordHash)): ?>
                                <button class="icon-button danger delete-action" type="button" data-id="<?php echo h($file['id']); ?>" data-file="<?php echo h($file['name']); ?>" title="删除" aria-label="删除 <?php echo h($file['name']); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M5 7h14M9 7V4.8h6V7m2 0-.65 12H7.65L7 7m3 3.5v5m4-5v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="empty-state search-empty" id="searchEmpty">
                    <div class="empty-visual">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none"><circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="1.7"/><path d="m15.5 15.5 4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    </div>
                    <h3>没有找到相关文件</h3>
                    <p>换一个关键词试试看</p>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-visual">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><path d="M4.5 8.5h15v9a2 2 0 0 1-2 2h-11a2 2 0 0 1-2-2v-9Z" stroke="currentColor" stroke-width="1.6"/><path d="m5 8.5 2.1-4h9.8l2.1 4M9.5 13h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </div>
                <h3>这里还没有文件</h3>
                <p>从上方上传第一个文件，开启你的分享空间</p>
            </div>
        <?php endif; ?>
    </section>

    <footer class="footer">
        <span>© <?php echo date('Y'); ?> <?php echo h($siteTitle); ?></span>
        <span class="footer-note">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 3.5 19 6v5.3c0 4.4-2.9 7.7-7 9.2-4.1-1.5-7-4.8-7-9.2V6l7-2.5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            安全传输 · 无需登录
        </span>
    </footer>
</main>

<aside class="announcement" role="note" aria-label="网站公告">
    <div class="announcement-head"><span class="announcement-dot"></span>网站公告</div>
    <p>页面焕新，网盘 2.0 正式上线！</p>
</aside>

<?php if (!empty($adminPasswordHash)): ?>
<dialog id="deleteDialog">
    <form method="post" id="deleteForm">
        <div class="dialog-body">
            <div class="dialog-icon">
                <svg width="23" height="23" viewBox="0 0 24 24" fill="none"><path d="M5 7h14M9 7V4.8h6V7m2 0-.65 12H7.65L7 7m3 3.5v5m4-5v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h2 class="dialog-title">确认删除文件？</h2>
            <p class="dialog-copy">即将永久删除 <span class="dialog-file" id="deleteFileName"></span>，此操作无法撤销。</p>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="file_id" id="deleteFileInput">
            <label class="field-label" for="adminPass">管理密码</label>
            <input class="password-input" id="adminPass" type="password" name="admin_pass" placeholder="请输入管理密码" required autocomplete="current-password">
            <div class="dialog-actions">
                <button class="button" type="button" id="cancelDelete">取消</button>
                <button class="button button-danger" type="submit">确认删除</button>
            </div>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="copy-toast" id="copyToast" role="status" aria-live="polite">链接已复制</div>

<script nonce="<?php echo h($cspNonce); ?>">
(() => {
    document.querySelectorAll('.alert-close').forEach(button => {
        button.addEventListener('click', () => button.parentElement.remove());
    });

    const uploadForm = document.getElementById('uploadForm');
    const uploadZone = document.getElementById('uploadZone');
    const fileInput = document.getElementById('fileInput');
    const uploadTitle = document.getElementById('uploadTitle');
    const uploadHint = document.getElementById('uploadHint');
    const maxBytes = <?php echo (int) $maxFileSize; ?>;

    const readableSize = bytes => {
        if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    };

    const submitFile = () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        if (file.size > maxBytes) {
            uploadTitle.textContent = '文件大小超出限制';
            uploadHint.textContent = `${file.name} · ${readableSize(file.size)}，请选择较小的文件`;
            fileInput.value = '';
            return;
        }
        uploadTitle.textContent = '正在上传…';
        uploadHint.textContent = `${file.name} · ${readableSize(file.size)}`;
        uploadZone.classList.add('is-uploading');
        uploadForm.submit();
    };

    fileInput.addEventListener('change', submitFile);
    ['dragenter', 'dragover'].forEach(eventName => {
        uploadZone.addEventListener(eventName, event => {
            event.preventDefault();
            uploadZone.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, event => {
            event.preventDefault();
            uploadZone.classList.remove('is-dragging');
        });
    });
    uploadZone.addEventListener('drop', event => {
        if (!event.dataTransfer.files.length) return;
        fileInput.files = event.dataTransfer.files;
        submitFile();
    });

    const searchInput = document.getElementById('fileSearch');
    if (searchInput) {
        const rows = Array.from(document.querySelectorAll('.file-row'));
        const searchEmpty = document.getElementById('searchEmpty');
        searchInput.addEventListener('input', () => {
            const keyword = searchInput.value.trim().toLocaleLowerCase();
            let visibleCount = 0;
            rows.forEach(row => {
                const visible = row.dataset.name.toLocaleLowerCase().includes(keyword);
                row.hidden = !visible;
                if (visible) visibleCount++;
            });
            searchEmpty.classList.toggle('is-visible', visibleCount === 0);
        });
    }

    const toast = document.getElementById('copyToast');
    let toastTimer;
    const showToast = message => {
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 1800);
    };

    document.querySelectorAll('.copy-action').forEach(button => {
        button.addEventListener('click', async () => {
            const url = new URL(button.dataset.copy, window.location.href).href;
            try {
                await navigator.clipboard.writeText(url);
                showToast('链接已复制');
            } catch (error) {
                const helper = document.createElement('textarea');
                helper.value = url;
                helper.className = 'clipboard-helper';
                document.body.appendChild(helper);
                helper.select();
                document.execCommand('copy');
                helper.remove();
                showToast('链接已复制');
            }
        });
    });

    const dialog = document.getElementById('deleteDialog');
    if (dialog) {
        const fileField = document.getElementById('deleteFileInput');
        const fileName = document.getElementById('deleteFileName');
        const password = document.getElementById('adminPass');
        document.querySelectorAll('.delete-action').forEach(button => {
            button.addEventListener('click', () => {
                fileField.value = button.dataset.id;
                fileName.textContent = `“${button.dataset.file}”`;
                password.value = '';
                dialog.showModal();
                setTimeout(() => password.focus(), 80);
            });
        });
        document.getElementById('cancelDelete').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
    }
})();
</script>
</body>
</html>
