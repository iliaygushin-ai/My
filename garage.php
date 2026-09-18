<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
$myCars = getUserCars($pdo, $user['id']);

if (empty($myCars)) {
    $pdo->prepare("UPDATE users SET car_id = NULL WHERE id = ?")->execute([$user['id']]);
    header('Location: choose_car.php');
    exit;
}

// Определяем текущую машину
$carIds = array_column($myCars, 'car_id');
if (empty($user['car_id']) || !in_array($user['car_id'], $carIds)) {
    $user['car_id'] = $myCars[0]['car_id'];
    $pdo->prepare("UPDATE users SET car_id = ? WHERE id = ?")->execute([$user['car_id'], $user['id']]);
}

$currentIdx = 0;
foreach ($myCars as $i => $mc) {
    if ($mc['car_id'] === $user['car_id']) { $currentIdx = $i; break; }
}
$car = $myCars[$currentIdx];
$carColor  = $car['color'] ?: $car['default_color'];
$carStance = (int)($car['stance'] ?? 0);

// Поздравление с покупкой
$boughtMessage = '';
if (!empty($_GET['bought'])) {
    $b = getCarById($pdo, $_GET['bought']);
    if ($b) $boughtMessage = '🎉 Поздравляем с покупкой ' . $b['brand'] . ' ' . $b['name'] . '!';
}

$progressData = getLevelProgress($pdo, $user);
$csrfToken = generateCsrfToken();
$pageTitle = 'Гараж';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="level-bar">
    <div style="display:flex;justify-content:space-between;">
        <span>
            <?php if (!$progressData['level_max']): ?>
                <?= (int)$progressData['current'] ?> / <?= (int)$progressData['required'] ?> XP
            <?php else: ?>
                МАКС. УРОВЕНЬ
            <?php endif; ?>
        </span>
        <span><?= (int)$progressData['percent'] ?>%</span>
    </div>
    <div class="level-progress">
        <div class="level-progress-bar" style="width:<?= (int)$progressData['percent'] ?>%"></div>
    </div>
</div>

<div class="nav-tabs">
    <a href="district.php">Район</a>
    <a href="garage.php" class="active">Гараж</a>
    <a href="races.php">Гонки</a>
    <a href="autosalon.php">Салон</a>
    <a href="profile.php">Профиль</a>
</div>

<div class="section-header">
    <h2>Гараж (<?= $currentIdx + 1 ?> из <?= count($myCars) ?>)</h2>
    <a href="autosalon.php" class="btn-add">+</a>
</div>

<?php if ($boughtMessage): ?>
    <div style="padding:12px 15px;"><div class="message success"><?= e($boughtMessage) ?></div></div>
<?php endif; ?>

<div class="car-info">
    <div class="car-details">
        <div class="car-name"><?= e($car['brand'] . ' ' . $car['name']) ?></div>
        <div class="car-stats">
            <span class="hp"><?= (int)$car['hp'] ?> л.с.</span> |
            <span class="class"><?= e($car['class']) ?> класс</span>
        </div>
    </div>
    <div class="car-plate-box">
        <div class="car-plate">AP <?= str_pad($currentIdx + 1, 3, '0', STR_PAD_LEFT) ?> Y</div>
    </div>
</div>

<div class="car-viewport">
    <?= renderCarImage($car['image'], $carColor, round($carStance * 0.7)) ?>
    <div class="car-stance-info">🔧 Занижение: <span><?= $carStance ?> мм</span></div>
</div>

<div class="car-controls">
    <a href="cars.php">Сменить авто</a>
    <a href="tuning.php">Тюнинг</a>
    <a href="district.php">Район</a>
</div>

<?php if (isStaff($user)): ?>
    <div style="padding:10px 15px;">
        <a href="admin.php" class="btn btn-block"
           style="background:linear-gradient(180deg,<?= isAdmin($user) ? '#d29922,#b8860b' : '#58a6ff,#1f6feb' ?>);
                  border-color:<?= isAdmin($user) ? '#f0c674' : '#58a6ff' ?>;
                  color:<?= isAdmin($user) ? '#000' : '#fff' ?>;">
            <?= getRoleName($user['role']) ?> панель
        </a>
    </div>
<?php endif; ?>

<div class="content" style="padding:15px;">
    <a href="logout.php" class="btn btn-block btn-danger">Выйти</a>
</div>

<?php include 'footer.php'; ?>