<?php
require_once 'config.php';
require_once 'functions.php';
$pageTitle = 'Аккаунт заблокирован';
?>
<?php include 'header.php'; ?>

<div class="content" style="padding:40px 20px;text-align:center;">
    <div style="font-size:60px;margin-bottom:20px;">🚫</div>
    <h2 style="color:#f85149;font-size:20px;margin-bottom:15px;">Аккаунт заблокирован</h2>
    <p style="color:#8b949e;font-size:13px;line-height:1.6;">
        Ваш аккаунт был заблокирован администрацией.<br>
        Если вы считаете это ошибкой — свяжитесь с поддержкой.
    </p>
    <div style="margin-top:30px;">
        <a href="auth.php" class="btn btn-block">← На страницу входа</a>
    </div>
</div>

<?php include 'footer.php'; ?>