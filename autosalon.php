<?php
require_once 'config.php';
require_once 'functions.php';

// Запрет кэширования браузером
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user = requireAuth($pdo);

if (empty(getUserCars($pdo, $user['id']))) {
    header('Location: choose_car.php');
    exit;
}

// Полная очистка ошибки по ?clear=1
if (isset($_GET['clear'])) {
    unset($_SESSION['salon_error']);
    header('Location: autosalon.php');
    exit;
}

// ==========================================
// ПОКУПКА
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['salon_error'] = 'Ошибка безопасности.';
        header('Location: autosalon.php'); exit;
    }

    $carId = trim($_POST['car_id'] ?? '');

    // Пустой ID — просто игнорируем, без ошибки
    if ($carId === '') {
        header('Location: autosalon.php'); exit;
    }

    $car = getCarById($pdo, $carId);

    if (!$car) {
        $_SESSION['salon_error'] = 'Машина не найдена (id: ' . e($carId) . ').';
        header('Location: autosalon.php'); exit;
    }
    if ((int)$car['min_level'] > (int)$user['level']) {
        $_SESSION['salon_error'] = 'Ваш уровень слишком мал (нужен ' . (int)$car['min_level'] . ').';
        header('Location: autosalon.php'); exit;
    }
    if (userHasCar($pdo, $user['id'], $carId)) {
        $_SESSION['salon_error'] = 'Эта машина уже в гараже.';
        header('Location: autosalon.php'); exit;
    }
    if ($user['usdt'] < $car['price']) {
        $_SESSION['salon_error'] = 'Недостаточно USDT.';
        header('Location: autosalon.php'); exit;
    }

    $newBalance = (float)$user['usdt'] - (float)$car['price'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET usdt = ?, car_id = ? WHERE id = ?")
            ->execute([$newBalance, $carId, $user['id']]);
        $pdo->prepare("INSERT INTO user_cars (user_id, car_id, color, stance) VALUES (?, ?, ?, 0)")
            ->execute([$user['id'], $carId, $car['default_color']]);
        $pdo->commit();
        header('Location: garage.php?bought=' . urlencode($carId));
        exit;
    } catch (Exception $ex) {
        $pdo->rollBack();
        $_SESSION['salon_error'] = 'Ошибка покупки.';
        header('Location: autosalon.php'); exit;
    }
}

// Ошибка из сессии — показываем один раз
$error = '';
if (!empty($_SESSION['salon_error'])) {
    $error = $_SESSION['salon_error'];
    unset($_SESSION['salon_error']);
}

$salonCars = getSalonCars($pdo, $user['id'], $user['level']);
$csrfToken = generateCsrfToken();
$pageTitle = 'Автосалон';
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
    <a href="autosalon.php" class="active">Салон</a>
    <a href="profile.php">Профиль</a>
</div>

<div class="section-header">
    <h2>Автосалон</h2>
    <span style="color:#8b949e;font-size:11px;">Ур. <?= (int)$user['level'] ?> • <?= count($salonCars) ?></span>
</div>

<?php if ($error): ?>
    <div style="padding:10px 15px;">
        <div class="message error">
            <?= e($error) ?>
            <a href="?clear=1" style="color:#fff;text-decoration:none;margin-left:10px;font-weight:bold;font-size:16px;">✕</a>
        </div>
    </div>
<?php endif; ?>

<div class="content" style="padding:15px;">
    <?php if (empty($salonCars)): ?>
        <div class="message error">Автосалон пока пуст.</div>
    <?php else: ?>
        <div class="car-choice-list">
            <?php foreach ($salonCars as $car):
                $owned     = $car['owned'] > 0;
                $locked    = $car['min_level'] > $user['level'];
                $canAfford = $user['usdt'] >= $car['price'];
            ?>
                <div class="car-choice-card <?= $locked ? 'locked' : '' ?>">
                    <div class="car-choice-preview">
                        <?= renderCarImage($car['image'], $car['default_color'], 0) ?>
                        <?php if ($locked): ?>
                            <div class="car-lock-overlay">🔒 Уровень <?= (int)$car['min_level'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="car-choice-body">
                        <div class="car-choice-name"><?= e($car['brand'] . ' ' . $car['name']) ?></div>
                        <div class="car-choice-desc"><?= e($car['description']) ?></div>
                        <div class="car-choice-row">
                            <span class="car-choice-hp"><?= (int)$car['hp'] ?> л.с.</span>
                            <span class="car-choice-class"><?= e($car['class']) ?> класс</span>
                        </div>
                        <div class="car-choice-price">💵 <?= formatUSDT($car['price']) ?> USDT</div>

                        <?php if ($owned): ?>
                            <button type="button" class="btn btn-block" style="opacity:.5;cursor:default;" disabled>Уже в гараже</button>
                        <?php elseif ($locked): ?>
                            <button type="button" class="btn btn-block" style="opacity:.5;cursor:default;background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;" disabled>
                                🔒 Откроется на ур. <?= (int)$car['min_level'] ?>
                            </button>
                        <?php elseif (!$canAfford): ?>
                            <button type="button" class="btn btn-block" style="opacity:.5;cursor:default;background:linear-gradient(180deg,#8b1a1a,#5d1010);border-color:#8b1a1a;" disabled>
                                Недостаточно USDT
                            </button>
                        <?php else: ?>
                            <form method="POST" action="autosalon.php" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="car_id" value="<?= e($car['id']) ?>">
                                <button type="submit" class="btn btn-block">Купить за <?= formatUSDT($car['price']) ?> USDT</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>