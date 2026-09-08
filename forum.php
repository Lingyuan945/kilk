<?php
require_once 'header.php';
$max_image = 10; // 最多上传图片数

// 当前选中的频道
$cur_channel = isset($_GET['channel']) ? intval($_GET['channel']) : 0;

// 频道列表（含版主）
$chan_res = mysqli_query($conn, "SELECT c.*, u.username AS moderator_name, u.name AS moderator_nick 
    FROM channel c LEFT JOIN user u ON c.moderator_id = u.id 
    ORDER BY c.sort ASC, c.id ASC");
$channels = [];
while ($ch = mysqli_fetch_assoc($chan_res)) $channels[] = $ch;

// 当前用户角色
$my_role = $login_user['role'];

// 发布新帖
if ($_POST && $is_login && isset($_POST['new_post'])) {
    csrf_check();
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $content = mysqli_real_escape_string($conn, $_POST['content']);
    $channel_id = intval($_POST['channel_id']);
    $uid = $login_user['id'];
    $time = date('Y-m-d H:i:s');
    $upload_images = [];

    // 处理多图上传
    if (!empty($_FILES['post_image']['name'][0])) {
        $allowed_type = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 单张最大5MB
        $files = $_FILES['post_image'];
        $file_count = count($files['name']);
        
        if ($file_count > $max_image) {
            $msg = '最多只能上传 '.$max_image.' 张图片';
        } else {
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
                // 真实图片内容校验（getimagesize 失败即拒绝）
                $img_info = @getimagesize($files['tmp_name'][$i]);
                if (!$img_info || !$ext) {
                    $msg = '第'.($i+1).'张图片格式不支持，仅支持 jpg/png/gif/webp';
                    break;
                }
                $filename = date('YmdHis') . '_' . uniqid() . '.' . $ext;
                $url_path = '/upload/' . $filename;
                $save_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $filename;

                if (move_uploaded_file($files['tmp_name'][$i], $save_path)) {
                    $upload_images[] = $url_path;
                }
            }
        }
    }

    if (!isset($msg)) {
        $sql = "INSERT INTO forum_post (user_id, channel_id, title, content, create_time) 
                VALUES ($uid, $channel_id, '$title', '$content', '$time')";
        if (mysqli_query($conn, $sql)) {
            $post_id = mysqli_insert_id($conn);
            if (!empty($upload_images)) {
                foreach ($upload_images as $k => $img) {
                    $sort = $k;
                    mysqli_query($conn, "INSERT INTO forum_post_image (post_id, image_path, sort) VALUES ($post_id, '$img', $sort)");
                }
            }
            header('Location: forum.php' . ($cur_channel ? "?channel=$cur_channel" : ''));
            exit;
        } else {
            $msg = '发布失败，请重试';
        }
    }
}

// 删除帖子（超管全删；管理员/高级用户可删自己或等级更低用户的帖子，同级不可互删）
if (isset($_GET['del'])) {
    csrf_check_get();
    $id = intval($_GET['del']);
    $pr = mysqli_query($conn, "SELECT p.user_id, u.role FROM forum_post p LEFT JOIN user u ON p.user_id=u.id WHERE p.id=$id");
    $prw = mysqli_fetch_assoc($pr);
    $p_author_role = $prw ? $prw['role'] : '';
    $p_owner = $prw ? $prw['user_id'] : 0;
    $can_del_post = false;
    if ($my_role == 'super') $can_del_post = true;
    elseif ($my_role == 'admin' || $my_role == 'senior') {
        $alv = isset($role_level[$p_author_role]) ? $role_level[$p_author_role] : 1;
        $can_del_post = ($p_owner == $login_user['id']) || ($role_level[$my_role] > $alv);
    }
    if ($can_del_post) {
        $img_res = mysqli_query($conn, "SELECT image_path FROM forum_post_image WHERE post_id=$id");
        while ($img = mysqli_fetch_assoc($img_res)) {
            $real_path = $_SERVER['DOCUMENT_ROOT'] . $img['image_path'];
            if ($real_path && file_exists($real_path)) {
                @unlink($real_path);
            }
        }
        mysqli_query($conn, "DELETE FROM forum_post_image WHERE post_id=$id");
        mysqli_query($conn, "DELETE FROM forum_post WHERE id=$id");
        mysqli_query($conn, "DELETE FROM forum_reply WHERE post_id=$id");
        header('Location: forum.php' . ($cur_channel ? "?channel=$cur_channel" : ''));
        exit;
    }
}

// 帖子列表（关联首图 + 频道 + 作者角色）
$post_where = $cur_channel ? "WHERE p.channel_id=$cur_channel" : '';
$sql = "SELECT p.*, u.username, u.avatar as author_avatar, u.id as author_id, u.role AS author_role, c.name AS channel_name,
        (SELECT image_path FROM forum_post_image WHERE post_id = p.id ORDER BY sort ASC LIMIT 1) as first_img
        FROM forum_post p 
        LEFT JOIN user u ON p.user_id = u.id 
        LEFT JOIN channel c ON p.channel_id = c.id 
        $post_where
        ORDER BY p.id DESC";
$res = mysqli_query($conn, $sql);
?>
<style>
.page-title { font-size: 28px; margin-bottom: 18px; color: #2d3748; border-left: 4px solid #2563eb; padding-left: 12px; }
.page-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.head-btns { display: flex; gap: 10px; flex-wrap: wrap; }
.post-btn {
    height: 40px;
    padding: 0 20px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.post-btn:hover { background: #1d4ed8; }

/* 频道选择条 */
.channel-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    padding: 14px 16px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.channel-bar a {
    padding: 6px 16px;
    border-radius: 20px;
    background: #f0f2f5;
    color: #555;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s;
}
.channel-bar a:hover { background: #dbeafe; color: #2563eb; }
.channel-bar a.active { background: #2563eb; color: #fff; }

/* 帖子列表 */
.post-list { display: flex; flex-direction: column; gap: 12px; }
.post-item {
    background: #fff;
    padding: 20px 24px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: all 0.2s;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}
.post-item:hover { transform: translateX(4px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.post-main { flex: 1; display: flex; gap: 16px; align-items: center; }
.post-thumb {
    width: 80px;
    height: 80px;
    border-radius: 6px;
    object-fit: cover;
    background: #f5f5f5;
    flex-shrink: 0;
}
.post-info h3 { font-size: 17px; margin-bottom: 8px; color: #333; }
.post-info h3 a { color: #333; text-decoration: none; }
.post-info h3 a:hover { color: #2563eb; }
.post-meta { font-size: 13px; color: #999; display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }
.post-meta .chan-tag {
    display: inline-block;
    padding: 2px 10px;
    background: #dbeafe;
    color: #2563eb;
    border-radius: 12px;
    font-size: 12px;
}
.post-meta .author-link { display: inline-flex; align-items: center; gap: 5px; color: #666; text-decoration: none; }
.post-meta .author-link img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; }
.post-right { text-align: right; flex-shrink: 0; }
.view-count { font-size: 13px; color: #999; margin-bottom: 8px; }
.view-btn {
    padding: 4px 12px;
    background: #f0f7ff;
    color: #2563eb;
    border-radius: 4px;
    font-size: 13px;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
}
.view-btn:hover { background: #2563eb; color: #fff; }
.del-link { font-size: 12px; color: #f56c6c; text-decoration: none; }
.post-right .del-link { margin-left: 8px; }

/* 弹窗 */
.modal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
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
    max-width: 650px;
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
    background: none; border: none;
    font-size: 22px; color: #999;
    cursor: pointer; line-height: 1;
}
.modal-body { padding: 24px; }
.form-item { margin-bottom: 16px; display: flex; flex-direction: column; gap: 6px; }
.form-item label { font-size: 13px; color: #666; }
.form-item input, .form-item textarea, .form-item select {
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    font-family: inherit;
}
.form-item input[type="file"] { padding: 8px; }
.form-item textarea { height: 120px; resize: vertical; }
.form-item select { height: 42px; background: #fff; }
.form-item input:focus, .form-item textarea:focus, .form-item select:focus { border-color: #2563eb; }
.msg { color: #f56c6c; margin-bottom: 12px; font-size: 14px; }

.preview-area {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
    margin-top: 10px;
}
.preview-item {
    position: relative;
    width: 100%;
    padding-top: 100%;
    border-radius: 6px;
    overflow: hidden;
    background: #f5f5f5;
}
.preview-item img {
    position: absolute;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.preview-del {
    position: absolute;
    top: 4px; right: 4px;
    width: 20px; height: 20px;
    line-height: 18px;
    text-align: center;
    background: rgba(0,0,0,0.6);
    color: #fff;
    border-radius: 50%;
    font-size: 14px;
    cursor: pointer;
    border: none;
    padding: 0;
}
.upload-tip { font-size: 12px; color: #999; margin-top: 4px; }

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #f0f0f0;
    text-align: right;
}
.cancel-btn {
    height: 38px; padding: 0 24px;
    background: #f0f2f5; color: #666;
    border: none; border-radius: 6px;
    cursor: pointer; margin-right: 10px;
}
.submit-btn {
    height: 38px; padding: 0 24px;
    background: #2563eb; color: #fff;
    border: none; border-radius: 6px;
    cursor: pointer;
}

.empty { text-align: center; padding: 40px 0; color: #999; background: #fff; border-radius: 8px; font-size: 14px; }

/* 手机端适配 */
@media (max-width: 768px) {
    .page-title { font-size: 22px; margin-bottom: 14px; }
    .post-item { padding: 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .post-main { width: 100%; }
    .post-thumb { width: 60px; height: 60px; }
    .post-right { width: 100%; display: flex; justify-content: space-between; align-items: center; }
    .preview-area { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 480px) {
    .page-title { font-size: 20px; }
    .post-btn { padding: 0 14px; font-size: 13px; }
    .post-meta { gap: 10px; }
    .preview-area { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-head">
    <h2 class="page-title">交流论坛</h2>
    <?php if ($is_login): ?>
        <div class="head-btns">
            <button id="newPostBtn" class="post-btn">发布新帖</button>
        </div>
    <?php else: ?>
        <a href="login.php" style="padding:10px 22px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:14px;">登录后发帖</a>
    <?php endif; ?>
</div>

<!-- 频道选择条 -->
<div class="channel-bar">
    <a href="forum.php" class="<?php echo $cur_channel ? '' : 'active'; ?>">全部</a>
    <?php foreach ($channels as $ch): ?>
        <a href="forum.php?channel=<?php echo $ch['id']; ?>" class="<?php echo $cur_channel == $ch['id'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($ch['name']); ?></a>
    <?php endforeach; ?>
</div>

<!-- 帖子列表 -->
<?php if (mysqli_num_rows($res) == 0): ?>
    <div class="empty" style="margin-bottom:12px;">该频道暂无内容，快来发布第一条吧～</div>
<?php endif; ?>
<div class="post-list">
    <?php while ($post = mysqli_fetch_assoc($res)): ?>
    <div class="post-item">
        <div class="post-main">
            <?php if ($post['first_img']): ?>
                <img src="<?php echo htmlspecialchars($post['first_img']); ?>" class="post-thumb" alt="帖子配图">
            <?php endif; ?>
            <div class="post-info">
                <h3><a href="post_detail.php?id=<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a></h3>
                <div class="post-meta">
                    <?php if ($post['channel_name']): ?>
                        <span class="chan-tag"><?php echo htmlspecialchars($post['channel_name']); ?></span>
                    <?php endif; ?>
                    <span class="author-link">
                        <a href="profile.php?id=<?php echo $post['author_id']; ?>" style="display:inline-flex;align-items:center;gap:6px;color:#666;text-decoration:none;">
                            <img src="<?php echo htmlspecialchars($post['author_avatar'] ? $post['author_avatar'] : '/img/default_avatar.png'); ?>" onerror="this.src='/img/default_avatar.png'">
                            <?php echo htmlspecialchars($post['username']); ?>
                        </a>
                    </span>
                    <span><?php echo htmlspecialchars($post['create_time']); ?></span>
                </div>
            </div>
        </div>
        <div class="post-right">
            <div class="view-count">浏览 <?php echo $post['view_count']; ?></div>
            <div>
                <a href="post_detail.php?id=<?php echo $post['id']; ?>" class="view-btn">查看</a>
                <?php
                $can_del_post = false;
                if ($my_role == 'super') $can_del_post = true;
                elseif ($my_role == 'admin' || $my_role == 'senior') {
                    $alv = isset($role_level[$post['author_role']]) ? $role_level[$post['author_role']] : 1;
                    $can_del_post = ($post['user_id'] == $login_user['id']) || ($role_level[$my_role] > $alv);
                }
                if ($can_del_post):
                ?>
                    <a href="?del=<?php echo $post['id']; ?>&token=<?php echo urlencode(csrf_token()); ?>" class="del-link" onclick="return confirm('确定删除该帖子？')">删除</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endwhile; ?>
</div>

<!-- 发帖弹窗 -->
<div id="postModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>发布新帖</h4>
            <button class="modal-close" id="closeModal">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="modal-body">
                <?php if (isset($msg)) echo '<div class="msg">'.$msg.'</div>'; ?>
                <div class="form-item">
                    <label>选择频道</label>
                    <select name="channel_id" required>
                        <option value="">请选择频道</option>
                        <?php foreach ($channels as $ch): ?>
                            <option value="<?php echo $ch['id']; ?>" <?php echo $cur_channel == $ch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-item">
                    <label>帖子标题</label>
                    <input type="text" name="title" placeholder="请输入帖子标题" required>
                </div>
                <div class="form-item">
                    <label>帖子内容</label>
                    <textarea name="content" placeholder="请输入详细内容" required></textarea>
                </div>
                <div class="form-item">
                    <label>上传图片（可选，最多<?php echo $max_image; ?>张）</label>
                    <input type="file" id="imageInput" name="post_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    <div class="upload-tip">支持 jpg/png/gif/webp，单张最大 5MB</div>
                    <div class="preview-area" id="previewArea"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" id="cancelBtn">取消</button>
                <button type="submit" name="new_post" class="submit-btn">发布</button>
            </div>
        </form>
    </div>
</div>

<script>
const maxImage = <?php echo $max_image; ?>;
const imageInput = document.getElementById('imageInput');
const previewArea = document.getElementById('previewArea');
let fileList = [];

imageInput.addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    if (fileList.length + files.length > maxImage) {
        alert(`最多只能上传${maxImage}张图片，当前已选${fileList.length}张`);
        imageInput.value = '';
        syncInputFiles();
        return;
    }
    files.forEach(file => {
        if (!file.type.startsWith('image/')) return;
        fileList.push(file);
        const reader = new FileReader();
        reader.onload = function(ev) {
            const index = fileList.length - 1;
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.dataset.index = index;
            div.innerHTML = `
                <img src="${ev.target.result}" alt="预览">
                <button type="button" class="preview-del" onclick="removeImage(${index})">×</button>
            `;
            previewArea.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
    syncInputFiles();
});

function syncInputFiles() {
    const dt = new DataTransfer();
    fileList.forEach(f => dt.items.add(f));
    imageInput.files = dt.files;
}

function removeImage(index) {
    fileList.splice(index, 1);
    renderPreview();
    syncInputFiles();
}

function renderPreview() {
    previewArea.innerHTML = '';
    fileList.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(ev) {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `
                <img src="${ev.target.result}" alt="预览">
                <button type="button" class="preview-del" onclick="removeImage(${index})">×</button>
            `;
            previewArea.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// 发帖弹窗控制
const postModal = document.getElementById('postModal');
const btn = document.getElementById('newPostBtn');
const closeBtn = document.getElementById('closeModal');
const cancelBtn = document.getElementById('cancelBtn');
if (btn) btn.addEventListener('click', () => postModal.classList.add('show'));
function closePostModal() {
    postModal.classList.remove('show');
    fileList = [];
    previewArea.innerHTML = '';
}
if (closeBtn) closeBtn.addEventListener('click', closePostModal);
if (cancelBtn) cancelBtn.addEventListener('click', closePostModal);
postModal.addEventListener('click', e => { if (e.target === postModal) closePostModal(); });
</script>

<?php require_once 'footer.php'; ?>
