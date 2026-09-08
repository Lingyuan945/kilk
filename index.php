<?php 
require_once 'header.php';

// 读取首页内容
$res = mysqli_query($conn, "SELECT * FROM home_content WHERE id=1");
$home = mysqli_fetch_assoc($res);
?>
<style>
.banner {
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
    color: #fff;
    padding: 80px 40px;
    border-radius: 12px;
    margin-bottom: 40px;
    animation: bannerIn 0.55s ease both;
}
@keyframes bannerIn {
    from { opacity: 0; transform: translateY(-12px); }
    to   { opacity: 1; transform: translateY(0); }
}
.banner h1 { font-size: 36px; margin-bottom: 16px; }
.banner p { font-size: 16px; opacity: 0.9; line-height: 1.8; max-width: 600px; }

.section-title { font-size: 24px; margin-bottom: 20px; color: #2d3748; }
.card-wrap { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.card {
    background: #fff;
    padding: 24px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: transform 0.2s;
    opacity: 0;
    animation: cardFade 0.5s ease forwards;
}
.card:nth-child(2) { animation-delay: 0.08s; }
.card:nth-child(3) { animation-delay: 0.16s; }
@keyframes cardFade { from { opacity: 0; } to { opacity: 1; } }
.card:hover { transform: translateY(-3px); box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.card h3 { color: #2563eb; margin-bottom: 10px; font-size: 18px; }
.forum-btn {
    display: inline-block;
    padding: 10px 28px;
    background: #2563eb;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    transition: all 0.25s ease;
    box-shadow: 0 2px 6px rgba(37,99,235,0.25);
}
.forum-btn:hover {
    background: #1d4ed8;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(37,99,235,0.35);
}
.card p { color: #666; line-height: 1.7; font-size: 14px; }

/* 常用入口双卡片（论坛 + 服务支持，对称布局） */
.entry-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 8px;
}

/* 手机端适配 */
@media (max-width: 768px) {
    .entry-grid { grid-template-columns: 1fr; }
    .banner { padding: 40px 20px; margin-bottom: 25px; }
    .banner h1 { font-size: 24px; }
    .banner p { font-size: 14px; }
    .section-title { font-size: 20px; margin-bottom: 15px; }
    .card-wrap { grid-template-columns: 1fr; gap: 15px; }
    .card { padding: 20px; }
}

/* 小屏手机适配 */
@media (max-width: 480px) {
    .banner { padding: 32px 16px; }
    .banner h1 { font-size: 21px; }
    .card { padding: 18px; }
    .card h3 { font-size: 16px; }
}</style>

<div class="banner">
    <h1><?php echo htmlspecialchars($home['banner_title']); ?></h1>
    <p><?php echo htmlspecialchars($home['banner_desc']); ?></p>
</div>

<h2 class="section-title">核心业务</h2>
<div class="card-wrap">
    <div class="card">
        <h3><?php echo htmlspecialchars($home['card1_title']); ?></h3>
        <p><?php echo htmlspecialchars($home['card1_text']); ?></p>
    </div>
    <div class="card">
        <h3><?php echo htmlspecialchars($home['card2_title']); ?></h3>
        <p><?php echo htmlspecialchars($home['card2_text']); ?></p>
    </div>
    <div class="card">
        <h3><?php echo htmlspecialchars($home['card3_title']); ?></h3>
        <p><?php echo htmlspecialchars($home['card3_text']); ?></p>
    </div>
</div>
<div style="margin-top: 50px;">
    <h2 class="section-title">常用入口</h2>
    <div class="entry-grid">
        <div class="card" style="text-align:center;padding:40px 30px;">
            <h3 style="margin-bottom:12px;color:#2563eb;">团队内部交流论坛</h3>
            <p style="color:#666;line-height:1.7;margin-bottom:20px;">
                分享技术经验、交流工作问题、发布团队通知，在这里和大家一起互动讨论
            </p>
            <a href="forum.php" class="forum-btn">进入论坛</a>
        </div>
        <div class="card" style="text-align:center;padding:40px 30px;">
            <h3 style="margin-bottom:12px;color:#2563eb;">服务支持中心</h3>
            <p style="color:#666;line-height:1.7;margin-bottom:20px;">
                团队共享资源与作品下载中心，随时下载需要的文档、软件与工具包
            </p>
            <a href="service.php" class="forum-btn">进入服务</a>
        </div>
    </div>
</div>


<?php require_once 'footer.php'; ?>
