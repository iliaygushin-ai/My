<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);

if ((int)$user['level'] < SEASON_MIN_LEVEL) {
    header('Location: district.php');
    exit;
}

$season = getActiveSeason($pdo);
if (!$season) {
    $pageTitle = 'Сезон';
    include 'header.php';
    ?>
    <div class="top-bar">
        <div>Уровень <?= (int)$user['level'] ?></div>
        <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
    </div>
    <div class="nav-tabs">
        <a href="district.php">Район</a>
        <a href="garage.php">Гараж</a>
        <a href="races.php">Гонки</a>
        <a href="season.php" class="active">Сезон</a>
        <a href="profile.php">Профиль</a>
    </div>
    <div class="content" style="padding:30px 15px;text-align:center;">
        <div style="font-size:40px;margin-bottom:10px;">🏆</div>
        <div style="color:#8b949e;">Активного сезона пока нет</div>
    </div>
    <?php include 'footer.php'; exit;
}

$userSeason = getUserSeason($pdo, $user['id'], $season['id']);
$justCompleted = checkSeasonTasks($pdo, $user['id'], $season, $userSeason);

$oldLevel = (int)$userSeason['level'];
recalcSeasonLevel($pdo, $userSeason);

$stmt = $pdo->prepare("SELECT * FROM user_seasons WHERE id = ? LIMIT 1");
$stmt->execute([$userSeason['id']]);
$userSeason = $stmt->fetch();

$newLevel = (int)$userSeason['level'];
$lastRewardLevel = (int)$userSeason['last_reward_level'];

$notifications = [];
if ($newLevel > $oldLevel) {
    $notifications[] = ['type' => 'success', 'text' => '🎉 Уровень сезона повышен до ' . $newLevel . '!'];
}
foreach ($justCompleted as $task) {
    $notifications[] = ['type' => 'success', 'text' => '✅ Задание выполнено: «' . $task['title'] . '» — заберите награду!'];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Забрать задание ---
        if ($action === 'claim_task') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $res = claimTaskReward($pdo, $user['id'], $season['id'], $taskId);
            if (!$res['ok']) {
                $error = $res['error'];
            } else {
                $bonus = [];
                if ($res['xp'] > 0)   $bonus[] = '+' . $res['xp'] . ' XP';
                if ($res['usdt'] > 0) $bonus[] = '+' . formatUSDT($res['usdt']) . ' USDT';
                $success = '✅ «' . $res['title'] . '» — ' . implode(', ', $bonus);

                $stmt = $pdo->prepare("SELECT * FROM user_seasons WHERE id = ? LIMIT 1");
                $stmt->execute([$userSeason['id']]);
                $userSeason = $stmt->fetch();
                recalcSeasonLevel($pdo, $userSeason);

                $stmt = $pdo->prepare("SELECT * FROM user_seasons WHERE id = ? LIMIT 1");
                $stmt->execute([$userSeason['id']]);
                $userSeason = $stmt->fetch();
                $newLevel = (int)$userSeason['level'];

                $GLOBALS['__auth_user'] = null;
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$user['id']]);
                $user = $stmt->fetch();
                $GLOBALS['__season_badge'] = null;
            }
        }

        // --- Забрать награду за уровень ---
        if ($action === 'claim_reward') {
            $expected = $lastRewardLevel + 1;
            $nextReward = getNextSeasonReward($pdo, $season['id'], $lastRewardLevel);

            if (!$nextReward) {
                $error = 'Все награды сезона уже забраны!';
            } elseif ($newLevel < $expected) {
                $error = 'Сначала достигните ' . $expected . ' уровня сезона.';
            } else {
                $usdt = (float)$nextReward['usdt'];
                if ($usdt > 0) {
                    $pdo->prepare("UPDATE users SET usdt = usdt + ? WHERE id = ?")
                        ->execute([$usdt, $user['id']]);
                }
                $pdo->prepare("UPDATE user_seasons SET last_reward_level = ? WHERE id = ?")
                    ->execute([$expected, $userSeason['id']]);

                $success = '💰 Награда за ' . $expected . ' уровень: +' . formatUSDT($usdt) . ' USDT!';
                $lastRewardLevel = $expected;
                $userSeason['last_reward_level'] = $expected;
                $GLOBALS['__season_badge'] = null;

                $GLOBALS['__auth_user'] = null;
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$user['id']]);
                $user = $stmt->fetch();
            }
        }

        // --- Забрать машину ---
        if ($action === 'claim_car') {
            $unlockLevel = max(1, (int)$season['car_unlock_level']);

            if (!empty($userSeason['car_claimed'])) {
                $error = 'Машина сезона уже забрана.';
            } elseif ($newLevel < $unlockLevel) {
                $error = 'Машина открывается на ' . $unlockLevel . ' уровне.';
            } elseif (userHasCar($pdo, $user['id'], $season['car_id'])) {
                $pdo->prepare("UPDATE user_seasons SET car_claimed=1 WHERE id=?")->execute([$userSeason['id']]);
                $error = 'Эта машина уже в гараже.';
            } else {
                $car = getCarById($pdo, $season['car_id']);
                if (!$car) {
                    $error = 'Машина не найдена.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("INSERT INTO user_cars (user_id, car_id, color, stance) VALUES (?, ?, ?, 0)")
                            ->execute([$user['id'], $season['car_id'], $car['default_color']]);
                        $pdo->prepare("UPDATE user_seasons SET car_claimed=1 WHERE id=?")->execute([$userSeason['id']]);
                        $pdo->commit();
                        $success = '🎁 Вы получили машину: ' . $car['brand'] . ' ' . $car['name'] . '!';
                        $userSeason['car_claimed'] = 1;
                        $GLOBALS['__season_badge'] = null;
                    } catch (Exception $ex) { $pdo->rollBack(); $error = 'Ошибка.'; }
                }
            }
        }
    }
}

// ==========================================
// ДАННЫЕ
// ==========================================
$tasks = getSeasonTasks($pdo, $season['id']);
$userTasks = getUserSeasonTasks($pdo, $user['id'], $season['id']);
$rewards = getSeasonRewards($pdo, $season['id']);

$car = getCarById($pdo, $season['car_id']);
$carOwned = userHasCar($pdo, $user['id'], $season['car_id']);
$carClaimed = !empty($userSeason['car_claimed']);
$unlockLevel = max(1, (int)$season['car_unlock_level']);
$canClaimCar = ($newLevel >= $unlockLevel) && !$carClaimed && !$carOwned;

$myLevel = (int)$userSeason['level'];
$myXp = (int)$userSeason['xp'];
$nextLevelXp = getSeasonXpForLevel($myLevel + 1);
$prevLevelXp = getSeasonXpForLevel($myLevel);
$levelProgress = ($nextLevelXp - $prevLevelXp) > 0 ? min(100, round(($myXp - $prevLevelXp) / ($nextLevelXp - $prevLevelXp) * 100)) : 100;

$nextReward = getNextSeasonReward($pdo, $season['id'], $lastRewardLevel);
$canClaimNext = $nextReward && ($myLevel >= $lastRewardLevel + 1);

$seasonEndsIn = (strtotime($season['end_at']) - time()) / 86400;
$seasonDays = max(0, (int)ceil($seasonEndsIn));

$csrfToken = generateCsrfToken();
$pageTitle = 'Сезон: ' . $season['name'];
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div>Уровень <?= (int)$user['level'] ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="nav-tabs">
    <a href="district.php">Район <?= getSeasonBadge($pdo, $user) ?></a>
    <a href="garage.php">Гараж</a>
    <a href="races.php">Гонки</a>
    <a href="season.php" class="active">Сезон</a>
    <a href="profile.php">Профиль</a>
</div>

<div class="section-header">
    <h2>🏆 <?= e($season['name']) ?></h2>
    <a href="district.php" class="btn-add" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;font-size:14px;">←</a>
</div>

<?php if ($error): ?>
    <div style="padding:10px 12px;"><div class="message error"><?= e($error) ?></div></div>
<?php endif; ?>
<?php if ($success): ?>
    <div style="padding:10px 12px;"><div class="message success"><?= $success ?></div></div>
<?php endif; ?>
<?php foreach ($notifications as $n): ?>
    <div style="padding:10px 12px 0;"><div class="message <?= $n['type'] ?>"><?= e($n['text']) ?></div></div>
<?php endforeach; ?>

<!-- ШАПКА СЕЗОНА (компактная) -->
<div class="season-hero season-hero-compact">
    <div class="season-hero-bg"></div>
    <div class="season-hero-content">
        <div class="season-hero-badge">🏆 СЕЗОН</div>
        <div class="season-hero-title"><?= e($season['name']) ?></div>
        <div class="season-hero-dates">
            <?= date('d.m', strtotime($season['start_at'])) ?> — <?= date('d.m.Y', strtotime($season['end_at'])) ?>
            <span class="season-hero-days">осталось <?= $seasonDays ?> дн.</span>
        </div>
    </div>
</div>

<!-- УРОВЕНЬ СЕЗОНА -->
<div class="season-lvl-bar">
    <div class="season-lvl-circle"><?= $myLevel ?></div>
    <div class="season-lvl-info">
        <div class="season-lvl-title">Уровень сезона <b><?= $myLevel ?></b></div>
        <div class="season-lvl-progress">
            <div class="season-lvl-progress-fill" style="width:<?= $levelProgress ?>%"></div>
        </div>
        <div class="season-lvl-xp"><?= $myXp ?> / <?= $nextLevelXp ?> XP</div>
    </div>
</div>

<!-- МАШИНА СЕЗОНА -->
<?php if ($car): ?>
<div class="season-car-block">
    <div class="season-car-title">
        🚗 Машина сезона
        <?php if ($canClaimCar): ?><span class="season-car-ready-badge">ГОТОВА</span><?php endif; ?>
    </div>
    <div class="season-car-preview">
        <?= renderCarImage($car['image'], $car['default_color'], 0) ?>
    </div>
    <div class="season-car-info">
        <div class="season-car-name"><?= e($car['brand'] . ' ' . $car['name']) ?></div>
        <div class="season-car-stats">
            <span class="hp"><?= (int)$car['hp'] ?> л.с.</span> |
            <span class="class"><?= e($car['class']) ?> класс</span>
        </div>
    </div>

    <?php if ($carClaimed || $carOwned): ?>
        <div class="season-car-claimed">✓ Машина уже в вашем гараже</div>
    <?php elseif ($canClaimCar): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="claim_car">
            <button type="submit" class="btn btn-block season-claim-btn">🎁 Забрать машину сезона</button>
        </form>
    <?php else: ?>
        <div class="season-unlock-banner season-unlock-locked">
            🔒 Откроется на <b><?= $unlockLevel ?></b> уровне сезона
        </div>
        <div class="season-unlock-progress">
            <?php $unlockPercent = min(100, round($myLevel / $unlockLevel * 100)); ?>
            <div class="level-progress" style="margin-top:6px;">
                <div class="level-progress-bar" style="width:<?= $unlockPercent ?>%;background:linear-gradient(90deg,#8e44ad,#a855f7);"></div>
            </div>
            <div style="margin-top:4px;"><?= $myLevel ?> / <?= $unlockLevel ?></div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ПРОГРЕСС НАГРАД (компактный) -->
<div class="season-rewards-block">
    <div class="season-rewards-head">
        <div class="season-rewards-title">💰 Награды</div>
        <div class="season-rewards-counter"><?= $lastRewardLevel ?>/<?= count($rewards) ?></div>
    </div>

    <?php if ($nextReward): ?>
        <div class="season-next-compact <?= $canClaimNext ? 'ready' : '' ?>">
            <div class="season-next-c-num"><?= (int)$nextReward['level'] ?></div>
            <div class="season-next-c-info">
                <div class="season-next-c-title">
                    <?= $canClaimNext ? '🎁 Доступна награда!' : '🎯 Следующая: уровень ' . (int)$nextReward['level'] ?>
                </div>
                <div class="season-next-c-usdt"><?= formatUSDT($nextReward['usdt']) ?> USDT<?= !empty($nextReward['description']) ? ' · ' . e($nextReward['description']) : '' ?></div>
            </div>
            <?php if ($canClaimNext): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="claim_reward">
                    <button type="submit" class="season-next-c-btn">Забрать</button>
                </form>
            <?php else: ?>
                <?php $needXp = max(0, $prevLevelXp - $myXp); ?>
                <div class="season-next-c-hint">+<?= (int)$needXp ?> XP</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="season-next-compact done">
            <div class="season-next-c-num">🏆</div>
            <div class="season-next-c-info">
                <div class="season-next-c-title">Все награды забраны!</div>
            </div>
        </div>
    <?php endif; ?>

    <div class="season-rw-strip">
        <?php foreach ($rewards as $r):
            $lvl = (int)$r['level'];
            $claimed = ($lvl <= $lastRewardLevel);
            $available = !$claimed && ($lvl == $lastRewardLevel + 1) && ($myLevel >= $lvl);
            $unlocked = !$claimed && ($lvl <= $myLevel);
            $cls = 'locked';
            if ($claimed) $cls = 'done';
            elseif ($available) $cls = 'ready';
            elseif ($unlocked) $cls = 'pending';
        ?>
            <div class="season-rw-cell <?= $cls ?>" title="Уровень <?= $lvl ?> — <?= formatUSDT($r['usdt']) ?> USDT">
                <div class="season-rw-cell-lvl"><?= $lvl ?></div>
                <div class="season-rw-cell-usdt"><?= formatUSDT($r['usdt']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ЗАДАНИЯ -->
<div class="season-section-title">📋 Задания (<?= count($userTasks) ?>/<?= count($tasks) ?>)</div>

<div class="season-tasks">
    <?php if (empty($tasks)): ?>
        <div class="season-empty">Заданий пока нет</div>
    <?php else: foreach ($tasks as $task):
        $taskId = (int)$task['id'];
        $isCompleted = isset($userTasks[$taskId]);
        $isClaimed = $isCompleted && ($userTasks[$taskId] === 1);
        $canClaim = $isCompleted && !$isClaimed;

        $progress = $isCompleted ? (int)$task['target'] : getSeasonProgress($pdo, $user['id'], $season, $task['type']);
        $target = (int)$task['target'];
        $pct = $target > 0 ? min(100, round($progress / $target * 100)) : 0;
    ?>
        <div class="season-task <?= $isClaimed ? 'done' : '' ?> <?= $canClaim ? 'claimable' : '' ?>">
            <div class="season-task-head">
                <div class="season-task-title">
                    <?php if ($isClaimed): ?>✅
                    <?php elseif ($canClaim): ?>🎁
                    <?php else: ?>⚪<?php endif; ?>
                    <?= e($task['title']) ?>
                </div>
                <div class="season-task-rewards">
                    <?php if ((int)$task['xp_reward'] > 0): ?>
                        <span class="season-task-xp">+<?= (int)$task['xp_reward'] ?> XP</span>
                    <?php endif; ?>
                    <?php if ((float)($task['usdt_reward'] ?? 0) > 0): ?>
                        <span class="season-task-usdt">+<?= formatUSDT($task['usdt_reward']) ?> 💵</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($task['description'])): ?>
                <div class="season-task-desc"><?= e($task['description']) ?></div>
            <?php endif; ?>
            <div class="season-task-progress">
                <div class="season-task-bar">
                    <div class="season-task-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="season-task-count"><?= (int)min($progress, $target) ?> / <?= $target ?></div>
            </div>
            <?php if ($canClaim): ?>
                <form method="POST" style="margin-top:8px;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="claim_task">
                    <input type="hidden" name="task_id" value="<?= $taskId ?>">
                    <button type="submit" class="season-task-claim">🎁 Забрать награду</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="content" style="padding:15px;">
    <a href="district.php" class="btn btn-block">← В район</a>
</div>

<?php include 'footer.php'; ?>