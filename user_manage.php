<?php
require_once 'header.php';

// 页面权限校验：仅管理员和超级管理员可访问
if (!$is_login || !in_array($login_user['role'], ['admin','super'])) {
    header('Location: index.php');
    exit;
}
$current_role = $login_user['role'];
$current_uid = $login_user['id'];

// 添加用户（管理员可添加任意角色；高级用户仅可添加普通用户）
if (isset($_POST['add']) && in_array($current_role, ['admin','senior','super'])) {
    csrf_check();
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = md5($_POST['password']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
    $job_number = mysqli_real_escape_string($conn, $_POST['job_number']);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);
    $role = in_array($_POST['role'], ['user','senior','admin']) ? $_POST['role'] : 'user';
    // 高级用户只能创建普通用户
    if ($current_role == 'senior') $role = 'user';
    

    // 自动分配 8 位账号ID（按顺序递增）
    $no_res = mysqli_query($conn, "SELECT LPAD(COALESCE(MAX(CAST(user_no AS UNSIGNED)), 10000000) + 1, 8, '0') AS next_no FROM user");
    $no_row = mysqli_fetch_assoc($no_res);
    $user_no = isset($no_row['next_no']) ? $no_row['next_no'] : '00000001';

    // 用户名称唯一性检查
    if ($name !== '' && mysqli_num_rows(mysqli_query($conn, "SELECT id FROM user WHERE name='$name'")) > 0) {
        $msg = '该用户名称已被使用，请更换';
    } else {

    $sql = "INSERT INTO user (user_no, username, password, name, department, job_number, role, remark, create_time) 
            VALUES ('$user_no', '$username', '$password', '$name', '$department', '$job_number', '$role', '$remark', NOW())";
    if (mysqli_query($conn, $sql)) {
        header('Location: user_manage.php');
        exit;
    } else {
        $msg = '添加失败，账号可能已存在';
    }
    }
}

// 管理操作提交：修改角色 + （管理员）修改用户信息
if (isset($_POST['save_manage'])) {
    csrf_check();
    $id = intval($_POST['user_id']);
    // Senior cannot manage self; admin CAN manage self to maintain own account info
    if ($current_role == 'senior' && $id == $current_uid) {
        header('Location: user_manage.php');
        exit;
    }

    // 查询目标用户信息
    $res = mysqli_query($conn, "SELECT role, avatar FROM user WHERE id=$id");
    $target_user = mysqli_fetch_assoc($res);
    if (!$target_user) {
        header('Location: user_manage.php');
        exit;
    }

    // Block managing users with level >= self (except admin editing self)
    if (!(in_array($current_role, ['admin','super']) && $id == $current_uid)) {
        if ($role_level[$target_user['role']] >= $role_level[$current_role]) {
            header('Location: user_manage.php');
            exit;
        }
    }

    // Change role (cannot grant higher than self; admin editing self keeps admin to avoid losing admin)
    $target_role = in_array($_POST['role'], ['user','senior','admin']) ? $_POST['role'] : 'user';
    if (in_array($current_role, ['admin','super']) && $id == $current_uid) {
        $target_role = $current_role;
    } elseif ($role_level[$target_role] > $role_level[$current_role]) {
        header('Location: user_manage.php');
        exit;
    }
    mysqli_query($conn, "UPDATE user SET role='$target_role' WHERE id=$id");

    // 管理员/超级管理员：修改用户信息（账号名/用户名称/备注/签名/密码/头像；部门工号仅管理员及以上可编辑）
    if (in_array($current_role, ['admin','super'])) {
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $department = mysqli_real_escape_string($conn, isset($_POST['department']) ? $_POST['department'] : '');
        $job_number = mysqli_real_escape_string($conn, isset($_POST['job_number']) ? $_POST['job_number'] : '');
        $remark = mysqli_real_escape_string($conn, $_POST['remark']);
        $signature = mysqli_real_escape_string($conn, $_POST['signature']);

        // 账号唯一性检查
        $chk = mysqli_query($conn, "SELECT id FROM user WHERE username='$username' AND id != $id");
        if (mysqli_fetch_assoc($chk)) {
            header('Location: user_manage.php?dup=1');
            exit;
        }
        // 用户名称唯一性检查
        if ($name !== '' && mysqli_num_rows(mysqli_query($conn, "SELECT id FROM user WHERE name='$name' AND id != $id")) > 0) {
            header('Location: user_manage.php?dup=1');
            exit;
        }
        // 部门/工号仅管理员账号保留，普通/高级用户不保留
        $dept_part = (in_array($target_role, ['admin','super'])) ? ", department='$department', job_number='$job_number'" : '';
        // 账号ID（8位编号）可修改，须唯一
        $no_part = '';
        if ($current_role == 'super' && isset($_POST['user_no']) && trim($_POST['user_no']) !== '') {
            $user_no_new = preg_replace('/[^0-9]/', '', trim($_POST['user_no']));
            if ($user_no_new !== '') {
                $user_no_new = str_pad(substr($user_no_new, 0, 8), 8, '0', STR_PAD_LEFT);
                $chk_no = mysqli_query($conn, "SELECT id FROM user WHERE user_no='$user_no_new' AND id != $id");
                if (!mysqli_fetch_assoc($chk_no)) {
                    $no_part = ", user_no='$user_no_new'";
                }
            }
        }
        mysqli_query($conn, "UPDATE user SET username='$username', name='$name', remark='$remark', signature='$signature'$dept_part$no_part WHERE id=$id");

        // 重置密码（可选）
        if (!empty($_POST['password'])) {
            mysqli_query($conn, "UPDATE user SET password='" . md5($_POST['password']) . "' WHERE id=$id");
        }
        // 修改头像（可选）
        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] == 0) {
            $allowed_type = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 2 * 1024 * 1024;
            if (in_array($_FILES['avatar']['type'], $allowed_type) && $_FILES['avatar']['size'] <= $max_size) {
                // 扩展名白名单映射（不信任客户端提供的扩展名/MIME）
                $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $ext = isset($ext_map[$_FILES['avatar']['type']]) ? $ext_map[$_FILES['avatar']['type']] : '';
                // 真实图片内容校验
                $img_info = @getimagesize($_FILES['avatar']['tmp_name']);
                if (!$img_info || !$ext) {
                    $ext = false;
                }
                $filename = $ext ? ('avatar_' . date('YmdHis') . '_' . uniqid() . '.' . $ext) : '';
                $url_path = '/upload/' . $filename;
                $save_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $filename;
                if ($ext && move_uploaded_file($_FILES['avatar']['tmp_name'], $save_path)) {
                    $old_avatar = $target_user['avatar'];
                    if ($old_avatar && strpos($old_avatar, '/upload/') === 0) {
                        $old_real = $_SERVER['DOCUMENT_ROOT'] . $old_avatar;
                        if (file_exists($old_real)) @unlink($old_real);
                    }
                    mysqli_query($conn, "UPDATE user SET avatar='$url_path' WHERE id=$id");
                }
            }
        }
    }

    header('Location: user_manage.php');
    exit;
}

// 删除用户（管理员/超级管理员可用；管理员不可删除超级管理员；不能删除自己）
if (isset($_GET['del']) && in_array($current_role, ['admin','super'])) {
    csrf_check_get();
    $id = intval($_GET['del']);
    $tres = mysqli_query($conn, "SELECT role FROM user WHERE id=$id");
    $trow = mysqli_fetch_assoc($tres);
    if ($trow && $id != $current_uid) {
        if (!(in_array($current_role, ['admin','super']) && $trow['role'] == 'super')) {
            mysqli_query($conn, "DELETE FROM user WHERE id=$id");
        }
    }
    header('Location: user_manage.php');
    exit;
}
?>
<style>
.admin-title { font-size: 24px; margin-bottom: 24px; color: #2d3748; }
.admin-nav { display: flex; gap: 16px; margin-bottom: 20px; }
.admin-nav a {
    padding: 10px 20px;
    background: #fff;
    border-radius: 6px;
    text-decoration: none;
    color: #555;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.admin-nav a.active { background: #2563eb; color: #fff; }

/* 用户查找 */
.search-wrap { margin-bottom: 16px; }
.search-form { display: flex; gap: 8px; max-width: 480px; }
.search-form input {
    flex: 1;
    height: 38px;
    padding: 0 14px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
    background: #fff;
}
.search-form input:focus { border-color: #2563eb; }
.search-form button {
    height: 38px;
    padding: 0 20px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.2s;
}
.search-form button:hover { background: #1d4ed8; }
.search-clear { line-height: 38px; color: #999; font-size: 13px; text-decoration: none; }
.search-clear:hover { color: #333; }

/* 新增用户按钮 */
.add-btn-wrap { margin-bottom: 20px; }
.add-btn {
    height: 40px;
    padding: 0 22px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.2s;
}
.add-btn:hover { background: #1d4ed8; }

/* 通用弹窗 */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal.show { display: flex; }
.modal-content {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalIn 0.2s ease-out;
}
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #f0f0f0;
}
.modal-header h4 { font-size: 16px; color: #333; }
.modal-close {
    background: none;
    border: none;
    font-size: 22px;
    color: #999;
    cursor: pointer;
    line-height: 1;
}
.modal-close:hover { color: #333; }
.modal-body { padding: 24px; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #f0f0f0;
    text-align: right;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* 表单样式 */
.form-section { margin-bottom: 20px; }
.form-section:last-child { margin-bottom: 0; }
.form-section h4 { 
    margin-bottom: 14px; 
    color: #333; 
    font-size: 15px;
    border-left: 3px solid #2563eb;
    padding-left: 10px;
}
.form-row { 
    display: grid; 
    grid-template-columns: repeat(2, 1fr); 
    gap: 15px; 
    margin-bottom: 15px;
}
.form-item { display: flex; flex-direction: column; gap: 6px; }
.form-item label { font-size: 13px; color: #666; }
.form-item input, .form-item textarea, .form-item select {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
    background: #fff;
}
.form-item textarea {
    height: 70px;
    padding: 8px 12px;
    resize: vertical;
}
.form-item input:focus, .form-item textarea:focus, .form-item select:focus { border-color: #2563eb; }
.form-item .readonly { background: #f8f9fa; color: #666; }

/* 权限选择区 */
.role-group {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.role-option {
    border: 1px solid #e4e7ed;
    border-radius: 8px;
    padding: 14px;
    cursor: pointer;
    transition: all 0.2s;
}
.role-option:hover { border-color: #2563eb; background: #f5f9ff; }
.role-option input[type="radio"] { margin-right: 6px; accent-color: #2563eb; }
.role-option h5 { font-size: 14px; color: #333; margin-bottom: 4px; }
.role-option p { font-size: 12px; color: #999; }

.submit-btn {
    height: 38px;
    padding: 0 24px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.2s;
}
.submit-btn:hover { background: #1d4ed8; }
.cancel-btn {
    height: 38px;
    padding: 0 24px;
    background: #f0f2f5;
    color: #666;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.cancel-btn:hover { background: #e4e7ed; }
.del-btn {
    color: #f56c6c;
    text-decoration: none;
    font-size: 14px;
}
.del-btn:hover { text-decoration: underline; }

.msg { color: #f56c6c; margin-bottom: 12px; font-size: 14px; }

/* 用户列表 */
.table-wrap { overflow-x: auto; }
table {
    width: 100%;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border-collapse: collapse;
    min-width: 700px;
}
th, td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #f0f0f0;
    font-size: 14px;
}
th { background: #fafafa; font-weight: normal; color: #666; }
.role-tag {
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    white-space: nowrap;
    display: inline-block;
}
.role-user { background: #dbeafe; color: #2563eb; }
.role-senior { background: #fce7f3; color: #db2777; }
.role-admin { background: #fef3c7; color: #d97706; }
.role-super { background: #fef9c3; color: #a16207; border: 1px solid #fde047; }

.manage-btn {
    padding: 5px 14px;
    background: #f0f7ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
    display: inline-block;
}
.manage-btn:hover { background: #2563eb; color: #fff; }

/* 手机端适配 */
@media (max-width: 768px) {
    .admin-title { font-size: 20px; margin-bottom: 18px; }
    .admin-nav { gap: 10px; margin-bottom: 16px; }
    .admin-nav a { padding: 8px 14px; font-size: 14px; }
    
    .modal-content { max-width: 100%; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    .form-row { grid-template-columns: 1fr; gap: 12px; }
    .role-group { grid-template-columns: 1fr; gap: 10px; }
    
    /* 表格手机端卡片化 */
    .table-wrap { overflow-x: visible; }
    table { min-width: 0; background: transparent; box-shadow: none; }
    thead { display: none; }
    tbody tr {
        display: block; background: #fff; border: 1px solid #eef0f3;
        border-radius: 10px; padding: 14px; margin-bottom: 12px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }
    tbody tr td {
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; padding: 7px 0; border-bottom: 1px dashed #f0f0f0;
        font-size: 14px;
    }
    tbody tr td::before {
        content: attr(data-label);
        color: #999; font-size: 13px; flex-shrink: 0;
    }
    tbody tr td:first-child { border-bottom: 1px solid #f0f0f0; padding-bottom: 10px; font-weight: 600; color: #333; font-size: 15px; }
    tbody tr td:last-child { border-bottom: none; justify-content: flex-start; padding-top: 8px; }
    tbody tr td.empty {
        display: block; text-align: center; padding: 26px 0; border: none; color: #bbb;
    }
    tbody tr td.empty::before { display: none; }
    .manage-btn { padding: 8px 20px; font-size: 14px; }
}

@media (max-width: 480px) {
    .add-btn { width: 100%; }
    .search-form { flex-wrap: wrap; gap: 6px; }
    .search-form input { flex: 1 1 100%; }
    .search-form button { flex: 1; }
    .modal-body { padding: 14px; }
    .modal-content { max-height: 88vh; }
    th, td { padding: 8px 10px; font-size: 12px; }
    .manage-btn { padding: 4px 10px; font-size: 12px; }

    /* ===== 管理/新增弹窗手机端协调 ===== */
    .modal-footer { flex-wrap: wrap; gap: 10px; }
    .modal-footer > div { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .modal-footer .cancel-btn, .modal-footer .submit-btn { min-height: 40px; padding: 0 18px; }
    .modal-footer .del-btn, .modal-footer .manage-btn { font-size: 13px; }
    .role-option { padding: 12px; }
    .role-option h5 { font-size: 14px; }
    .role-option p { font-size: 12px; line-height: 1.5; }
    .form-section h4 { font-size: 14px; margin-bottom: 12px; }
    .form-item label { font-size: 12px; }
    .form-item input { font-size: 13px; height: 40px; }
    .form-item textarea { height: 64px; }
    .modal-header { padding: 16px 18px; }
    .modal-header h4 { font-size: 15px; }
}</style>

<?php if (isset($_GET['dup'])): ?>
<div style="max-width:900px;margin-bottom:12px;color:#f56c6c;background:#fef2f2;padding:10px 14px;border-radius:6px;font-size:14px;">账号名已存在，修改失败</div>
<?php endif; ?>
<h2 class="admin-title">用户管理</h2>
<div class="admin-nav">
    <a href="admin.php">首页</a>
    <a href="forum_manage.php">论坛</a>
    <a href="service_manage.php">服务支持</a>
    <a href="user_manage.php" class="active">用户</a>
</div>

<?php if (in_array($current_role, ['admin','senior','super'])): ?>
<!-- 新增用户按钮 -->
<div class="add-btn-wrap">
    <button id="addUserBtn" class="add-btn">+ 新增用户</button>
</div>
<?php endif; ?>

<!-- 用户查找 -->
<div class="search-wrap">
    <form method="get" class="search-form">
        <input type="text" name="keyword" value="<?php echo isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : ''; ?>" placeholder="搜索账号 / 用户名称 / 账号ID / 角色">
        <button type="submit">查找</button>
        <?php if (isset($_GET['keyword']) && trim($_GET['keyword']) !== ''): ?><a href="user_manage.php" class="search-clear">清除</a><?php endif; ?>
    </form>
</div>

<!-- 用户列表 -->
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th width="80" class="hide-mobile">账号ID</th>
            <th>账号</th>
            <th class="hide-mobile">用户名称</th>
            <th class="hide-mobile">部门</th>
            <th width="100">角色</th>
            <th width="100">操作</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $kw = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    $where = '';
    if ($kw !== '') {
        $kw_esc = mysqli_real_escape_string($conn, $kw);
        $where = " WHERE username LIKE '%$kw_esc%' OR name LIKE '%$kw_esc%' OR user_no LIKE '%$kw_esc%' OR role LIKE '%$kw_esc%'";
    }
    $res = mysqli_query($conn, "SELECT * FROM user$where ORDER BY CAST(user_no AS UNSIGNED) ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        $can_manage = false;
        if ($current_role == 'admin') {
            $can_manage = (($row['role'] != 'admin') && ($row['role'] != 'super')) || ($row['id'] == $current_uid);
        } elseif ($current_role == 'super') {
            $can_manage = ($row['role'] != 'super') || ($row['id'] == $current_uid);
        } elseif ($current_role == 'senior') {
            $can_manage = ($row['role'] == 'user') && ($row['id'] != $current_uid);
        }
    ?>
        <tr>
            <td class="hide-mobile" data-label="账号ID"><?php echo htmlspecialchars($row['user_no']); ?></td>
            <td data-label="账号"><?php echo htmlspecialchars($row['username']); ?></td>
            <td class="hide-mobile" data-label="用户名称"><?php echo htmlspecialchars($row['name'] ? $row['name'] : '-'); ?></td>
            <td class="hide-mobile" data-label="部门"><?php echo htmlspecialchars($row['department'] ? $row['department'] : '-'); ?></td>
            <td data-label="角色"><span class="role-tag role-<?php echo $row['role']; ?>"><?php echo $role_name[$row['role']]; ?></span></td>
            <td>
                <?php if ($can_manage): ?>
                    <button class="manage-btn" onclick="openManageModal(
                        <?php echo $row['id']; ?>,
                        <?php echo htmlspecialchars(json_encode($row['user_no']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['username']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['name']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['department']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['job_number']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['remark']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['role']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['create_time']), ENT_QUOTES, 'UTF-8'); ?>,
                        <?php echo htmlspecialchars(json_encode($row['signature']), ENT_QUOTES, 'UTF-8'); ?>
                    )">管理</button>
                <?php else: ?>
                    <span style="color:#bbb;font-size:13px;">无权限</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>
</div>

<!-- 新增用户弹窗 -->
<?php if (in_array($current_role, ['admin','senior','super'])): ?>
<div id="addModal" class="modal">
    <div class="modal-content" style="max-width:800px;">
        <div class="modal-header">
            <h4>新增用户</h4>
            <button class="modal-close" id="closeAddModal">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="modal-body">
                <?php if (isset($msg)) echo '<div class="msg">'.$msg.'</div>'; ?>
                
                <div class="form-section">
                    <h4>基本信息</h4>
                    <div class="form-item" style="grid-column: span 2;">
                        <label>账号ID（自动分配8位编号，从10000001起）</label>
                        <input type="text" class="readonly" readonly value="添加后自动分配" style="background:#f8f9fa;">
                    </div>
                    <div class="form-row">
                        <div class="form-item">
                            <label>登录账号 *</label>
                            <input type="text" name="username" placeholder="唯一登录账号" required>
                        </div>
                        <div class="form-item">
                            <label>登录密码 *</label>
                            <input type="password" name="password" placeholder="至少6位" required>
                        </div>
                        <div class="form-item">
                            <label>用户名称</label>
                            <input type="text" name="name" placeholder="用户名称（唯一）">
                        </div>
                        <div class="form-item">
                            <label>部门</label>
                            <input type="text" name="department" placeholder="所属部门">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-item">
                            <label>工号</label>
                            <input type="text" name="job_number" placeholder="员工工号">
                        </div>
                        <div class="form-item" style="grid-column: span 1;">
                            <label>备注</label>
                            <textarea name="remark" placeholder="补充说明信息"></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>权限设置</h4>
                    <div class="role-group">
                        <label class="role-option">
                            <input type="radio" name="role" value="user" checked>
                            <h5>普通用户</h5>
                            <p>基础查看权限</p>
                        </label>
                        <?php if (in_array($current_role, ['admin','super'])): ?>
                        <label class="role-option">
                            <input type="radio" name="role" value="senior">
                            <h5>高级用户</h5>
                            <p>扩展配置权限</p>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="admin">
                            <h5>管理员</h5>
                            <p>管理普通和高级用户</p>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end;">
                <button type="button" class="cancel-btn" id="cancelAddBtn" style="margin-right:10px;">取消</button>
                <button type="submit" name="add" class="submit-btn">确认添加</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 用户管理弹窗 -->
<div id="manageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>用户管理</h4>
            <button class="modal-close" id="closeManageModal">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="user_id" id="manage_user_id">
            <div class="modal-body">
                <div class="form-section">
                    <h4>用户信息</h4>
                    <div class="form-item" style="grid-column: span 2;">
                        <label>账号ID（8位，可修改）</label>
                        <input type="text" id="manage_user_no" name="user_no" maxlength="8" placeholder="8位数字编号" <?php echo ($current_role == 'super') ? '' : 'class="readonly" readonly'; ?>>
                    </div>
                    <div class="form-row">
                        <div class="form-item">
                            <label>登录账号</label>
                            <input type="text" id="manage_username" name="username" <?php echo in_array($current_role,['admin','super']) ? '' : 'class="readonly" readonly'; ?>>
                        </div>
                        <div class="form-item">
                            <label>用户名称</label>
                            <input type="text" id="manage_name" name="name" <?php echo in_array($current_role,['admin','super']) ? '' : 'class="readonly" readonly'; ?>>
                        </div>
                        <div class="form-item" id="manage_department_item">
                            <label>部门</label>
                            <input type="text" id="manage_department" name="department" <?php echo in_array($current_role,['admin','super']) ? '' : 'class="readonly" readonly'; ?>>
                        </div>
                        <div class="form-item" id="manage_job_number_item">
                            <label>工号</label>
                            <input type="text" id="manage_job_number" name="job_number" <?php echo in_array($current_role,['admin','super']) ? '' : 'class="readonly" readonly'; ?>>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-item" style="grid-column: span 2;">
                            <label>备注</label>
                            <textarea id="manage_remark" name="remark" <?php echo in_array($current_role,['admin','super']) ? '' : 'class="readonly" readonly'; ?>></textarea>
                        </div>
                    </div>
                    <?php if (in_array($current_role, ['admin','super'])): ?>
                    <div class="form-row">
                        <div class="form-item">
                            <label>个性签名</label>
                            <input type="text" id="manage_signature" name="signature">
                        </div>
                        <div class="form-item">
                            <label>重置密码（留空则不修改）</label>
                            <input type="password" id="manage_password" name="password" placeholder="至少6位">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-item">
                            <label>更换头像</label>
                            <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-item">
                            <label>创建时间</label>
                            <input type="text" id="manage_create_time" class="readonly" readonly>
                        </div>
                        <div class="form-item">
                            <label>当前角色</label>
                            <input type="text" id="manage_role_text" class="readonly" readonly>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>修改权限</h4>
                    <div class="role-group" id="role_options">
                        <!-- JS动态生成可选权限 -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div>
                    <?php if (in_array($current_role, ['admin','super'])): ?>
                        <a href="javascript:void(0);" class="del-btn" id="delUserBtn">删除该用户</a>
                    <?php endif; ?>
                    <a href="javascript:void(0);" class="manage-btn" id="managePostsBtn" style="margin-left:10px;">管理该用户帖子</a>
                </div>
                <div>
                    <button type="button" class="cancel-btn" id="cancelManageBtn" style="margin-right:10px;">关闭</button>
                    <button type="submit" name="save_manage" class="submit-btn">保存修改</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// 新增用户弹窗
const addBtn = document.getElementById('addUserBtn');
const addModal = document.getElementById('addModal');
const closeAddBtn = document.getElementById('closeAddModal');
const cancelAddBtn = document.getElementById('cancelAddBtn');

if (addBtn) {
    addBtn.addEventListener('click', () => addModal.classList.add('show'));
}
function closeAddModal() { addModal.classList.remove('show'); }
if (closeAddBtn) closeAddBtn.addEventListener('click', closeAddModal);
if (cancelAddBtn) cancelAddBtn.addEventListener('click', closeAddModal);
if (addModal) {
    addModal.addEventListener('click', e => {
        if (e.target === addModal) closeAddModal();
    });
}

// 用户管理弹窗
const manageModal = document.getElementById('manageModal');
const closeManageBtn = document.getElementById('closeManageModal');
const cancelManageBtn = document.getElementById('cancelManageBtn');
const delUserBtn = document.getElementById('delUserBtn');
const managePostsBtn = document.getElementById('managePostsBtn');

const csrfTok = '<?php echo csrf_token(); ?>';
const roleLevel = <?php echo json_encode($role_level); ?>;
const currentRoleLevel = <?php echo $role_level[$current_role]; ?>;
const currentRoleKey = <?php echo json_encode($current_role); ?>;
const currentUid = <?php echo $current_uid; ?>;
const roleName = <?php echo json_encode($role_name); ?>;
const roleDesc = {
    user: '基础查看权限',
    senior: '扩展配置权限',
    admin: '管理普通和高级用户',
    super: '最高权限，可管理所有账户'
};

function openManageModal(id, userNo, username, name, department, job_number, remark, role, createTime, signature) {
    document.getElementById('manage_user_id').value = id;
    document.getElementById('manage_user_no').value = userNo;
    document.getElementById('manage_username').value = username;
    document.getElementById('manage_name').value = name || '-';
    document.getElementById('manage_department').value = department || '-';
    document.getElementById('manage_job_number').value = job_number || '-';
    document.getElementById('manage_remark').value = remark || '-';
    document.getElementById('manage_create_time').value = createTime;
    document.getElementById('manage_role_text').value = roleName[role];
    if (document.getElementById('manage_signature')) {
        document.getElementById('manage_signature').value = signature || '';
    }
    if (document.getElementById('manage_password')) {
        document.getElementById('manage_password').value = '';
    }

    // 动态生成可选权限（不高于当前用户等级；管理员管理自己时角色固定为管理员）
    const roleBox = document.getElementById('role_options');
    roleBox.innerHTML = '';
    if (id === currentUid && currentRoleLevel >= 3) {
        roleBox.innerHTML = `
            <label class="role-option">
                <input type="radio" name="role" value="${currentRoleKey}" checked>
                <h5>${roleName[currentRoleKey]}</h5>
                <p>${roleDesc[currentRoleKey] || ''}</p>
            </label>
        `;
    } else {
        for (const key in roleLevel) {
            if (roleLevel[key] <= currentRoleLevel && key !== 'super') {
                const checked = key === role ? 'checked' : '';
                roleBox.innerHTML += `
                    <label class="role-option">
                        <input type="radio" name="role" value="${key}" ${checked}>
                        <h5>${roleName[key]}</h5>
                        <p>${roleDesc[key]}</p>
                    </label>
                `;
            }
        }
    }
    toggleDeptRow(role);

    // 删除按钮绑定（仅管理员；不能删除自己）
    if (delUserBtn) {
        if (id === currentUid || currentRoleLevel < 3) {
            delUserBtn.style.display = 'none';
        } else {
            delUserBtn.style.display = '';
            delUserBtn.onclick = function() {
                if (confirm('确定删除该用户？')) {
                    window.location.href = '?del=' + id + '&token=' + encodeURIComponent(csrfTok);
                }
            }
        }
    }

    // 管理该用户帖子
    if (managePostsBtn) {
        managePostsBtn.onclick = function() {
            window.location.href = 'user_posts.php?id=' + id;
        }
    }

    manageModal.classList.add('show');
}

function closeManageModal() { manageModal.classList.remove('show'); }
closeManageBtn.addEventListener('click', closeManageModal);
cancelManageBtn.addEventListener('click', closeManageModal);

// 部门/工号仅管理员账号可编辑：根据所选角色显示/隐藏
function toggleDeptRow(role) {
    var di = document.getElementById('manage_department_item');
    var ji = document.getElementById('manage_job_number_item');
    var show = (role === 'admin' || role === 'super');
    if (di) di.style.display = show ? '' : 'none';
    if (ji) ji.style.display = show ? '' : 'none';
}
var roleOptionsBox = document.getElementById('role_options');
if (roleOptionsBox) {
    roleOptionsBox.addEventListener('change', function(e) {
        if (e.target.name === 'role') toggleDeptRow(e.target.value);
    });
}
manageModal.addEventListener('click', e => {
    if (e.target === manageModal) closeManageModal();
});
</script>

<?php require_once 'footer.php'; ?>
