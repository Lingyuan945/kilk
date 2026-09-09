<?php
require_once 'header.php';

// 页面权限：仅管理员和高级用户可访问
if (!$is_login || !in_array($login_user['role'], ['admin','senior','super'])) {
    header('Location: index.php');
    exit;
}
$current_role = $login_user['role'];

$id = intval($_GET['id']);
if (!$id) {
    header('Location: user_manage.php');
    exit;
}

// 目标用户信息
$ures = mysqli_query($conn, "SELECT * FROM user WHERE id=$id");
$target = mysqli_fetch_assoc($ures);
if (!$target) {
    header('Location: user_manage.php');
    exit;
}

// 权限：管理员可管理任何用户帖子；高级用户仅可管理普通用户(user)的帖子
$can_manage = false;
if (in_array($current_role, ['admin','super'])) {
    $can_manage = true;
} elseif ($current_role == 'senior' && $target['role'] == 'user') {
    $can_manage = true;
}
if (!$can_manage) {
    echo '<div style="text-align:center;padding:60px 0;color:#999;font-size:14px;">您没有权限管理该用户的帖子<br><br><a href="user_manage.php" style="color:#2563eb;">返回用户管理</a></div>';
    require_once 'footer.php';
    exit;
}

// 删除帖子（仅管理员，转发给 forum.php 的删除逻辑需登录态；这里直接处理）
if (isset($_GET['del']) && in_array($current_role, ['admin','super'])) {
    csrf_check_get();
    $pid = intval($_GET['del']);
    // 校验帖子属于该用户
    $chk = mysqli_query($conn, "SELECT id FROM forum_post WHERE id=$pid AND user_id=$id");
    if (mysqli_fetch_assoc($chk)) {
        // 删除图片文件
        $img_res = mysqli_query($conn, "SELECT image_path FROM forum_post_image WHERE post_id=$pid");
        while ($img = mysqli_fetch_assoc($img_res)) {
            $real_path = $_SERVER['DOCUMENT_ROOT'] . $img['image_path'];
            if (file_exists($real_path)) @unlink($real_path);
        }
        mysqli_query($conn, "DELETE FROM forum_post_image WHERE post_id=$pid");
        mysqli_query($conn, "DELETE FROM forum_reply WHERE post_id=$pid");
        mysqli_query($conn, "DELETE FROM forum_post WHERE id=$pid");
    }
    header("Location: user_posts.php?id=$id");
    exit;
}

// 获取该用户帖子列表
$posts = [];
$pres = mysqli_query($conn, "SELECT * FROM forum_post WHERE user_id=$id ORDER BY id DESC");
while ($p = mysqli_fetch_assoc($pres)) {
    $posts[] = $p;
}
?>
<style>
.up-title { font-size: 22px; margin-bottom: 20px; color: #2d3748; }
.up-back { display: inline-block; margin-bottom: 18px; color: #2563eb; text-decoration: none; font-size: 14px; }
.up-back:hover { text-decoration: underline; }
.up-table { width: 100%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-collapse: collapse; }
.up-table th, .up-table td { padding: 13px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
.up-table tbody tr { transition: background 0.2s; }
.up-table tbody tr:hover { background: #f5f8ff; }
.up-table th { background: #fafafa; font-weight: normal; color: #666; }
.up-op a { color: #2563eb; text-decoration: none; margin-right: 12px; font-size: 13px; }
.up-op a:hover { text-decoration: underline; }
.up-op .del { color: #f56c6c; }
.up-empty { text-align: center; padding: 50px 0; color: #bbb; background: #fff; border-radius: 8px; font-size: 14px; }
.up-msg { color: #16a34a; margin-bottom: 12px; font-size: 14px; }
.up-table-wrap { overflow-x: auto; }
@media (max-width: 768px) {
    .up-title { font-size: 18px; }
    /* 表格卡片化 */
    .up-table-wrap { overflow-x: visible; }
    .up-table { min-width: 0; background: transparent; box-shadow: none; }
    .up-table thead { display: none; }
    .up-table tbody tr {
        display: block; background: #fff; border: 1px solid #eef0f3;
        border-radius: 10px; padding: 14px; margin-bottom: 12px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }
    .up-table tbody tr td {
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; padding: 7px 0; border-bottom: 1px dashed #f0f0f0;
        font-size: 14px;
    }
    .up-table tbody tr td::before {
        content: attr(data-label);
        color: #999; font-size: 13px; flex-shrink: 0;
    }
    .up-table tbody tr td:nth-child(2) {
        display: block; font-weight: 600; font-size: 15px; color: #2d3748;
        border-bottom: 1px solid #f0f0f0; padding-bottom: 10px;
    }
    .up-table tbody tr td:nth-child(2)::before { display: none; }
    .up-table tbody tr td:last-child { border-bottom: none; justify-content: flex-start; }
    .up-table tbody tr:hover { background: #fff; }
    .up-op a { font-size: 14px; }
}
@media (max-width: 480px) {
    .up-op a { display: inline-block; margin-bottom: 6px; }
}</style>

<div>
    <a href="user_manage.php" class="up-back">← 返回用户管理</a>
    <h2 class="up-title">管理「<?php echo htmlspecialchars($target['name'] ? $target['name'] : $target['username']); ?>」的帖子 (<?php echo count($posts); ?>)</h2>

    <?php if (empty($posts)): ?>
        <div class="up-empty">该用户暂无帖子</div>
    <?php else: ?>
    <div class="up-table-wrap"><table class="up-table">
        <thead>
            <tr>
                <th width="60">ID</th>
                <th>标题</th>
                <th class="hide-mobile" width="170">发布时间</th>
                <th width="80">浏览</th>
                <th width="140">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts as $p): ?>
            <tr>
                <td data-label="ID"><?php echo $p['id']; ?></td>
                <td><a href="post_detail.php?id=<?php echo $p['id']; ?>" style="color:#2d3748;text-decoration:none;"><?php echo htmlspecialchars($p['title']); ?></a></td>
                <td class="hide-mobile" data-label="发布时间"><?php echo htmlspecialchars($p['create_time']); ?></td>
                <td data-label="浏览"><?php echo $p['view_count']; ?></td>
                <td class="up-op">
                    <a href="edit_post.php?id=<?php echo $p['id']; ?>">编辑</a>
                    <?php if (in_array($current_role, ['admin','super'])): ?>
                        <a href="?id=<?php echo $id; ?>&del=<?php echo $p['id']; ?>&token=<?php echo urlencode(csrf_token()); ?>" class="del" onclick="return confirm('确定删除该帖子及其所有回复？')">删除</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
