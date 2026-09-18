<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);

// Если машина уже есть — в гараж
if (!empty(getUserCars($pdo, $user['id']))) {
    header('Location: garage.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $carId = $_POST['car_id'] ?? '';
        $car = getCarById($pdo, $carId);

        if (!$car) {
            $error = 'Такой машины не существует.';
        } elseif (!$car['is_starter']) {
            $error = 'Эту машину нельзя купить на старте.';
        } elseif ($user['usdt'] < $car['price']) {
            $error = 'Недостаточно USDT.';
        } else {
            $newBalance = (float)$user['usdt'] - (float)$car['price'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE users SET car_id=?, usdt=? WHERE id=?")
                    ->execute([$carId, $newBalance, $user['id']]);
                $pdo->prepare("INSERT INTO user_cars (user_id, car_id, color, stance) VALUES (?, ?, ?, 0)")
                    ->execute([$user['id'], $carId, $car['default_color']]);
                $pdo->commit();
                header('Location: garage.php');
                exit;
            } catch (Exception $ex) {
                $pdo->rollBack();
                $error = 'Ошибка покупки.';
            }
        }
    }
}

$starterCars = getStarterCars($pdo);
$csrfToken = generateCsrfToken();
$pageTitle = 'Выбор машины';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="banner">Выберите свою первую машину</div>

<div class="content" style="padding:20px 15px;">
    <p style="text-align:center;color:#c9d1d9;font-size:13px;margin-bottom:20px;">
        Добро пожаловать, <b style="color:#58a6ff;"><?= e($user['username']) ?></b>!<br>
        На вашем счету <b style="color:#26a17b;"><?= formatUSDT($user['usdt']) ?> USDT</b>.
    </p>

    <?php if ($error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($starterCars)): ?>
        <div class="message error">Каталог стартовых машин пуст.</div>
    <?php else: ?>
        <div class="car-choice-list">
            <?php foreach ($starterCars as $car): ?>
                <div class="car-choice-card">
                    <div class="car-choice-preview">
                        <?= renderCarImage($car['image'], $car['default_color'], 0) ?>
                    </div>
                    <div class="car-choice-body">
                        <div class="car-choice-name"><?= e($car['name']) ?></div>
                        <div class="car-choice-desc"><?= e($car['description']) ?></div>
                        <div class="car-choice-row">
                            <span class="car-choice-hp"><?= (int)$car['hp'] ?> л.с.</span>
                            <span class="car-choice-class"><?= e($car['class']) ?> класс</span>
                        </div>
                        <div class="car-choice-price">💵 <?= formatUSDT($car['price']) ?> USDT</div>
                        <form method="POST" action="choose_car.php">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="car_id" value="<?= e($car['id']) ?>">
                            <button type="submit" class="btn btn-block" style="margin-top:10px;">
                                Купить за <?= formatUSDT($car['price']) ?> USDT
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p style="text-align:center;color:#484f58;font-size:11px;margin-top:20px;">
        Выбор можно сделать только один раз.
    </p>
</div>

<?php include 'footer.php'; ?>