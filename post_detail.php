<?php
require_once 'header.php';

$id = intval($_GET['id']);
if (!$id) {
    header('Location: forum.php');
    exit;
}

// 浏览量+1
mysqli_query($conn, "UPDATE forum_post SET view_count = view_count + 1 WHERE id=$id");

// 获取帖子完整信息
$sql = "SELECT p.*, u.username, u.name, u.avatar as author_avatar, u.signature as author_signature, u.role AS author_role FROM forum_post p 
        LEFT JOIN user u ON p.user_id = u.id 
        WHERE p.id=$id";
$post_res = mysqli_query($conn, $sql);
$post = mysqli_fetch_assoc($post_res);

// 帖子不存在则跳转列表
if (!$post) {
    header('Location: forum.php');
    exit;
}

// 查询该帖子所有图片
$img_sql = "SELECT image_path FROM forum_post_image WHERE post_id=$id ORDER BY sort ASC";
$img_res = mysqli_query($conn, $img_sql);
$img_list = [];
while ($img = mysqli_fetch_assoc($img_res)) {
    $img_list[] = $img['image_path'];
}

// 提交评论回复（POST + CSRF）
if ($_POST && $is_login && isset($_POST['reply_submit'])) {
    csrf_check();
    $content = trim(mysqli_real_escape_string($conn, $_POST['reply_content']));
    if ($content) {
        $uid = $login_user['id'];
        $time = date('Y-m-d H:i:s');
        $sql = "INSERT INTO forum_reply (post_id, user_id, content, reply_time) 
                VALUES ($id, $uid, '$content', '$time')";
        mysqli_query($conn, $sql);
        header("Location: post_detail.php?id=$id");
        exit;
    }
}

// 删除回复（POST + CSRF；超管全删；管理员不可删超管帖子下的回复）
if (isset($_POST['del_reply'])) {
    csrf_check();
    $prq = mysqli_query($conn, "SELECT p.user_id, u.role FROM forum_post p LEFT JOIN user u ON p.user_id=u.id WHERE p.id=$id");
    $prw = mysqli_fetch_assoc($prq);
    $p_role = $prw ? $prw['role'] : '';
    $p_owner = $prw ? $prw['user_id'] : 0;
    $can_del_reply = false;
    if ($login_user['role'] == 'super') $can_del_reply = true;
    elseif ($login_user['role'] == 'admin' || $login_user['role'] == 'senior') {
        $alv = isset($role_level[$p_role]) ? $role_level[$p_role] : 1;
        $can_del_reply = ($p_owner == $login_user['id']) || ($role_level[$login_user['role']] > $alv);
    }
    if ($can_del_reply) {
        $rid = intval($_POST['del_reply']);
        mysqli_query($conn, "DELETE FROM forum_reply WHERE id=$rid");
        header("Location: post_detail.php?id=$id");
        exit;
    }
}

// 删除整帖（POST + CSRF；超管全删；管理员不可删超管的帖子）
if (isset($_POST['del_post'])) {
    csrf_check();
    $prq = mysqli_query($conn, "SELECT p.user_id, u.role FROM forum_post p LEFT JOIN user u ON p.user_id=u.id WHERE p.id=$id");
    $prw = mysqli_fetch_assoc($prq);
    $p_role = $prw ? $prw['role'] : '';
    $p_owner = $prw ? $prw['user_id'] : 0;
    $can_del_post = false;
    if ($login_user['role'] == 'super') $can_del_post = true;
    elseif ($login_user['role'] == 'admin' || $login_user['role'] == 'senior') {
        $alv = isset($role_level[$p_role]) ? $role_level[$p_role] : 1;
        $can_del_post = ($p_owner == $login_user['id']) || ($role_level[$login_user['role']] > $alv);
    }
    if ($can_del_post) {
        // 清理帖子图片文件 + 记录
        $img_res = mysqli_query($conn, "SELECT image_path FROM forum_post_image WHERE post_id=$id");
        while ($img = mysqli_fetch_assoc($img_res)) {
            $real_path = $_SERVER['DOCUMENT_ROOT'] . $img['image_path'];
            if ($real_path && file_exists($real_path)) @unlink($real_path);
        }
        mysqli_query($conn, "DELETE FROM forum_post_image WHERE post_id=$id");
        mysqli_query($conn, "DELETE FROM forum_post WHERE id=$id");
        mysqli_query($conn, "DELETE FROM forum_reply WHERE post_id=$id");
        header('Location: forum.php');
        exit;
    }
}

// 获取全部评论回复
$sql = "SELECT r.*, u.username, u.name, u.avatar as reply_avatar FROM forum_reply r 
        LEFT JOIN user u ON r.user_id = u.id 
        WHERE r.post_id=$id 
        ORDER BY r.id ASC";
$reply_res = mysqli_query($conn, $sql);
$reply_count = mysqli_num_rows($reply_res);
?>
<style>
.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #2563eb;
    text-decoration: none;
    font-size: 14px;
}
.back-link:hover { text-decoration: underline; }

/* 帖子主体 */
.post-detail {
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 30px;
}
.post-detail h1 { 
    font-size: 24px; 
    margin-bottom: 15px; 
    color: #2d3748; 
    line-height: 1.4;
}
.post-meta {
    font-size: 13px;
    color: #999;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: 20px;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}
.post-content {
    line-height: 1.8;
    color: #444;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 15px;
}
.post-operate {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #f0f0f0;
    text-align: right;
}
.edit-post-btn {
    color: #2563eb;
    text-decoration: none;
    font-size: 13px;
    margin-right: 15px;
}
.edit-post-btn:hover { text-decoration: underline; }
.del-post-btn {
    color: #f56c6c;
    text-decoration: none;
    font-size: 13px;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    font-family: inherit;
}
.del-post-btn:hover { text-decoration: underline; }

/* 评论区 */
.reply-section { margin-bottom: 20px; }
.reply-title {
    font-size: 18px;
    margin-bottom: 15px;
    color: #2d3748;
    padding-bottom: 8px;
    border-bottom: 2px solid #2563eb;
    display: inline-block;
}
.reply-item {
    background: #fff;
    padding: 18px 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 12px;
}
.reply-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.reply-user { 
    font-weight: 600; 
    color: #2563eb; 
    font-size: 14px; 
}
.reply-right { display: flex; align-items: center; gap: 12px; }
.reply-time { font-size: 12px; color: #999; }
.del-reply-btn { 
    font-size: 12px; 
    color: #f56c6c; 
    text-decoration: none;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    font-family: inherit;
}
.del-reply-btn:hover { text-decoration: underline; }
.reply-content {
    line-height: 1.7;
    color: #555;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 14px;
}

/* 发表回复框 */
.reply-box {
    background: #fff;
    padding: 24px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-top: 20px;
}
.reply-box h4 { 
    margin-bottom: 15px; 
    color: #333; 
    font-size: 15px; 
}
.reply-box textarea {
    width: 100%;
    height: 100px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    margin-bottom: 12px;
    resize: vertical;
    font-family: inherit;
}
.reply-box textarea:focus { border-color: #2563eb; }
.reply-submit {
    height: 36px;
    padding: 0 22px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.2s;
}
.reply-submit:hover { background: #1d4ed8; }

.login-tip {
    text-align: center;
    padding: 25px;
    color: #999;
    background: #fff;
    border-radius: 8px;
    margin-top: 20px;
    font-size: 14px;
}
.login-tip a { color: #2563eb; text-decoration: none; }
.login-tip a:hover { text-decoration: underline; }

.empty-reply {
    text-align: center;
    padding: 40px 0;
    color: #bbb;
    background: #fff;
    border-radius: 8px;
    font-size: 14px;
}

/* 内联表单（删除按钮用） */
.inline-form { display: inline; }

/* 帖子配图 */
.post-img { width: 100%; border-radius: 8px; cursor: pointer; }

/* 手机端适配 */
@media (max-width: 768px) {
    .post-detail { padding: 20px; }
    .post-detail h1 { font-size: 20px; }
    .post-meta { gap: 12px; font-size: 12px; }
    .reply-item { padding: 15px; }
    .reply-box { padding: 18px; }
}

@media (max-width: 480px) {
    .post-detail { padding: 16px; }
    .post-meta { flex-wrap: wrap; gap: 8px 14px; }
    .reply-head { flex-direction: column; align-items: flex-start; gap: 6px; }
    .reply-box { padding: 15px; }
}
</style>

<a href="forum.php" class="back-link">← 返回论坛列表</a>

<!-- 帖子内容主体 -->
<div class="post-detail">
    <h1><?php echo htmlspecialchars($post['title']); ?></h1>
    <div class="post-meta">
        <span>
            <a href="profile.php?id=<?php echo $post['user_id']; ?>" style="display:inline-flex;align-items:center;gap:6px;color:#555;text-decoration:none;">
                <img src="<?php echo htmlspecialchars($post['author_avatar'] ? $post['author_avatar'] : '/img/default_avatar.png'); ?>" onerror="this.src='/img/default_avatar.png'" style="width:24px;height:24px;border-radius:50%;object-fit:cover;">
                <?php echo htmlspecialchars($post['name'] ? $post['name'] : $post['username']); ?>
            </a>
        </span>
        <span>发布时间：<?php echo htmlspecialchars($post['create_time']); ?></span>
        <span>浏览：<?php echo intval($post['view_count']); ?></span>
    </div>
    <?php if ($post['author_signature']): ?>
    <div style="margin-top:10px;padding:10px 14px;background:#f8fafc;border-left:3px solid #dbeafe;color:#777;font-size:13px;border-radius:0 6px 6px 0;"><?php echo htmlspecialchars($post['author_signature']); ?></div>
    <?php endif; ?>
    <div class="post-content"><?php echo htmlspecialchars($post['content']); ?></div>

    <?php if (!empty($img_list)): ?>
    <div style="margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
        <?php foreach ($img_list as $img): ?>
            <img src="<?php echo htmlspecialchars($img); ?>" data-full="<?php echo htmlspecialchars($img); ?>" class="post-img" alt="帖子配图">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    $is_owner = ($post['user_id'] == $login_user['id']);
    $can_edit = false;
    if ($login_user['role'] == 'super') { $can_edit = true; }
    elseif ($login_user['role'] == 'admin' || $login_user['role'] == 'senior') {
        $alv = isset($role_level[$post['author_role']]) ? $role_level[$post['author_role']] : 1;
        $can_edit = $is_owner || ($role_level[$login_user['role']] > $alv);
    }
    ?>
    <?php if ($can_edit): ?>
    <div class="post-operate">
        <a href="edit_post.php?id=<?php echo $id; ?>" class="edit-post-btn">编辑帖子</a>
        <?php if (in_array($login_user['role'], ['admin','super'])): ?>
            <form method="post" class="inline-form" onsubmit="return confirm('确定删除该帖子及所有回复？');">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="del_post" value="1">
                <button type="submit" class="del-post-btn">删除帖子</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 评论回复区 -->
<div class="reply-section">
    <h3 class="reply-title">全部评论 (<?php echo $reply_count; ?>)</h3>
    
    <?php if ($reply_count == 0): ?>
        <div class="empty-reply">暂无评论，快来抢沙发吧～</div>
    <?php else: ?>
        <?php while ($reply = mysqli_fetch_assoc($reply_res)): ?>
        <div class="reply-item">
            <div class="reply-head">
                <span class="reply-user">
                    <a href="profile.php?id=<?php echo $reply['user_id']; ?>" style="display:inline-flex;align-items:center;gap:6px;color:#2563eb;text-decoration:none;">
                        <img src="<?php echo htmlspecialchars($reply['reply_avatar'] ? $reply['reply_avatar'] : '/img/default_avatar.png'); ?>" onerror="this.src='/img/default_avatar.png'" style="width:22px;height:22px;border-radius:50%;object-fit:cover;">
                        <?php echo htmlspecialchars($reply['name'] ? $reply['name'] : $reply['username']); ?>
                    </a>
                </span>
                <div class="reply-right">
                    <span class="reply-time"><?php echo htmlspecialchars($reply['reply_time']); ?></span>
                    <?php if ($can_edit && in_array($login_user['role'], ['admin','super'])): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('确定删除该评论？');">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="del_reply" value="<?php echo $reply['id']; ?>">
                            <button type="submit" class="del-reply-btn">删除</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="reply-content"><?php echo htmlspecialchars($reply['content']); ?></div>
        </div>
        <?php endwhile; ?>
    <?php endif; ?>

    <!-- 发表回复 -->
    <?php if ($is_login): ?>
    <div class="reply-box">
        <h4>发表评论</h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <textarea name="reply_content" placeholder="写下你的想法..." required></textarea>
            <button type="submit" name="reply_submit" class="reply-submit">发布评论</button>
        </form>
    </div>
    <?php else: ?>
    <div class="login-tip">
        <a href="login.php">登录后</a> 即可发表评论
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    try {
        // 帖子配图点击放大（事件委托，避免内联 onclick）
        document.querySelectorAll('.post-img').forEach(function(img) {
            img.addEventListener('click', function() {
                window.open(img.dataset.full);
            });
        });
    } catch (e) { console.error('post_detail init error:', e); }
})();
</script>

<?php require_once 'footer.php'; ?>
