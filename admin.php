<?php
require_once 'header.php';
check_admin();

// 保存首页内容
if ($_POST && in_array($login_user['role'], ['admin','super'])) {
    csrf_check();
    $banner_title = mysqli_real_escape_string($conn, $_POST['banner_title']);
    $banner_desc = mysqli_real_escape_string($conn, $_POST['banner_desc']);
    $card1_title = mysqli_real_escape_string($conn, $_POST['card1_title']);
    $card1_text = mysqli_real_escape_string($conn, $_POST['card1_text']);
    $card2_title = mysqli_real_escape_string($conn, $_POST['card2_title']);
    $card2_text = mysqli_real_escape_string($conn, $_POST['card2_text']);
    $card3_title = mysqli_real_escape_string($conn, $_POST['card3_title']);
    $card3_text = mysqli_real_escape_string($conn, $_POST['card3_text']);
    
    $sql = "UPDATE home_content SET 
        banner_title='$banner_title',
        banner_desc='$banner_desc',
        card1_title='$card1_title',
        card1_text='$card1_text',
        card2_title='$card2_title',
        card2_text='$card2_text',
        card3_title='$card3_title',
        card3_text='$card3_text'
        WHERE id=1";
    mysqli_query($conn, $sql);
    $save_msg = '<div class="save-msg">保存成功！前台首页已同步更新</div>';
}

// 读取当前首页内容
$res = db_query($conn, "SELECT * FROM home_content WHERE id=1");
$home = mysqli_fetch_assoc($res);
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
.welcome p { color: #666; line-height: 1.7; }

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
.edit-btn {
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
.edit-btn:hover { background: #1d4ed8; }
.save-msg {
    color: #16a34a;
    margin-bottom: 12px;
    padding: 10px 15px;
    background: #f0fdf4;
    border-radius: 6px;
    font-size: 14px;
}

/* ===== 编辑弹窗 ===== */
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
    max-width: 760px;
    margin: 5% auto;
    border-radius: 10px;
    overflow: hidden;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 24px;
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
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.form-section { margin-bottom: 22px; }
.form-section:last-child { margin-bottom: 0; }
.form-section h4 {
    margin-bottom: 14px;
    color: #333;
    font-size: 15px;
    border-left: 3px solid #2563eb;
    padding-left: 10px;
}
.form-item { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.form-item label { font-size: 13px; color: #666; }
.form-item input, .form-item textarea {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
    font-family: inherit;
}
.form-item textarea {
    height: 80px;
    padding: 8px 12px;
    resize: vertical;
}
.form-item input:focus, .form-item textarea:focus { border-color: #2563eb; }
.submit-btn {
    height: 40px;
    padding: 0 30px;
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
    height: 40px;
    padding: 0 24px;
    background: #f0f2f5;
    color: #666;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.cancel-btn:hover { background: #e4e7ed; }

/* 手机端适配 */
@media (max-width: 768px) {
    .admin-title { font-size: 20px; margin-bottom: 18px; }
    .admin-nav { gap: 10px; margin-bottom: 16px; }
    .admin-nav a { padding: 8px 14px; font-size: 14px; }
    .panel { padding: 16px; }
    .manage-card { padding: 16px; }
    .modal-content { margin: 0; max-width: 100%; min-height: 100vh; border-radius: 0; }
    .modal-body { padding: 18px; }
    .modal-header, .modal-footer { padding: 16px 18px; }
}
@media (max-width: 480px) {
    .manage-grid { grid-template-columns: 1fr; }
    .modal-footer { flex-wrap: wrap; }
    .modal-footer .cancel-btn, .modal-footer .submit-btn { flex: 1; }
}
</style>

<h2 class="admin-title">首页管理</h2>
<div class="admin-nav">
    <a href="admin.php" class="active">首页</a>
    <a href="forum_manage.php">论坛</a>
    <a href="service_manage.php">服务支持</a>
    <a href="user_manage.php">用户</a>
</div>

<!-- 首页内容管理 -->
<div class="panel">
    <h3>首页内容</h3>
    <?php if (isset($save_msg)) echo $save_msg; ?>
    <div class="manage-grid">
        <div class="manage-card">
            <div class="mc-head">
                <h4>首页内容</h4>
                <p>管理网站首页 Banner 与业务板块内容</p>
            </div>
            <button type="button" class="edit-btn" id="editHomeBtn">修改内容</button>
        </div>
        <!-- 后续新增的首页内容板块可在此追加 -->
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>修改首页内容</h4>
            <button type="button" class="modal-close" id="closeEditModal">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="modal-body">
                <div class="form-section">
                    <h4>Banner 区域</h4>
                    <div class="form-item">
                        <label>大标题</label>
                        <input type="text" name="banner_title" value="<?php echo htmlspecialchars($home['banner_title']); ?>" required>
                    </div>
                    <div class="form-item">
                        <label>介绍文案</label>
                        <textarea name="banner_desc" required><?php echo htmlspecialchars($home['banner_desc']); ?></textarea>
                    </div>
                </div>
                <div class="form-section">
                    <h4>业务板块一</h4>
                    <div class="form-item">
                        <label>标题</label>
                        <input type="text" name="card1_title" value="<?php echo htmlspecialchars($home['card1_title']); ?>" required>
                    </div>
                    <div class="form-item">
                        <label>详情</label>
                        <textarea name="card1_text" required><?php echo htmlspecialchars($home['card1_text']); ?></textarea>
                    </div>
                </div>
                <div class="form-section">
                    <h4>业务板块二</h4>
                    <div class="form-item">
                        <label>标题</label>
                        <input type="text" name="card2_title" value="<?php echo htmlspecialchars($home['card2_title']); ?>" required>
                    </div>
                    <div class="form-item">
                        <label>详情</label>
                        <textarea name="card2_text" required><?php echo htmlspecialchars($home['card2_text']); ?></textarea>
                    </div>
                </div>
                <div class="form-section">
                    <h4>业务板块三</h4>
                    <div class="form-item">
                        <label>标题</label>
                        <input type="text" name="card3_title" value="<?php echo htmlspecialchars($home['card3_title']); ?>" required>
                    </div>
                    <div class="form-item">
                        <label>详情</label>
                        <textarea name="card3_text" required><?php echo htmlspecialchars($home['card3_text']); ?></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" id="cancelEditBtn">取消</button>
                <button type="submit" class="submit-btn">保存修改</button>
            </div>
        </form>
    </div>
</div>

<script>
// 编辑弹窗开关
const editModal = document.getElementById('editModal');
const editHomeBtn = document.getElementById('editHomeBtn');
const closeEditModal = document.getElementById('closeEditModal');
const cancelEditBtn = document.getElementById('cancelEditBtn');
if (editHomeBtn) editHomeBtn.addEventListener('click', () => editModal.classList.add('show'));
if (closeEditModal) closeEditModal.addEventListener('click', () => editModal.classList.remove('show'));
if (cancelEditBtn) cancelEditBtn.addEventListener('click', () => editModal.classList.remove('show'));
if (editModal) {
    editModal.addEventListener('click', e => {
        if (e.target === editModal) editModal.classList.remove('show');
    });
}
</script>

<?php require_once 'footer.php'; ?>
