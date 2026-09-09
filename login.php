<?php
require 'config.php';
if ($is_login) {
    header('Location: index.php');
    exit;
}

$msg = '';
$ajax_req = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') || isset($_POST['ajax']);
if ($_POST) {
    csrf_check();
    // 简单暴力破解防护：同一会话连续失败 5 次后锁定 15 分钟
    $fail = isset($_SESSION['login_fail']) ? intval($_SESSION['login_fail']) : 0;
    $fail_time = isset($_SESSION['login_fail_time']) ? intval($_SESSION['login_fail_time']) : 0;
    $lock = ($fail >= 5) && (time() - $fail_time) < 900;
    if ($lock) {
        $lock_msg = '尝试次数过多，请15分钟后再试';
        if ($ajax_req) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => $lock_msg]);
            exit;
        }
        $msg = $lock_msg;
    } else {
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $sql = "SELECT * FROM user WHERE username='$username'";
        $res = db_query($conn, $sql);
        $row = mysqli_fetch_assoc($res);
        if ($row && verify_password($_POST['password'], $row['password'])) {
            // 登录成功：重新生成会话ID，防止会话固定攻击
            session_regenerate_id(true);
            unset($_SESSION['login_fail'], $_SESSION['login_fail_time']);
            $_SESSION['user_id'] = $row['id'];
            // 旧 md5 哈希自动升级为 bcrypt
            if (password_needs_upgrade($row['password'])) {
                $new_hash = hash_password($_POST['password']);
                $uid = intval($row['id']);
                mysqli_query($conn, "UPDATE user SET password='$new_hash' WHERE id=$uid");
            }
            if ($ajax_req) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'redirect' => 'index.php']);
                exit;
            }
            header('Location: index.php');
            exit;
        } else {
            $_SESSION['login_fail'] = $fail + 1;
            $_SESSION['login_fail_time'] = time();
            if ($ajax_req) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'msg' => '账号或密码错误']);
                exit;
            }
            $msg = '账号或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>kilk - 登录</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { 
    font-family: "Microsoft YaHei", sans-serif;
    background: #f0f2f5;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.login-card {
    width: 380px;
    background: #fff;
    padding: 40px 32px;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    animation: cardIn 0.45s ease both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.login-card h2 {
    text-align: center;
    margin-bottom: 30px;
    color: #2d3748;
}
.form-item { margin-bottom: 20px; }
.form-item input {
    width: 100%;
    height: 44px;
    padding: 0 14px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
}
.form-item input:focus { border-color: #2563eb; }
.msg { color: #f56c6c; text-align: center; margin-bottom: 16px; font-size: 14px; }
.login-btn {
    width: 100%;
    height: 44px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    cursor: pointer;
    transition: background 0.2s;
}
.login-btn:hover { background: #1d4ed8; }
.tips { text-align: center; margin-top: 20px; font-size: 13px; color: #999; }
.tips a { color: #2563eb; text-decoration: none; }

/* 手机端适配 */
@media (max-width: 480px) {
    .login-card {
        width: 100%;
        padding: 30px 20px;
    }
    .login-card h2 { font-size: 20px; margin-bottom: 24px; }
}

@media (max-width: 360px) {
    body { padding: 12px; }
    .login-card { padding: 24px 16px; }
}</style>

</head>
<body>
<div class="login-card">
    <h2>用户登录</h2>
    <?php if ($msg) echo '<div class="msg">'.$msg.'</div>'; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="form-item">
            <input type="text" name="username" placeholder="请输入账号" required>
        </div>
        <div class="form-item">
            <input type="password" name="password" placeholder="请输入密码" required>
        </div>
        <button type="submit" class="login-btn">登 录</button>
    </form>
<div class="tips">
    <a href="index.php">← 返回首页</a>
    <span style="margin:0 8px;color:#ddd;">|</span>
    没有账号？<a href="register.php">立即注册</a>
</div>
</div>
</body>
</html>
