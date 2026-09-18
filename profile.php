<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
$progressData = getLevelProgress($pdo, $user);

// Статистика гонок (одним запросом)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN winner_id = ? THEN 1 ELSE 0 END) AS wins,
        SUM(CASE WHEN winner_id != ? THEN 1 ELSE 0 END) AS losses,
        COALESCE(SUM(CASE WHEN winner_id = ? THEN reward ELSE 0 END), 0) AS total_earned
    FROM races
    WHERE challenger_id = ? OR opponent_id = ?
");
$stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$stats = $stmt->fetch();

$total  = (int)($stats['total'] ?? 0);
$wins   = (int)($stats['wins'] ?? 0);
$losses = (int)($stats['losses'] ?? 0);
$earned = (float)($stats['total_earned'] ?? 0);
$winRate = $total > 0 ? round($wins / $total * 100) : 0;

// Последние 5 гонок
$stmt = $pdo->prepare("
    SELECT r.*, u1.username AS challenger_name, u2.username AS opponent_name
    FROM races r
    LEFT JOIN users u1 ON u1.id = r.challenger_id
    LEFT JOIN users u2 ON u2.id = r.opponent_id
    WHERE r.challenger_id = ? OR r.opponent_id = ?
    ORDER BY r.created_at DESC LIMIT 5
");
$stmt->execute([$user['id'], $user['id']]);
$lastRaces = $stmt->fetchAll();

// Текущая машина
$currentCar = null;
foreach (getUserCars($pdo, $user['id']) as $mc) {
    if ($mc['car_id'] === $user['car_id']) { $currentCar = $mc; break; }
}

$pageTitle = 'Профиль';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="nav-tabs">
    <a href="district.php">Район</a>
    <a href="garage.php">Гараж</a>
    <a href="races.php">Гонки</a>
    <a href="autosalon.php">Салон</a>
    <a href="profile.php" class="active">Профиль</a>
</div>

<div class="profile-header">
    <div class="profile-header-bg"></div>
    <div class="profile-avatar-wrap">
        <div class="profile-avatar-ring">
            <div class="profile-avatar-inner"><?= mb_strtoupper(mb_substr($user['username'], 0, 1)) ?></div>
        </div>
        <div class="profile-level-badge"><?= (int)$user['level'] ?></div>
    </div>
    <div class="profile-name-block">
        <div class="profile-username"><?= e($user['username']) ?></div>
        <div class="profile-rank">
            <?php
            $lvl = (int)$user['level'];
            if ($lvl < 5)       echo '🐣 Новичок';
            elseif ($lvl < 10)  echo '🚗 Водитель';
            elseif ($lvl < 20)  echo '🏎 Гонщик';
            elseif ($lvl < 30)  echo '🔥 Профи';
            elseif ($lvl < 40)  echo '⚡ Мастер';
            else                echo '👑 Легенда';
            ?>
        </div>
    </div>
</div>

<div class="profile-progress">
    <div class="profile-progress-info">
        <span>⭐ Уровень <?= (int)$user['level'] ?></span>
        <span>
            <?php if (!$progressData['level_max']): ?>
                <?= (int)$progressData['current'] ?> / <?= (int)$progressData['required'] ?> XP
            <?php else: ?>МАКС<?php endif; ?>
        </span>
    </div>
    <div class="level-progress" style="height:8px;">
        <div class="level-progress-bar" style="width:<?= (int)$progressData['percent'] ?>%"></div>
    </div>
    <div class="profile-progress-percent"><?= (int)$progressData['percent'] ?>% до следующего уровня</div>
</div>

<?php if ($currentCar): ?>
    <div class="profile-current-car">
        <div class="profile-car-label">🏁 Текущий автомобиль</div>
        <div class="profile-car-row">
            <div class="profile-car-img"><?= renderCarImage($currentCar['image'], $currentCar['color'] ?: $currentCar['default_color'], 0) ?></div>
            <div class="profile-car-info">
                <div class="profile-car-name"><?= e($currentCar['brand'] . ' ' . $currentCar['name']) ?></div>
                <div class="profile-car-power">⚡ <?= getCarPower($currentCar) ?> л.с.</div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-box"><div class="stat-icon">🎯</div><div class="stat-num"><?= $total ?></div><div class="stat-txt">Гонок</div></div>
    <div class="stat-box stat-green"><div class="stat-icon">🏆</div><div class="stat-num"><?= $wins ?></div><div class="stat-txt">Побед</div></div>
    <div class="stat-box stat-red"><div class="stat-icon">💨</div><div class="stat-num"><?= $losses ?></div><div class="stat-txt">Поражений</div></div>
    <div class="stat-box stat-blue"><div class="stat-icon">📊</div><div class="stat-num"><?= $winRate ?>%</div><div class="stat-txt">Винрейт</div></div>
</div>

<div class="earned-block">
    <div class="earned-label">💰 Заработано в гонках</div>
    <div class="earned-value"><?= formatUSDT($earned) ?> USDT</div>
</div>

<div class="section-title-row">
    <div class="section-title-line"></div>
    <div class="section-title-text">📜 Последние гонки</div>
    <div class="section-title-line"></div>
</div>

<?php if (empty($lastRaces)): ?>
    <div style="padding:15px;"><div class="message error">Вы ещё не участвовали в гонках.</div></div>
<?php else: ?>
    <div class="history-list">
        <?php foreach ($lastRaces as $race):
            $isChallenger = ($race['challenger_id'] == $user['id']);
            $won = ($race['winner_id'] == $user['id']);
            $oppName = $isChallenger ? $race['opponent_name'] : $race['challenger_name'];
        ?>
            <div class="history-item <?= $won ? 'history-win' : 'history-lose' ?>">
                <div class="history-badge"><?= $won ? '🏆' : '💨' ?></div>
                <div class="history-body">
                    <div class="history-vs">vs <b><?= e($oppName ?? 'Неизвестный') ?></b></div>
                    <div class="history-date"><?= date('d.m.Y · H:i', strtotime($race['created_at'])) ?></div>
                </div>
                <div class="history-reward <?= !$won ? 'history-reward-lose' : '' ?>">
                    <?= ($won && $race['reward'] > 0) ? '+' . formatUSDT($race['reward']) : '—' ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="content" style="padding:15px;">
    <a href="logout.php" class="btn btn-block btn-danger">Выйти из аккаунта</a>
</div>

<?php include 'footer.php'; ?>