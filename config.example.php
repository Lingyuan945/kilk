<?php
// 生产环境：不向用户暴露 PHP 错误详情
error_reporting(0);
ini_set('display_errors', '0');

// session cookie 安全加固（必须在 session_start 之前设置）
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
} else {
    session_set_cookie_params(0, '/', '', false, true);
}
session_start();
// 基础安全响应头（防点击劫持 / MIME 嗅探 / Referrer 泄露）
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// ===== 数据库配置（请根据实际环境修改）=====
$host = '127.0.0.1';
$db_user = 'your_db_user';
$db_pwd  = 'your_db_password';
$dbname = 'your_db_name';

$conn = @mysqli_connect($host, $db_user, $db_pwd);
if (!$conn) { error_log('DB connect failed: ' . mysqli_connect_error()); die('系统繁忙，请稍后重试'); }
mysqli_set_charset($conn, 'utf8');
mysqli_select_db($conn, $dbname);

// 获取当前登录用户信息
$is_login = false;
$login_user = array();
if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $res = mysqli_query($conn, "SELECT * FROM user WHERE id=$uid");
    if ($row = mysqli_fetch_assoc($res)) {
        $is_login = true;
        $login_user = $row;
    }
}

// 检查是否是管理员，不是则跳转
function check_admin() {
    global $login_user;
    if (!$GLOBALS['is_login'] || !in_array($login_user['role'], ['admin','super'])) {
        header('Location: login.php');
        exit;
    }
}

// ===== 角色体系（全站统一，新增角色/功能板块只需修改此处）=====
// 角色等级：user(1) < senior(2) < admin(3) < super(4)，等级越高权限越大
$role_level = ['user' => 1, 'senior' => 2, 'admin' => 3, 'super' => 4];
// 角色名称
$role_name = ['user' => '普通用户', 'senior' => '高级用户', 'admin' => '管理员', 'super' => '超级管理员'];

// ===== CSRF 防护（全站通用）=====
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// 校验 POST 请求的 CSRF token，失败直接 403 终止
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $expected = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    if (!$expected || !$token || !hash_equals($expected, $token)) {
        http_response_code(403);
        die('请求校验失败（CSRF token 不匹配），请刷新页面后重试。');
    }
}

// 校验 GET 链接携带的 token（用于删除类操作），失败直接 403 终止
function csrf_check_get() {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    $expected = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    if (!$expected || !$token || !hash_equals($expected, $token)) {
        http_response_code(403);
        die('请求校验失败，请刷新页面后重试。');
    }
}
?>
