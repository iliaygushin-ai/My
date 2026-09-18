<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
autoStartTournaments($pdo);

$tab = $_GET['tab'] ?? 'tours';
if (!in_array($tab, ['tours', 'finished'])) $tab = 'tours';

$error = '';
$success = '';

// ==========================================
// БЫСТРАЯ РЕГИСТРАЦИЯ
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'quick_join') {
            $tourId = (int)($_POST['tournament_id'] ?? 0);
            $tour = getTournamentById($pdo, $tourId);
            if (!$tour) {
                $error = 'Турнир не найден.';
            } else {
                $check = canJoinTournamentFull($pdo, $tour, $user);
                if (!$check['ok']) {
                    $error = $check['error'];
                } else {
                    $car = $check['car'];
                    $pdo->beginTransaction();
                    try {
                        if ((float)$tour['entry_fee'] > 0) {
                            $pdo->prepare("UPDATE users SET usdt = usdt - ? WHERE id = ?")
                                ->execute([$tour['entry_fee'], $user['id']]);
                        }
                        $pdo->prepare("
                            INSERT INTO tournament_participants (tournament_id, user_id, car_id, car_power)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$tour['id'], $user['id'], $car['id'], getCarPower($car)]);
                        $pdo->commit();
                        $success = '✅ Вы участвуете в «' . e($tour['name']) . '»!';
                        $GLOBALS['__auth_user'] = null;
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                        $stmt->execute([$user['id']]);
                        $user = $stmt->fetch();
                    } catch (Exception $ex) {
                        $pdo->rollBack();
                        $error = 'Ошибка регистрации.';
                    }
                }
            }
        }
    }
}

// ==========================================
// ДАННЫЕ
// ==========================================
if ($tab === 'finished') {
    $tournaments = getTournamentsByStatus($pdo, 'finished', $user, 30);
    $cntTours = 0;
    $cntFinished = countTournamentsByStatus($pdo, 'finished', $user);
} else {
    $active   = getTournamentsByStatus($pdo, 'active', $user, 30);
    $upcoming = getTournamentsByStatus($pdo, 'upcoming', $user, 30);
    $tournaments = array_merge($active, $upcoming);
    $cntTours = count($tournaments);
    $cntFinished = countTournamentsByStatus($pdo, 'finished', $user);
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Турниры';
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
    <a href="bar.php">Бар</a>
    <a href="tournaments.php" class="active">Турниры</a>
</div>

<div class="section-header">
    <h2>🏆 Турниры</h2>
    <a href="district.php" class="btn-add" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;font-size:14px;">←</a>
</div>

<div class="tour-tabs">
    <a href="?tab=tours" class="tour-tab <?= $tab === 'tours' ? 'active' : '' ?>">
        <span class="tour-tab-icon">🏆</span>
        <span class="tour-tab-text">Турниры</span>
        <?php if ($cntTours > 0): ?><span class="tour-tab-badge"><?= $cntTours ?></span><?php endif; ?>
    </a>
    <a href="?tab=finished" class="tour-tab <?= $tab === 'finished' ? 'active' : '' ?>">
        <span class="tour-tab-icon">🏁</span>
        <span class="tour-tab-text">Завершённые</span>
        <?php if ($cntFinished > 0): ?><span class="tour-tab-badge" style="background:#8b949e;"><?= $cntFinished ?></span><?php endif; ?>
    </a>
</div>

<?php if ($error): ?>
    <div style="padding:10px 12px;"><div class="message error"><?= e($error) ?></div></div>
<?php endif; ?>
<?php if ($success): ?>
    <div style="padding:10px 12px;"><div class="message success"><?= $success ?></div></div>
<?php endif; ?>

<div class="tour-list">
    <?php if (empty($tournaments)): ?>
        <div class="tour-empty">
            <div style="font-size:36px;margin-bottom:8px;opacity:.5;"><?= $tab === 'finished' ? '🏁' : '🏆' ?></div>
            <div><?= $tab === 'finished' ? 'Нет завершённых турниров' : 'Турниров пока нет' ?></div>
            <?php if ($tab === 'finished'): ?>
                <small>Показываются за сегодня и вчера</small>
            <?php else: ?>
                <small>Скрыты турниры, не подходящие по уровню</small>
            <?php endif; ?>
        </div>
    <?php else: foreach ($tournaments as $t):
        $starts = strtotime($t['start_at']);
        $ends   = !empty($t['end_at']) ? strtotime($t['end_at']) : null;
        $now    = time();
        $diff   = $starts - $now;
        $isSoon = ($t['status'] === 'upcoming' && $diff > 0 && $diff < 3600);
        $isLive = ($t['status'] === 'active');
        $isFinished = ($t['status'] === 'finished');
        $iAmIn  = isUserInTournament($pdo, $t['id'], $user['id']);
        $tType  = $t['tournament_type'] ?? 'all';

        $onTourCar = true;
        if ($iAmIn && $isLive) {
            $onTourCar = isPlayerOnTournamentCar($pdo, $t['id'], $user['id']);
        }

        $conditions = [];
        if ($tType === 'levels')  $conditions[] = '⭐ Ур. ' . (int)$t['min_level'] . '–' . (int)$t['max_level'];
        if ($tType === 'classes') {
            $ac = [];
            if (!empty($t['allowed_classes'])) { $d = json_decode($t['allowed_classes'], true); if (is_array($d)) $ac = $d; }
            $conditions[] = '🏷 ' . (empty($ac) ? 'любые классы' : implode(', ', $ac));
        }
        if ($tType === 'brands') {
            $ab = [];
            if (!empty($t['allowed_brands'])) { $d = json_decode($t['allowed_brands'], true); if (is_array($d)) $ab = $d; }
            $conditions[] = '🚗 ' . (empty($ab) ? 'любые марки' : implode(', ', $ab));
        }
        if ($tType === 'all') $conditions[] = '🌐 открытый';

        $canJoin = false; $joinError = ''; $joinErrorType = '';
        if (!$isFinished && !$iAmIn) {
            $check = canJoinTournamentFull($pdo, $t, $user);
            $canJoin = $check['ok'];
            $joinError = $check['error'] ?? '';
            $joinErrorType = $check['error_type'] ?? '';
        }

        $winner = null;
        if ($isFinished) {
            $st = $pdo->prepare("SELECT tp.wins_count, tp.rides_count, u.username FROM tournament_participants tp JOIN users u ON u.id = tp.user_id WHERE tp.tournament_id = ? AND tp.place = 1 LIMIT 1");
            $st->execute([$t['id']]);
            $winner = $st->fetch();
        }
    ?>
        <div class="tour-card2 <?= $isLive ? 'tour-card2-live' : '' ?> <?= $isFinished ? 'tour-card2-finished' : '' ?>">
            <div class="tour-card2-head">
                <div class="tour-card2-title">
                    <?php if ($isLive): ?><span class="tour-live-dot"></span><?php endif; ?>
                    <?= e($t['name']) ?>
                </div>
                <div class="tour-card-status status-<?= e($t['status']) ?>">
                    <?php if ($t['status'] === 'upcoming'): ?><?= $isSoon ? 'СКОРО' : 'АНОНС' ?>
                    <?php elseif ($t['status'] === 'active'): ?>LIVE
                    <?php else: ?>ФИНИШ<?php endif; ?>
                </div>
            </div>

            <div class="tour-card2-type">
                <span class="tour-type-badge tour-type-<?= e($tType) ?>"><?= e(getTournamentTypeShort($t)) ?></span>
                <?php if ($iAmIn && !$isFinished): ?>
                    <span class="tour-my-badge">✓ участвую</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($conditions)): ?>
                <div class="tour-card2-conditions">
                    <?php foreach ($conditions as $c): ?>
                        <span class="tour-tag-mini"><?= e($c) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="tour-card2-dates <?= $t['entry_fee'] <= 0 ? 'no-fee' : '' ?>">
                <div class="tour-date-item">
                    <span class="tour-date-icon">🕒</span>
                    <div>
                        <b><?= date('d.m H:i', $starts) ?></b>
                        <small>старт МСК</small>
                    </div>
                </div>
                <?php if ($ends): ?>
                    <div class="tour-date-item">
                        <span class="tour-date-icon">🏁</span>
                        <div>
                            <b><?= date('d.m H:i', $ends) ?></b>
                            <small>конец МСК</small>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($t['entry_fee'] > 0): ?>
                    <div class="tour-date-item">
                        <span class="tour-date-icon">💵</span>
                        <div>
                            <b style="color:#26a17b;"><?= formatUSDT($t['entry_fee']) ?></b>
                            <small>взнос</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isFinished && $winner): ?>
                <div class="tour-card2-winner">
                    <span>🏆</span>
                    <div>
                        <b><?= e($winner['username']) ?></b>
                        <small><?= (int)$winner['rides_count'] ?> заездов · <?= (int)$winner['wins_count'] ?> побед</small>
                    </div>
                </div>
            <?php elseif ($t['status'] === 'upcoming' && $diff > 0): ?>
                <div class="tour-card2-timer" data-start="<?= $starts ?>">
                    <span class="tour-timer-lbl">⏳ до старта</span>
                    <span class="tour-timer-val">
                        <?php
                        $d = floor($diff / 86400); $h = floor(($diff % 86400) / 3600); $m = floor(($diff % 3600) / 60); $s = $diff % 60;
                        if ($d > 0) echo $d . 'д ' . $h . 'ч ' . $m . 'м';
                        elseif ($h > 0) echo $h . 'ч ' . $m . 'м ' . $s . 'с';
                        elseif ($m > 0) echo $m . 'м ' . $s . 'с';
                        else echo $s . 'с';
                        ?>
                    </span>
                </div>
            <?php elseif ($isLive && $ends):
                $diffEnd = $ends - $now;
            ?>
                <div class="tour-card2-timer tour-card2-timer-live" data-start="<?= $ends ?>">
                    <span class="tour-timer-lbl">🔥 до конца</span>
                    <span class="tour-timer-val">
                        <?php
                        if ($diffEnd > 0) {
                            $d = floor($diffEnd / 86400); $h = floor(($diffEnd % 86400) / 3600); $m = floor(($diffEnd % 3600) / 60); $s = $diffEnd % 60;
                            if ($d > 0) echo $d . 'д ' . $h . 'ч ' . $m . 'м';
                            elseif ($h > 0) echo $h . 'ч ' . $m . 'м ' . $s . 'с';
                            elseif ($m > 0) echo $m . 'м ' . $s . 'с';
                            else echo $s . 'с';
                        } else echo '—';
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="tour-card2-prizes">
                <div class="tour-prize-mini prize-gold"><span>🥇</span><b><?= formatUSDT($t['prize_1']) ?></b></div>
                <div class="tour-prize-mini prize-silver"><span>🥈</span><b><?= formatUSDT($t['prize_2']) ?></b></div>
                <div class="tour-prize-mini prize-bronze"><span>🥉</span><b><?= formatUSDT($t['prize_3']) ?></b></div>
                <?php if ((int)$t['top_places'] > 3): ?>
                    <div class="tour-prize-mini prize-other"><span>+<?= (int)$t['top_places'] - 3 ?></span><b><?= formatUSDT($t['prize_other']) ?></b></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($t['description']) && !$isFinished): ?>
                <div class="tour-card2-desc"><?= e($t['description']) ?></div>
            <?php endif; ?>

            <div class="tour-card2-actions">
                <?php if ($isFinished): ?>
                    <a href="tournament.php?id=<?= (int)$t['id'] ?>" class="btn btn-block" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;">
                        👁 Смотреть итоги
                    </a>

                <?php elseif ($iAmIn && $isLive && $onTourCar): ?>
                    <a href="tournament.php?id=<?= (int)$t['id'] ?>" class="btn btn-block tour-card2-rides">
                        🏁 ЗАЕЗДЫ
                    </a>

                <?php elseif ($iAmIn && $isLive && !$onTourCar): ?>
                    <a href="cars.php" class="btn btn-block tour-card2-changecar">
                        🚗 Вернитесь на турнирную машину
                    </a>

                <?php elseif ($iAmIn): ?>
                    <div class="tour-card2-mystatus">✓ Вы участвуете</div>

                <?php elseif ($canJoin): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="quick_join">
                        <input type="hidden" name="tournament_id" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="btn btn-block tour-card2-join">
                            ⚔ УЧАСТВОВАТЬ<?= $t['entry_fee'] > 0 ? ' · ' . formatUSDT($t['entry_fee']) . ' USDT' : '' ?>
                        </button>
                    </form>

                <?php elseif ($joinErrorType === 'car'): ?>
                    <a href="cars.php" class="btn btn-block tour-card2-changecar">
                        🚗 Смените автомобиль
                    </a>

                <?php elseif ($joinError): ?>
                    <div class="tour-card2-locked">🚫 <?= e($joinError) ?></div>

                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="content" style="padding:15px;">
    <a href="district.php" class="btn btn-block">← В район</a>
</div>

<script>
function fmtTime(diff) {
    var d = Math.floor(diff / 86400);
    var h = Math.floor((diff % 86400) / 3600);
    var m = Math.floor((diff % 3600) / 60);
    var s = diff % 60;
    if (d > 0) return d + 'д ' + h + 'ч ' + m + 'м';
    if (h > 0) return h + 'ч ' + m + 'м ' + s + 'с';
    if (m > 0) return m + 'м ' + s + 'с';
    return s + 'с';
}

var reloaded = false;

setInterval(function() {
    document.querySelectorAll('.tour-card2-timer').forEach(function(el) {
        var start = parseInt(el.dataset.start) * 1000;
        var diff = Math.floor((start - Date.now()) / 1000);
        var val = el.querySelector('.tour-timer-val');
        if (!val) return;
        if (diff <= 0) {
            val.textContent = 'обновление...';
            if (!reloaded) {
                reloaded = true;
                setTimeout(function() { location.reload(); }, 3000);
            }
            return;
        }
        val.textContent = fmtTime(diff);
    });
}, 1000);
</script>

<?php include 'footer.php'; ?>