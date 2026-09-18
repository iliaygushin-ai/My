<?php
if (!defined('DB_HOST')) {
    http_response_code(403);
    die('Access denied');
}

date_default_timezone_set('Europe/Moscow');

// ==========================================
// КЭШ
// ==========================================
$GLOBALS['__cache_levels']     = [];
$GLOBALS['__cache_cars']       = null;
$GLOBALS['__cache_cars_by_id'] = [];
$GLOBALS['__cache_user_cars']  = [];
$GLOBALS['__auth_user']        = null;
$GLOBALS['__season_badge']     = null;

// ==========================================
// СЕССИИ
// ==========================================
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function getFingerprint() {
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . SESSION_SALT);
}

// ==========================================
// АВТОРИЗАЦИЯ
// ==========================================
function requireAuth($pdo) {
    if (!empty($GLOBALS['__auth_user'])) return $GLOBALS['__auth_user'];

    startSecureSession();

    if (empty($_SESSION['user_id']) || empty($_SESSION['fingerprint'])) {
        header('Location: auth.php'); exit;
    }
    if (!hash_equals($_SESSION['fingerprint'], getFingerprint())) {
        session_unset(); session_destroy();
        header('Location: auth.php?error=session'); exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset(); session_destroy();
        header('Location: auth.php?error=timeout'); exit;
    }
    $_SESSION['last_activity'] = time();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !empty($user['is_banned'])) {
        session_unset(); session_destroy();
        header('Location: ' . (!$user ? 'auth.php' : 'banned.php'));
        exit;
    }

    if (!isset($_SESSION['regenerated']) || (time() - $_SESSION['regenerated']) > 300) {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = time();
    }

    $GLOBALS['__auth_user'] = $user;
    return $user;
}

// ==========================================
// ХЕЛПЕРЫ
// ==========================================
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatUSDT($n) { return number_format((float)$n, 0, '.', ' '); }

// ==========================================
// КЭШ
// ==========================================
function cacheGetLevels($pdo) {
    if (!empty($GLOBALS['__cache_levels'])) return $GLOBALS['__cache_levels'];
    $rows = $pdo->query("SELECT * FROM levels ORDER BY level ASC")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[(int)$r['level']] = $r;
    $GLOBALS['__cache_levels'] = $out;
    return $out;
}

function cacheGetAllCars($pdo) {
    if ($GLOBALS['__cache_cars'] !== null) return $GLOBALS['__cache_cars'];
    $rows = $pdo->query("SELECT * FROM cars ORDER BY min_level ASC, price ASC")->fetchAll();
    $GLOBALS['__cache_cars'] = $rows;
    $byId = [];
    foreach ($rows as $r) $byId[$r['id']] = $r;
    $GLOBALS['__cache_cars_by_id'] = $byId;
    return $rows;
}

// ==========================================
// МАШИНЫ
// ==========================================
function getCarById($pdo, $carId) {
    if (empty($carId)) return null;
    if (empty($GLOBALS['__cache_cars_by_id'])) cacheGetAllCars($pdo);
    $car = $GLOBALS['__cache_cars_by_id'][$carId] ?? null;
    return ($car && $car['is_active']) ? $car : null;
}

function getStarterCars($pdo) {
    $all = cacheGetAllCars($pdo);
    return array_values(array_filter($all, fn($c) => !empty($c['is_starter']) && !empty($c['is_active'])));
}

function getSalonCars($pdo, $userId, $userLevel) {
    $nextLevel = (int)$userLevel + 1;
    $all = cacheGetAllCars($pdo);
    $filtered = array_values(array_filter($all, fn($c) =>
        !empty($c['is_active']) && empty($c['is_starter']) && (int)$c['min_level'] <= $nextLevel
    ));
    if (empty($filtered)) return [];

    $ids = array_column($filtered, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT car_id FROM user_cars WHERE user_id = ? AND car_id IN ($ph)");
    $stmt->execute(array_merge([$userId], $ids));
    $owned = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($filtered as &$c) $c['owned'] = isset($owned[$c['id']]) ? 1 : 0;
    return $filtered;
}

function getUserCars($pdo, $userId) {
    $key = (int)$userId;
    if (!empty($GLOBALS['__cache_user_cars'][$key])) return $GLOBALS['__cache_user_cars'][$key];

    $stmt = $pdo->prepare("
        SELECT uc.id AS uc_id, uc.color, uc.stance,
               c.id AS car_id, c.name, c.brand, c.description,
               c.hp, c.class, c.image, c.price, c.min_level, c.default_color
        FROM user_cars uc
        JOIN cars c ON c.id = uc.car_id
        WHERE uc.user_id = ?
        ORDER BY uc.id ASC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $GLOBALS['__cache_user_cars'][$key] = $rows;
    return $rows;
}

function userHasCar($pdo, $userId, $carId) {
    $stmt = $pdo->prepare("SELECT id FROM user_cars WHERE user_id = ? AND car_id = ? LIMIT 1");
    $stmt->execute([$userId, $carId]);
    return (bool)$stmt->fetch();
}

function renderCarImage($image, $color = '#c0392b', $stanceOffset = 0) {
    if (empty($image)) return '<div class="car-no-image">Нет изображения</div>';
    $image = basename($image);
    $offset = (int)$stanceOffset;
    $carDir = __DIR__ . '/car/';
    $src = 'car/' . $image;

    if (!file_exists($carDir . $image)) {
        $found = false;
        if (is_dir($carDir)) {
            foreach (scandir($carDir) as $f) {
                if (strcasecmp($f, $image) === 0) { $image = $f; $src = 'car/' . $f; $found = true; break; }
            }
        }
        if (!$found) return '<div class="car-no-image">Файл ' . e($image) . ' не найден</div>';
    }
    return '<img src="' . e($src) . '" alt="Car" class="car-img" style="transform: translateY(' . $offset . 'px);">';
}

function getCarPower($car) {
    return (int)($car['hp'] ?? 0) + (int)floor(((int)($car['stance'] ?? 0)) / 4);
}

// ==========================================
// УРОВНИ
// ==========================================
function getLevelData($pdo, $level) {
    $levels = cacheGetLevels($pdo);
    return $levels[(int)$level] ?? null;
}

function getMaxLevel($pdo) {
    $levels = cacheGetLevels($pdo);
    return empty($levels) ? 1 : max(array_keys($levels));
}

function addExp($pdo, $userId, $expAmount) {
    $stmt = $pdo->prepare("SELECT id, level, exp, usdt FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return ['level_up'=>false, 'new_level'=>0, 'reward'=>0];

    $level = (int)$u['level'];
    $exp = (int)$u['exp'] + (int)$expAmount;
    $totalReward = 0;
    $leveledUp = false;
    $maxLevel = getMaxLevel($pdo);
    $levels = cacheGetLevels($pdo);

    while ($level < $maxLevel) {
        $next = $levels[$level + 1] ?? null;
        if (!$next) break;
        if ($exp >= (int)$next['exp_required']) {
            $exp -= (int)$next['exp_required'];
            $level++;
            $totalReward += (float)$next['reward_usdt'];
            $leveledUp = true;
        } else break;
    }
    if ($level >= $maxLevel) $exp = 0;

    $pdo->prepare("UPDATE users SET level=?, exp=?, usdt=usdt+? WHERE id=?")
        ->execute([$level, $exp, $totalReward, $userId]);

    return ['level_up'=>$leveledUp, 'new_level'=>$level, 'reward'=>$totalReward];
}

function getLevelProgress($pdo, $user) {
    $level = (int)$user['level'];
    $exp = (int)$user['exp'];
    $next = getLevelData($pdo, $level + 1);
    if (!$next) return ['percent'=>100, 'current'=>$exp, 'required'=>0, 'level_max'=>true];
    $req = (int)$next['exp_required'];
    return [
        'percent'  => $req > 0 ? min(100, round($exp / $req * 100)) : 0,
        'current'  => $exp,
        'required' => $req,
        'level_max'=> false,
    ];
}

// ==========================================
// РОЛИ И ПРАВА
// ==========================================
define('ROLE_PLAYER', 0);
define('ROLE_ADMIN', 1);
define('ROLE_MODERATOR', 2);
define('SUPER_ADMIN_ID', 1);

function isSuperAdmin($user) { return (int)($user['id'] ?? 0) === SUPER_ADMIN_ID; }
function isAdmin($user)  { $r = (int)($user['role'] ?? 0); return ($r > 0 && $r <= ROLE_ADMIN) || isSuperAdmin($user); }
function isStaff($user)  { $r = (int)($user['role'] ?? 0); return ($r > 0 && $r <= ROLE_MODERATOR) || isSuperAdmin($user); }

function getRoleName($role) {
    switch ((int)$role) {
        case ROLE_ADMIN:     return '👑 Администратор';
        case ROLE_MODERATOR: return '🛡 Модератор';
        default:             return '👤 Игрок';
    }
}

function requireStaff($pdo, $minRole = ROLE_MODERATOR) {
    $user = requireAuth($pdo);
    $r = (int)($user['role'] ?? 0);
    if (!(($r > 0 && $r <= $minRole) || isSuperAdmin($user))) {
        http_response_code(403);
        die('<div style="color:#f85149;text-align:center;padding:50px;font-family:Arial;background:#0d1117;">🚫 Доступ запрещён</div>');
    }
    return $user;
}

function getAllPermissions() {
    return [
        'edit_users'     => '✏ Редактировать игроков',
        'give_cars'      => '🚗 Выдавать машины',
        'remove_cars'    => '🗑 Удалять машины',
        'ban_users'      => '🚫 Банить',
        'reset_password' => '🔐 Сбрасывать пароли',
        'quick_actions'  => '⚡ Быстрые действия',
        'manage_cars'    => '🛠 Управлять каталогом',
        'delete_users'   => '💀 Удалять игроков',
    ];
}

function hasPermission($user, $perm) {
    if (isSuperAdmin($user)) return true;
    $role = (int)($user['role'] ?? 0);
    if ($role === 0 || $role > ROLE_MODERATOR) return false;
    if (empty($user['permissions'])) return false;
    $d = json_decode($user['permissions'], true);
    return is_array($d) && !empty($d[$perm]);
}

function canManageRoles($user) { return isSuperAdmin($user); }

function encodePermissions($array) {
    if (empty($array)) return null;
    $f = [];
    foreach (array_keys(getAllPermissions()) as $p) if (!empty($array[$p])) $f[$p] = true;
    return empty($f) ? null : json_encode($f);
}

// ==========================================
// БАН
// ==========================================
function banUser($pdo, $userId, $reason = null) {
    $pdo->prepare("UPDATE users SET is_banned=1, ban_reason=?, banned_at=? WHERE id=?")
        ->execute([$reason, date('Y-m-d H:i:s'), $userId]);
}
function unbanUser($pdo, $userId) {
    $pdo->prepare("UPDATE users SET is_banned=0, ban_reason=NULL, banned_at=NULL WHERE id=?")
        ->execute([$userId]);
}

// ==========================================
// ГОНКИ PvP
// ==========================================
if (!defined('RACE_DAILY_LIMIT_PER_OPPONENT')) define('RACE_DAILY_LIMIT_PER_OPPONENT', 5);

function findOpponents($pdo, $myId, $limit = 3) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.level,
               uc.car_id, uc.color, uc.stance,
               c.name AS car_name, c.brand AS car_brand, c.hp, c.class, c.image
        FROM users u
        JOIN user_cars uc ON uc.user_id = u.id
        JOIN cars c ON c.id = uc.car_id
        WHERE u.id != ? AND u.car_id = uc.car_id AND u.is_banned = 0
        ORDER BY RAND() LIMIT ?
    ");
    $stmt->bindValue(1, $myId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function simulateRace($myPower, $myId, $oppPower, $oppId) {
    $myRoll  = round($myPower  * 1.10 * (1 + random_int(-250, 250) / 1000));
    $oppRoll = round($oppPower * (1 + random_int(-250, 250) / 1000));
    if ($myRoll === $oppRoll) $winnerId = random_int(0, 1) ? $myId : $oppId;
    else $winnerId = ($myRoll > $oppRoll) ? $myId : $oppId;
    $reward = max(30, round(($winnerId === $myId ? $myPower : $oppPower) * 0.15));
    return ['winner_id'=>$winnerId, 'reward'=>$reward, 'my_roll'=>$myRoll, 'opp_roll'=>$oppRoll];
}

function getRaceCountToday($pdo, $userId, $opponentId) {
    $stmt = $pdo->prepare("SELECT races_count FROM race_limits WHERE user_id=? AND opponent_id=? AND race_date=? LIMIT 1");
    $stmt->execute([$userId, $opponentId, date('Y-m-d')]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function incrementRaceCount($pdo, $userId, $opponentId) {
    $pdo->prepare("
        INSERT INTO race_limits (user_id, opponent_id, race_date, races_count)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE races_count = races_count + 1
    ")->execute([$userId, $opponentId, date('Y-m-d')]);
}

// ==========================================
// ЧАТ
// ==========================================
if (!defined('CHAT_MIN_LEVEL')) define('CHAT_MIN_LEVEL', 3);

function canUseChat($user) { return (int)$user['level'] >= CHAT_MIN_LEVEL || isStaff($user); }

function isChatBanned($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM chat_bans WHERE user_id=? AND (is_permanent=1 OR until_date>?) ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, date('Y-m-d H:i:s')]);
    return $stmt->fetch() ?: null;
}

function chatBanUser($pdo, $userId, $bannedBy, $reason, $duration) {
    $perm = ($duration === 0);
    $until = $perm ? null : date('Y-m-d H:i:s', time() + $duration);
    $pdo->prepare("INSERT INTO chat_bans (user_id, banned_by, reason, until_date, is_permanent) VALUES (?, ?, ?, ?, ?)")
        ->execute([$userId, $bannedBy, $reason, $until, $perm ? 1 : 0]);
}

function chatUnbanUser($pdo, $userId) {
    $pdo->prepare("DELETE FROM chat_bans WHERE user_id = ?")->execute([$userId]);
}

function getBanDurations() {
    return [
        900=>'15 минут', 1800=>'30 минут', 3600=>'1 час', 7200=>'2 часа', 10800=>'3 часа',
        14400=>'4 часа', 21600=>'6 часов', 86400=>'1 день', 259200=>'3 дня', 604800=>'7 дней',
        2592000=>'1 месяц', 7776000=>'3 месяца', 15552000=>'6 месяцев', 31536000=>'1 год', 0=>'Навсегда',
    ];
}

function getChatMessages($pdo, $limit = 50) {
    $stmt = $pdo->prepare("
        SELECT m.id, m.user_id, m.message, m.created_at, m.is_deleted,
               u.username, u.role, u.id AS author_id
        FROM chat_messages m
        JOIN users u ON u.id = m.user_id
        ORDER BY m.id DESC LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getChatNickColor($role) {
    $role = (int)$role;
    if ($role === ROLE_ADMIN) return '#f85149';
    if ($role === ROLE_MODERATOR) return '#d29922';
    return '#58a6ff';
}

// ==========================================
// ТУРНИРЫ
// ==========================================
if (!defined('TOUR_RESTART_COST')) define('TOUR_RESTART_COST', 50);

function getTournamentById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getTournamentStandings($pdo, $tourId) {
    $stmt = $pdo->prepare("
        SELECT tp.*, u.username, u.level
        FROM tournament_participants tp
        JOIN users u ON u.id = tp.user_id
        WHERE tp.tournament_id = ?
        ORDER BY 
            CASE WHEN tp.place IS NOT NULL AND tp.place > 0 THEN 0 ELSE 1 END ASC,
            CASE WHEN tp.place IS NOT NULL AND tp.place > 0 THEN tp.place END ASC,
            tp.rides_count DESC,
            tp.wins_count DESC,
            tp.joined_at ASC
    ");
    $stmt->execute([$tourId]);
    return $stmt->fetchAll();
}

function isUserInTournament($pdo, $tourId, $userId) {
    $stmt = $pdo->prepare("SELECT id FROM tournament_participants WHERE tournament_id=? AND user_id=? LIMIT 1");
    $stmt->execute([$tourId, $userId]);
    return (bool)$stmt->fetch();
}

function getTournamentRules() {
    return "Правила турнира:\n"
        . "• Каждый заезд = +1 к рейтингу.\n"
        . "• Победа = если твои л.с. ≥ л.с. соперника.\n"
        . "• Поражение = вылет из турнира.\n"
        . "• Место в топе — по количеству заездов.\n"
        . "• Спай соперника — 10 USDT (5 раз).\n"
        . "• Перезапуск — " . TOUR_RESTART_COST . " USDT.";
}

function getTournamentTypeLabel($tour) {
    switch ($tour['tournament_type'] ?? 'all') {
        case 'levels':  return '⭐ По уровням ' . (int)$tour['min_level'] . '–' . (int)$tour['max_level'];
        case 'classes': return '🏷 По классам';
        case 'brands':  return '🚗 По маркам';
        default:        return '🌐 Открытый';
    }
}

function getTournamentTypeShort($tour) {
    switch ($tour['tournament_type'] ?? 'all') {
        case 'levels':  return 'Ур. ' . (int)$tour['min_level'] . '–' . (int)$tour['max_level'];
        case 'classes': return 'Классы';
        case 'brands':  return 'Марки';
        default:        return 'Все';
    }
}

function isCarSuitableForTournament($tour, $car, $user) {
    $type = $tour['tournament_type'] ?? 'all';

    if ($type === 'levels') {
        $lvl = (int)$user['level'];
        if ($lvl < (int)$tour['min_level'] || $lvl > (int)$tour['max_level']) return false;
    }
    if ($type === 'classes') {
        $allowed = [];
        if (!empty($tour['allowed_classes'])) { $d = json_decode($tour['allowed_classes'], true); if (is_array($d)) $allowed = $d; }
        if (!empty($allowed) && !in_array($car['class'], $allowed)) return false;
    }
    if ($type === 'brands') {
        $allowed = [];
        if (!empty($tour['allowed_brands'])) { $d = json_decode($tour['allowed_brands'], true); if (is_array($d)) $allowed = $d; }
        if (!empty($allowed)) {
            $found = false;
            foreach ($allowed as $b) if (mb_strtolower($b) === mb_strtolower($car['brand'] ?? '')) { $found = true; break; }
            if (!$found) return false;
        }
    }
    if (!empty($tour['min_class']) && strcmp($car['class'], $tour['min_class']) < 0) return false;
    if (!empty($tour['max_class']) && strcmp($car['class'], $tour['max_class']) > 0) return false;
    $hp = (int)$car['hp'];
    if ((int)$tour['min_hp'] > 0 && $hp < (int)$tour['min_hp']) return false;
    if ((int)$tour['max_hp'] > 0 && $hp > (int)$tour['max_hp']) return false;
    if (!empty($tour['allowed_cars'])) {
        $d = json_decode($tour['allowed_cars'], true);
        if (is_array($d) && !empty($d) && !in_array($car['car_id'], $d)) return false;
    }
    return true;
}

function canJoinTournamentFull($pdo, $tour, $user) {
    if (!in_array($tour['status'], ['upcoming', 'active'])) {
        return ['ok'=>false, 'error'=>'Турнир закрыт.', 'error_type'=>'closed'];
    }
    if ($tour['status'] === 'upcoming' && strtotime($tour['start_at']) <= time()) {
        return ['ok'=>false, 'error'=>'Старт уже прошёл.', 'error_type'=>'started'];
    }
    if ($tour['status'] === 'active' && !empty($tour['end_at']) && time() >= strtotime($tour['end_at'])) {
        return ['ok'=>false, 'error'=>'Турнир окончен.', 'error_type'=>'ended'];
    }
    if ($user['usdt'] < (float)$tour['entry_fee']) {
        return ['ok'=>false, 'error'=>'Недостаточно USDT для взноса.', 'error_type'=>'usdt'];
    }
    if (isUserInTournament($pdo, $tour['id'], $user['id'])) {
        return ['ok'=>false, 'error'=>'Вы уже участвуете.', 'error_type'=>'joined'];
    }

    if (empty($user['car_id'])) {
        return ['ok'=>false, 'error'=>'У вас нет машины.', 'error_type'=>'car'];
    }
    $currentCar = getCarById($pdo, $user['car_id']);
    if (!$currentCar) {
        return ['ok'=>false, 'error'=>'У вас нет машины.', 'error_type'=>'car'];
    }

    if (!isCarSuitableForTournament($tour, $currentCar, $user)) {
        $type = $tour['tournament_type'] ?? 'all';
        $msg = 'Ваша текущая машина не подходит под условия.';
        $errType = 'car';

        if ($type === 'levels') {
            $msg = 'Ваш уровень (' . (int)$user['level'] . ') не подходит (' . (int)$tour['min_level'] . '–' . (int)$tour['max_level'] . ').';
            $errType = 'level';
        }
        if ($type === 'classes') {
            $msg = 'Нужен автомобиль другого класса.';
            $errType = 'car';
        }
        if ($type === 'brands') {
            $msg = 'Нужна машина другой марки.';
            $errType = 'car';
        }
        return ['ok'=>false, 'error'=>$msg, 'error_type'=>$errType];
    }

    return ['ok'=>true, 'error'=>'', 'error_type'=>'', 'car'=>$currentCar];
}

function isPlayerOnTournamentCar($pdo, $tourId, $userId) {
    $stmt = $pdo->prepare("
        SELECT tp.car_id AS tour_car, u.car_id AS user_car
        FROM tournament_participants tp
        JOIN users u ON u.id = tp.user_id
        WHERE tp.tournament_id = ? AND tp.user_id = ? LIMIT 1
    ");
    $stmt->execute([$tourId, $userId]);
    $row = $stmt->fetch();
    return $row && ($row['tour_car'] === $row['user_car']);
}

function getRandomOpponent($pdo, $tourId, $userId) {
    $stmt = $pdo->prepare("
        SELECT tp.user_id, tp.car_power, tp.car_id,
               u.username, u.level,
               c.name AS car_name, c.brand AS car_brand, c.image, c.class,
               uc.color, uc.stance
        FROM tournament_participants tp
        JOIN users u ON u.id = tp.user_id
        JOIN cars c ON c.id = tp.car_id
        LEFT JOIN user_cars uc ON uc.user_id = tp.user_id AND uc.car_id = tp.car_id
        WHERE tp.tournament_id = ? AND tp.user_id != ? AND tp.status = 'active'
        ORDER BY RAND() LIMIT 1
    ");
    $stmt->execute([$tourId, $userId]);
    return $stmt->fetch() ?: null;
}

function spyOpponentPower($pdo, $tourId, $userId, $opponentId) {
    if ($userId === $opponentId) return ['ok'=>false, 'error'=>'Нельзя спаить себя.'];
    $cost = 10;

    $stmt = $pdo->prepare("SELECT spy_count FROM tournament_participants WHERE tournament_id=? AND user_id=? LIMIT 1");
    $stmt->execute([$tourId, $userId]);
    $me = $stmt->fetch();
    if (!$me) return ['ok'=>false, 'error'=>'Вы не в турнире.'];
    if ((int)$me['spy_count'] >= 5) return ['ok'=>false, 'error'=>'Лимит 5 спаев исчерпан.'];

    $stmt = $pdo->prepare("SELECT usdt FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    if ((float)$stmt->fetchColumn() < $cost) return ['ok'=>false, 'error'=>'Недостаточно USDT.'];

    $stmt = $pdo->prepare("SELECT car_power FROM tournament_participants WHERE tournament_id=? AND user_id=? LIMIT 1");
    $stmt->execute([$tourId, $opponentId]);
    $opp = $stmt->fetch();
    if (!$opp) return ['ok'=>false, 'error'=>'Соперник не найден.'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET usdt = usdt - ? WHERE id = ?")->execute([$cost, $userId]);
        $pdo->prepare("UPDATE tournament_participants SET spy_count = spy_count + 1 WHERE tournament_id=? AND user_id=?")
            ->execute([$tourId, $userId]);
        $pdo->commit();
    } catch (Exception $ex) { $pdo->rollBack(); return ['ok'=>false, 'error'=>'Ошибка.']; }

    return ['ok'=>true, 'power'=>(int)$opp['car_power'], 'left'=>5 - ((int)$me['spy_count'] + 1)];
}

function makeTournamentRide($pdo, $tourId, $userId) {
    $tour = getTournamentById($pdo, $tourId);
    if (!$tour || $tour['status'] !== 'active') return ['ok'=>false, 'error'=>'Турнир не активен.'];
    if (!empty($tour['end_at']) && time() >= strtotime($tour['end_at'])) return ['ok'=>false, 'error'=>'Время истекло.'];

    $stmt = $pdo->prepare("SELECT * FROM tournament_participants WHERE tournament_id=? AND user_id=? LIMIT 1");
    $stmt->execute([$tourId, $userId]);
    $me = $stmt->fetch();
    if (!$me) return ['ok'=>false, 'error'=>'Вы не участвуете.'];
    if ($me['status'] === 'eliminated') return ['ok'=>false, 'error'=>'🚫 Вы вылетели. Рестарт — ' . TOUR_RESTART_COST . ' USDT.'];
    if (!isPlayerOnTournamentCar($pdo, $tourId, $userId)) return ['ok'=>false, 'error'=>'🚫 Вы сменили автомобиль.'];
    if (!empty($me['last_ride_at']) && (time() - strtotime($me['last_ride_at'])) < 3) return ['ok'=>false, 'error'=>'⏳ Подождите 3 сек.'];

    $opp = getRandomOpponent($pdo, $tourId, $userId);
    if (!$opp) return ['ok'=>false, 'error'=>'Нет активных соперников.'];

    $myPower = (int)$me['car_power'];
    $oppPower = (int)$opp['car_power'];
    $won = ($myPower >= $oppPower);

    $pdo->beginTransaction();
    try {
        $nowSql = date('Y-m-d H:i:s');
        if ($won) {
            $pdo->prepare("UPDATE tournament_participants SET rides_count=rides_count+1, wins_count=wins_count+1, last_ride_at=? WHERE tournament_id=? AND user_id=?")
                ->execute([$nowSql, $tourId, $userId]);
        } else {
            $pdo->prepare("UPDATE tournament_participants SET rides_count=rides_count+1, status='eliminated', last_ride_at=? WHERE tournament_id=? AND user_id=?")
                ->execute([$nowSql, $tourId, $userId]);
        }
        $pdo->commit();
    } catch (Exception $ex) { $pdo->rollBack(); return ['ok'=>false, 'error'=>'Ошибка.']; }

    return ['ok'=>true, 'winner'=>$won, 'my_power'=>$myPower, 'opp_power'=>$oppPower, 'opp_name'=>$opp['username']];
}

function restartTournamentRun($pdo, $tourId, $userId) {
    $tour = getTournamentById($pdo, $tourId);
    if (!$tour || $tour['status'] !== 'active') return ['ok'=>false, 'error'=>'Турнир не активен.'];

    $stmt = $pdo->prepare("SELECT * FROM tournament_participants WHERE tournament_id=? AND user_id=? LIMIT 1");
    $stmt->execute([$tourId, $userId]);
    $me = $stmt->fetch();
    if (!$me) return ['ok'=>false, 'error'=>'Вы не участвуете.'];

    $stmt = $pdo->prepare("SELECT usdt FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    if ((float)$stmt->fetchColumn() < TOUR_RESTART_COST) return ['ok'=>false, 'error'=>'Недостаточно USDT (' . TOUR_RESTART_COST . ').'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET usdt = usdt - ? WHERE id = ?")->execute([TOUR_RESTART_COST, $userId]);
        $pdo->prepare("UPDATE tournament_participants SET rides_count=0, wins_count=0, status='active', last_ride_at=NULL WHERE tournament_id=? AND user_id=?")
            ->execute([$tourId, $userId]);
        $pdo->commit();
    } catch (Exception $ex) { $pdo->rollBack(); return ['ok'=>false, 'error'=>'Ошибка.']; }

    return ['ok'=>true];
}

function getTournamentsByStatus($pdo, $status, $user = null, $limit = 30) {
    $where = "t.status = ?";
    $params = [$status];
    if ($status === 'finished') {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $where .= " AND DATE(t.finished_at) >= ?";
        $params[] = $yesterday;
    }

    $sql = "SELECT t.* FROM tournaments t WHERE $where
        ORDER BY 
            CASE WHEN t.status = 'upcoming' THEN t.start_at END ASC,
            CASE WHEN t.status = 'active' THEN t.start_at END DESC,
            CASE WHEN t.status = 'finished' THEN t.finished_at END DESC
        LIMIT ?";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $p) $stmt->bindValue($i+1, $p, PDO::PARAM_STR);
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if ($status === 'upcoming' && $user) {
        $lvl = (int)$user['level'];
        $rows = array_values(array_filter($rows, function($t) use ($lvl) {
            if (($t['tournament_type'] ?? 'all') === 'levels') {
                if ($lvl < (int)$t['min_level'] || $lvl > (int)$t['max_level']) return false;
            }
            return true;
        }));
    }
    return $rows;
}

function countTournamentsByStatus($pdo, $status, $user = null) {
    if ($status === 'finished') {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE status='finished' AND DATE(finished_at) >= ?");
        $stmt->execute([$yesterday]);
        return (int)$stmt->fetchColumn();
    }
    if ($status === 'upcoming' && $user) return count(getTournamentsByStatus($pdo, 'upcoming', $user, 1000));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE status = ?");
    $stmt->execute([$status]);
    return (int)$stmt->fetchColumn();
}

function forceStartTournament($pdo, $tourId) {
    $stmt = $pdo->prepare("UPDATE tournaments SET status='active' WHERE id=? AND status='upcoming'");
    $stmt->execute([$tourId]);
    return $stmt->rowCount() > 0;
}

function cancelTournament($pdo, $tourId) {
    $tour = getTournamentById($pdo, $tourId);
    if (!$tour || $tour['status'] === 'finished') return false;
    if ((float)$tour['entry_fee'] > 0) {
        $stmt = $pdo->prepare("SELECT user_id FROM tournament_participants WHERE tournament_id = ?");
        $stmt->execute([$tourId]);
        foreach ($stmt->fetchAll() as $p) {
            $pdo->prepare("UPDATE users SET usdt = usdt + ? WHERE id = ?")->execute([$tour['entry_fee'], $p['user_id']]);
        }
    }
    $pdo->prepare("UPDATE tournaments SET status='cancelled' WHERE id=?")->execute([$tourId]);
    return true;
}

function autoStartTournaments($pdo) {
    $key = 'auto_tour_' . ($_SESSION['user_id'] ?? 0);
    if (!empty($_SESSION[$key]) && (time() - $_SESSION[$key]) < 1) return;

    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("UPDATE tournaments SET status='active' WHERE status='upcoming' AND start_at <= ?");
    $stmt->execute([$now]);

    $stmt = $pdo->prepare("SELECT id FROM tournaments WHERE status='active' AND end_at IS NOT NULL AND end_at <= ?");
    $stmt->execute([$now]);
    foreach ($stmt->fetchAll() as $t) {
        finishTournamentByRides($pdo, $t['id']);
    }

    $_SESSION[$key] = time();
}

function finishTournamentByRides($pdo, $tourId) {
    $tour = getTournamentById($pdo, $tourId);
    if (!$tour) return false;

    $stmt = $pdo->prepare("SELECT * FROM tournament_participants WHERE tournament_id=? ORDER BY rides_count DESC, wins_count DESC, joined_at ASC");
    $stmt->execute([$tourId]);
    $participants = $stmt->fetchAll();

    $nowSql = date('Y-m-d H:i:s');

    if (empty($participants)) {
        $pdo->prepare("UPDATE tournaments SET status='finished', finished_at=? WHERE id=?")->execute([$nowSql, $tourId]);
        return true;
    }

    $topPlaces = max(1, (int)$tour['top_places']);
    $prize1 = (float)$tour['prize_1'];
    $prize2 = (float)$tour['prize_2'];
    $prize3 = (float)$tour['prize_3'];
    $prizeOther = (float)$tour['prize_other'];

    $pdo->beginTransaction();
    try {
        foreach ($participants as $i => $p) {
            $place = $i + 1;
            $prize = 0; $rating = 0;
            if ($place <= $topPlaces) {
                if ($place === 1)      { $prize = $prize1; $rating = 100; }
                elseif ($place === 2)  { $prize = $prize2; $rating = 50;  }
                elseif ($place === 3)  { $prize = $prize3; $rating = 25;  }
                else                   { $prize = $prizeOther; $rating = 5; }
            }
            $pdo->prepare("UPDATE tournament_participants SET place=?, prize_won=?, rating_won=? WHERE id=?")
                ->execute([$place, $prize, $rating, $p['id']]);
            if ($prize > 0 || $rating > 0) {
                $pdo->prepare("UPDATE users SET usdt = usdt + ?, rating = rating + ? WHERE id = ?")
                    ->execute([$prize, $rating, $p['user_id']]);
            }
        }
        $pdo->prepare("UPDATE tournaments SET status='finished', finished_at=? WHERE id=?")->execute([$nowSql, $tourId]);
        $pdo->commit();
    } catch (Exception $ex) { $pdo->rollBack(); return false; }
    return true;
}

// ==========================================
// СЕЗОНЫ
// ==========================================
if (!defined('SEASON_MIN_LEVEL')) define('SEASON_MIN_LEVEL', 5);

function getActiveSeason($pdo) {
    $now = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT * FROM seasons 
        WHERE is_active = 1 AND start_at <= ? AND end_at >= ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$now, $now]);
    return $stmt->fetch() ?: null;
}

function getUserSeason($pdo, $userId, $seasonId) {
    $stmt = $pdo->prepare("SELECT * FROM user_seasons WHERE user_id=? AND season_id=? LIMIT 1");
    $stmt->execute([$userId, $seasonId]);
    $row = $stmt->fetch();
    if ($row) return $row;

    $pdo->prepare("INSERT INTO user_seasons (user_id, season_id) VALUES (?, ?)")
        ->execute([$userId, $seasonId]);

    $stmt = $pdo->prepare("SELECT * FROM user_seasons WHERE user_id=? AND season_id=? LIMIT 1");
    $stmt->execute([$userId, $seasonId]);
    return $stmt->fetch();
}

function getSeasonTasks($pdo, $seasonId) {
    $stmt = $pdo->prepare("SELECT * FROM season_tasks WHERE season_id=? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$seasonId]);
    return $stmt->fetchAll();
}

function getCompletedTasks($pdo, $userId, $seasonId) {
    $stmt = $pdo->prepare("SELECT task_id FROM user_season_tasks WHERE user_id=? AND season_id=?");
    $stmt->execute([$userId, $seasonId]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Задания игрока с флагом забрано/нет
 */
function getUserSeasonTasks($pdo, $userId, $seasonId) {
    $stmt = $pdo->prepare("
        SELECT ust.task_id, ust.is_claimed
        FROM user_season_tasks ust
        WHERE ust.user_id=? AND ust.season_id=?
    ");
    $stmt->execute([$userId, $seasonId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['task_id']] = (int)$r['is_claimed'];
    }
    return $out;
}

function getSeasonProgress($pdo, $userId, $season, $type) {
    $start = $season['start_at'] . ' 00:00:00';
    $end   = $season['end_at'] . ' 23:59:59';

    switch ($type) {
        case 'race':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM races WHERE (challenger_id=? OR opponent_id=?) AND created_at BETWEEN ? AND ?");
            $stmt->execute([$userId, $userId, $start, $end]);
            return (int)$stmt->fetchColumn();

        case 'win':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM races WHERE winner_id=? AND created_at BETWEEN ? AND ?");
            $stmt->execute([$userId, $start, $end]);
            return (int)$stmt->fetchColumn();

        case 'tour_ride':
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(rides_count), 0) FROM tournament_participants tp
                JOIN tournaments t ON t.id = tp.tournament_id
                WHERE tp.user_id=? AND t.start_at BETWEEN ? AND ?
            ");
            $stmt->execute([$userId, $start, $end]);
            return (int)$stmt->fetchColumn();

        case 'tour_win':
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(wins_count), 0) FROM tournament_participants tp
                JOIN tournaments t ON t.id = tp.tournament_id
                WHERE tp.user_id=? AND t.start_at BETWEEN ? AND ?
            ");
            $stmt->execute([$userId, $start, $end]);
            return (int)$stmt->fetchColumn();

        case 'buy_car':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_cars WHERE user_id=? AND purchased_at BETWEEN ? AND ?");
            $stmt->execute([$userId, $start, $end]);
            return (int)$stmt->fetchColumn();
    }
    return 0;
}

/**
 * Помечаем выполненные задания (is_claimed=0). Награды НЕ начисляются.
 */
function checkSeasonTasks($pdo, $userId, $season, $userSeason) {
    $tasks = getSeasonTasks($pdo, $season['id']);
    $completed = getCompletedTasks($pdo, $userId, $season['id']);
    $justCompleted = [];

    foreach ($tasks as $task) {
        if (isset($completed[$task['id']])) continue;

        $progress = getSeasonProgress($pdo, $userId, $season, $task['type']);
        if ($progress >= (int)$task['target']) {
            try {
                $pdo->prepare("INSERT INTO user_season_tasks (user_id, season_id, task_id, is_claimed) VALUES (?, ?, ?, 0)")
                    ->execute([$userId, $season['id'], $task['id']]);
                $justCompleted[] = $task;
            } catch (Exception $ex) { /* уже помечено */ }
        }
    }

    return $justCompleted;
}

/**
 * Забрать награду за задание (XP + USDT)
 */
function claimTaskReward($pdo, $userId, $seasonId, $taskId) {
    $stmt = $pdo->prepare("
        SELECT ust.id AS ust_id, st.xp_reward, st.usdt_reward, st.title
        FROM user_season_tasks ust
        JOIN season_tasks st ON st.id = ust.task_id
        WHERE ust.user_id=? AND ust.season_id=? AND ust.task_id=? AND ust.is_claimed=0
        LIMIT 1
    ");
    $stmt->execute([$userId, $seasonId, $taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        return ['ok'=>false, 'error'=>'Задание не найдено или уже забрано.'];
    }

    $xp = (int)$task['xp_reward'];
    $usdt = (float)$task['usdt_reward'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE user_season_tasks SET is_claimed=1 WHERE id=?")
            ->execute([$task['ust_id']]);

        if ($xp > 0) {
            $pdo->prepare("UPDATE user_seasons SET xp = xp + ? WHERE user_id=? AND season_id=?")
                ->execute([$xp, $userId, $seasonId]);
        }
        if ($usdt > 0) {
            $pdo->prepare("UPDATE users SET usdt = usdt + ? WHERE id=?")
                ->execute([$usdt, $userId]);
        }
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        return ['ok'=>false, 'error'=>'Ошибка начисления.'];
    }

    return ['ok'=>true, 'xp'=>$xp, 'usdt'=>$usdt, 'title'=>$task['title']];
}

function getSeasonXpForLevel($level) {
    return (int)round(50 * pow($level - 1, 1.5));
}

function recalcSeasonLevel($pdo, $userSeason) {
    $xp = (int)$userSeason['xp'];
    $level = 1;
    while ($level < 50) {
        $nextLevelXp = getSeasonXpForLevel($level + 1);
        if ($xp >= $nextLevelXp) $level++;
        else break;
    }
    if ($level != (int)$userSeason['level']) {
        $pdo->prepare("UPDATE user_seasons SET level=? WHERE id=?")->execute([$level, $userSeason['id']]);
        $userSeason['level'] = $level;
    }
    return $level;
}

function getSeasonRewards($pdo, $seasonId) {
    $stmt = $pdo->prepare("SELECT * FROM season_rewards WHERE season_id=? ORDER BY level ASC");
    $stmt->execute([$seasonId]);
    return $stmt->fetchAll();
}

function getNextSeasonReward($pdo, $seasonId, $lastClaimedLevel) {
    $nextLevel = (int)$lastClaimedLevel + 1;
    $stmt = $pdo->prepare("SELECT * FROM season_rewards WHERE season_id=? AND level=? LIMIT 1");
    $stmt->execute([$seasonId, $nextLevel]);
    return $stmt->fetch() ?: null;
}

/**
 * Сколько уведомлений по сезону (что забрать)
 */
function countSeasonNotifications($pdo, $userId, $user = null) {
    if (!$user) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
    if (!$user) return 0;
    if ((int)$user['level'] < SEASON_MIN_LEVEL) return 0;

    $season = getActiveSeason($pdo);
    if (!$season) return 0;

    $userSeason = getUserSeason($pdo, $userId, $season['id']);
    $count = 0;

    // 1. Выполненные незабранные задания
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_season_tasks WHERE user_id=? AND season_id=? AND is_claimed=0");
    $stmt->execute([$userId, $season['id']]);
    $count += (int)$stmt->fetchColumn();

    // 2. Награда за уровень
    $nextLevel = (int)$userSeason['last_reward_level'] + 1;
    if ((int)$userSeason['level'] >= $nextLevel) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM season_rewards WHERE season_id=? AND level=?");
        $stmt->execute([$season['id'], $nextLevel]);
        if ((int)$stmt->fetchColumn() > 0) $count++;
    }

    // 3. Машина сезона
    if (empty($userSeason['car_claimed'])
        && (int)$userSeason['level'] >= (int)$season['car_unlock_level']
        && !userHasCar($pdo, $userId, $season['car_id'])
    ) {
        $count++;
    }

    return $count;
}

/**
 * HTML-значок для навигации (кешируется на страницу)
 */
function getSeasonBadge($pdo, $user) {
    if ($GLOBALS['__season_badge'] !== null) return $GLOBALS['__season_badge'];

    $count = countSeasonNotifications($pdo, $user['id'], $user);
    if ($count <= 0) {
        $GLOBALS['__season_badge'] = '';
        return '';
    }
    $GLOBALS['__season_badge'] = '<span class="nav-badge">' . $count . '</span>';
    return $GLOBALS['__season_badge'];
}

