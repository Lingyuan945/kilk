<?php require 'header.php'; ?>
<style>
.page-title { font-size: 28px; margin-bottom: 24px; color: #2d3748; border-left: 4px solid #2563eb; padding-left: 12px; }
.content-box {
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    line-height: 1.8;
    color: #555;
    animation: fadeUp 0.5s ease 0.1s both;
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}
.content-box h3 { color: #333; margin: 20px 0 10px; }
.content-box p { margin-bottom: 12px; }

/* 手机端适配 */
@media (max-width: 768px) {
    .page-title { font-size: 22px; margin-bottom: 18px; }
    .content-box { padding: 20px; font-size: 14px; }
}

@media (max-width: 480px) {
    .content-box { padding: 16px; font-size: 13px; }
}</style>


<h2 class="page-title">关于我们</h2>
<div class="content-box">
    <h3>团队介绍</h3>
    <p>我们是一支专注于互联网技术的年轻团队（其实只有一个人），成员可能拥有多年行业经验，坚持以技术驱动发展，以服务赢得信任。</p>
    
    <h3>我们的理念</h3>
    <p>秉持专业、高效、诚信的原则，为每一位客户提供超出预期的产品与服务，助力企业数字化转型升级。</p>
    
    <h3>联系我们</h3>
    <p>邮箱：2338315916@qq.com<br>地址：中国 · 海南三亚</p>
</div>

<?php require 'footer.php'; ?>
