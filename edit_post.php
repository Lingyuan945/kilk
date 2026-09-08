<?php
require_once 'header.php';

$max_image = 10; // 最多图片数

$id = intval($_GET['id']);
if (!$id) {
    header('Location: forum.php');
    exit;
}

// 获取帖子信息
$post_res = mysqli_query($conn, "SELECT * FROM forum_post WHERE id=$id");
$post = mysqli_fetch_assoc($post_res);
if (!$post) {
    header('Location: forum.php');
    exit;
}

// 权限校验：管理员可编辑所有帖子；高级用户(senior)可编辑自己的帖子 或 普通用户(user)发布的帖子
if (!$is_login) {
    header('Location: login.php');
    exit;
}
$is_owner = ($post['user_id'] == $login_user['id']);
$author_res = mysqli_query($conn, 'SELECT role FROM user WHERE id=' . $post['user_id']);
$author = mysqli_fetch_assoc($author_res);
$post_author_role = $author ? $author['role'] : '';
// 权限：超管全权；自己的帖子可编辑；仅可编辑等级低于自己的帖子（同级不可互改）
$can_edit = false;
if ($login_user['role'] == 'super') {
    $can_edit = true;
} elseif ($login_user['role'] == 'admin' || $login_user['role'] == 'senior') {
    $alv = isset($role_level[$post_author_role]) ? $role_level[$post_author_role] : 1;
    $can_edit = $is_owner || ($role_level[$login_user['role']] > $alv);
}
if (!$can_edit) {
    echo '<div style="text-align:center;padding:60px 0;color:#999;font-size:14px;">您没有权限编辑该帖子<br><br><a href="post_detail.php?id='.$id.'" style="color:#2563eb;">返回帖子详情</a></div>';
    require_once 'footer.php';
    exit;
}

$msg = '';

// 处理编辑提交
if ($_POST && isset($_POST['edit_submit'])) {
    csrf_check();
    $title = trim(mysqli_real_escape_string($conn, $_POST['title']));
    $content = trim(mysqli_real_escape_string($conn, $_POST['content']));

    if ($title === '' || $content === '') {
        $msg = '标题和内容不能为空';
    } else {
        // 1. 更新帖子文本（管理员以上可同时修改所属频道）
        $upd = "UPDATE forum_post SET title='$title', content='$content'";
        if (in_array($login_user['role'], ['admin','super'])) {
            $channel_id = intval($_POST['channel_id']);
            $upd .= ", channel_id=$channel_id";
        }
        $upd .= " WHERE id=$id";
        mysqli_query($conn, $upd);

        // 2. 删除勾选的旧图片
        if (!empty($_POST['del_img'])) {
            foreach ($_POST['del_img'] as $img_id) {
                $img_id = intval($img_id);
                $r = mysqli_query($conn, "SELECT image_path FROM forum_post_image WHERE id=$img_id AND post_id=$id");
                if ($row = mysqli_fetch_assoc($r)) {
                    $real = $_SERVER['DOCUMENT_ROOT'] . $row['image_path'];
                    if (file_exists($real)) @unlink($real);
                    mysqli_query($conn, "DELETE FROM forum_post_image WHERE id=$img_id");
                }
            }
        }

        // 3. 上传新图片
        if (!empty($_FILES['post_image']['name'][0])) {
            // 当前图片数量
            $cnt_res = mysqli_query($conn, "SELECT COUNT(*) c FROM forum_post_image WHERE post_id=$id");
            $cnt_row = mysqli_fetch_assoc($cnt_res);
            $cnt = intval($cnt_row['c']);

            $allowed_type = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 单张最大5MB
            $files = $_FILES['post_image'];
            $file_count = count($files['name']);
            $room = $max_image - $cnt;

            if ($file_count > $room) {
                $msg = '图片总数不能超过 '.$max_image.' 张（当前已有 '.$cnt.' 张）';
            } else {
                // 获取当前最大 sort
                $sort_res = mysqli_query($conn, "SELECT MAX(sort) m FROM forum_post_image WHERE post_id=$id");
                $sort_row = mysqli_fetch_assoc($sort_res);
                $sort_max = ($sort_row['m'] === null) ? -1 : intval($sort_row['m']);

                for ($i = 0; $i < $file_count; $i++) {
                    if ($files['error'][$i] != 0) continue;
                    if (!in_array($files['type'][$i], $allowed_type)) {
                        $msg = '第'.($i+1).'张图片格式不支持，仅支持 jpg/png/gif/webp';
                        break;
                    }
                    if ($files['size'][$i] > $max_size) {
                        $msg = '第'.($i+1).'张图片超过5MB限制';
                        break;
                    }
                    // 扩展名白名单映射（不信任客户端提供的扩展名/MIME）
                    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                    $ext = isset($ext_map[$files['type'][$i]]) ? $ext_map[$files['type'][$i]] : '';
                    // 真实图片内容校验
                    $img_info = @getimagesize($files['tmp_name'][$i]);
                    if (!$img_info || !$ext) {
                        $msg = '第'.($i+1).'张图片格式不支持，仅支持 jpg/png/gif/webp';
                        break;
                    }
                    $filename = date('YmdHis') . '_' . uniqid() . '.' . $ext;
                    $url_path = '/upload/' . $filename;
                    $save_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $filename;
                    if (move_uploaded_file($files['tmp_name'][$i], $save_path)) {
                        $sort_max++;
                        mysqli_query($conn, "INSERT INTO forum_post_image (post_id, image_path, sort) VALUES ($id, '$url_path', $sort_max)");
                    }
                }
            }
        }

        // 无错误则跳转回详情页
        if (!$msg) {
            header("Location: post_detail.php?id=$id");
            exit;
        }
    }
}

// 获取当前图片列表
$img_sql = "SELECT id, image_path FROM forum_post_image WHERE post_id=$id ORDER BY sort ASC";
$img_res = mysqli_query($conn, $img_sql);
$img_list = [];
while ($img = mysqli_fetch_assoc($img_res)) {
    $img_list[] = $img;
}
?>
<style>
.edit-container { max-width: 800px; margin: 0 auto; }
.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #2563eb;
    text-decoration: none;
    font-size: 14px;
}
.back-link:hover { text-decoration: underline; }
.edit-card {
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    animation: fadeUp 0.5s ease both;
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}
.edit-card h2 { font-size: 20px; margin-bottom: 20px; color: #2d3748; border-left: 4px solid #2563eb; padding-left: 12px; }
.form-item { margin-bottom: 20px; display: flex; flex-direction: column; gap: 8px; }
.form-item label { font-size: 14px; color: #555; font-weight: 600; }
.form-item input[type="text"], .form-item select {
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    font-family: inherit;
    background: #fff;
}
.form-item select { height: 42px; }
.form-item input[type="text"]:focus, .form-item textarea:focus { border-color: #2563eb; }
.form-item textarea {
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    height: 160px;
    resize: vertical;
    outline: none;
    font-family: inherit;
}
.form-item input[type="file"] { padding: 8px 0; font-size: 14px; }
.upload-tip { font-size: 12px; color: #999; }
.msg { color: #f56c6c; margin-bottom: 16px; font-size: 14px; background: #fef2f2; padding: 10px 14px; border-radius: 6px; }

/* 已有图片网格 */
.edit-img-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    margin-top: 4px;
}
.edit-img-item {
    border: 1px solid #eee;
    border-radius: 8px;
    overflow: hidden;
    background: #fafafa;
}
.edit-img-item img {
    width: 100%;
    height: 100px;
    object-fit: cover;
    display: block;
    background: #f5f5f5;
}
.img-del-check {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 8px;
    font-size: 13px;
    color: #f56c6c;
    cursor: pointer;
    user-select: none;
}
.img-del-check input { cursor: pointer; }
.no-img { color: #bbb; font-size: 13px; padding: 10px 0; }

.btn-row { display: flex; gap: 12px; margin-top: 24px; }
.save-btn {
    height: 38px;
    padding: 0 26px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.save-btn:hover { background: #1d4ed8; }
.cancel-btn {
    height: 38px;
    padding: 0 26px;
    background: #f0f2f5;
    color: #666;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}
.cancel-btn:hover { background: #e5e7eb; }

@media (max-width: 768px) {
    .edit-card { padding: 20px; }
}

@media (max-width: 480px) {
    .edit-card { padding: 16px; }
    .btn-row { flex-direction: column; }
    .btn-row .save-btn, .btn-row .cancel-btn { width: 100%; justify-content: center; }
    .edit-img-grid { grid-template-columns: repeat(2, 1fr); }
}</style>

<div class="edit-container">
    <a href="post_detail.php?id=<?php echo $id; ?>" class="back-link">← 返回帖子详情</a>

    <div class="edit-card">
        <h2>编辑帖子</h2>

        <?php if ($msg): ?>
            <div class="msg"><?php echo $msg; ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <?php if (in_array($login_user['role'], ['admin','super'])): $chan_eres = mysqli_query($conn, "SELECT * FROM channel ORDER BY sort ASC, id ASC"); ?>
            <div class="form-item">
                <label>所属频道</label>
                <select name="channel_id">
                    <option value="0">未分类</option>
                    <?php while ($ch = mysqli_fetch_assoc($chan_eres)): ?>
                    <option value="<?php echo $ch['id']; ?>" <?php echo $post['channel_id'] == $ch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ch['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-item">
                <label>帖子标题</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($post['title']); ?>" required>
            </div>
            <div class="form-item">
                <label>帖子内容</label>
                <textarea name="content" required><?php echo htmlspecialchars($post['content']); ?></textarea>
            </div>

            <div class="form-item">
                <label>当前图片（勾选"删除"后保存将移除）</label>
                <?php if (empty($img_list)): ?>
                    <div class="no-img">暂无图片</div>
                <?php else: ?>
                    <div class="edit-img-grid">
                        <?php foreach ($img_list as $img): ?>
                        <div class="edit-img-item">
                            <img src="<?php echo htmlspecialchars($img['image_path']); ?>" alt="帖子图片">
                            <label class="img-del-check">
                                <input type="checkbox" name="del_img[]" value="<?php echo $img['id']; ?>"> 删除
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-item">
                <label>添加新图片（可选，图片总数最多 <?php echo $max_image; ?> 张）</label>
                <input type="file" name="post_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                <div class="upload-tip">支持 jpg/png/gif/webp，单张最大 5MB</div>
            </div>

            <div class="btn-row">
                <button type="submit" name="edit_submit" class="save-btn">保存修改</button>
                <a href="post_detail.php?id=<?php echo $id; ?>" class="cancel-btn">取消</a>
            </div>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
