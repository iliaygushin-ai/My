<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
autoStartTournaments($pdo);

$tourId = (int)($_GET['id'] ?? 0);
$tour = getTournamentById($pdo, $tourId);
if (!$tour) { header('Location: tournaments.php'); exit; }

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'join') {
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
                    $pdo->prepare("INSERT INTO tournament_participants (tournament_id, user_id, car_id, car_power) VALUES (?, ?, ?, ?)")
                        ->execute([$tour['id'], $user['id'], $car['id'], getCarPower($car)]);
                    $pdo->commit();
                    $success = '✅ Вы зарегистрированы!';
                    $GLOBALS['__auth_user'] = null;
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$user['id']]);
                    $user = $stmt->fetch();
                } catch (Exception $ex) { $pdo->rollBack(); $error = 'Ошибка.'; }
            }
        }

        if ($action === 'leave') {
            if ($tour['status'] !== 'upcoming') {
                $error = 'Нельзя покинуть начавшийся турнир.';
            } else {
                $pdo->beginTransaction();
                try {
                    if ((float)$tour['entry_fee'] > 0) {
                        $pdo->prepare("UPDATE users SET usdt = usdt + ? WHERE id = ?")
                            ->execute([$tour['entry_fee'], $user['id']]);
                    }
                    $pdo->prepare("DELETE FROM tournament_participants WHERE tournament_id = ? AND user_id = ?")
                        ->execute([$tour['id'], $user['id']]);
                    $pdo->commit();
                    $success = '✅ Вы покинули турнир.';
                    $GLOBALS['__auth_user'] = null;
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$user['id']]);
                    $user = $stmt->fetch();
                } catch (Exception $ex) { $pdo->rollBack(); $error = 'Ошибка.'; }
            }
        }

        if ($action === 'ride') {
            $res = makeTournamentRide($pdo, $tour['id'], $user['id']);
            if (!$res['ok']) { $error = $res['error']; }
            else {
                if ($res['winner']) $success = '🏆 Победа!';
                $GLOBALS['__auth_user'] = null;
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$user['id']]);
                $user = $stmt->fetch();
            }
        }

        if ($action === 'spy') {
            $oppId = (int)($_POST['opponent_id'] ?? 0);
            $res = spyOpponentPower($pdo, $tour['id'], $user['id'], $oppId);
            if (!$res['ok']) {
                $error = $res['error'];
            } else {
                $_SESSION['spy_' . $tour['id'] . '_' . $oppId] = (int)$res['power'];
                $GLOBALS['__auth_user'] = null;
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$user['id']]);
                $user = $stmt->fetch();
            }
        }

        if ($action === 'restart') {
            $res = restartTournamentRun($pdo, $tour['id'], $user['id']);
            if (!$res['ok']) { $error = $res['error']; }
            else {
                $success = '🔄 Прогресс обнулён!';
                $GLOBALS['__auth_user'] = null;
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$user['id']]);
                $user = $stmt->fetch();
            }
        }
    }
    $tour = getTournamentById($pdo, $tourId);
}

$stmt = $pdo->prepare("SELECT * FROM tournament_participants WHERE tournament_id=? AND user_id=? LIMIT 1");
$stmt->execute([$tourId, $user['id']]);
$meIn = $stmt->fetch() ?: null;

$starts = strtotime($tour['start_at']);
$ends   = !empty($tour['end_at']) ? strtotime($tour['end_at']) : null;
$now    = time();

$isUpcoming = ($tour['status'] === 'upcoming');
$isActive   = ($tour['status'] === 'active');
$isFinished = ($tour['status'] === 'finished');

$canJoin = false; $joinError = '';
if (($isUpcoming || $isActive) && !$meIn) {
    $check = canJoinTournamentFull($pdo, $tour, $user);
    $canJoin = $check['ok'];
    $joinError = $check['error'] ?? '';
}

$opponent = null;
$spyPower = null;
if ($isActive && $meIn && $meIn['status'] === 'active') {
    $opponent = getRandomOpponent($pdo, $tourId, $user['id']);
    if ($opponent) {
        $key = 'spy_' . $tourId . '_' . $opponent['user_id'];
        $spyPower = $_SESSION[$key] ?? null;
    }
}

$mySpyLeft = $meIn ? max(0, 5 - (int)$meIn['spy_count']) : 5;

$standings = [];
$winner = null;
if ($isFinished) {
    $standings = getTournamentStandings($pdo, $tourId);
    foreach ($standings as $p) if ((int)$p['place'] === 1) { $winner = $p; break; }
}

$csrfToken = generateCsrfToken();
$pageTitle = $tour['name'];
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
    <h2>🏆 <?= e($tour['name']) ?></h2>
    <a href="tournaments.php" class="btn-add" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;font-size:14px;">←</a>
</div>

<?php if ($error): ?><div style="padding:10px 12px;"><div class="message error"><?= e($error) ?></div></div><?php endif; ?>
<?php if ($success): ?><div style="padding:10px 12px;"><div class="message success"><?= $success ?></div></div><?php endif; ?>

<?php if ($isActive): ?>
    <?php if ($ends): ?>
        <div class="tour-timer" data-start="<?= $ends ?>">
            <span class="tour-timer-label">до конца</span>
            <span class="tour-timer-time">
                <?php
                $diff = $ends - $now;
                if ($diff > 0) {
                    $d = floor($diff / 86400); $h = floor(($diff % 86400) / 3600); $m = floor(($diff % 3600) / 60); $s = $diff % 60;
                    if ($d > 0) echo $d . 'д ' . $h . 'ч ' . $m . 'м';
                    elseif ($h > 0) echo $h . 'ч ' . $m . 'м ' . $s . 'с';
                    elseif ($m > 0) echo $m . 'м ' . $s . 'с';
                    else echo $s . 'с';
                } else echo '—';
                ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if (!$meIn): ?>
        <div class="tour-join-block">
            <?php if ($canJoin): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="join">
                    <button type="submit" class="btn btn-block tour-join-btn">
                        ⚔ ПРИСОЕДИНИТЬСЯ<?= $tour['entry_fee'] > 0 ? ' · ' . formatUSDT($tour['entry_fee']) . ' USDT' : '' ?>
                    </button>
                </form>
                <div class="tour-note">⚠ Машина закрепляется до конца турнира.</div>
            <?php else: ?>
                <div class="tour-join-error">🚫 <?= e($joinError) ?></div>
            <?php endif; ?>
        </div>
    <?php elseif ($meIn['status'] === 'eliminated'): ?>
        <div class="tour-eliminated">
            <div class="tour-eliminated-icon">💨</div>
            <div class="tour-eliminated-title">Вы вылетели</div>
            <div class="tour-eliminated-sub">Заездов сделано: <b><?= (int)$meIn['rides_count'] ?></b></div>
            <div class="tour-eliminated-hint">Заезды сохранены в рейтинге. Чтобы продолжить — начните заново.</div>
        </div>
        <div class="tour-restart-block">
            <form method="POST" onsubmit="return confirm('Начать заново за <?= TOUR_RESTART_COST ?> USDT?');">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="restart">
                <button class="tour-restart-btn tour-restart-btn-active">🔄 Начать заново · <?= TOUR_RESTART_COST ?> USDT</button>
            </form>
        </div>
    <?php elseif (!$opponent): ?>
        <div class="tour-waiting">Ожидание других игроков...</div>
    <?php else: ?>
        <div class="tour-wins-line">
            <span>Заездов: <b><?= (int)$meIn['rides_count'] ?></b></span>
            <span class="tour-wins-sep">·</span>
            <span>Побед: <b style="color:#3fb950;"><?= (int)$meIn['wins_count'] ?></b></span>
        </div>

        <div class="tour-opp-card">
            <div class="tour-opp-car"><?= renderCarImage($opponent['image'], $opponent['color'] ?: '#c0392b', 0) ?></div>
            <div class="tour-opp-info">
                <div class="tour-opp-vs">Соперник</div>
                <div class="tour-opp-name"><?= e($opponent['username']) ?></div>
                <div class="tour-opp-meta">Ур. <?= (int)$opponent['level'] ?> · <?= e($opponent['car_brand'] . ' ' . $opponent['car_name']) ?></div>
                <div class="tour-opp-power">
                    <?php if ($spyPower !== null): ?>
                        ⚡ <b><?= (int)$spyPower ?> л.с.</b>
                    <?php else: ?>
                        ⚡ ??? л.с.
                    <?php endif; ?>
                </div>

                <?php if ($mySpyLeft > 0 && $user['usdt'] >= 10): ?>
                    <form method="POST" class="tour-spy-form" onsubmit="return confirm('Спай стоит 10 USDT?');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="spy">
                        <input type="hidden" name="opponent_id" value="<?= (int)$opponent['user_id'] ?>">
                        <button class="tour-spy-btn">🕵 Спай · 10 USDT (<?= (int)$mySpyLeft ?>/5)</button>
                    </form>
                <?php elseif ($mySpyLeft <= 0): ?>
                    <div class="tour-spy-empty">Спаи исчерпаны</div>
                <?php else: ?>
                    <div class="tour-spy-empty">Мало USDT для спая</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tour-ride-block">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="ride">
                <button type="submit" class="btn btn-block tour-ride-btn">🏁 ЗАЕЗД</button>
            </form>
            <div class="tour-ride-hint">Победа = ваши л.с. ≥ соперника. <b style="color:#f85149;">Поражение = вылет.</b></div>
        </div>
    <?php endif; ?>

<?php elseif ($isUpcoming): ?>
    <div class="tour-detail-head">
        <div class="tour-detail-status status-upcoming">📢 АНОНС</div>
        <div class="tour-detail-type"><?= e(getTournamentTypeLabel($tour)) ?></div>
        <?php if (!empty($tour['description'])): ?>
            <div class="tour-detail-desc"><?= nl2br(e($tour['description'])) ?></div>
        <?php endif; ?>
    </div>

    <div class="tour-info-line">
        <span>🕒 <?= date('d.m H:i', $starts) ?><?= $ends ? ' — ' . date('d.m H:i', $ends) : '' ?> МСК</span>
        <span>💵 <?= $tour['entry_fee'] > 0 ? formatUSDT($tour['entry_fee']) : 'FREE' ?></span>
    </div>

    <?php if ($starts > $now): ?>
        <div class="tour-countdown-mini tour-countdown-mini-soon" data-start="<?= $starts ?>">
            <span class="tour-countdown-label">до старта</span>
            <span class="tour-countdown-time">
                <?php
                $diff = $starts - $now;
                $d = floor($diff / 86400); $h = floor(($diff % 86400) / 3600); $m = floor(($diff % 3600) / 60); $s = $diff % 60;
                if ($d > 0) echo $d . 'д ' . $h . 'ч ' . $m . 'м';
                elseif ($h > 0) echo $h . 'ч ' . $m . 'м ' . $s . 'с';
                elseif ($m > 0) echo $m . 'м ' . $s . 'с';
                else echo $s . 'с';
                ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="tour-prizes-line">
        <div class="tour-prize-cell prize-gold"><span>🥇</span><b><?= formatUSDT($tour['prize_1']) ?></b></div>
        <div class="tour-prize-cell prize-silver"><span>🥈</span><b><?= formatUSDT($tour['prize_2']) ?></b></div>
        <div class="tour-prize-cell prize-bronze"><span>🥉</span><b><?= formatUSDT($tour['prize_3']) ?></b></div>
        <div class="tour-prize-cell prize-other"><span>🎁</span><b><?= formatUSDT($tour['prize_other']) ?></b><small>топ <?= (int)$tour['top_places'] ?></small></div>
    </div>

    <?php
    $conditions = [];
    $tType = $tour['tournament_type'] ?? 'all';
    if ($tType === 'levels') $conditions[] = '⭐ Ур. ' . (int)$tour['min_level'] . '–' . (int)$tour['max_level'];
    if ($tType === 'classes') {
        $allowed = [];
        if (!empty($tour['allowed_classes'])) { $d = json_decode($tour['allowed_classes'], true); if (is_array($d)) $allowed = $d; }
        $conditions[] = '🏷 ' . (empty($allowed) ? 'любые классы' : implode(', ', $allowed));
    }
    if ($tType === 'brands') {
        $allowed = [];
        if (!empty($tour['allowed_brands'])) { $d = json_decode($tour['allowed_brands'], true); if (is_array($d)) $allowed = $d; }
        $conditions[] = '🚗 ' . (empty($allowed) ? 'любые марки' : implode(', ', $allowed));
    }
    if ($tType === 'all') $conditions[] = '🌐 открытый';
    if ($tour['min_hp'] > 0) $conditions[] = '⚡ от ' . (int)$tour['min_hp'] . ' л.с.';
    if ($tour['max_hp'] > 0) $conditions[] = '⚡ до ' . (int)$tour['max_hp'] . ' л.с.';
    ?>
    <div class="tour-tags">
        <?php foreach ($conditions as $c): ?>
            <span class="tour-tag"><?= e($c) ?></span>
        <?php endforeach; ?>
    </div>

    <?php if (!$meIn): ?>
        <div class="tour-join-block">
            <?php if ($canJoin): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="join">
                    <button type="submit" class="btn btn-block tour-join-btn">
                        ⚔ УЧАСТВОВАТЬ<?= $tour['entry_fee'] > 0 ? ' · ' . formatUSDT($tour['entry_fee']) . ' USDT' : '' ?>
                    </button>
                </form>
                <div class="tour-note">⚠ Машина закрепляется до конца турнира.</div>
            <?php else: ?>
                <div class="tour-join-error">🚫 <?= e($joinError) ?></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tour-my-line tour-my-line-green">
            <div style="flex:1;text-align:left;">
                <b style="color:#3fb950;">✓ Вы участвуете</b>
                <small style="display:block;color:#8b949e;font-size:10px;margin-top:2px;">Старт <?= date('H:i', $starts) ?> МСК</small>
            </div>
            <form method="POST" onsubmit="return confirm('Покинуть турнир?');">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="leave">
                <button type="submit" class="tour-leave-btn">Выйти</button>
            </form>
        </div>
    <?php endif; ?>

<?php elseif ($isFinished): ?>
    <?php if ($winner): ?>
        <div class="tour-winner-banner">
            <div class="tour-winner-icon">🏆</div>
            <div class="tour-winner-name"><?= e($winner['username']) ?></div>
            <div class="tour-winner-power"><?= (int)$winner['rides_count'] ?> заездов · <?= (int)$winner['wins_count'] ?> побед</div>
        </div>
    <?php endif; ?>

    <div class="tour-participants-block">
        <div class="tour-participants-title">Итоги</div>
        <?php if (empty($standings)): ?>
            <div class="tour-participants-empty">Нет участников</div>
        <?php else: ?>
            <div class="tour-standings">
                <div class="tour-standings-head has-prize">
                    <div class="stand-cell stand-place">#</div>
                    <div class="stand-cell stand-name">Игрок</div>
                    <div class="stand-cell stand-num">Заезды</div>
                    <div class="stand-cell stand-prize">USDT</div>
                </div>
                <?php foreach ($standings as $i => $p):
                    $place = (int)($p['place'] ?? 0);
                    $displayPlace = $place > 0 ? $place : ($i + 1);
                    $isMe = ((int)$p['user_id'] === (int)$user['id']);
                    $inTop = ($displayPlace <= (int)$tour['top_places']);
                ?>
                    <div class="tour-stand-row <?= ($isMe ? 'stand-me' : '') . ($inTop ? ' stand-top' : '') ?> has-prize">
                        <div class="stand-cell stand-place">
                            <?php if ($displayPlace === 1): ?>🥇
                            <?php elseif ($displayPlace === 2): ?>🥈
                            <?php elseif ($displayPlace === 3): ?>🥉
                            <?php else: ?><?= $displayPlace ?><?php endif; ?>
                        </div>
                        <div class="stand-cell stand-name">
                            <?= e($p['username']) ?><?= $isMe ? ' <small style="color:#58a6ff;">вы</small>' : '' ?>
                        </div>
                        <div class="stand-cell stand-num stand-rides"><?= (int)$p['rides_count'] ?></div>
                        <div class="stand-cell stand-prize">
                            <?= (float)$p['prize_won'] > 0 ? '+' . formatUSDT($p['prize_won']) : '—' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="content" style="padding:15px;">
    <a href="tournaments.php" class="btn btn-block">← К турнирам</a>
</div>

<script>
function fmtTourTime(diff) {
    var d = Math.floor(diff / 86400);
    var h = Math.floor((diff % 86400) / 3600);
    var m = Math.floor((diff % 3600) / 60);
    var s = diff % 60;
    if (d > 0) return d + 'д ' + h + 'ч ' + m + 'м';
    if (h > 0) return h + 'ч ' + m + 'м ' + s + 'с';
    if (m > 0) return m + 'м ' + s + 'с';
    return s + 'с';
}

var tourReloaded = false;

setInterval(function() {
    var el = document.querySelector('.tour-timer') || document.querySelector('.tour-countdown-mini');
    if (!el) return;
    var start = parseInt(el.dataset.start) * 1000;
    var diff = Math.floor((start - Date.now()) / 1000);
    var t = el.querySelector('.tour-timer-time') || el.querySelector('.tour-countdown-time');
    if (diff <= 0) {
        if (t) t.textContent = 'обновление...';
        if (!tourReloaded) {
            tourReloaded = true;
            setTimeout(function() { location.reload(); }, 3000);
        }
        return;
    }
    if (t) t.textContent = fmtTourTime(diff);
}, 1000);
</script>

<?php include 'footer.php'; ?>