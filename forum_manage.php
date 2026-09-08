<?php
require_once 'header.php';
check_admin();

// ============================================================
// 本文件辅助函数（私有，仅 forum_manage.php 使用）
// ============================================================

// 构建管理页 URL，自动过滤空值参数
function fm_url($params = []) {
    $clean = array_filter($params, function($v) { return $v !== '' && $v !== null; });
    $q = http_build_query($clean);
    return 'forum_manage.php' . ($q ? '?' . $q : '');
}

// 预处理封装：执行 SELECT 并返回 result set
function fm_query($sql, $types = '', $params = []) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        error_log('fm_query prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
        return false;
    }
    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log('fm_query execute failed: ' . mysqli_stmt_error($stmt));
        return false;
    }
    return mysqli_stmt_get_result($stmt);
}

// 预处理封装：执行写操作（INSERT/UPDATE/DELETE），返回 bool
function fm_exec($sql, $types = '', $params = []) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) {
        error_log('fm_exec prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
        return false;
    }
    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        error_log('fm_exec execute failed: ' . mysqli_stmt_error($stmt));
    }
    return $ok;
}

// 安全删除帖子图片：仅允许删除 DOCUMENT_ROOT/upload 下的文件，防路径穿越
function fm_delete_post_image($image_path) {
    if (!$image_path) return;
    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $upload_dir = $doc_root . '/upload';
    $full = $doc_root . $image_path;
    $real = realpath($full);
    $upload_real = realpath($upload_dir);
    if ($real && $upload_real && strpos($real . '/', rtrim($upload_real, '/') . '/') === 0 && is_file($real)) {
        @unlink($real);
    }
}

// 生成分页 HTML
function fm_pagination($current, $total_pages, $base_params, $page_key) {
    if ($total_pages <= 1) return '';
    $html = '<div class="pagination">';
    if ($current > 1) {
        $p = array_merge($base_params, [$page_key => $current - 1]);
        $html .= '<a href="' . fm_url($p) . '" class="page-link">上一页</a>';
    }
    $html .= '<span class="page-info">第 ' . $current . ' / ' . $total_pages . ' 页</span>';
    if ($current < $total_pages) {
        $p = array_merge($base_params, [$page_key => $current + 1]);
        $html .= '<a href="' . fm_url($p) . '" class="page-link">下一页</a>';
    }
    $html .= '</div>';
    return $html;
}

// ============================================================
// 写操作处理（全部 POST + CSRF 校验）
// ============================================================

// ---- 保存频道（新增/编辑）----
if (isset($_POST['channel_save'])) {
    csrf_check();
    $cid = intval($_POST['channel_id']);
    $name = trim($_POST['name']);
    $desc = trim($_POST['description']);
    $moderator_id = intval($_POST['moderator_id']);
    $sort = intval($_POST['sort']);
    if ($name !== '') {
        if ($cid) {
            fm_exec(
                'UPDATE channel SET name=?, description=?, moderator_id=?, sort=? WHERE id=?',
                'ssiii',
                [$name, $desc, $moderator_id, $sort, $cid]
            );
        } else {
            fm_exec(
                'INSERT INTO channel (name, description, moderator_id, sort, create_time) VALUES (?,?,?,?,NOW())',
                'ssii',
                [$name, $desc, $moderator_id, $sort]
            );
        }
    }
    header('Location: ' . fm_url([
        'channel_kw' => $_GET['channel_kw'] ?? '',
        'channel_page' => $_GET['channel_page'] ?? '',
    ]));
    exit;
}

// ---- 删除频道（POST）----
if (isset($_POST['del_channel'])) {
    csrf_check();
    $cid = intval($_POST['del_channel']);
    fm_exec('UPDATE forum_post SET channel_id=0 WHERE channel_id=?', 'i', [$cid]);
    fm_exec('DELETE FROM channel WHERE id=?', 'i', [$cid]);
    header('Location: ' . fm_url([
        'channel_kw' => $_GET['channel_kw'] ?? '',
        'channel_page' => $_GET['channel_page'] ?? '',
    ]));
    exit;
}

// ---- 保存帖子编辑 ----
if (isset($_POST['post_edit_save'])) {
    csrf_check();
    $pid = intval($_POST['post_id']);
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $channel_id = intval($_POST['channel_id']);
    $pw = mysqli_fetch_assoc(fm_query(
        'SELECT p.user_id, u.role FROM forum_post p LEFT JOIN user u ON p.user_id=u.id WHERE p.id=?',
        'i',
        [$pid]
    ));
    $can_edit = false;
    if ($login_user['role'] == 'super') $can_edit = true;
    elseif ($pw && $pw['role'] !== 'super') $can_edit = true;
    if ($can_edit && $title !== '' && $content !== '') {
        fm_exec(
            'UPDATE forum_post SET title=?, content=?, channel_id=? WHERE id=?',
            'ssii',
            [$title, $content, $channel_id, $pid]
        );
    }
    header('Location: ' . fm_url([
        'post_kw' => $_GET['post_kw'] ?? '',
        'post_page' => $_GET['post_page'] ?? '',
    ]));
    exit;
}

// ---- 删除帖子（POST，级联删图片+回复）----
if (isset($_POST['del_post'])) {
    csrf_check();
    $pid = intval($_POST['del_post']);
    $pw = mysqli_fetch_assoc(fm_query(
        'SELECT p.user_id, u.role FROM forum_post p LEFT JOIN user u ON p.user_id=u.id WHERE p.id=?',
        'i',
        [$pid]
    ));
    $can_del = false;
    if ($login_user['role'] == 'super') $can_del = true;
    elseif ($pw) {
        $alv = isset($role_level[$pw['role']]) ? $role_level[$pw['role']] : 1;
        $can_del = ($pw['user_id'] == $login_user['id']) || ($role_level[$login_user['role']] > $alv);
    }
    if ($can_del) {
        $img_res = fm_query('SELECT image_path FROM forum_post_image WHERE post_id=?', 'i', [$pid]);
        while ($img = mysqli_fetch_assoc($img_res)) {
            fm_delete_post_image($img['image_path']);
        }
        fm_exec('DELETE FROM forum_post_image WHERE post_id=?', 'i', [$pid]);
        fm_exec('DELETE FROM forum_post WHERE id=?', 'i', [$pid]);
        fm_exec('DELETE FROM forum_reply WHERE post_id=?', 'i', [$pid]);
    }
    header('Location: ' . fm_url([
        'post_kw' => $_GET['post_kw'] ?? '',
        'post_page' => $_GET['post_page'] ?? '',
    ]));
    exit;
}

// ============================================================
// 数据查询（全部预处理 + 分页）
// ============================================================

// 用户列表（供版主选择）
$user_res = mysqli_query($conn, 'SELECT id, username, name FROM user ORDER BY CAST(user_no AS UNSIGNED)');
$users = [];
while ($u = mysqli_fetch_assoc($user_res)) $users[] = $u;

// 全量频道（供帖子编辑弹窗选择，不分页）
$all_channels_res = mysqli_query($conn, 'SELECT id, name FROM channel ORDER BY sort ASC, id ASC');
$all_channels = [];
while ($ac = mysqli_fetch_assoc($all_channels_res)) $all_channels[] = $ac;

// ---- 频道搜索 + 分页 ----
$channel_kw = isset($_GET['channel_kw']) ? trim($_GET['channel_kw']) : '';
$channel_page = max(1, intval($_GET['channel_page'] ?? 1));
$channel_per_page = 10;
$channel_offset = ($channel_page - 1) * $channel_per_page;

if ($channel_kw !== '') {
    $kw = '%' . $channel_kw . '%';
    $chan_total = mysqli_fetch_assoc(fm_query(
        'SELECT COUNT(*) c FROM channel c LEFT JOIN user u ON c.moderator_id=u.id WHERE c.name LIKE ? OR c.description LIKE ? OR u.username LIKE ? OR u.name LIKE ?',
        'ssss',
        [$kw, $kw, $kw, $kw]
    ))['c'];
    $chan_res = fm_query(
        'SELECT c.*, u.username AS mod_name, u.name AS mod_nick FROM channel c LEFT JOIN user u ON c.moderator_id=u.id WHERE c.name LIKE ? OR c.description LIKE ? OR u.username LIKE ? OR u.name LIKE ? ORDER BY c.sort ASC, c.id ASC LIMIT ? OFFSET ?',
        'ssssii',
        [$kw, $kw, $kw, $kw, $channel_per_page, $channel_offset]
    );
} else {
    $chan_total = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) c FROM channel'))['c'];
    $chan_res = mysqli_query($conn, "SELECT c.*, u.username AS mod_name, u.name AS mod_nick FROM channel c LEFT JOIN user u ON c.moderator_id=u.id ORDER BY c.sort ASC, c.id ASC LIMIT $channel_per_page OFFSET $channel_offset");
}
$channel_total_pages = max(1, ceil($chan_total / $channel_per_page));
$channels = [];
while ($ch = mysqli_fetch_assoc($chan_res)) $channels[] = $ch;

// ---- 帖子搜索 + 分页 ----
$post_kw = isset($_GET['post_kw']) ? trim($_GET['post_kw']) : '';
$post_page = max(1, intval($_GET['post_page'] ?? 1));
$post_per_page = 10;
$post_offset = ($post_page - 1) * $post_per_page;

if ($post_kw !== '') {
    $kw = '%' . $post_kw . '%';
    $post_total = mysqli_fetch_assoc(fm_query(
        'SELECT COUNT(*) c FROM forum_post p LEFT JOIN user u ON p.user_id=u.id LEFT JOIN channel c ON p.channel_id=c.id WHERE p.title LIKE ? OR u.username LIKE ? OR c.name LIKE ?',
        'sss',
        [$kw, $kw, $kw]
    ))['c'];
    $post_res = fm_query(
        'SELECT p.*, u.username, u.role AS author_role, c.name AS channel_name FROM forum_post p LEFT JOIN user u ON p.user_id=u.id LEFT JOIN channel c ON p.channel_id=c.id WHERE p.title LIKE ? OR u.username LIKE ? OR c.name LIKE ? ORDER BY p.id DESC LIMIT ? OFFSET ?',
        'sssii',
        [$kw, $kw, $kw, $post_per_page, $post_offset]
    );
} else {
    $post_total = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) c FROM forum_post'))['c'];
    $post_res = mysqli_query($conn, "SELECT p.*, u.username, u.role AS author_role, c.name AS channel_name FROM forum_post p LEFT JOIN user u ON p.user_id=u.id LEFT JOIN channel c ON p.channel_id=c.id ORDER BY p.id DESC LIMIT $post_per_page OFFSET $post_offset");
}
$post_total_pages = max(1, ceil($post_total / $post_per_page));

// 卡片统计（全量总数，不受搜索影响）
$chan_total_all = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) c FROM channel'))['c'];
$post_total_all = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) c FROM forum_post'))['c'];
?>
<style>
.admin-title { font-size: 24px; margin-bottom: 24px; color: #2d3748; }
.admin-nav { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.admin-nav a {
    padding: 10px 20px;
    background: #fff;
    border-radius: 6px;
    text-decoration: none;
    color: #555;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.admin-nav a.active { background: #2563eb; color: #fff; }

.panel {
    background: #fff;
    padding: 24px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.panel h3 {
    margin-bottom: 16px;
    color: #333;
    font-size: 16px;
    border-left: 3px solid #2563eb;
    padding-left: 10px;
}

/* ===== 管理功能小板块卡片（16:9） ===== */
.manage-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 15px;
}
.manage-card {
    aspect-ratio: 16 / 9;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 18px;
    border: 1px solid #f0f0f0;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: box-shadow 0.2s;
}
.manage-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
.mc-head h4 { color: #333; font-size: 16px; margin-bottom: 6px; }
.mc-head p { color: #999; font-size: 13px; margin: 0; line-height: 1.5; }
.open-btn {
    height: 34px;
    padding: 0 18px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    white-space: nowrap;
    align-self: flex-end;
    transition: background 0.2s;
}
.open-btn:hover { background: #1d4ed8; }

/* ===== 小窗弹窗 ===== */
.modal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    overflow-y: auto;
}
.modal.show { display: block; }
.modal.show .modal-content { animation: modalIn 0.25s ease both; }
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-16px); }
    to   { opacity: 1; transform: translateY(0); }
}
.modal-content {
    background: #fff;
    max-width: 900px;
    margin: 4% auto;
    border-radius: 10px;
    overflow: hidden;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 24px;
    border-bottom: 1px solid #f0f0f0;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
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

/* 编辑弹窗（在管理小窗之上） */
.edit-modal { z-index: 1100; }
.edit-modal .modal-content { max-width: 560px; }

/* 搜索栏 */
.search-bar { display: flex; gap: 8px; margin-bottom: 16px; }
.search-bar input {
    flex: 1;
    min-width: 0;
    height: 38px;
    padding: 0 12px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    font-family: inherit;
}
.search-bar input:focus { border-color: #2563eb; }
.search-btn {
    height: 38px;
    padding: 0 20px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    white-space: nowrap;
}
.search-btn:hover { background: #1d4ed8; }
.reset-btn {
    height: 38px;
    line-height: 38px;
    padding: 0 16px;
    background: #f0f2f5;
    color: #666;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    white-space: nowrap;
}
.add-btn {
    height: 38px;
    padding: 0 18px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    white-space: nowrap;
}
.add-btn:hover { background: #1d4ed8; }
.toolbar { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.toolbar .search-bar { flex: 1; min-width: 220px; margin-bottom: 0; }

/* 编辑表单 */
.form-item { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.form-item label { font-size: 13px; color: #666; }
.form-item input, .form-item textarea, .form-item select {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    font-family: inherit;
    background: #fff;
}
.form-item textarea { height: 140px; padding: 10px 12px; resize: vertical; }
.form-item input:focus, .form-item textarea:focus, .form-item select:focus { border-color: #2563eb; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #f0f0f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.submit-btn {
    height: 38px; padding: 0 24px;
    background: #2563eb; color: #fff;
    border: none; border-radius: 6px;
    cursor: pointer; font-size: 14px;
}
.submit-btn:hover { background: #1d4ed8; }
.cancel-btn {
    height: 38px; padding: 0 24px;
    background: #f0f2f5; color: #666;
    border: none; border-radius: 6px;
    cursor: pointer; font-size: 14px;
}
.cancel-btn:hover { background: #e4e7ed; }

/* 表格 */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 560px; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
th { color: #666; font-weight: 600; background: #fafafa; white-space: nowrap; }
td { color: #444; }
.mod-tag { display: inline-block; padding: 2px 10px; background: #f3e8ff; color: #7c3aed; border-radius: 12px; font-size: 12px; }
.empty { text-align: center; padding: 30px 0; color: #bbb; font-size: 14px; }
.edit-link { color: #2563eb; text-decoration: none; font-size: 13px; margin-right: 8px; cursor: pointer; }

/* 删除按钮（POST 表单，外观保持链接样式） */
.inline-form { display: inline; }
.del-link-btn {
    background: none;
    border: none;
    color: #f56c6c;
    font-size: 13px;
    cursor: pointer;
    padding: 0;
    font-family: inherit;
    text-decoration: none;
}
.del-link-btn:hover { text-decoration: underline; }

/* 分页 */
.pagination {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 16px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.page-link {
    padding: 6px 14px;
    background: #fff;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    color: #2563eb;
    text-decoration: none;
    font-size: 13px;
}
.page-link:hover { background: #f0f2f5; }
.page-info { font-size: 13px; color: #999; }

/* 手机端适配 */
@media (max-width: 768px) {
    .admin-title { font-size: 20px; margin-bottom: 18px; }
    .admin-nav { gap: 10px; margin-bottom: 16px; }
    .admin-nav a { padding: 8px 14px; font-size: 14px; }
    .panel { padding: 16px; }
    .manage-card { padding: 16px; }
    /* 小窗全屏 */
    .modal-content { margin: 0; max-width: 100%; min-height: 100vh; border-radius: 0; }
    .modal-body { padding: 18px 16px; }
    .modal-header, .modal-footer { padding: 16px; }
    /* 表格转卡片 */
    .tbl-wrap { overflow-x: visible; }
    table { min-width: 0; }
    table, thead, tbody, tr, td { display: block; width: 100%; }
    thead { display: none; }
    tbody tr {
        background: #fafbfc;
        border: 1px solid #eef0f3;
        border-radius: 8px;
        margin-bottom: 12px;
        padding: 4px 12px;
    }
    tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 9px 0;
        border-bottom: 1px dashed #ececec;
        text-align: right;
    }
    tbody td:last-child { border-bottom: none; }
    tbody td::before {
        content: attr(data-label);
        color: #999;
        font-size: 12px;
        flex-shrink: 0;
    }
    tbody td.empty { display: block; text-align: center; }
    tbody td.empty::before { display: none; }
    .hide-mobile { display: none !important; }
    .edit-link { margin-right: 12px; }
    .pagination { justify-content: center; }
}
@media (max-width: 480px) {
    .manage-grid { grid-template-columns: 1fr; }
    .modal-footer { flex-wrap: wrap; }
    .modal-footer .cancel-btn, .modal-footer .submit-btn { flex: 1; }
}
</style>

<h2 class="admin-title">论坛管理</h2>
<div class="admin-nav">
    <a href="admin.php">首页</a>
    <a href="forum_manage.php" class="active">论坛</a>
    <a href="service_manage.php">服务支持</a>
    <a href="user_manage.php">用户</a>
</div>

<!-- 管理功能入口卡片 -->
<div class="panel">
    <h3>管理功能</h3>
    <div class="manage-grid">
        <div class="manage-card">
            <div class="mc-head">
                <h4>频道管理</h4>
                <p>新增 / 编辑频道，设置版主与排序（共 <?php echo $chan_total_all; ?> 个频道）</p>
            </div>
            <button type="button" class="open-btn" id="openChannelBtn">打开管理</button>
        </div>
        <div class="manage-card">
            <div class="mc-head">
                <h4>帖子管理</h4>
                <p>搜索、编辑并管理论坛帖子（共 <?php echo $post_total_all; ?> 篇帖子）</p>
            </div>
            <button type="button" class="open-btn" id="openPostBtn">打开管理</button>
        </div>
    </div>
</div>

<!-- 频道管理小窗 -->
<div id="channelModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>频道管理</h4>
            <button type="button" class="modal-close" id="closeChannel">&times;</button>
        </div>
        <div class="modal-body">
            <div class="toolbar">
                <form method="get" class="search-bar">
                    <input type="text" name="channel_kw" value="<?php echo htmlspecialchars($channel_kw); ?>" placeholder="搜索频道名称 / 描述 / 版主">
                    <button type="submit" class="search-btn">搜索</button>
                </form>
                <button type="button" class="add-btn" id="addChannelBtn">新增频道</button>
                <?php if ($channel_kw !== ''): ?>
                    <a href="forum_manage.php" class="reset-btn">重置</a>
                <?php endif; ?>
            </div>

            <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>排序</th>
                        <th>频道名称</th>
                        <th class="hide-mobile">描述</th>
                        <th>版主</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($channels)): ?>
                        <tr><td colspan="5" class="empty"><?php echo $channel_kw !== '' ? '没有匹配的频道' : '暂无频道'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($channels as $ch): ?>
                    <tr>
                        <td data-label="排序"><?php echo $ch['sort']; ?></td>
                        <td data-label="频道名称"><b><?php echo htmlspecialchars($ch['name']); ?></b></td>
                        <td data-label="描述" class="hide-mobile"><?php echo htmlspecialchars($ch['description']); ?></td>
                        <td data-label="版主">
                            <?php if ($ch['moderator_id']): ?>
                                <span class="mod-tag"><?php echo htmlspecialchars($ch['mod_nick'] ? $ch['mod_nick'] : $ch['mod_name']); ?></span>
                            <?php else: ?>
                                <span style="color:#bbb;">未设置</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="操作">
                            <a href="javascript:void(0)" class="edit-link channel-edit-link"
                               data-id="<?php echo $ch['id']; ?>"
                               data-name="<?php echo htmlspecialchars($ch['name']); ?>"
                               data-desc="<?php echo htmlspecialchars($ch['description']); ?>"
                               data-mod="<?php echo $ch['moderator_id']; ?>"
                               data-sort="<?php echo $ch['sort']; ?>">编辑</a>
                            <form method="post" class="inline-form" onsubmit="return confirm('删除频道后其下帖子将变为未分类，确定删除？');">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="del_channel" value="<?php echo $ch['id']; ?>">
                                <button type="submit" class="del-link-btn">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php echo fm_pagination($channel_page, $channel_total_pages, ['channel_kw' => $channel_kw], 'channel_page'); ?>
        </div>
    </div>
</div>

<!-- 帖子管理小窗 -->
<div id="postModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>帖子管理</h4>
            <button type="button" class="modal-close" id="closePost">&times;</button>
        </div>
        <div class="modal-body">
            <div class="toolbar">
                <form method="get" class="search-bar">
                    <input type="text" name="post_kw" value="<?php echo htmlspecialchars($post_kw); ?>" placeholder="搜索帖子标题 / 作者 / 频道">
                    <button type="submit" class="search-btn">搜索</button>
                </form>
                <?php if ($post_kw !== ''): ?>
                    <a href="forum_manage.php" class="reset-btn">重置</a>
                <?php endif; ?>
            </div>

            <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>标题</th>
                        <th>作者</th>
                        <th>频道</th>
                        <th class="hide-mobile">浏览</th>
                        <th class="hide-mobile">发布时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($post_res) == 0): ?>
                        <tr><td colspan="6" class="empty"><?php echo $post_kw !== '' ? '没有匹配的帖子' : '暂无帖子'; ?></td></tr>
                    <?php endif; ?>
                    <?php while ($p = mysqli_fetch_assoc($post_res)): ?>
                    <tr>
                        <td data-label="标题"><a href="post_detail.php?id=<?php echo $p['id']; ?>" target="_blank" style="color:#2563eb;text-decoration:none;"><?php echo htmlspecialchars($p['title']); ?></a></td>
                        <td data-label="作者"><?php echo htmlspecialchars($p['username']); ?></td>
                        <td data-label="频道"><?php echo $p['channel_name'] ? htmlspecialchars($p['channel_name']) : '未分类'; ?></td>
                        <td data-label="浏览" class="hide-mobile"><?php echo $p['view_count']; ?></td>
                        <td data-label="发布时间" class="hide-mobile"><?php echo $p['create_time']; ?></td>
                        <td data-label="操作">
                            <?php if ($login_user['role'] == 'super' || $p['author_role'] !== 'super'): ?>
                            <a href="javascript:void(0)" class="edit-link post-edit-link"
                               data-id="<?php echo $p['id']; ?>"
                               data-title="<?php echo htmlspecialchars($p['title']); ?>"
                               data-content="<?php echo htmlspecialchars($p['content']); ?>"
                               data-channel="<?php echo $p['channel_id']; ?>">编辑</a>
                            <?php endif; ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('确定删除该帖子及所有回复？');">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="del_post" value="<?php echo $p['id']; ?>">
                                <button type="submit" class="del-link-btn">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php echo fm_pagination($post_page, $post_total_pages, ['post_kw' => $post_kw], 'post_page'); ?>
        </div>
    </div>
</div>

<!-- 频道编辑弹窗（新增/编辑） -->
<div id="channelEditModal" class="modal edit-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 id="channelEditTitle">新增频道</h4>
            <button type="button" class="modal-close" id="closeChannelEdit">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="channel_id" id="ce_id" value="0">
            <div class="modal-body">
                <div class="form-item">
                    <label>频道名称</label>
                    <input type="text" name="name" id="ce_name" required placeholder="如：技术交流">
                </div>
                <div class="form-item">
                    <label>频道描述</label>
                    <input type="text" name="description" id="ce_desc" placeholder="一句话描述">
                </div>
                <div class="form-item">
                    <label>版主</label>
                    <select name="moderator_id" id="ce_mod">
                        <option value="0">不设版主</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name'] ? $u['name'] : $u['username']); ?>（<?php echo htmlspecialchars($u['username']); ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-item">
                    <label>排序</label>
                    <input type="number" name="sort" id="ce_sort" value="0" min="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" id="cancelChannelEdit">取消</button>
                <button type="submit" name="channel_save" class="submit-btn">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 帖子编辑弹窗 -->
<div id="postEditModal" class="modal edit-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>编辑帖子</h4>
            <button type="button" class="modal-close" id="closePostEdit">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="post_id" id="pe_id">
            <div class="modal-body">
                <div class="form-item">
                    <label>帖子标题</label>
                    <input type="text" name="title" id="pe_title" required>
                </div>
                <div class="form-item">
                    <label>帖子内容</label>
                    <textarea name="content" id="pe_content" required></textarea>
                </div>
                <div class="form-item">
                    <label>所属频道</label>
                    <select name="channel_id" id="pe_channel">
                        <option value="0">未分类</option>
                        <?php foreach ($all_channels as $ch): ?>
                            <option value="<?php echo $ch['id']; ?>"><?php echo htmlspecialchars($ch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" id="cancelPostEdit">取消</button>
                <button type="submit" name="post_edit_save" class="submit-btn">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    try {
        const channelModal = document.getElementById('channelModal');
        const postModal = document.getElementById('postModal');
        const channelEditModal = document.getElementById('channelEditModal');
        const postEditModal = document.getElementById('postEditModal');
        const allModals = [channelModal, postModal, channelEditModal, postEditModal];

        function openModal(m) {
            m.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function closeModal(m) {
            m.classList.remove('show');
            const anyOpen = allModals.some(function(x) { return x.classList.contains('show'); });
            if (!anyOpen) document.body.style.overflow = '';
        }

        // 管理小窗开关
        const openChannelBtn = document.getElementById('openChannelBtn');
        const openPostBtn = document.getElementById('openPostBtn');
        if (openChannelBtn) openChannelBtn.addEventListener('click', function() { openModal(channelModal); });
        if (openPostBtn) openPostBtn.addEventListener('click', function() { openModal(postModal); });
        document.getElementById('closeChannel').addEventListener('click', function() { closeModal(channelModal); });
        document.getElementById('closePost').addEventListener('click', function() { closeModal(postModal); });
        [channelModal, postModal].forEach(function(m) {
            m.addEventListener('click', function(e) { if (e.target === m) closeModal(m); });
        });

        // 频道编辑弹窗
        function openChannelAdd() {
            document.getElementById('ce_id').value = 0;
            document.getElementById('ce_name').value = '';
            document.getElementById('ce_desc').value = '';
            document.getElementById('ce_mod').value = 0;
            document.getElementById('ce_sort').value = 0;
            document.getElementById('channelEditTitle').textContent = '新增频道';
            openModal(channelEditModal);
        }
        function openChannelEdit(id, name, desc, modId, sort) {
            document.getElementById('ce_id').value = id;
            document.getElementById('ce_name').value = name;
            document.getElementById('ce_desc').value = desc;
            document.getElementById('ce_mod').value = modId;
            document.getElementById('ce_sort').value = sort;
            document.getElementById('channelEditTitle').textContent = '编辑频道';
            openModal(channelEditModal);
        }
        document.getElementById('addChannelBtn').addEventListener('click', openChannelAdd);
        document.getElementById('closeChannelEdit').addEventListener('click', function() { closeModal(channelEditModal); });
        document.getElementById('cancelChannelEdit').addEventListener('click', function() { closeModal(channelEditModal); });
        channelEditModal.addEventListener('click', function(e) { if (e.target === channelEditModal) closeModal(channelEditModal); });

        // 频道编辑链接：data-* 属性传参（事件委托）
        document.querySelectorAll('.channel-edit-link').forEach(function(el) {
            el.addEventListener('click', function() {
                openChannelEdit(el.dataset.id, el.dataset.name, el.dataset.desc, el.dataset.mod, el.dataset.sort);
            });
        });

        // 帖子编辑弹窗
        function openPostEdit(id, title, content, channelId) {
            document.getElementById('pe_id').value = id;
            document.getElementById('pe_title').value = title;
            document.getElementById('pe_content').value = content;
            document.getElementById('pe_channel').value = channelId;
            openModal(postEditModal);
        }
        document.getElementById('closePostEdit').addEventListener('click', function() { closeModal(postEditModal); });
        document.getElementById('cancelPostEdit').addEventListener('click', function() { closeModal(postEditModal); });
        postEditModal.addEventListener('click', function(e) { if (e.target === postEditModal) closeModal(postEditModal); });

        // 帖子编辑链接：data-* 属性传参（事件委托）
        document.querySelectorAll('.post-edit-link').forEach(function(el) {
            el.addEventListener('click', function() {
                openPostEdit(el.dataset.id, el.dataset.title, el.dataset.content, el.dataset.channel);
            });
        });

        // URL 参数自动打开对应管理小窗（搜索/分页后保持）
        const urlParams = new URLSearchParams(location.search);
        const chPage = parseInt(urlParams.get('channel_page') || '1', 10);
        const poPage = parseInt(urlParams.get('post_page') || '1', 10);
        if (urlParams.has('channel_kw') || chPage > 1) channelModal.classList.add('show');
        if (urlParams.has('post_kw') || poPage > 1) postModal.classList.add('show');
        if (channelModal.classList.contains('show') || postModal.classList.contains('show')) {
            document.body.style.overflow = 'hidden';
        }
    } catch (e) {
        console.error('forum_manage init error:', e);
    }
})();
</script>

<?php require_once 'footer.php'; ?>
