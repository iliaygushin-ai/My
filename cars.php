<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
$myCars = getUserCars($pdo, $user['id']);

if (empty($myCars)) {
    header('Location: choose_car.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $carId = $_POST['car_id'] ?? '';
        $carIds = array_column($myCars, 'car_id');
        if (in_array($carId, $carIds)) {
            $pdo->prepare("UPDATE users SET car_id = ? WHERE id = ?")->execute([$carId, $user['id']]);
            header('Location: garage.php');
            exit;
        }
    }
    header('Location: cars.php');
    exit;
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Мои машины';
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
    <h2>Мои машины (<?= count($myCars) ?>)</h2>
    <a href="garage.php" class="btn-add" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;font-size:14px;">←</a>
</div>

<div class="content" style="padding:15px;">
    <p style="text-align:center;color:#8b949e;font-size:12px;margin-bottom:15px;">
        Выберите машину, на которой хотите ездить
    </p>

    <div class="my-cars-list">
        <?php foreach ($myCars as $mc):
            $isCurrent = ($mc['car_id'] === $user['car_id']);
            $mcColor   = $mc['color'] ?: $mc['default_color'];
            $mcStance  = (int)($mc['stance'] ?? 0);
        ?>
            <div class="my-car-card <?= $isCurrent ? 'current' : '' ?>">
                <?php if ($isCurrent): ?>
                    <div class="my-car-badge">✓ Сейчас используется</div>
                <?php endif; ?>
                <div class="my-car-preview">
                    <?= renderCarImage($mc['image'], $mcColor, round($mcStance * 0.7)) ?>
                </div>
                <div class="my-car-body">
                    <div class="my-car-name"><?= e($mc['brand'] . ' ' . $mc['name']) ?></div>
                    <div class="my-car-stats">
                        <span class="hp"><?= (int)$mc['hp'] ?> л.с.</span>
                        <span class="class"><?= e($mc['class']) ?> класс</span>
                        <?php if ($mcStance > 0): ?>
                            <span style="color:#58a6ff;">🔧 -<?= $mcStance ?> мм</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isCurrent): ?>
                        <button class="btn btn-block" disabled
                                style="opacity:.5;background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;cursor:default;">
                            Текущая машина
                        </button>
                    <?php else: ?>
                        <form method="POST" action="cars.php">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="car_id" value="<?= e($mc['car_id']) ?>">
                            <button type="submit" class="btn btn-block">Сесть за руль</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'footer.php'; ?>