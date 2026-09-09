<?php
require_once 'config.php';
if ($is_login) {
    header('Location: index.php');
    exit;
}

$msg = '';
$msg_type = 'error';
$ajax_req = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') || isset($_POST['ajax']);
if ($_POST) {
    csrf_check();
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $password = $_POST['password'];
    $password2 = $_POST['password2'];
    
    // 基础校验
    if (strlen($username) < 3) {
        $msg = '账号至少3位字符';
    } elseif (strlen($password) < 6) {
        $msg = '密码至少6位字符';
    } elseif ($password != $password2) {
        $msg = '两次输入的密码不一致';
    } else {
        // 检查账号是否已存在
        $res = mysqli_query($conn, "SELECT id FROM user WHERE username='$username'");
        if (mysqli_num_rows($res) > 0) {
            $msg = '该账号已被注册，请更换账号';
        } elseif ($name !== '' && mysqli_num_rows(mysqli_query($conn, "SELECT id FROM user WHERE name='$name'")) > 0) {
            $msg = '该用户名称已被使用，请更换';
        } else {
            $password_hash = hash_password($password);
            // 自动分配 8 位账号ID（与后台添加用户规则一致，按顺序递增）
            $no_res = mysqli_query($conn, "SELECT LPAD(COALESCE(MAX(CAST(user_no AS UNSIGNED)), 10000000) + 1, 8, '0') AS next_no FROM user");
            $no_row = mysqli_fetch_assoc($no_res);
            $user_no = isset($no_row['next_no']) ? $no_row['next_no'] : '00000001';
            $sql = "INSERT INTO user (user_no, username, password, name, role, create_time) 
                    VALUES ('$user_no', '$username', '$password_hash', '$name', 'user', NOW())";
            if (mysqli_query($conn, $sql)) {
                $msg = '注册成功！即将跳转到登录页';
                $msg_type = 'success';
                if (!$ajax_req) header("refresh:2;url=login.php");
            } else {
                $msg = '注册失败，请重试';
            }
        }
    }
}

// AJAX 抽屉注册：返回 JSON
if ($ajax_req && $_POST) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => ($msg_type === 'success'), 'msg' => $msg]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>kilk - 注册</title>
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
.register-card {
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
.register-card h2 {
    text-align: center;
    margin-bottom: 30px;
    color: #2d3748;
}
.form-item { margin-bottom: 18px; }
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
.msg { 
    text-align: center; 
    margin-bottom: 16px; 
    font-size: 14px; 
    padding: 10px;
    border-radius: 6px;
}
.msg.error { color: #f56c6c; background: #fef0f0; }
.msg.success { color: #2ecc71; background: #f0fdf4; }
.register-btn {
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
.register-btn:hover { background: #1d4ed8; }
.tips { text-align: center; margin-top: 20px; font-size: 13px; color: #999; }
.tips a { color: #2563eb; text-decoration: none; }

/* 手机端适配 */
@media (max-width: 480px) {
    .register-card {
        width: 100%;
        padding: 30px 20px;
    }
    .register-card h2 { font-size: 20px; margin-bottom: 24px; }
}

@media (max-width: 360px) {
    body { padding: 12px; }
    .register-card { padding: 24px 16px; }
}</style>
</head>
<body>
<div class="register-card">
    <h2>用户注册</h2>
    <?php if ($msg): ?>
        <div class="msg <?php echo $msg_type; ?>"><?php echo $msg; ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="form-item">
            <input type="text" name="username" placeholder="登录账号（至少3位）" required value="<?php echo isset($_POST['username'])?htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8'):''; ?>">
        </div>
        <div class="form-item">
            <input type="text" name="name" placeholder="用户名称（选填）" value="<?php echo isset($_POST['name'])?htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8'):''; ?>">
        </div>
        <div class="form-item">
            <input type="password" name="password" placeholder="设置密码（至少6位）" required>
        </div>
        <div class="form-item">
            <input type="password" name="password2" placeholder="确认密码" required>
        </div>
        <button type="submit" class="register-btn">注 册</button>
    </form>
    <div class="tips">
        已有账号？<a href="login.php">立即登录</a>
    </div>
</div>
</body>
</html>
