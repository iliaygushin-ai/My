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
$myCar = null;
foreach ($myCars as $mc) if ($mc['car_id'] === $user['car_id']) { $myCar = $mc; break; }
if (!$myCar) $myCar = $myCars[0];

$myPower = getCarPower($myCar);
$result = null;

// ==========================================
// ОБРАБОТКА ГОНКИ
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oppId = (int)($_POST['opponent_id'] ?? 0);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['race_result'] = ['error' => 'Ошибка безопасности.'];
        header('Location: races.php'); exit;
    }
    if ($oppId <= 0 || $oppId === (int)$user['id']) {
        $_SESSION['race_result'] = ['error' => 'Некорректный соперник.'];
        header('Location: races.php'); exit;
    }

    $racesToday = getRaceCountToday($pdo, $user['id'], $oppId);
    if ($racesToday >= RACE_DAILY_LIMIT_PER_OPPONENT) {
        $_SESSION['race_result'] = ['error' => '🚫 Лимит гонок с этим соперником исчерпан.'];
        header('Location: races.php'); exit;
    }

    $elapsed = !empty($user['last_race']) ? (time() - strtotime($user['last_race'])) : 999;
    if ($elapsed < 2) {
        $_SESSION['race_result'] = ['error' => '⏳ Подождите 2 сек.'];
        header('Location: races.php'); exit;
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.username,
               uc.car_id, uc.color, uc.stance,
               c.name AS car_name, c.brand AS car_brand, c.hp, c.class, c.image
        FROM users u
        JOIN user_cars uc ON uc.user_id = u.id AND uc.car_id = u.car_id
        JOIN cars c ON c.id = uc.car_id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$oppId]);
    $opp = $stmt->fetch();

    if (!$opp) {
        $_SESSION['race_result'] = ['error' => 'Соперник не найден.'];
        header('Location: races.php'); exit;
    }

    $oppPower = getCarPower($opp);
    $sim = simulateRace($myPower, $user['id'], $oppPower, $opp['id']);
    $won = ($sim['winner_id'] == $user['id']);
    $reward = $won ? $sim['reward'] : 0;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO races 
            (challenger_id, opponent_id, challenger_car, opponent_car, 
             challenger_power, opponent_power, winner_id, reward)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $user['id'], $opp['id'],
            $myCar['car_id'], $opp['car_id'],
            $myPower, $oppPower, $sim['winner_id'], $reward
        ]);

        if ($won) {
            $pdo->prepare("UPDATE users SET usdt = usdt + ?, last_race = NOW() WHERE id = ?")
                ->execute([$reward, $user['id']]);
        } else {
            $pdo->prepare("UPDATE users SET last_race = NOW() WHERE id = ?")->execute([$user['id']]);
        }
        $pdo->commit();

        incrementRaceCount($pdo, $user['id'], $opp['id']);

        $expReward = $won ? 30 : 10;
        $levelResult = addExp($pdo, $user['id'], $expReward);
        $racesLeft = max(0, RACE_DAILY_LIMIT_PER_OPPONENT - ($racesToday + 1));

        $_SESSION['race_result'] = [
            'won'          => $won,
            'reward'       => $reward,
            'exp'          => $expReward,
            'level_up'     => $levelResult['level_up'],
            'new_level'    => $levelResult['new_level'],
            'level_reward' => $levelResult['reward'],
            'opp_power'    => $oppPower,
            'opp_name'     => $opp['username'],
            'opp_id'       => (int)$opp['id'],
            'races_left'   => $racesLeft,
        ];
    } catch (Exception $ex) {
        $pdo->rollBack();
        $_SESSION['race_result'] = ['error' => 'Ошибка при проведении гонки.'];
    }
    header('Location: races.php'); exit;
}

// Восстановление результата из сессии
if (!empty($_SESSION['race_result'])) {
    $result = $_SESSION['race_result'];
    unset($_SESSION['race_result']);
}

$opponents = findOpponents($pdo, $user['id'], 3);
$csrfToken = generateCsrfToken();
$pageTitle = 'Гонки';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="nav-tabs">
    <a href="district.php">Район</a>
    <a href="garage.php">Гараж</a>
    <a href="races.php" class="active">Гонки</a>
    <a href="autosalon.php">Салон</a>
    <a href="profile.php">Профиль</a>
</div>

<div class="section-header">
    <h2>Гонки</h2>
    <span style="color:#8b949e;font-size:11px;">⚡ <?= (int)$myPower ?> л.с.</span>
</div>

<?php if ($result && isset($result['error'])): ?>
    <div style="padding:10px 12px;"><div class="message error" style="margin-bottom:0;"><?= e($result['error']) ?></div></div>
<?php endif; ?>

<div class="race-vs-banner">🏁 ВЫБЕРИ СОПЕРНИКА</div>

<?php if ($result && isset($result['won'])): ?>
    <div style="padding:10px 12px;">
        <?php if ($result['won']): ?>
            <div class="race-result race-win">
                <div class="race-result-title">🏆 ПОБЕДА</div>
                <div class="race-result-line"><b><?= e($result['opp_name']) ?></b> — <?= (int)$result['opp_power'] ?> л.с.</div>
                <div class="race-result-reward">+<?= formatUSDT($result['reward']) ?> USDT</div>
                <div class="race-result-exp">+<?= (int)$result['exp'] ?> опыта</div>
                <?php if ($result['level_up']): ?>
                    <div class="level-up-banner">
                        ⭐ УРОВЕНЬ <?= (int)$result['new_level'] ?>!
                        <?php if ($result['level_reward'] > 0): ?><br>Награда: +<?= formatUSDT($result['level_reward']) ?> USDT<?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="race-result race-lose">
                <div class="race-result-title">💨 ПОРАЖЕНИЕ</div>
                <div class="race-result-line"><b><?= e($result['opp_name']) ?></b> — <?= (int)$result['opp_power'] ?> л.с.</div>
                <div class="race-result-exp">+<?= (int)$result['exp'] ?> опыта</div>
                <?php if ($result['level_up']): ?>
                    <div class="level-up-banner">⭐ УРОВЕНЬ <?= (int)$result['new_level'] ?>!</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="race-actions">
            <?php if ($result['races_left'] > 0): ?>
                <form method="POST" style="flex:1;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="opponent_id" value="<?= (int)$result['opp_id'] ?>">
                    <button type="submit" class="btn btn-block" style="padding:10px;">
                        🔄 Реванш (<?= (int)$result['races_left'] ?>/<?= RACE_DAILY_LIMIT_PER_OPPONENT ?>)
                    </button>
                </form>
            <?php else: ?>
                <button class="btn btn-block" disabled
                        style="flex:1;opacity:.5;cursor:not-allowed;background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;padding:10px;">
                    🚫 Лимит исчерпан
                </button>
            <?php endif; ?>
            <a href="races.php" class="btn" style="flex:1;background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;padding:10px;">👥 Другой</a>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($opponents)): ?>
    <div class="content" style="padding:20px 15px;">
        <div class="message error">
            Сейчас нет доступных соперников.<br>
            <small style="font-size:10px;">Пригласи друзей — пусть зарегистрируются!</small>
        </div>
    </div>
<?php else: ?>
    <div class="opponents-list">
        <?php foreach ($opponents as $opp):
            $oppPower = getCarPower($opp);
            $oppColor = $opp['color'] ?: '#c0392b';
            $diff = $oppPower - $myPower;

            if ($diff > 30)      { $hintClass = 'hint-hard'; $hintText = '🔥'; }
            elseif ($diff > 0)   { $hintClass = 'hint-mid';  $hintText = '⚠'; }
            elseif ($diff > -30) { $hintClass = 'hint-easy'; $hintText = '✓'; }
            else                 { $hintClass = 'hint-free'; $hintText = '💚'; }

            $racesToday = getRaceCountToday($pdo, $user['id'], $opp['id']);
            $limitReached = ($racesToday >= RACE_DAILY_LIMIT_PER_OPPONENT);
            $racesLeft = max(0, RACE_DAILY_LIMIT_PER_OPPONENT - $racesToday);
        ?>
            <div class="opponent-card <?= $limitReached ? 'opponent-card-disabled' : '' ?>">
                <div class="opponent-car"><?= renderCarImage($opp['image'], $oppColor, 0) ?></div>
                <div class="opponent-body">
                    <div class="opponent-name">
                        <span class="opponent-nick"><?= e($opp['username']) ?></span>
                        <span class="opponent-hint <?= $hintClass ?>"><?= $hintText ?></span>
                    </div>
                    <div class="opponent-car-name"><?= e($opp['car_brand'] . ' ' . $opp['car_name']) ?></div>
                    <div class="opponent-power">
                        ⚡ <?= (int)$oppPower ?> л.с.
                        <span class="opponent-limit">
                            <?= $limitReached ? '🚫 0/' . RACE_DAILY_LIMIT_PER_OPPONENT : '🎯 ' . $racesLeft . '/' . RACE_DAILY_LIMIT_PER_OPPONENT ?>
                        </span>
                    </div>
                </div>
                <div class="opponent-action">
                    <?php if ($limitReached): ?>
                        <div class="btn-race-disabled">🚫</div>
                    <?php else: ?>
                        <form method="POST" action="races.php">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="opponent_id" value="<?= (int)$opp['id'] ?>">
                            <button type="submit" class="btn-race">🏁</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="content" style="padding:15px;">
    <a href="garage.php" class="btn btn-block">← В гараж</a>
</div>

<?php include 'footer.php'; ?>