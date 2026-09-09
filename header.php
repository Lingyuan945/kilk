<?php
require_once 'config.php';
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>kilk</title>
<link rel="icon" href="/img/favicon.png" type="image/png">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { 
    font-family: "Microsoft YaHei", sans-serif; 
    color: #333; 
    background: #f8f9fa;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* 顶部导航 */
.navbar { 
    height: 60px; 
    background: #fff; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); 
    position: sticky; 
    top: 0; 
    z-index: 999;
}
.nav-container { 
    max-width: 1200px; 
    margin: 0 auto; 
    height: 100%; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    padding: 0 20px;
}
.logo { font-size: 20px; font-weight: bold; color: #2563eb; text-decoration: none; }
.nav-menu { display: flex; gap: 30px; list-style: none; }
.nav-menu a { 
    color: #444; 
    text-decoration: none;
    font-size: 15px;
    line-height: 60px;
    display: inline-block;
}
.nav-menu a:hover, .nav-menu a.active { color: #2563eb; border-bottom: 2px solid #2563eb; }

/* 右上角登录区 */
.nav-right { display: flex; align-items: center; gap: 16px; font-size: 14px; }
.nav-right .login-btn { 
    padding: 6px 18px; 
    border: 1px solid #2563eb; 
    color: #2563eb; 
    border-radius: 4px; 
    text-decoration: none;
    transition: all 0.2s;
}
.nav-right .login-btn:hover { background: #2563eb; color: #fff; }
.nav-right .user-info { color: #555; }
.nav-avatar { display: inline-block; width: 32px; height: 32px; border-radius: 50%; overflow: hidden; vertical-align: middle; }
.nav-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.nav-profile-link { color: #2563eb; text-decoration: none; font-size: 14px; }
.nav-profile-link:hover { text-decoration: underline; }
.nav-right .admin-btn { color: #2563eb; text-decoration: none; margin-right: 8px; }
.nav-right .logout-btn { color: #999; text-decoration: none; }
.nav-right .logout-btn:hover { color: #f56c6c; }

/* 主体容器 */
.container { 
    max-width: 1200px; 
    margin: 0 auto; 
    padding: 40px 20px;
    flex: 1;
    width: 100%;
}

/* ---------- 页面切换过渡动画 ---------- */
@keyframes page-in {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes page-out {
    from { opacity: 1; transform: translateY(0); }
    to   { opacity: 0; transform: translateY(-8px); }
}
.container {
    animation: page-in 0.4s ease both;
}
body.page-leaving .container {
    animation: page-out 0.28s ease both;
}
/* 视障用户减少动效偏好 */
@media (prefers-reduced-motion: reduce) {
    .container, body.page-leaving .container { animation: none; }
}

/* ---------- 手机端适配 ---------- */
@media (max-width: 768px) {
    .nav-container { padding: 0 15px; }
    .logo { font-size: 16px; }
    .nav-menu { gap: 14px; }
    .nav-menu a { font-size: 13px; }
    .nav-right { gap: 8px; font-size: 12px; }
    .nav-right .login-btn { padding: 5px 12px; font-size: 13px; }
    .container { padding: 22px 15px; }
    .nav-right .user-info { display: none; }
    /* 头像本身就是个人主页入口，手机端隐藏文字链接，避免导航溢出 */
    .nav-right .nav-profile-link { display: none; }
    .nav-right .admin-btn { font-size: 12px; margin-right: 4px; }
    .nav-right .logout-btn { font-size: 12px; }
}
@media (max-width: 480px) {
    .navbar { height: auto; }
    .nav-container { flex-wrap: wrap; row-gap: 2px; padding: 6px 12px; }
    .nav-menu { gap: 12px; order: 2; width: 100%; margin-top: 2px; }
    .nav-menu a { line-height: 34px; }
    .nav-right { order: 1; margin-left: auto; }
    .logo { font-size: 15px; }
}
/* ---------- 右上角登录/注册抽屉 ---------- */
.auth-mask {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.35);
    opacity: 0; visibility: hidden;
    transition: opacity 0.25s ease, visibility 0.25s;
    z-index: 1000;
}
.auth-mask.open { opacity: 1; visibility: visible; }
.auth-drawer {
    position: fixed; top: 64px; right: 16px;
    width: 340px; max-width: calc(100vw - 32px);
    background: #fff; border-radius: 12px;
    box-shadow: 0 14px 40px rgba(0,0,0,0.2);
    z-index: 1001;
    opacity: 0; transform: translateY(-14px) scale(0.97);
    visibility: hidden;
    transition: transform 0.28s ease, opacity 0.28s ease, visibility 0.28s;
    overflow: hidden;
}
.auth-drawer.open { opacity: 1; transform: translateY(0) scale(1); visibility: visible; }
.auth-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 18px 0;
    border-bottom: 1px solid #f0f0f0;
}
.auth-tabs { display: flex; gap: 2px; }
.auth-tab {
    background: none; border: none; padding: 10px 16px;
    font-size: 15px; color: #888; cursor: pointer;
    border-bottom: 2px solid transparent;
    font-family: inherit;
    transition: color 0.2s, border-color 0.2s;
}
.auth-tab:hover { color: #2563eb; }
.auth-tab.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
.auth-close {
    background: none; border: none; font-size: 24px; color: #999; cursor: pointer;
    line-height: 1; padding: 4px;
    transition: color 0.2s;
}
.auth-close:hover { color: #333; }
.auth-form { padding: 20px 22px 24px; }
.auth-form.hidden { display: none; }
.auth-field { margin-bottom: 16px; }
.auth-field label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
.auth-field input {
    width: 100%; height: 42px; padding: 0 12px;
    border: 1px solid #dcdfe6; border-radius: 6px;
    font-size: 14px; outline: none; font-family: inherit;
    transition: border-color 0.2s;
}
.auth-field input:focus { border-color: #2563eb; }
.auth-submit {
    width: 100%; height: 44px; margin-top: 4px;
    background: #2563eb; color: #fff; border: none; border-radius: 6px;
    font-size: 15px; cursor: pointer; font-family: inherit;
    transition: background 0.2s, transform 0.15s;
}
.auth-submit:hover { background: #1d4ed8; }
.auth-submit:active { transform: scale(0.98); }
.auth-msg { min-height: 20px; margin-bottom: 12px; font-size: 13px; }
.auth-msg.err { color: #f56c6c; }
.auth-msg.ok { color: #16a34a; }
.auth-msg.loading { color: #888; }
@media (max-width: 480px) {
    .auth-drawer { top: 56px; right: 8px; width: calc(100vw - 16px); max-width: 360px; }
}
</style>
<script>
// 页面切换过渡：站内链接无刷新加载（AJAX 替换主体 + pushState），失败自动回退整页跳转
(function () {
    var LEAVING = 'page-leaving';
    var DURATION = 300;
    var busy = false;
    var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var canSpa = !!(window.fetch && window.history && window.history.pushState && window.DOMParser);

    function isSamePage(href) {
        return href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href);
    }
    function isExternal(href) {
        try {
            var u = new URL(href, location.href);
            return u.origin !== location.origin;
        } catch (e) { return true; }
    }
    function isDownload(href) {
        return href.indexOf('service_download.php') !== -1 || /[?&]download=/.test(href);
    }
    // 必须整页跳转的链接：独立页 / 带删除、退出等副作用参数
    function isFullNav(href) {
        if (/login\.php|register\.php|logout\.php|service_download\.php/.test(href)) return true;
        if (/[?&](del|delete|remove|logout|download)=/.test(href)) return true;
        return false;
    }

    // 用新页面 HTML 替换当前主体
    function applyPage(html, href, push) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newC = doc.querySelector('.container');
        if (!newC) throw new Error('no container');
        var cur = document.querySelector('.container');
        cur.innerHTML = newC.innerHTML;
        // 重放新页面脚本（旧元素与旧监听随之销毁）
        // 内联脚本用间接 eval 执行：每次独立词法环境，避免顶层 const/let 重复声明冲突
        cur.querySelectorAll('script').forEach(function (s) {
            if (s.src) {
                var ns = document.createElement('script');
                ns.src = s.src;
                s.parentNode.replaceChild(ns, s);
            } else {
                try { (0, eval)(s.textContent); } catch (err) {}
                s.parentNode.removeChild(s);
            }
        });
        if (doc.title) document.title = doc.title;
        if (push) history.pushState(null, '', href);
        // 重放进入动画
        cur.style.animation = 'none';
        void cur.offsetWidth;
        cur.style.animation = '';
        document.body.classList.remove(LEAVING);
        busy = false;
        window.scrollTo(0, 0);
    }

    function loadPage(href, push) {
        return fetch(href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
            credentials: 'same-origin'
        })
            .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
            .then(function (html) { applyPage(html, href, push); });
    }

    function hardNav(href) {
        document.body.classList.remove(LEAVING);
        window.location.href = href;
    }

    function startSpa(href, push) {
        busy = true;
        if (reduced) {
            // 减弱动效：直接无刷新替换，不做过渡
            loadPage(href, push).catch(function () { hardNav(href); });
            return;
        }
        document.body.classList.add(LEAVING);
        setTimeout(function () {
            loadPage(href, push).catch(function () { hardNav(href); });
        }, DURATION);
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a) return;
        if (a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-no-transition')) return;
        var href = a.getAttribute('href') || '';
        if (!href || isSamePage(href) || isExternal(href) || isDownload(href)) return;
        if (busy) { e.preventDefault(); return; }
        e.preventDefault();
        if (!canSpa || isFullNav(href)) { hardNav(href); return; }
        startSpa(href, true);
    });

    // 前进/后退：无刷新加载
    window.addEventListener('popstate', function () {
        if (!canSpa || reduced || busy) return;
        startSpa(location.href, false);
    });

    // 浏览器前进/后退（整页加载场景）时清除残留的退出状态
    window.addEventListener('pageshow', function () {
        document.body.classList.remove(LEAVING);
        busy = false;
    });
})();

// ===== 右上角登录/注册抽屉 =====
document.addEventListener('DOMContentLoaded', function () {
    var drawer = document.getElementById('authDrawer');
    var mask = document.getElementById('authMask');
    if (!drawer || !mask) return;
    var loginForm = document.getElementById('loginForm');
    var registerForm = document.getElementById('registerForm');
    var tabs = document.querySelectorAll('.auth-tab');
    var loginMsg = document.getElementById('loginMsg');
    var registerMsg = document.getElementById('registerMsg');

    function showMsg(form, text, type) {
        var box = form === 'login' ? loginMsg : registerMsg;
        box.textContent = text;
        box.className = 'auth-msg ' + (type || 'err');
    }
    function clearMsgs() {
        loginMsg.className = 'auth-msg';
        registerMsg.className = 'auth-msg';
    }
    function switchTab(tab) {
        tabs.forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === tab);
        });
        loginForm.classList.toggle('hidden', tab !== 'login');
        registerForm.classList.toggle('hidden', tab !== 'register');
        clearMsgs();
    }
    function openDrawer(tab) {
        drawer.classList.add('open');
        mask.classList.add('open');
        document.body.style.overflow = 'hidden';
        switchTab(tab || 'login');
    }
    function closeDrawer() {
        drawer.classList.remove('open');
        mask.classList.remove('open');
        document.body.style.overflow = '';
        clearMsgs();
    }

    // 登录按钮：事件委托，避免元素渲染时序问题
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('.login-btn') : null;
        if (!a) return;
        e.preventDefault();
        openDrawer('login');
    });
    tabs.forEach(function (t) {
        t.addEventListener('click', function () { switchTab(t.getAttribute('data-tab')); });
    });
    mask.addEventListener('click', closeDrawer);
    var closeBtn = document.getElementById('authClose');
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

    function ajax(formEl, url, done) {
        var fd = new FormData(formEl);
        fd.append('ajax', '1');
        fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { done(d); })
            .catch(function () { done({ ok: false, msg: '网络错误，请重试' }); });
    }

    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();
        showMsg('login', '正在登录…', 'loading');
        ajax(loginForm, 'login.php', function (d) {
            if (d && d.ok) {
                showMsg('login', '登录成功，正在进入…', 'ok');
                setTimeout(function () { window.location.href = (d.redirect || 'index.php'); }, 600);
            } else {
                showMsg('login', (d && d.msg) || '登录失败', 'err');
            }
        });
    });
    registerForm.addEventListener('submit', function (e) {
        e.preventDefault();
        showMsg('register', '正在注册…', 'loading');
        ajax(registerForm, 'register.php', function (d) {
            if (d && d.ok) {
                showMsg('register', '注册成功，请登录', 'ok');
                setTimeout(function () { switchTab('login'); }, 900);
            } else {
                showMsg('register', (d && d.msg) || '注册失败', 'err');
            }
        });
    });
});
</script>
</head>
<body>
<div class="navbar">
    <div class="nav-container">
        <a href="index.php" class="logo">kilk</a>
        
        <ul class="nav-menu">
    <li><a href="index.php" <?php if(basename($_SERVER['PHP_SELF'])=='index.php') echo 'class="active"'; ?>>首页</a></li>
    <li><a href="forum.php" <?php if(basename($_SERVER['PHP_SELF'])=='forum.php' || basename($_SERVER['PHP_SELF'])=='post_detail.php') echo 'class="active"'; ?>>论坛</a></li>
    <li><a href="service.php" <?php if(basename($_SERVER['PHP_SELF'])=='service.php') echo 'class="active"'; ?>>服务支持</a></li>
    <li><a href="about.php" <?php if(basename($_SERVER['PHP_SELF'])=='about.php') echo 'class="active"'; ?>>关于我们</a></li>
</ul>

        <div class="nav-right">
            <?php if ($is_login): ?>
                <a href="profile.php" class="nav-avatar" title="个人主页">
                    <img src="<?php echo htmlspecialchars($login_user['avatar'] ? $login_user['avatar'] : '/img/default_avatar.png'); ?>" onerror="this.src='/img/default_avatar.png'" alt="头像">
                </a>
                <span class="user-info">你好，<?php echo htmlspecialchars($login_user['username']); ?></span>
                <a href="profile.php" class="nav-profile-link">个人主页</a>
                <?php if (in_array($login_user['role'], ['admin','super'])): ?>
                    <a href="admin.php" class="admin-btn">管理后台</a>
                <?php endif; ?>
                <a href="logout.php" class="logout-btn">退出</a>
            <?php else: ?>
                <a href="login.php" class="login-btn" data-no-transition>登录</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<!-- 右上角登录/注册抽屉 -->
<div class="auth-mask" id="authMask"></div>
<div class="auth-drawer" id="authDrawer">
    <div class="auth-head">
        <div class="auth-tabs">
            <button type="button" class="auth-tab active" data-tab="login">登 录</button>
            <button type="button" class="auth-tab" data-tab="register">注 册</button>
        </div>
        <button type="button" class="auth-close" id="authClose" title="关闭">&times;</button>
    </div>
    <form class="auth-form" id="loginForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="auth-msg" id="loginMsg"></div>
        <div class="auth-field">
            <label>账号</label>
            <input type="text" name="username" placeholder="请输入登录账号" required>
        </div>
        <div class="auth-field">
            <label>密码</label>
            <input type="password" name="password" placeholder="请输入密码" required>
        </div>
        <button type="submit" class="auth-submit">登 录</button>
    </form>
    <form class="auth-form hidden" id="registerForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="auth-msg" id="registerMsg"></div>
        <div class="auth-field">
            <label>登录账号（至少3位）</label>
            <input type="text" name="username" placeholder="请输入账号" required>
        </div>
        <div class="auth-field">
            <label>用户名称（选填）</label>
            <input type="text" name="name" placeholder="请输入名称">
        </div>
        <div class="auth-field">
            <label>密码（至少6位）</label>
            <input type="password" name="password" placeholder="请输入密码" required>
        </div>
        <div class="auth-field">
            <label>确认密码</label>
            <input type="password" name="password2" placeholder="请再次输入密码" required>
        </div>
        <button type="submit" class="auth-submit">注 册</button>
    </form>
</div>
<div class="container">
