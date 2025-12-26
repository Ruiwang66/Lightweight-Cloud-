<?php
/**
 * Simple Single-File PHP Cloud Disk
 * 轻量云网盘 Lightweight Cloud网盘
 */

// ================= 配置区域 =================
// 网页标题
$siteTitle = "我的私有云盘";
// 管理密码 (用于删除文件)，留空则禁用删除功能
$adminPass = "123456"; 
// 允许上传的最大文件大小 (单位: 字节, 默认 50MB)
// 注意：还需要修改 php.ini 中的 upload_max_filesize 和 post_max_size
$maxFileSize = 50 * 1024 * 1024; 
// 禁止上传的文件后缀 (黑名单)
$blackList = ['php', 'php5', 'php7', 'phtml', 'exe', 'sh', 'bat', 'cmd', 'dll', 'cgi'];
// ===========================================

$uploadDir = 'uploads/';
$currentUrl = $_SERVER['PHP_SELF'];

// 初始化目录
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        die("<h3>错误：无法创建 uploads 目录，请手动创建并给予 777 权限。</h3>");
    }
}

// 辅助函数：格式化大小
function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// 处理逻辑
$msg = '';
$msgType = ''; // success, danger

// 1. 上传文件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = "上传出错，错误码: " . $file['error'];
        $msgType = "danger";
    } elseif (in_array($ext, $blackList)) {
        $msg = "安全警告：禁止上传此类型文件！";
        $msgType = "danger";
    } elseif ($file['size'] > $maxFileSize) {
        $msg = "文件过大，超过限制！";
        $msgType = "danger";
    } else {
        // 防止文件名乱码，保留原扩展名，主文件名加时间戳防止覆盖
        // $newName = date('YmdHis_') . $file['name']; // 如果想改名取消注释这行
        $newName = $file['name']; // 保持原名
        // 简单的防重名覆盖处理
        if(file_exists($uploadDir . $newName)){
             $newName = time() . "_" . $newName;
        }

        // 尝试移动
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
            $msg = "文件上传成功！";
            $msgType = "success";
        } else {
            $msg = "上传失败，请检查目录写入权限 (chmod 777)。";
            $msgType = "danger";
        }
    }
}

// 2. 删除文件
if (isset($_GET['del']) && !empty($adminPass)) {
    $fileToDelete = basename($_GET['del']);
    $pass = isset($_GET['key']) ? $_GET['key'] : '';
    
    if ($pass === $adminPass) {
        $target = $uploadDir . $fileToDelete;
        if (file_exists($target)) {
            unlink($target);
            $msg = "文件已删除。";
            $msgType = "success";
        } else {
            $msg = "文件不存在。";
            $msgType = "danger";
        }
    } else {
        $msg = "管理密码错误，无法删除。";
        $msgType = "danger";
    }
}

// 读取文件列表
$files = [];
$scandir = scandir($uploadDir);
foreach ($scandir as $file) {
    if ($file !== '.' && $file !== '..') {
        $path = $uploadDir . $file;
        if(is_file($path)){
            $files[] = [
                'name' => $file,
                'size' => filesize($path),
                'time' => filemtime($path),
                'path' => $path
            ];
        }
    }
}
// 按时间倒序排列 (最新的在最前)
usort($files, function($a, $b) {
    return $b['time'] - $a['time'];
});

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $siteTitle; ?></title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .main-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 0; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .table th { border-top: none; color: #6c757d; font-weight: 600; font-size: 0.9rem; }
        .table td { vertical-align: middle; }
        .file-name { font-weight: 500; color: #333; text-decoration: none; }
        .file-name:hover { color: #667eea; }
        .btn-primary-custom { background-color: #667eea; border-color: #667eea; color: white; }
        .btn-primary-custom:hover { background-color: #5a6fd6; border-color: #5a6fd6; color: white; }
        .upload-area { background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; padding: 20px; text-align: center; transition: all 0.3s; cursor: pointer; position: relative;}
        .upload-area:hover { border-color: #667eea; background: #fff; }
        .upload-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .badge-ext { font-size: 0.7em; margin-right: 5px; background-color: #e9ecef; color: #495057; }
        .footer { color: #adb5bd; font-size: 0.85rem; margin-top: 40px; }
    </style>
</head>
<body>

<div class="main-header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="fw-bold mb-0">☁️ <?php echo $siteTitle; ?></h2>
            <div>
                <span class="badge bg-white text-primary bg-opacity-75">无需登录</span>
                <span class="badge bg-white text-primary bg-opacity-75">极速传输</span>
            </div>
        </div>
    </div>
</div>

<div class="container">
    
    <?php if(!empty($msg)): ?>
    <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show" role="alert">
        <?php echo $msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
                <div class="upload-area">
                    <input type="file" name="file" class="upload-input" onchange="document.getElementById('uploadForm').submit();">
                    <div class="py-3">
                        <h5 class="text-muted">点击或拖拽文件到此处上传</h5>
                        <p class="mb-0 text-muted small">支持任意格式 (exe/sh除外) | 最大 <?php echo formatSize($maxFileSize); ?></p>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0">📄 文件列表 (<?php echo count($files); ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="50%">文件名</th>
                        <th width="15%">大小</th>
                        <th width="20%">上传时间</th>
                        <th width="15%" class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($files) > 0): ?>
                        <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <a href="<?php echo $file['path']; ?>" class="file-name" target="_blank">
                                    <span class="badge rounded-pill badge-ext"><?php echo strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION)); ?></span>
                                    <?php echo htmlspecialchars($file['name']); ?>
                                </a>
                            </td>
                            <td class="text-muted small"><?php echo formatSize($file['size']); ?></td>
                            <td class="text-muted small"><?php echo date("Y-m-d H:i", $file['time']); ?></td>
                            <td class="text-end">
                                <a href="<?php echo $file['path']; ?>" download class="btn btn-sm btn-outline-primary" title="下载">⬇</a>
                                <?php if(!empty($adminPass)): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteFile('<?php echo htmlspecialchars($file['name']); ?>')" title="删除">×</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <div>📭 暂无文件，快来上传第一个吧</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="footer text-center pb-4">
        &copy; <?php echo date("Y"); ?> <?php echo $siteTitle; ?> | Powered by PHP
    </div>
</div>

<script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script>
function deleteFile(filename) {
    const pass = prompt("请输入管理密码以删除文件：");
    if (pass) {
        window.location.href = "?del=" + encodeURIComponent(filename) + "&key=" + encodeURIComponent(pass);
    }
}
</script>
</body>
</html>