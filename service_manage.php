<?php
require_once 'header.php';
check_admin();

$current_role = $login_user['role'];
$current_uid = $login_user['id'];

// ============================================================
// 辅助函数
// ============================================================
function sm_query($sql, $types = '', $params = []) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) { error_log('sm_query: ' . mysqli_error($conn)); return false; }
    if ($types !== '' && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) return false;
    return mysqli_stmt_get_result($stmt);
}
function sm_exec($sql, $types = '', $params = []) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) { error_log('sm_exec: ' . mysqli_error($conn)); return false; }
    if ($types !== '' && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    return mysqli_stmt_execute($stmt);
}
function sm_fsize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
// 判断当前管理员是否可管理某作品
function sm_can_manage($work) {
    global $login_user, $role_level;
    if ($login_user['role'] == 'super') return true;
    if ($login_user['role'] != 'admin') return false;
    $author_level = isset($role_level[$work['author_role']]) ? $role_level[$work['author_role']] : 1;
    return ($work['user_id'] == $login_user['id']) || ($role_level[$login_user['role']] > $author_level);
}
// 安全删除 upload 目录下的文件（统一正斜杠比较，兼容 Windows 反斜杠路径）
function sm_delete_file($file_path) {
    if (!$file_path) return;
    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $real = str_replace('\\', '/', realpath($doc_root . $file_path));
    $upload_real = str_replace('\\', '/', realpath($doc_root . '/upload'));
    if ($real && $upload_real && strpos($real . '/', rtrim($upload_real, '/') . '/') === 0 && is_file($real)) {
        @unlink($doc_root . $file_path);
    }
}
// 把 $_FILES 规范化为列表（兼容单选/多选）
function sm_upload_list($files) {
    if (!$files || empty($files['name'])) return [];
    if (is_array($files['name'])) {
        $list = [];
        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] == 0 && $files['name'][$i] !== '') {
                $list[] = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            }
        }
        return $list;
    }
    return $files['name'] !== '' ? [$files] : [];
}
// 保存一个文件为一个版本
function sm_save_item($svc_id, $file_arr) {
    global $allowed_ext, $max_size;
    $ext = strtolower(pathinfo($file_arr['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext) || $file_arr['size'] > $max_size) return false;
    $filename = 'svc_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
    $url_path = '/upload/service/' . $filename;
    $save_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/service/' . $filename;
    if (!move_uploaded_file($file_arr['tmp_name'], $save_path)) return false;
    return sm_exec(
        'INSERT INTO service_file_item (service_id, file_path, file_name, file_size, create_time) VALUES (?,?,?,?,NOW())',
        'issi',
        [$svc_id, $url_path, $file_arr['name'], filesize($save_path)]
    );
}

$allowed_ext = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','zip','rar','7z','csv','json','xml','html','css','js','php','sql','png','jpg','jpeg','gif','webp'];
$max_size = 50 * 1024 * 1024;
$msg = '';

// ============================================================
// 新增作品（可一次选择多个文件，每个文件作为一个版本）
// ============================================================
if (isset($_POST['sm_add'])) {
    csrf_check();
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $upload_list = sm_upload_list($_FILES['sm_file'] ?? null);
    if ($title === '') {
        $msg = '请填写作品名称';
    } elseif (empty($upload_list)) {
        $msg = '请至少选择一个要上传的文件';
    } else {
        $bad = false;
        foreach ($upload_list as $f) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext) || $f['size'] > $max_size) { $bad = true; break; }
        }
        if ($bad) {
            $msg = '存在不支持的文件类型或超过 50MB 限制，请检查后重试';
        } else {
            sm_exec('INSERT INTO service_file (user_id, title, description, create_time) VALUES (?,?,?,NOW())', 'iss', [$current_uid, $title, $description]);
            $svc_id = mysqli_insert_id($GLOBALS['conn']);
            if (!$svc_id) {
                $msg = '作品创建失败，请重试';
            } else {
                foreach ($upload_list as $f) sm_save_item($svc_id, $f);
                header('Location: service_manage.php');
                exit;
            }
        }
    }
}

// ============================================================
// 保存编辑（更新标题/描述 + 可选追加新版本，保留旧版本）
// ============================================================
if (isset($_POST['sm_save'])) {
    csrf_check();
    $id = intval($_POST['file_id']);
    $work = mysqli_fetch_assoc(sm_query(
        'SELECT f.*, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id WHERE f.id=?',
        'i', [$id]
    ));
    if ($work && sm_can_manage($work)) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        if ($title !== '') {
            sm_exec('UPDATE service_file SET title=?, description=? WHERE id=?', 'ssi', [$title, $description, $id]);
            $upload_list = sm_upload_list($_FILES['sm_file'] ?? null);
            if (!empty($upload_list)) {
                foreach ($upload_list as $f) sm_save_item($id, $f);
            }
        }
    }
    header('Location: service_manage.php');
    exit;
}

// ============================================================
// 单独删除某个文件版本（最后一个版本删除后，作品随之删除）
// ============================================================
if (isset($_POST['sm_delete_item'])) {
    csrf_check();
    $item_id = intval($_POST['item_id']);
    $item = mysqli_fetch_assoc(sm_query(
        'SELECT i.*, s.user_id AS svc_user, u.role AS author_role FROM service_file_item i JOIN service_file s ON i.service_id=s.id LEFT JOIN user u ON s.user_id=u.id WHERE i.id=?',
        'i', [$item_id]
    ));
    if ($item) {
        $work = ['user_id' => $item['svc_user'], 'author_role' => $item['author_role']];
        if (sm_can_manage($work)) {
            sm_delete_file($item['file_path']);
            sm_exec('DELETE FROM service_file_item WHERE id=?', 'i', [$item_id]);
            $cnt = mysqli_fetch_assoc(sm_query('SELECT COUNT(*) AS c FROM service_file_item WHERE service_id=?', 'i', [$item['service_id']]));
            if ($cnt && $cnt['c'] == 0) {
                sm_exec('DELETE FROM service_file WHERE id=?', 'i', [$item['service_id']]);
            }
        }
    }
    header('Location: service_manage.php');
    exit;
}

// ============================================================
// 删除整个作品（含所有文件版本）
// ============================================================
if (isset($_POST['sm_delete'])) {
    csrf_check();
    $id = intval($_POST['file_id']);
    $work = mysqli_fetch_assoc(sm_query(
        'SELECT f.*, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id WHERE f.id=?',
        'i', [$id]
    ));
    if ($work && sm_can_manage($work)) {
        $items = sm_query('SELECT * FROM service_file_item WHERE service_id=?', 'i', [$id]);
        while ($it = mysqli_fetch_assoc($items)) sm_delete_file($it['file_path']);
        sm_exec('DELETE FROM service_file_item WHERE service_id=?', 'i', [$id]);
        sm_exec('DELETE FROM service_file WHERE id=?', 'i', [$id]);
    }
    header('Location: service_manage.php');
    exit;
}

// ============================================================
// 作品列表（支持搜索）
// ============================================================
$kw = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
if ($kw !== '') {
    $search = '%' . $kw . '%';
    $res = sm_query(
        'SELECT f.*, u.username, u.name AS author_name, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id WHERE f.title LIKE ? OR u.username LIKE ? OR u.name LIKE ? ORDER BY f.id DESC',
        'sss',
        [$search, $search, $search]
    );
} else {
    $res = sm_query(
        'SELECT f.*, u.username, u.name AS author_name, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id ORDER BY f.id DESC'
    );
}
$works = [];
while ($w = mysqli_fetch_assoc($res)) $works[] = $w;

// 一次性取所有文件版本，按作品分组
$items_by_svc = [];
$items_res = sm_query('SELECT * FROM service_file_item ORDER BY id ASC');
if ($items_res) {
    while ($it = mysqli_fetch_assoc($items_res)) $items_by_svc[$it['service_id']][] = $it;
}
?>
<style>
.admin-title { font-size: 24px; margin-bottom: 24px; color: #2d3748; }
.admin-nav { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.admin-nav a {
    padding: 10px 20px; background: #fff; border-radius: 6px;
    text-decoration: none; color: #555; box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.admin-nav a.active { background: #2563eb; color: #fff; }

/* 搜索 */
.search-wrap { margin-bottom: 16px; }
.search-form { display: flex; gap: 8px; max-width: 480px; }
.search-form input {
    flex: 1; height: 38px; padding: 0 14px;
    border: 1px solid #dcdfe6; border-radius: 6px; font-size: 14px; outline: none; background: #fff;
}
.search-form input:focus { border-color: #2563eb; }
.search-form button {
    height: 38px; padding: 0 20px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.search-form button:hover { background: #1d4ed8; }
.search-clear { line-height: 38px; color: #999; font-size: 13px; text-decoration: none; }
.search-clear:hover { color: #333; }

/* 新增按钮 */
.add-btn-wrap { margin-bottom: 20px; }
.add-btn {
    height: 40px; padding: 0 22px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.add-btn:hover { background: #1d4ed8; }

/* 弹窗 */
.modal {
    display: none; position: fixed; top: 0; left: 0;
    width: 100%; height: 100%; background: rgba(0,0,0,0.5);
    z-index: 1000; align-items: center; justify-content: center; padding: 20px;
}
.modal.show { display: flex; }
.modal-content {
    background: #fff; border-radius: 12px; width: 100%; max-width: 640px;
    max-height: 90vh; overflow-y: auto; animation: modalIn 0.2s ease-out;
}
@keyframes modalIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid #f0f0f0;
}
.modal-header h4 { font-size: 16px; color: #333; }
.modal-close { background: none; border: none; font-size: 22px; color: #999; cursor: pointer; line-height: 1; }
.modal-close:hover { color: #333; }
.modal-body { padding: 24px; }
.modal-footer {
    padding: 16px 24px; border-top: 1px solid #f0f0f0;
    display: flex; justify-content: space-between; align-items: center;
}
.form-item { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-item label { font-size: 13px; color: #666; }
.form-item input, .form-item textarea {
    height: 38px; padding: 0 12px; border: 1px solid #dcdfe6; border-radius: 6px;
    font-size: 14px; outline: none; font-family: inherit; background: #fff;
}
.form-item textarea { height: 80px; padding: 8px 12px; resize: vertical; }
.form-item input:focus, .form-item textarea:focus { border-color: #2563eb; }
.form-item input[type="file"] { padding: 8px; height: auto; }
.upload-tip { font-size: 12px; color: #999; margin-top: 4px; }
.submit-btn {
    height: 38px; padding: 0 24px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.submit-btn:hover { background: #1d4ed8; }
.cancel-btn {
    height: 38px; padding: 0 24px; background: #f0f2f5; color: #666;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.cancel-btn:hover { background: #e4e7ed; }
.del-btn { color: #f56c6c; text-decoration: none; font-size: 14px; cursor: pointer; }
.del-btn:hover { text-decoration: underline; }
.msg { color: #f56c6c; margin-bottom: 12px; font-size: 14px; }

/* 版本列表（管理弹窗内） */
.ver-title { font-size: 13px; color: #666; margin: 14px 0 8px; font-weight: 600; }
.ver-list { display: flex; flex-direction: column; gap: 8px; }
.ver-item {
    display: flex; align-items: center; gap: 10px; padding: 8px 12px;
    background: #f8f9fa; border: 1px solid #eef0f3; border-radius: 8px; font-size: 13px;
}
.ver-item .vi-name { flex: 1; min-width: 0; word-break: break-all; color: #444; }
.ver-item .vi-sub { color: #999; font-size: 12px; flex-shrink: 0; }
.ver-item .vi-del { color: #f56c6c; background: none; border: none; cursor: pointer; font-size: 12px; padding: 2px; font-family: inherit; flex-shrink: 0; }
.ver-item .vi-del:hover { text-decoration: underline; }
.ver-empty { color: #bbb; font-size: 13px; padding: 10px 0; }

/* 表格 */
.table-wrap { overflow-x: auto; }
table {
    width: 100%; background: #fff; border-radius: 8px; overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-collapse: collapse; min-width: 600px;
}
th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
th { background: #fafafa; font-weight: normal; color: #666; }
td { color: #444; }
.file-title { font-weight: 600; color: #333; max-width: 240px; word-break: break-word; }
.manage-btn {
    padding: 5px 14px; background: #f0f7ff; color: #2563eb;
    border: 1px solid #bfdbfe; border-radius: 4px; cursor: pointer;
    font-size: 13px; text-decoration: none; display: inline-block;
}
.manage-btn:hover { background: #2563eb; color: #fff; }
.empty { text-align: center; padding: 40px 0; color: #bbb; font-size: 14px; }

/* 手机端适配 */
@media (max-width: 768px) {
    .admin-title { font-size: 20px; margin-bottom: 18px; }
    .admin-nav { gap: 10px; margin-bottom: 16px; }
    .admin-nav a { padding: 8px 14px; font-size: 14px; }
    .modal-content { max-width: 100%; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }

    /* 管理表格手机端卡片化：每行一张卡片、字段竖排，无需横向滚动 */
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
    tbody tr td.file-title {
        font-size: 16px; font-weight: 600; color: #333;
        padding-bottom: 10px; border-bottom: 1px solid #f0f0f0;
    }
    tbody tr td.file-title::before { display: none; }
    tbody tr td:last-child { border-bottom: none; justify-content: flex-start; padding-top: 10px; }
    tbody tr td.empty {
        display: block; text-align: center; padding: 26px 0; border: none; color: #bbb;
    }
    tbody tr td.empty::before { display: none; }
    .manage-btn { padding: 8px 20px; font-size: 14px; }

    /* 管理弹窗内版本列表：文件名独占一行，大小/删除按钮靠右 */
    .ver-item { flex-wrap: wrap; align-items: center; row-gap: 6px; }
    .ver-item .vi-name { flex: 1 1 100%; order: 3; }
    .ver-item form { margin-left: auto; }
}
@media (max-width: 480px) {
    .add-btn { width: 100%; }
    .search-form { flex-wrap: wrap; gap: 6px; }
    .search-form input { flex: 1 1 100%; }
    .search-form button { flex: 1; }
    .modal-body { padding: 14px; }
    .modal-content { max-height: 88vh; }
    .modal-footer { flex-wrap: wrap; gap: 10px; }
    .modal-footer > div { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .modal-footer .cancel-btn, .modal-footer .submit-btn { min-height: 40px; padding: 0 18px; }
    /* 卡片微调 */
    tbody tr { padding: 12px; }
    tbody tr td { font-size: 13px; padding: 6px 0; }
    tbody tr td::before { font-size: 12px; }
    tbody tr td.file-title { font-size: 15px; }
}
</style>

<h2 class="admin-title">服务支持管理</h2>
<div class="admin-nav">
    <a href="admin.php">首页</a>
    <a href="forum_manage.php">论坛</a>
    <a href="service_manage.php" class="active">服务支持</a>
    <a href="user_manage.php">用户</a>
</div>

<!-- 新增作品按钮 -->
<div class="add-btn-wrap">
    <button id="addBtn" class="add-btn">+ 新增作品</button>
</div>

<!-- 搜索 -->
<div class="search-wrap">
    <form method="get" class="search-form">
        <input type="text" name="keyword" value="<?php echo htmlspecialchars($kw); ?>" placeholder="搜索作品名称 / 上传者">
        <button type="submit">查找</button>
        <?php if ($kw !== ''): ?><a href="service_manage.php" class="search-clear">清除</a><?php endif; ?>
    </form>
</div>

<!-- 作品列表 -->
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>作品名称</th>
            <th>上传者</th>
            <th>版本数</th>
            <th class="hide-mobile">总下载</th>
            <th class="hide-mobile">上传时间</th>
            <th width="100">操作</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($works)): ?>
            <tr><td colspan="6" class="empty"><?php echo $kw !== '' ? '没有匹配的作品' : '暂无作品'; ?></td></tr>
        <?php endif; ?>
        <?php foreach ($works as $w):
            $items = isset($items_by_svc[$w['id']]) ? $items_by_svc[$w['id']] : [];
            $total_dl = 0;
            foreach ($items as $it) $total_dl += $it['download_count'];
            $can_mg = sm_can_manage($w);
            $items_json = htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        ?>
        <tr>
            <td class="file-title"><?php echo htmlspecialchars($w['title']); ?></td>
            <td data-label="上传者"><?php echo htmlspecialchars($w['author_name'] ? $w['author_name'] : $w['username']); ?></td>
            <td data-label="版本数"><?php echo count($items); ?></td>
            <td class="hide-mobile" data-label="总下载"><?php echo $total_dl; ?></td>
            <td class="hide-mobile" data-label="上传时间"><?php echo $w['create_time']; ?></td>
            <td>
                <?php if ($can_mg): ?>
                    <button class="manage-btn sm-manage-btn"
                        data-id="<?php echo $w['id']; ?>"
                        data-title="<?php echo htmlspecialchars($w['title']); ?>"
                        data-desc="<?php echo htmlspecialchars($w['description']); ?>"
                        data-items='<?php echo $items_json; ?>'>管理</button>
                <?php else: ?>
                    <span style="color:#bbb;font-size:13px;">无权限</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- 新增作品弹窗 -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>新增作品</h4>
            <button class="modal-close" id="closeAdd">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="modal-body">
                <?php if ($msg) echo '<div class="msg">' . htmlspecialchars($msg) . '</div>'; ?>
                <div class="form-item">
                    <label>作品名称 *</label>
                    <input type="text" name="title" placeholder="请输入作品名称" required>
                </div>
                <div class="form-item">
                    <label>作品描述</label>
                    <textarea name="description" placeholder="简要描述作品内容（可选）"></textarea>
                </div>
                <div class="form-item">
                    <label>上传文件（可多选，每个文件作为一个版本）*</label>
                    <input type="file" name="sm_file[]" multiple required>
                    <div class="upload-tip">支持 pdf/doc/docx/xls/xlsx/ppt/pptx/txt/zip/rar/图片等，单文件最大 50MB，可一次选择多个文件作为该作品的多个版本</div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end;">
                <button type="button" class="cancel-btn" id="cancelAdd" style="margin-right:10px;">取消</button>
                <button type="submit" name="sm_add" class="submit-btn">确认添加</button>
            </div>
        </form>
    </div>
</div>

<!-- 管理/编辑弹窗 -->
<div id="manageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>管理作品</h4>
            <button class="modal-close" id="closeManage">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data" id="manageForm">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="file_id" id="mg_id">
            <div class="modal-body">
                <div class="form-item">
                    <label>作品名称 *</label>
                    <input type="text" name="title" id="mg_title" required>
                </div>
                <div class="form-item">
                    <label>作品描述</label>
                    <textarea name="description" id="mg_desc"></textarea>
                </div>

                <div class="ver-title">文件版本</div>
                <div class="ver-list" id="mg_items"></div>

                <div class="form-item" style="margin-top:16px;">
                    <label>追加新版本文件（可选，可多选，保留旧版本）</label>
                    <input type="file" name="sm_file[]" multiple>
                    <div class="upload-tip">选择一个或多个文件追加为新的文件版本，旧版本不会被删除</div>
                </div>
            </div>
            <div class="modal-footer">
                <div>
                    <form method="post" class="inline-form" id="delForm" style="display:inline;" onsubmit="return confirm('确定删除该作品？其全部文件版本将一并删除。');">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="file_id" id="mg_del_id">
                        <button type="submit" name="sm_delete" class="del-btn" style="background:none;border:none;padding:0;font-family:inherit;">删除整个作品</button>
                    </form>
                </div>
                <div>
                    <button type="button" class="cancel-btn" id="cancelManage" style="margin-right:10px;">关闭</button>
                    <button type="submit" name="sm_save" class="submit-btn">保存修改</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    try {
        var addModal = document.getElementById('addModal');
        var manageModal = document.getElementById('manageModal');
        var csrfToken = '<?php echo csrf_token(); ?>';

        function openModal(m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; }
        function closeModal(m) { m.classList.remove('show'); document.body.style.overflow = ''; }

        // 新增
        document.getElementById('addBtn').addEventListener('click', function() { openModal(addModal); });
        document.getElementById('closeAdd').addEventListener('click', function() { closeModal(addModal); });
        document.getElementById('cancelAdd').addEventListener('click', function() { closeModal(addModal); });
        addModal.addEventListener('click', function(e) { if (e.target === addModal) closeModal(addModal); });

        // 管理
        document.getElementById('closeManage').addEventListener('click', function() { closeModal(manageModal); });
        document.getElementById('cancelManage').addEventListener('click', function() { closeModal(manageModal); });
        manageModal.addEventListener('click', function(e) { if (e.target === manageModal) closeModal(manageModal); });

        function fmtSize(bytes) {
            bytes = Number(bytes) || 0;
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }

        // HTML 转义（防文件名注入 XSS）
        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // 渲染版本列表
        function renderItems(items) {
            var box = document.getElementById('mg_items');
            var title = document.querySelector('#manageModal .ver-title');
            if (title) title.textContent = '文件版本（' + items.length + '）';
            if (!items.length) {
                box.innerHTML = '<div class="ver-empty">暂无文件版本</div>';
                return;
            }
            var html = '';
            items.forEach(function(it) {
                var ext = (it.file_name.split('.').pop() || 'file').toUpperCase().substring(0, 4);
                html += '<div class="ver-item">' +
                    '<span style="color:#2563eb;background:#e8f0fe;border-radius:5px;padding:2px 6px;font-size:11px;flex-shrink:0;">' + escapeHtml(ext) + '</span>' +
                    '<span class="vi-name" title="' + escapeHtml(it.file_name) + '">' + escapeHtml(it.file_name) + '</span>' +
                    '<span class="vi-sub">' + fmtSize(it.file_size) + ' · ' + it.download_count + '次</span>' +
                    '<form method="post" style="display:inline;" onsubmit="return confirm(\'确定删除该文件版本？文件将一并删除。\');">' +
                        '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                        '<input type="hidden" name="item_id" value="' + it.id + '">' +
                        '<button type="submit" name="sm_delete_item" class="vi-del">删除</button>' +
                    '</form>' +
                '</div>';
            });
            box.innerHTML = html;
        }

        // 管理按钮：data-* 传参
        document.querySelectorAll('.sm-manage-btn').forEach(function(el) {
            el.addEventListener('click', function() {
                document.getElementById('mg_id').value = el.dataset.id;
                document.getElementById('mg_del_id').value = el.dataset.id;
                document.getElementById('mg_title').value = el.dataset.title;
                document.getElementById('mg_desc').value = el.dataset.desc;
                var items = [];
                try { items = JSON.parse(el.dataset.items || '[]'); } catch (e) {}
                renderItems(items);
                openModal(manageModal);
            });
        });

        // 上传失败时自动打开新增弹窗
        <?php if ($msg): ?>
        openModal(addModal);
        <?php endif; ?>
    } catch (e) { console.error('service_manage init error:', e); }
})();
</script>

<?php require_once 'footer.php'; ?>
