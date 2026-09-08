<?php
// 下载处理页：不输出 HTML，直接返回文件流
// 支持两种调用方式：
//   service_download.php?item=版本ID          —— 下载指定文件版本
//   service_download.php?id=作品ID            —— 兼容：下载该作品的第一个（最新）版本
require_once 'config.php';

$item_id = intval($_GET['item'] ?? 0);
$svc_id  = intval($_GET['id'] ?? 0);

if ($item_id) {
    // 按版本 id 查询（同时校验所属作品存在）
    $stmt = mysqli_prepare($conn, 'SELECT i.*, s.id AS service_id FROM service_file_item i JOIN service_file s ON i.service_id=s.id WHERE i.id=?');
    mysqli_stmt_bind_param($stmt, 'i', $item_id);
    mysqli_stmt_execute($stmt);
    $file = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($file) $download_item_id = $item_id;
} elseif ($svc_id) {
    // 兼容旧链接：按作品 id 取最新一个版本
    $stmt = mysqli_prepare($conn, 'SELECT i.*, s.id AS service_id FROM service_file_item i JOIN service_file s ON i.service_id=s.id WHERE i.service_id=? ORDER BY i.id DESC LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $svc_id);
    mysqli_stmt_execute($stmt);
    $file = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($file) $download_item_id = $file['id'];
} else {
    header('Location: service.php');
    exit;
}

// 下载需登录
if (!$is_login) {
    $back = $item_id ? 'service_download.php?item=' . $item_id : 'service_download.php?id=' . $svc_id;
    header('Location: login.php?redirect=' . urlencode($back));
    exit;
}

if (!$file) {
    header('Location: service.php');
    exit;
}

// 路径安全校验：必须在 DOCUMENT_ROOT/upload 下
// 注意：Windows 下 realpath() 返回反斜杠分隔路径，需统一转为正斜杠后再做前缀比较，否则会误判文件不存在
$doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$full_path = $doc_root . $file['file_path'];
$real_path = str_replace('\\', '/', realpath($full_path));
$upload_real = str_replace('\\', '/', realpath($doc_root . '/upload'));

if (!$real_path || !$upload_real || strpos($real_path . '/', rtrim($upload_real, '/') . '/') !== 0 || !is_file($real_path)) {
    http_response_code(404);
    die('文件不存在或已被移除');
}

// 下载计数 +1（按版本计数）
mysqli_query($conn, 'UPDATE service_file_item SET download_count = download_count + 1 WHERE id=' . $download_item_id);

// 强制下载
$orig_name = $file['file_name'];
$filesize = filesize($real_path);
$mime = mime_content_type($real_path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($orig_name) . '"; filename*=UTF-8\'\'' . rawurlencode($orig_name));
header('Content-Length: ' . $filesize);
header('Cache-Control: private, no-transform, no-store');
header('Pragma: no-cache');

readfile($real_path);
exit;
