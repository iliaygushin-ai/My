<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
$myCars = getUserCars($pdo, $user['id']);

if (empty($myCars)) {
    header('Location: choose_car.php');
    exit;
}

// Текущая машина
$car = null;
foreach ($myCars as $mc) if ($mc['car_id'] === $user['car_id']) { $car = $mc; break; }
if (!$car) {
    $car = $myCars[0];
    $pdo->prepare("UPDATE users SET car_id = ? WHERE id = ?")->execute([$car['car_id'], $user['id']]);
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Ошибка безопасности.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'stance') {
            $stance = max(0, min(40, (int)($_POST['stance'] ?? 0)));
            $pdo->prepare("UPDATE user_cars SET stance = ? WHERE user_id = ? AND car_id = ?")
                ->execute([$stance, $user['id'], $car['car_id']]);
            $message = 'Посадка настроена: ' . $stance . ' мм';
            $car['stance'] = $stance;
        }
    }
}

$carColor  = $car['color'] ?: $car['default_color'];
$carStance = (int)($car['stance'] ?? 0);
$csrfToken = generateCsrfToken();
$pageTitle = 'Тюнинг';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="nav-tabs">
    <a href="district.php">Район</a>
    <a href="garage.php" class="active">Гараж</a>
    <a href="races.php">Гонки</a>
    <a href="autosalon.php">Салон</a>
    <a href="profile.php">Профиль</a>
</div>

<div class="section-header">
    <h2>Тюнинг</h2>
    <a href="garage.php" class="btn-add" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;font-size:14px;">←</a>
</div>

<div class="car-info">
    <div class="car-details">
        <div class="car-name"><?= e($car['brand'] . ' ' . $car['name']) ?></div>
        <div class="car-stats">
            <span class="hp"><?= (int)$car['hp'] ?> л.с.</span> |
            <span class="class"><?= e($car['class']) ?> класс</span>
        </div>
    </div>
</div>

<div class="car-viewport">
    <?= renderCarImage($car['image'], $carColor, round($carStance * 0.7)) ?>
    <div class="car-stance-info">🔧 Занижение: <span><?= $carStance ?> мм</span></div>
</div>

<div class="message-log">
    <?php if ($message): ?>
        <span class="highlight"><?= e($message) ?></span>
    <?php else: ?>
        Измените посадку своего автомобиля
    <?php endif; ?>
</div>

<div class="tuning-panel">
    <div class="tuning-block">
        <div class="tuning-title">🔧 Посадка (занижение подвески)</div>
        <form method="POST" action="tuning.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="stance">
            <div class="stance-slider">
                <input type="range" name="stance" min="0" max="40" step="5"
                       value="<?= $carStance ?>" class="slider"
                       oninput="document.getElementById('stanceValue').textContent = this.value + ' мм'">
                <div class="stance-labels">
                    <span>Сток</span>
                    <span id="stanceValue"><?= $carStance ?> мм</span>
                    <span>Слэм</span>
                </div>
            </div>
            <button type="submit" class="btn btn-block" style="margin-top:10px;">Применить</button>
        </form>
    </div>
</div>

<div class="car-controls">
    <a href="cars.php">Сменить авто</a>
    <a href="garage.php">В гараж</a>
    <a href="autosalon.php">Автосалон</a>
</div>

<?php include 'footer.php'; ?>