<?php
require_once 'header.php';

$my_role = $login_user['role'];
$is_editor = in_array($my_role, ['admin', 'super']);

// ============================================================
// 辅助函数
// ============================================================
function svc_query($sql, $types = '', $params = []) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) { error_log('svc_query: ' . mysqli_error($conn)); return false; }
    if ($types !== '' && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) return false;
    return mysqli_stmt_get_result($stmt);
}
function svc_exec($sql, $types = '', $params = []) {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) { error_log('svc_exec: ' . mysqli_error($conn)); return false; }
    if ($types !== '' && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    return mysqli_stmt_execute($stmt);
}
function svc_fsize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
// 判断当前用户是否可管理某作品（编辑/删除）
function svc_can_manage($work) {
    global $login_user, $role_level;
    if ($login_user['role'] == 'super') return true;
    if ($login_user['role'] != 'admin') return false;
    $author_level = isset($role_level[$work['author_role']]) ? $role_level[$work['author_role']] : 1;
    // admin 可管理自己的，或等级严格低于自己的（user/senior）；不可管理同级 admin 或 super
    return ($work['user_id'] == $login_user['id']) || ($role_level[$login_user['role']] > $author_level);
}
// 安全删除 upload 目录下的文件（统一正斜杠比较，兼容 Windows 反斜杠路径）
function svc_delete_file($file_path) {
    if (!$file_path) return;
    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $real = str_replace('\\', '/', realpath($doc_root . $file_path));
    $upload_real = str_replace('\\', '/', realpath($doc_root . '/upload'));
    if ($real && $upload_real && strpos($real . '/', rtrim($upload_real, '/') . '/') === 0 && is_file($real)) {
        @unlink($doc_root . $file_path);
    }
}
// 把 $_FILES['svc_file'] 规范化为列表（兼容单选/多选）
function svc_upload_list($files) {
    if (!$files || (empty($files['name']))) return [];
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
// 保存一个文件为一个版本，成功返回 true
function svc_save_item($svc_id, $file_arr) {
    global $allowed_ext, $max_size, $conn;
    $ext = strtolower(pathinfo($file_arr['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext) || $file_arr['size'] > $max_size) return false;
    $filename = 'svc_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
    $url_path = '/upload/service/' . $filename;
    $save_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/service/' . $filename;
    if (!move_uploaded_file($file_arr['tmp_name'], $save_path)) return false;
    return svc_exec(
        'INSERT INTO service_file_item (service_id, file_path, file_name, file_size, create_time) VALUES (?,?,?,?,NOW())',
        'issi',
        [$svc_id, $url_path, $file_arr['name'], filesize($save_path)]
    );
}

$allowed_ext = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','zip','rar','7z','csv','json','xml','png','jpg','jpeg','gif','webp'];
$max_size = 50 * 1024 * 1024; // 50MB
$msg = '';

// ============================================================
// 上传作品（可一次选择多个文件，每个文件作为一个版本）
// ============================================================
if (isset($_POST['svc_upload']) && $is_editor) {
    csrf_check();
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $upload_list = svc_upload_list($_FILES['svc_file'] ?? null);

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
            svc_exec(
                'INSERT INTO service_file (user_id, title, description, create_time) VALUES (?,?,?,NOW())',
                'iss',
                [$login_user['id'], $title, $description]
            );
            $svc_id = mysqli_insert_id($conn);
            if (!$svc_id) {
                $msg = '作品创建失败，请重试';
            } else {
                foreach ($upload_list as $f) {
                    svc_save_item($svc_id, $f);
                }
                header('Location: service.php');
                exit;
            }
        }
    }
}

// ============================================================
// 编辑作品信息 + 追加新版本（保存修改时如选择文件则作为新版本追加，不删旧文件）
// ============================================================
if (isset($_POST['svc_edit_save']) && $is_editor) {
    csrf_check();
    $id = intval($_POST['file_id']);
    $work = mysqli_fetch_assoc(svc_query(
        'SELECT f.*, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id WHERE f.id=?',
        'i', [$id]
    ));
    if ($work && svc_can_manage($work)) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        if ($title !== '') {
            svc_exec('UPDATE service_file SET title=?, description=? WHERE id=?', 'ssi', [$title, $description, $id]);
            // 追加新版本文件（可多选），保留旧版本
            $upload_list = svc_upload_list($_FILES['svc_file'] ?? null);
            if (!empty($upload_list)) {
                foreach ($upload_list as $f) {
                    svc_save_item($id, $f);
                }
            }
        }
    }
    header('Location: service.php');
    exit;
}

// ============================================================
// 单独删除某个文件版本
// ============================================================
if (isset($_POST['svc_delete_item']) && $is_editor) {
    csrf_check();
    $item_id = intval($_POST['item_id']);
    $item = mysqli_fetch_assoc(svc_query(
        'SELECT i.*, s.user_id AS svc_user, u.role AS author_role FROM service_file_item i JOIN service_file s ON i.service_id=s.id LEFT JOIN user u ON s.user_id=u.id WHERE i.id=?',
        'i', [$item_id]
    ));
    if ($item) {
        $work = ['user_id' => $item['svc_user'], 'author_role' => $item['author_role']];
        if (svc_can_manage($work)) {
            svc_delete_file($item['file_path']);
            svc_exec('DELETE FROM service_file_item WHERE id=?', 'i', [$item_id]);
            // 若该作品已没有文件版本，则连同作品一起删除
            $cnt = mysqli_fetch_assoc(svc_query('SELECT COUNT(*) AS c FROM service_file_item WHERE service_id=?', 'i', [$item['service_id']]));
            if ($cnt && $cnt['c'] == 0) {
                svc_exec('DELETE FROM service_file WHERE id=?', 'i', [$item['service_id']]);
            }
        }
    }
    header('Location: service.php');
    exit;
}

// ============================================================
// 删除整个作品（含所有版本文件）
// ============================================================
if (isset($_POST['svc_delete']) && $is_editor) {
    csrf_check();
    $id = intval($_POST['file_id']);
    $work = mysqli_fetch_assoc(svc_query(
        'SELECT f.*, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id WHERE f.id=?',
        'i', [$id]
    ));
    if ($work && svc_can_manage($work)) {
        $items = svc_query('SELECT * FROM service_file_item WHERE service_id=?', 'i', [$id]);
        while ($it = mysqli_fetch_assoc($items)) svc_delete_file($it['file_path']);
        svc_exec('DELETE FROM service_file_item WHERE service_id=?', 'i', [$id]);
        svc_exec('DELETE FROM service_file WHERE id=?', 'i', [$id]);
    }
    header('Location: service.php');
    exit;
}

// ============================================================
// 作品列表 + 各作品的文件版本
// ============================================================
$res = svc_query(
    'SELECT f.*, u.username, u.name AS author_name, u.role AS author_role FROM service_file f LEFT JOIN user u ON f.user_id=u.id ORDER BY f.id DESC'
);
$works = [];
while ($w = mysqli_fetch_assoc($res)) $works[] = $w;

$items_by_svc = [];
$items_res = svc_query('SELECT * FROM service_file_item ORDER BY id ASC');
if ($items_res) {
    while ($it = mysqli_fetch_assoc($items_res)) $items_by_svc[$it['service_id']][] = $it;
}
?>
<style>
.page-title { font-size: 28px; margin-bottom: 18px; color: #2d3748; border-left: 4px solid #2563eb; padding-left: 12px; }
.page-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.page-desc { color: #666; font-size: 14px; line-height: 1.6; }
.upload-btn {
    height: 40px; padding: 0 22px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background 0.2s;
}
.upload-btn:hover { background: #1d4ed8; }

/* 卡片网格 */
.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
}
.svc-card {
    background: #fff; border-radius: 10px; padding: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    display: flex; flex-direction: column; gap: 12px;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
}
.svc-card:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,0.1); }
.svc-icon {
    width: 44px; height: 44px; border-radius: 10px;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px; font-weight: bold; flex-shrink: 0;
}
.svc-card-head { display: flex; align-items: flex-start; gap: 12px; }
.svc-card-title { font-size: 16px; font-weight: 600; color: #2d3748; margin: 0; line-height: 1.4; word-break: break-word; }
.svc-card-desc { font-size: 13px; color: #666; line-height: 1.6; flex: 1; word-break: break-word; }
.svc-meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: 12px; color: #999; }
.svc-meta span { display: inline-flex; align-items: center; gap: 4px; }

/* 版本列表 */
.svc-items { display: flex; flex-direction: column; gap: 8px; }
.svc-item {
    display: flex; align-items: center; gap: 10px; padding: 9px 12px;
    background: #f8f9fa; border: 1px solid #eef0f3; border-radius: 8px;
    font-size: 13px; color: #444;
}
.svc-item .it-ext {
    flex-shrink: 0; width: 40px; text-align: center; font-size: 11px; font-weight: 600;
    color: #2563eb; background: #e8f0fe; border-radius: 5px; padding: 3px 0;
}
.svc-item .it-name { flex: 1; min-width: 0; word-break: break-all; }
.svc-item .it-sub { color: #999; font-size: 12px; flex-shrink: 0; }
.svc-item .it-actions { display: flex; gap: 6px; flex-shrink: 0; align-items: center; }
.item-dl-btn {
    padding: 4px 12px; background: #2563eb; color: #fff;
    border: none; border-radius: 5px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block;
}
.item-dl-btn:hover { background: #1d4ed8; }
.item-del-btn {
    background: none; border: none; color: #f56c6c; font-size: 12px;
    cursor: pointer; padding: 2px; font-family: inherit;
}
.item-del-btn:hover { text-decoration: underline; }

.svc-card-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #f0f0f0; flex-wrap: wrap; gap: 8px; }
.card-actions { display: flex; gap: 10px; align-items: center; }
.card-edit-link { color: #2563eb; text-decoration: none; font-size: 13px; cursor: pointer; }
.card-edit-link:hover { text-decoration: underline; }
.card-del-btn {
    background: none; border: none; color: #f56c6c; font-size: 13px;
    cursor: pointer; padding: 0; font-family: inherit;
}
.card-del-btn:hover { text-decoration: underline; }
.inline-form { display: inline; }

.empty { text-align: center; padding: 60px 20px; color: #999; background: #fff; border-radius: 10px; font-size: 14px; }

/* 弹窗 */
.modal {
    display: none; position: fixed; top: 0; left: 0;
    width: 100%; height: 100%; background: rgba(0,0,0,0.5);
    z-index: 1000; align-items: center; justify-content: center; padding: 20px;
}
.modal.show { display: flex; }
.modal-content {
    background: #fff; border-radius: 12px; width: 100%; max-width: 560px;
    max-height: 90vh; overflow-y: auto; animation: modalIn 0.2s ease-out;
}
@keyframes modalIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 24px; border-bottom: 1px solid #f0f0f0;
}
.modal-header h4 { font-size: 16px; color: #333; }
.modal-close { background: none; border: none; font-size: 22px; color: #999; cursor: pointer; line-height: 1; }
.modal-close:hover { color: #333; }
.modal-body { padding: 24px; }
.modal-footer { padding: 16px 24px; border-top: 1px solid #f0f0f0; text-align: right; }
.form-item { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-item label { font-size: 13px; color: #666; }
.form-item input, .form-item textarea {
    border: 1px solid #dcdfe6; border-radius: 6px; padding: 10px 12px;
    font-size: 14px; outline: none; font-family: inherit;
}
.form-item textarea { height: 90px; resize: vertical; }
.form-item input:focus, .form-item textarea:focus { border-color: #2563eb; }
.form-item input[type="file"] { padding: 8px; }
.upload-tip { font-size: 12px; color: #999; margin-top: 4px; }
.msg { color: #f56c6c; margin-bottom: 12px; font-size: 14px; }
.cancel-btn {
    height: 38px; padding: 0 24px; background: #f0f2f5; color: #666;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-right: 10px;
}
.submit-btn {
    height: 38px; padding: 0 24px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.submit-btn:hover { background: #1d4ed8; }
.current-file { font-size: 12px; color: #666; background: #f8f9fa; padding: 6px 10px; border-radius: 4px; margin-top: 4px; }

/* 手机端适配 */
@media (max-width: 768px) {
    .page-title { font-size: 22px; margin-bottom: 14px; }
    .card-grid { grid-template-columns: 1fr; gap: 14px; }
    .svc-card { padding: 18px; }
    .modal-content { max-width: 100%; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    /* 版本列表：文件名独占一行，大小/操作按钮换行靠右 */
    .svc-item { flex-wrap: wrap; align-items: center; row-gap: 6px; }
    .svc-item .it-name { flex: 1 1 100%; order: 3; }
    .svc-item .it-actions { margin-left: auto; }
}
@media (max-width: 480px) {
    .page-title { font-size: 20px; }
    .upload-btn { width: 100%; }
    .modal-footer { display: flex; gap: 10px; }
    .modal-footer .cancel-btn, .modal-footer .submit-btn { flex: 1; margin-right: 0; }
    .svc-item { flex-wrap: wrap; }
}
</style>

<div class="page-head">
    <div>
        <h2 class="page-title">服务支持</h2>
        <p class="page-desc">团队共享资源与作品下载中心，管理员可上传作品（一个作品可包含多个文件版本）供所有用户下载</p>
    </div>
    <?php if ($is_editor): ?>
        <button id="uploadBtn" class="upload-btn">+ 上传作品</button>
    <?php endif; ?>
</div>

<?php if (empty($works)): ?>
    <div class="empty">暂无作品，<?php echo $is_editor ? '点击上方按钮上传第一个作品吧' : '敬请期待'; ?></div>
<?php else: ?>
<div class="card-grid">
    <?php foreach ($works as $w):
        $items = isset($items_by_svc[$w['id']]) ? $items_by_svc[$w['id']] : [];
        $can_mg = $is_editor && svc_can_manage($w);
    ?>
    <div class="svc-card">
        <div class="svc-card-head">
            <div class="svc-icon"><?php echo count($items) > 1 ? count($items) : 'FILE'; ?></div>
            <div style="flex:1;min-width:0;">
                <h3 class="svc-card-title"><?php echo htmlspecialchars($w['title']); ?></h3>
            </div>
        </div>
        <?php if ($w['description']): ?>
            <p class="svc-card-desc"><?php echo htmlspecialchars($w['description']); ?></p>
        <?php else: ?>
            <p class="svc-card-desc" style="color:#bbb;">暂无描述</p>
        <?php endif; ?>
        <div class="svc-meta">
            <span>上传者：<?php echo htmlspecialchars($w['author_name'] ? $w['author_name'] : $w['username']); ?></span>
            <span><?php echo count($items); ?> 个版本</span>
            <span class="hide-mobile"><?php echo date('Y-m-d', strtotime($w['create_time'])); ?></span>
        </div>

        <!-- 文件版本列表 -->
        <div class="svc-items">
            <?php foreach ($items as $idx => $it):
                $ext = strtoupper(pathinfo($it['file_name'], PATHINFO_EXTENSION));
            ?>
            <div class="svc-item">
                <div class="it-ext"><?php echo $ext ? htmlspecialchars(substr($ext, 0, 4)) : 'FILE'; ?></div>
                <div class="it-name" title="<?php echo htmlspecialchars($it['file_name']); ?>"><?php echo htmlspecialchars($it['file_name']); ?></div>
                <div class="it-sub"><?php echo svc_fsize($it['file_size']); ?> · <?php echo $it['download_count']; ?>次</div>
                <div class="it-actions">
                    <a href="service_download.php?item=<?php echo $it['id']; ?>" class="item-dl-btn">下载</a>
                    <?php if ($can_mg): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('确定删除该文件版本？文件将一并删除。');">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                        <button type="submit" name="svc_delete_item" class="item-del-btn">删除</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
                <div class="svc-item" style="color:#bbb;">该作品暂无文件版本</div>
            <?php endif; ?>
        </div>

        <div class="svc-card-footer">
            <?php if ($can_mg): ?>
            <div class="card-actions">
                <a href="javascript:void(0)" class="card-edit-link svc-edit-link"
                   data-id="<?php echo $w['id']; ?>"
                   data-title="<?php echo htmlspecialchars($w['title']); ?>"
                   data-desc="<?php echo htmlspecialchars($w['description']); ?>">管理</a>
                <form method="post" class="inline-form" onsubmit="return confirm('确定删除该作品？其全部文件版本将一并删除。');">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="file_id" value="<?php echo $w['id']; ?>">
                    <button type="submit" name="svc_delete" class="card-del-btn">删除</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 上传作品弹窗 -->
<?php if ($is_editor): ?>
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>上传作品</h4>
            <button class="modal-close" id="closeUpload">&times;</button>
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
                    <input type="file" name="svc_file[]" multiple required>
                    <div class="upload-tip">支持 pdf/doc/docx/xls/xlsx/ppt/pptx/txt/zip/rar/图片等，单文件最大 50MB，可一次选择多个文件作为该作品的多个版本</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" id="cancelUpload">取消</button>
                <button type="submit" name="svc_upload" class="submit-btn">确认上传</button>
            </div>
        </form>
    </div>
</div>

<!-- 管理/编辑弹窗 -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>管理作品</h4>
            <button class="modal-close" id="closeEdit">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="file_id" id="edit_id">
            <div class="modal-body">
                <div class="form-item">
                    <label>作品名称 *</label>
                    <input type="text" name="title" id="edit_title" required>
                </div>
                <div class="form-item">
                    <label>作品描述</label>
                    <textarea name="description" id="edit_desc"></textarea>
                </div>
                <div class="form-item">
                    <label>追加新版本文件（可选，可多选，保留旧版本）</label>
                    <input type="file" name="svc_file[]" multiple>
                    <div class="upload-tip">选择一个或多个文件追加为新的文件版本，旧版本不会被删除</div>
                </div>
                <div class="current-file">提示：删除单个文件版本请在作品卡片上点击对应版本的「删除」按钮。</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" id="cancelEdit">取消</button>
                <button type="submit" name="svc_edit_save" class="submit-btn">保存修改</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    try {
        var uploadModal = document.getElementById('uploadModal');
        var editModal = document.getElementById('editModal');
        if (!uploadModal) return;

        function openModal(m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; }
        function closeModal(m) { m.classList.remove('show'); document.body.style.overflow = ''; }

        var uploadBtn = document.getElementById('uploadBtn');
        if (uploadBtn) uploadBtn.addEventListener('click', function() { openModal(uploadModal); });
        document.getElementById('closeUpload').addEventListener('click', function() { closeModal(uploadModal); });
        document.getElementById('cancelUpload').addEventListener('click', function() { closeModal(uploadModal); });
        uploadModal.addEventListener('click', function(e) { if (e.target === uploadModal) closeModal(uploadModal); });

        document.getElementById('closeEdit').addEventListener('click', function() { closeModal(editModal); });
        document.getElementById('cancelEdit').addEventListener('click', function() { closeModal(editModal); });
        editModal.addEventListener('click', function(e) { if (e.target === editModal) closeModal(editModal); });

        // 管理链接：data-* 传参
        document.querySelectorAll('.svc-edit-link').forEach(function(el) {
            el.addEventListener('click', function() {
                document.getElementById('edit_id').value = el.dataset.id;
                document.getElementById('edit_title').value = el.dataset.title;
                document.getElementById('edit_desc').value = el.dataset.desc;
                openModal(editModal);
            });
        });

        // 上传失败时自动打开弹窗
        <?php if ($msg): ?>
        openModal(uploadModal);
        <?php endif; ?>
    } catch (e) { console.error('service init error:', e); }
})();
</script>

<?php require_once 'footer.php'; ?>
