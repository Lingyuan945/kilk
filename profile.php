<?php
require_once 'header.php';

// 查看哪个用户的主页：默认自己；?id=X 查看指定用户
$view_id = isset($_GET['id']) ? intval($_GET['id']) : ($is_login ? intval($login_user['id']) : 0);
if (!$view_id) {
    header('Location: login.php');
    exit;
}

$res = db_query($conn, "SELECT * FROM user WHERE id=$view_id");
$user = mysqli_fetch_assoc($res);
if (!$user) {
    header('Location: index.php');
    exit;
}

$is_self = $is_login && ($login_user['id'] == $view_id);

// ========== 处理「基本资料」提交（头像+签名，仅本人）==========
$pmsg = '';
$pok  = '';
if ($_POST && $is_self && isset($_POST['save_profile'])) {
    csrf_check();
    $signature = trim(mysqli_real_escape_string($conn, $_POST['signature']));
    mysqli_query($conn, "UPDATE user SET signature='$signature' WHERE id=$view_id");

    // 管理员/超级管理员可修改自己的部门、工号（普通/高级用户无此资料）
    if (in_array($user['role'], ['admin','super'])) {
        $department = trim(mysqli_real_escape_string($conn, isset($_POST['department']) ? $_POST['department'] : ''));
        $job_number = trim(mysqli_real_escape_string($conn, isset($_POST['job_number']) ? $_POST['job_number'] : ''));
        mysqli_query($conn, "UPDATE user SET department='$department', job_number='$job_number' WHERE id=$view_id");
    }

    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] == 0) {
        $allowed_type = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 2 * 1024 * 1024;
        if (!in_array($_FILES['avatar']['type'], $allowed_type)) {
            $pmsg = '头像格式不支持，仅支持 jpg/png/gif/webp';
        } elseif ($_FILES['avatar']['size'] > $max_size) {
            $pmsg = '头像不能超过2MB';
        } else {
            // 扩展名白名单映射（不信任客户端提供的扩展名/MIME）
            $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext = isset($ext_map[$_FILES['avatar']['type']]) ? $ext_map[$_FILES['avatar']['type']] : '';
            // 真实图片内容校验
            $img_info = @getimagesize($_FILES['avatar']['tmp_name']);
            if (!$img_info || !$ext) {
                $pmsg = '头像格式不支持，仅支持 jpg/png/gif/webp';
                $ext = false;
            }
            $filename = $ext ? ('avatar_' . date('YmdHis') . '_' . uniqid() . '.' . $ext) : '';
            $url_path = '/upload/' . $filename;
            $save_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $filename;
            if ($ext && move_uploaded_file($_FILES['avatar']['tmp_name'], $save_path)) {
                $old_avatar = $user['avatar'];
                if ($old_avatar && strpos($old_avatar, '/upload/') === 0) {
                    $old_real = $_SERVER['DOCUMENT_ROOT'] . $old_avatar;
                    if (file_exists($old_real)) @unlink($old_real);
                }
                mysqli_query($conn, "UPDATE user SET avatar='$url_path' WHERE id=$view_id");
                $user['avatar'] = $url_path;
                $pok = '头像已更新';
            } elseif ($ext) {
                $pmsg = '头像上传失败，请重试';
            }
        }
    }
    if (!$pmsg && !$pok) {
        $pok = '基本资料已保存';
    }
    // 刷新签名回显
    $user['signature'] = $signature;
}

// ========== 处理「账号安全」提交（修改密码，仅本人）==========
$wmsg = '';
$wok  = '';
if ($_POST && $is_self && isset($_POST['save_password'])) {
    csrf_check();
    $old_pwd = isset($_POST['old_password']) ? $_POST['old_password'] : '';
    $new_pwd = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_pwd = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    if (!verify_password($old_pwd, $user['password'])) {
        $wmsg = '原密码不正确';
    } elseif (strlen($new_pwd) < 6) {
        $wmsg = '新密码长度至少6位';
    } elseif ($new_pwd !== $confirm_pwd) {
        $wmsg = '两次输入的新密码不一致';
    } else {
        $new_pwd_hash = hash_password($new_pwd); mysqli_query($conn, "UPDATE user SET password='$new_pwd_hash' WHERE id=$view_id");
        $wok = '密码已修改，下次登录请使用新密码';
    }
}

// 保存后重新读取最新用户数据（部门/工号/头像/签名回显）
$res = db_query($conn, "SELECT * FROM user WHERE id=$view_id");
$user = mysqli_fetch_assoc($res);

// 保存后自动打开的编辑标签页
$auto_open = '';
if ($_POST && $is_self) {
    if (isset($_POST['save_profile'])) { $auto_open = 'profile-tab'; }
    elseif (isset($_POST['save_password'])) { $auto_open = 'pwd-tab'; }
}

// 获取该用户发布的帖子
$posts = [];
$pr = mysqli_query($conn, "SELECT * FROM forum_post WHERE user_id=$view_id ORDER BY id DESC");
while ($p = mysqli_fetch_assoc($pr)) {
    $posts[] = $p;
}
$avatar = $user['avatar'] ? $user['avatar'] : '/img/default_avatar.png';
?>
<style>
.profile-container { max-width: 800px; margin: 0 auto; }
.top-links { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.back-link { display: inline-block; color: #2563eb; text-decoration: none; font-size: 14px; }
.back-link:hover { text-decoration: underline; }
.my-profile-link {
    display: inline-block;
    padding: 6px 16px;
    background: #f0f7ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
}
.my-profile-link:hover { background: #2563eb; color: #fff; }

.profile-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow: hidden;
    margin-bottom: 20px;
}
.profile-banner { height: 120px; background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%); }
.profile-main { padding: 0 30px 25px; position: relative; }
.avatar-wrap {
    width: 96px; height: 96px; border-radius: 50%;
    border: 4px solid #fff; overflow: hidden; background: #f0f2f5;
    margin-top: -48px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}
.avatar-wrap img { width: 100%; height: 100%; object-fit: cover; }
.profile-name-row { display: flex; align-items: center; gap: 12px; margin-top: 14px; }
.profile-name { font-size: 22px; color: #2d3748; font-weight: 700; }
.role-tag { padding: 3px 12px; border-radius: 12px; font-size: 12px; display: inline-block; }
.role-user { background: #dbeafe; color: #2563eb; }
.role-senior { background: #fce7f3; color: #db2777; }
.role-admin { background: #fef3c7; color: #d97706; }
.role-super { background: #fef9c3; color: #a16207; border: 1px solid #fde047; }
.profile-signature { margin-top: 10px; color: #777; font-size: 14px; }
.profile-signature.empty { color: #bbb; }
.profile-meta {
    margin-top: 16px; display: flex; gap: 24px; flex-wrap: wrap;
    font-size: 13px; color: #999; padding-top: 14px; border-top: 1px solid #f0f0f0;
}
.edit-btn {
    display: inline-block; margin-top: 16px; padding: 8px 20px;
    background: #2563eb; color: #fff; border: none; border-radius: 6px;
    cursor: pointer; font-size: 14px;
}
.edit-btn:hover { background: #1d4ed8; }

/* 消息提示 */
.msg { color: #f56c6c; margin-bottom: 16px; font-size: 14px; background: #fef2f2; padding: 10px 14px; border-radius: 6px; }
.ok-msg { color: #16a34a; margin-bottom: 16px; font-size: 14px; background: #f0fdf4; padding: 10px 14px; border-radius: 6px; }

/* 帖子列表 */
.posts-section h3 {
    font-size: 17px; color: #2d3748; margin-bottom: 15px;
    border-left: 4px solid #2563eb; padding-left: 12px;
}
.post-list-item {
    background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    padding: 16px 20px; margin-bottom: 12px;
    display: flex; justify-content: space-between; align-items: center; gap: 16px;
}
.post-list-item h4 { font-size: 15px; }
.post-list-item h4 a { color: #2d3748; text-decoration: none; }
.post-list-item h4 a:hover { color: #2563eb; }
.post-list-meta { font-size: 12px; color: #999; margin-top: 6px; }
.post-list-view { color: #2563eb; text-decoration: none; font-size: 13px; white-space: nowrap; }
.empty-posts { background: #fff; border-radius: 8px; padding: 40px; text-align: center; color: #bbb; font-size: 14px; }

/* ---------- 编辑资料弹窗 ---------- */
.modal {
    display: none;
    position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center; justify-content: center;
    padding: 20px;
}
.modal.show { display: flex; }
.modal-content {
    background: #fff; border-radius: 12px;
    width: 100%; max-width: 520px;
    max-height: 90vh; overflow-y: auto;
    animation: modalIn 0.2s ease-out;
}
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid #f0f0f0;
}
.modal-header h4 { font-size: 16px; color: #333; }
.modal-close { background: none; border: none; font-size: 22px; color: #999; cursor: pointer; line-height: 1; }
.modal-close:hover { color: #333; }
.modal-body { padding: 24px; }

/* 标签页 */
.modal-tabs { display: flex; border-bottom: 1px solid #e4e7ed; padding: 0 24px; }
.tab-btn {
    padding: 14px 18px; background: none; border: none; cursor: pointer;
    font-size: 14px; color: #666; border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}
.tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

.form-item { margin-bottom: 18px; display: flex; flex-direction: column; gap: 8px; }
.form-item label { font-size: 14px; color: #555; font-weight: 600; }
.form-item input[type="text"], .form-item input[type="password"] {
    border: 1px solid #dcdfe6; border-radius: 6px;
    padding: 10px 12px; font-size: 14px; outline: none; font-family: inherit;
    max-width: 420px; width: 100%;
}
.form-item input:focus { border-color: #2563eb; }
.form-item input[type="file"] { padding: 8px 0; font-size: 14px; }
.upload-tip { font-size: 12px; color: #999; }

.avatar-preview-row { display: flex; align-items: center; gap: 18px; }
.avatar-preview {
    width: 72px; height: 72px; border-radius: 50%;
    object-fit: cover; border: 2px solid #e4e7ed; background: #f0f2f5;
}
.tab-msg { color: #f56c6c; margin-bottom: 14px; font-size: 13px; background: #fef2f2; padding: 8px 12px; border-radius: 6px; }
.tab-ok { color: #16a34a; margin-bottom: 14px; font-size: 13px; background: #f0fdf4; padding: 8px 12px; border-radius: 6px; }

.save-btn {
    height: 38px; padding: 0 26px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.save-btn:hover { background: #1d4ed8; }

@media (max-width: 768px) {
    .profile-main { padding: 0 18px 20px; }
    .modal-body { padding: 18px; }
    .modal-tabs { padding: 0 18px; }
    .avatar-preview-row { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .profile-banner { height: 90px; }
    .profile-main { padding: 0 16px 18px; }
    .avatar-wrap { width: 78px; height: 78px; margin-top: -39px; }
    .profile-name { font-size: 19px; }
    .profile-meta { flex-direction: column; gap: 10px; }
    .modal-content { max-height: 88vh; }
}
</style>

<div class="profile-container">
    <div class="top-links">
        <a href="forum.php" class="back-link">← 返回论坛</a>
        <?php if ($is_login && !$is_self): ?>
            <a href="profile.php" class="my-profile-link">查看我的主页</a>
        <?php endif; ?>
    </div>

    <!-- 个人信息卡片 -->
    <div class="profile-card">
        <div class="profile-banner"></div>
        <div class="profile-main">
            <div class="avatar-wrap">
                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="头像" onerror="this.src='/img/default_avatar.png'">
            </div>
            <div class="profile-name-row">
                <span class="profile-name"><?php echo htmlspecialchars($user['name'] ? $user['name'] : $user['username']); ?></span>
                <span class="role-tag role-<?php echo $user['role']; ?>"><?php echo $role_name[$user['role']]; ?></span>
            </div>
            <div class="profile-signature <?php echo $user['signature'] ? '' : 'empty'; ?>">
                <?php echo $user['signature'] ? htmlspecialchars($user['signature']) : '这个人很懒，还没有签名～'; ?>
            </div>
            <div class="profile-meta">
                <span>账号：<?php echo htmlspecialchars($user['username']); ?></span>
                <?php if (in_array($user['role'], ['admin','super'])): ?>
                <span>部门：<?php echo $user['department'] ? htmlspecialchars($user['department']) : '-'; ?></span>
                <span>工号：<?php echo $user['job_number'] ? htmlspecialchars($user['job_number']) : '-'; ?></span>
                <?php endif; ?>
                <span>注册时间：<?php echo htmlspecialchars($user['create_time']); ?></span>
            </div>
            <?php if ($is_self): ?>
                <button type="button" class="edit-btn" onclick="openEditModal()">编辑资料</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 保存结果提示（刷新后显示在页面顶部） -->
    <?php if ($pmsg || $wmsg): ?>
        <div class="msg"><?php echo $pmsg ? $pmsg : $wmsg; ?></div>
    <?php endif; ?>
    <?php if ($pok || $wok): ?>
        <div class="ok-msg"><?php echo $pok ? $pok : $wok; ?></div>
    <?php endif; ?>

    <!-- 发布的帖子 -->
    <div class="posts-section">
        <h3><?php echo htmlspecialchars($user['name'] ? $user['name'] : $user['username']); ?> 发布的帖子 (<?php echo count($posts); ?>)</h3>
        <?php if (empty($posts)): ?>
            <div class="empty-posts">暂无发布帖子</div>
        <?php else: ?>
            <?php foreach ($posts as $p): ?>
            <div class="post-list-item">
                <div>
                    <h4><a href="post_detail.php?id=<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></a></h4>
                    <div class="post-list-meta">发布于 <?php echo $p['create_time']; ?> · 浏览 <?php echo $p['view_count']; ?></div>
                </div>
                <a href="post_detail.php?id=<?php echo $p['id']; ?>" class="post-list-view">查看</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_self): ?>
<!-- 编辑资料弹窗 -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>编辑资料</h4>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-tabs">
            <button type="button" class="tab-btn active" data-tab="profile-tab" onclick="switchTab('profile-tab', this)">基本资料</button>
            <button type="button" class="tab-btn" data-tab="pwd-tab" onclick="switchTab('pwd-tab', this)">账号安全</button>
        </div>
        <div class="modal-body">
            <!-- 基本资料：头像 + 签名 -->
            <form id="profile-tab" class="tab-panel active" method="post" enctype="multipart/form-data" action="profile.php?id=<?php echo $view_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <?php if ($pmsg): ?><div class="tab-msg"><?php echo $pmsg; ?></div><?php endif; ?>
                <?php if ($pok): ?><div class="tab-ok"><?php echo $pok; ?></div><?php endif; ?>
                <div class="form-item">
                    <label>头像</label>
                    <div class="avatar-preview-row">
                        <img id="avatar_preview" src="<?php echo $avatar; ?>" alt="头像预览" class="avatar-preview" onerror="this.src='/img/default_avatar.png'">
                        <div>
                            <input type="file" id="avatar_input" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="upload-tip">支持 jpg/png/gif/webp，最大 2MB，选择后即时预览</div>
                        </div>
                    </div>
                </div>
                <div class="form-item">
                    <label>个性签名</label>
                    <input type="text" name="signature" placeholder="写点什么介绍自己" maxlength="500" value="<?php echo htmlspecialchars($user['signature']); ?>">
                </div>
                <?php if (in_array($user['role'], ['admin','super'])): ?>
                <div class="form-item">
                    <label>部门</label>
                    <input type="text" name="department" placeholder="所属部门" maxlength="100" value="<?php echo htmlspecialchars($user['department']); ?>">
                </div>
                <div class="form-item">
                    <label>工号</label>
                    <input type="text" name="job_number" placeholder="员工工号" maxlength="50" value="<?php echo htmlspecialchars($user['job_number']); ?>">
                </div>
                <?php endif; ?>
                <button type="submit" name="save_profile" class="save-btn">保存资料</button>
            </form>

            <!-- 账号安全：修改密码 -->
            <form id="pwd-tab" class="tab-panel" method="post" action="profile.php?id=<?php echo $view_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <?php if ($wmsg): ?><div class="tab-msg"><?php echo $wmsg; ?></div><?php endif; ?>
                <?php if ($wok): ?><div class="tab-ok"><?php echo $wok; ?></div><?php endif; ?>
                <div class="form-item">
                    <label>原密码</label>
                    <input type="password" name="old_password" placeholder="请输入当前登录密码">
                </div>
                <div class="form-item">
                    <label>新密码</label>
                    <input type="password" name="new_password" placeholder="至少6位">
                </div>
                <div class="form-item">
                    <label>确认新密码</label>
                    <input type="password" name="confirm_password" placeholder="再次输入新密码">
                </div>
                <button type="submit" name="save_password" class="save-btn">修改密码</button>
            </form>
        </div>
    </div>
</div>

<script>
// 弹窗开关
const editModal = document.getElementById('editModal');
function openEditModal() { editModal.classList.add('show'); }
function closeEditModal() { editModal.classList.remove('show'); }
editModal.addEventListener('click', function(e) {
    if (e.target === editModal) closeEditModal();
});

// 标签页切换
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById(tabId).classList.add('active');
    if (btn) btn.classList.add('active');
}

// 头像实时预览
const avatarInput = document.getElementById('avatar_input');
const avatarPreview = document.getElementById('avatar_preview');
if (avatarInput && avatarPreview) {
    avatarInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) { avatarPreview.src = e.target.result; };
            reader.readAsDataURL(file);
        }
    });
}

// 保存后自动打开对应标签页（便于查看保存结果）
<?php if ($auto_open): ?>
document.addEventListener('DOMContentLoaded', function() {
    openEditModal();
    var targetBtn = document.querySelector('.tab-btn[data-tab="<?php echo $auto_open; ?>"]');
    if (targetBtn) switchTab('<?php echo $auto_open; ?>', targetBtn);
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
