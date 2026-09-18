<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
$progressData = getLevelProgress($pdo, $user);

$season = getActiveSeason($pdo);
$canSeason = ((int)$user['level'] >= SEASON_MIN_LEVEL) && $season;

$seasonBadge = $canSeason ? getSeasonBadge($pdo, $user) : '';

$pageTitle = 'Район';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="level-bar">
    <div style="display:flex;justify-content:space-between;">
        <span><?= (int)$progressData['current'] ?> / <?= (int)$progressData['required'] ?> XP</span>
        <span><?= (int)$progressData['percent'] ?>%</span>
    </div>
    <div class="level-progress">
        <div class="level-progress-bar" style="width:<?= (int)$progressData['percent'] ?>%"></div>
    </div>
</div>

<div class="nav-tabs">
    <a href="district.php" class="active">Район <?= $seasonBadge ?></a>
    <a href="garage.php">Гараж</a>
    <a href="races.php">Гонки</a>
    <a href="autosalon.php">Салон</a>
    <a href="profile.php">Профиль</a>
</div>

<div class="section-header">
    <h2>🏙 Район</h2>
    <span style="color:#8b949e;font-size:11px;">выбери локацию</span>
</div>

<div class="district-map">
    <div class="district-bg"></div>

    <div class="district-grid">
        <a href="bar.php" class="district-card district-card-bar">
            <div class="district-card-icon">🍺</div>
            <div class="district-card-text">
                <b>Бар</b>
                <small>чат · с 3 ур.</small>
            </div>
        </a>

        <a href="tournaments.php" class="district-card district-card-tour">
            <div class="district-card-icon">🏆</div>
            <div class="district-card-text">
                <b>Турниры</b>
                <small>призы · заезды</small>
            </div>
        </a>

        <a href="races.php" class="district-card district-card-race">
            <div class="district-card-icon">🏁</div>
            <div class="district-card-text">
                <b>Гонки</b>
                <small>PvP соперники</small>
            </div>
        </a>

        <a href="autosalon.php" class="district-card district-card-saloon">
            <div class="district-card-icon">🚗</div>
            <div class="district-card-text">
                <b>Салон</b>
                <small>новые авто</small>
            </div>
        </a>

        <?php if ($canSeason): ?>
            <a href="season.php" class="district-card district-card-season district-card-wide <?= $seasonBadge ? 'has-badge' : '' ?>">
                <?php if ($seasonBadge): ?>
                    <span class="district-badge"><?= preg_replace('/[^0-9]/', '', $seasonBadge) ?></span>
                <?php endif; ?>
                <div class="district-card-icon">🌟</div>
                <div class="district-card-text">
                    <b>Сезон · <?= e($season['name']) ?></b>
                    <small><?= $seasonBadge ? '🎁 Есть награды!' : 'задания · машина сезона' ?></small>
                </div>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="content" style="padding:15px;">
    <a href="garage.php" class="btn btn-block">🏠 В гараж</a>
</div>

<?php include 'footer.php'; ?>