<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireStaff($pdo, ROLE_MODERATOR);
$isAdmin = isAdmin($user);
$isSuperAdmin = isSuperAdmin($user);

$tab     = $_GET['tab'] ?? 'users';
$search  = trim($_GET['q'] ?? '');
$sort    = $_GET['sort'] ?? 'id';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$message = '';
$msgType = 'success';

// ==========================================
// ДЕЙСТВИЯ
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Ошибка безопасности.';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Редактировать игрока ---
        if ($action === 'edit_user' && hasPermission($user, 'edit_users')) {
            $userId = (int)($_POST['user_id'] ?? 0);
            $level  = max(1, min(50, (int)($_POST['level'] ?? 1)));
            $exp    = max(0, (int)($_POST['exp'] ?? 0));
            $usdt   = max(0, (float)($_POST['usdt'] ?? 0));
            $rating = max(0, (int)($_POST['rating'] ?? 0));
            $carId  = trim($_POST['car_id'] ?? '');

            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $target = $stmt->fetch();

            if (!$target) {
                $message = 'Игрок не найден.'; $msgType = 'error';
            } elseif (!$isAdmin && (int)$target['role'] > 0) {
                $message = 'Модератор не может редактировать персонал.'; $msgType = 'error';
            } else {
                $pdo->prepare("UPDATE users SET level=?, exp=?, usdt=?, rating=?, car_id=? WHERE id=?")
                    ->execute([$level, $exp, $usdt, $rating, ($carId === '' ? null : $carId), $userId]);
                $message = '✅ Игрок обновлён.';
            }
        }

        // --- Назначить роль ---
        if ($action === 'set_role' && canManageRoles($user)) {
            $userId   = (int)($_POST['user_id'] ?? 0);
            $newRole  = (int)($_POST['role'] ?? 0);
            $newPerms = $_POST['perms'] ?? [];

            if ($userId === SUPER_ADMIN_ID) {
                $message = 'Нельзя менять супер-админа.'; $msgType = 'error';
            } elseif (!in_array($newRole, [0, 1, 2])) {
                $message = 'Неверная роль.'; $msgType = 'error';
            } else {
                $pdo->prepare("UPDATE users SET role=?, permissions=? WHERE id=?")
                    ->execute([$newRole, ($newRole === 0 ? null : encodePermissions($newPerms)), $userId]);
                $message = $newRole === 0 ? '👤 Роль снята.' : ($newRole === 1 ? '👑 Выдан админ.' : '🛡 Выдан модер.');
            }
        }

        // --- Быстрые действия ---
        if ($action === 'quick_action' && hasPermission($user, 'quick_actions')) {
            $uid = (int)($_POST['user_id'] ?? 0);
            $q = $_POST['quick'] ?? '';
            if ($uid > 0) {
                if ($q === 'usdt_1000')      { $pdo->prepare("UPDATE users SET usdt = usdt + 1000 WHERE id=?")->execute([$uid]); $message = '💰 +1000 USDT'; }
                elseif ($q === 'usdt_10000') { $pdo->prepare("UPDATE users SET usdt = usdt + 10000 WHERE id=?")->execute([$uid]); $message = '💰 +10 000 USDT'; }
                elseif ($q === 'level_1')    { $pdo->prepare("UPDATE users SET level = LEAST(50, level + 1) WHERE id=?")->execute([$uid]); $message = '⭐ +1 уровень'; }
                elseif ($q === 'level_5')    { $pdo->prepare("UPDATE users SET level = LEAST(50, level + 5) WHERE id=?")->execute([$uid]); $message = '⭐ +5 уровней'; }
                elseif ($q === 'reset_exp')  { $pdo->prepare("UPDATE users SET exp = 0 WHERE id=?")->execute([$uid]); $message = '🔄 Опыт сброшен'; }
            }
        }

        // --- Машины игрока ---
        if ($action === 'give_car' && hasPermission($user, 'give_cars')) {
            $userId = (int)($_POST['user_id'] ?? 0);
            $carId  = trim($_POST['car_id'] ?? '');
            $car    = getCarById($pdo, $carId);
            if ($car && $userId > 0 && !userHasCar($pdo, $userId, $carId)) {
                $pdo->prepare("INSERT INTO user_cars (user_id, car_id, color, stance) VALUES (?, ?, ?, 0)")
                    ->execute([$userId, $carId, $car['default_color']]);
                $message = '🚗 Машина выдана.';
            } else {
                $message = 'Не удалось выдать.'; $msgType = 'error';
            }
        }
        if ($action === 'remove_car' && hasPermission($user, 'remove_cars')) {
            $pdo->prepare("DELETE FROM user_cars WHERE id = ?")->execute([(int)($_POST['uc_id'] ?? 0)]);
            $message = '🗑 Машина удалена.';
        }

        // --- Бан ---
        if ($action === 'ban_user' && hasPermission($user, 'ban_users')) {
            $uid = (int)($_POST['user_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $target = $stmt->fetch();

            if ($uid === (int)$user['id']) $message = 'Нельзя забанить себя.';
            elseif ($uid === SUPER_ADMIN_ID) $message = 'Нельзя забанить супер-админа.';
            elseif ($target && (int)$target['role'] === ROLE_ADMIN && !$isSuperAdmin) $message = 'Только супер-админ может банить админов.';
            else {
                banUser($pdo, $uid, $reason ?: null);
                $message = '🚫 Игрок забанен.';
            }
        }
        if ($action === 'unban_user' && hasPermission($user, 'ban_users')) {
            unbanUser($pdo, (int)($_POST['user_id'] ?? 0));
            $message = '✅ Игрок разбанен.';
        }

        // --- Сброс пароля ---
        if ($action === 'reset_password' && hasPermission($user, 'reset_password')) {
            $uid = (int)($_POST['user_id'] ?? 0);
            $newPass = trim($_POST['new_password'] ?? '');
            if (strlen($newPass) < 4) {
                $message = 'Пароль минимум 4 символа.'; $msgType = 'error';
            } else {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
                $message = '🔐 Пароль изменён на: ' . e($newPass);
            }
        }

        // --- Каталог машин ---
        if (in_array($action, ['add_car', 'edit_car', 'delete_car']) && hasPermission($user, 'manage_cars')) {
            if ($action === 'add_car') {
                $carId = trim($_POST['car_id'] ?? '');
                $name  = trim($_POST['name'] ?? '');
                $image = trim($_POST['image'] ?? '');
                if ($carId === '' || $name === '' || $image === '') {
                    $message = 'Заполните ID, название и картинку.'; $msgType = 'error';
                } elseif (getCarById($pdo, $carId)) {
                    $message = 'ID уже существует.'; $msgType = 'error';
                } else {
                    $pdo->prepare("
                        INSERT INTO cars (id, name, brand, description, price, hp, class, min_level, default_color, image, is_starter, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ")->execute([
                        $carId, $name,
                        trim($_POST['brand'] ?? ''),
                        trim($_POST['description'] ?? ''),
                        (float)($_POST['price'] ?? 0),
                        (int)($_POST['hp'] ?? 0),
                        trim($_POST['class'] ?? 'E'),
                        max(1, (int)($_POST['min_level'] ?? 1)),
                        trim($_POST['default_color'] ?? '#c0392b'),
                        $image,
                        !empty($_POST['is_starter']) ? 1 : 0,
                    ]);
                    $message = '🚗 Машина добавлена.';
                }
            }
            if ($action === 'edit_car') {
                $carId = trim($_POST['car_id'] ?? '');
                $name  = trim($_POST['name'] ?? '');
                if ($carId === '' || $name === '') {
                    $message = 'Заполните поля.'; $msgType = 'error';
                } else {
                    $pdo->prepare("
                        UPDATE cars SET name=?, brand=?, description=?, price=?, hp=?, class=?, min_level=?, image=?, is_active=?
                        WHERE id=?
                    ")->execute([
                        $name,
                        trim($_POST['brand'] ?? ''),
                        trim($_POST['description'] ?? ''),
                        (float)($_POST['price'] ?? 0),
                        (int)($_POST['hp'] ?? 0),
                        trim($_POST['class'] ?? 'E'),
                        max(1, (int)($_POST['min_level'] ?? 1)),
                        trim($_POST['image'] ?? ''),
                        !empty($_POST['is_active']) ? 1 : 0,
                        $carId,
                    ]);
                    $message = '✅ Машина обновлена.';
                }
            }
            if ($action === 'delete_car') {
                $carId = trim($_POST['car_id'] ?? '');
                if ($carId !== '') {
                    $pdo->prepare("DELETE FROM cars WHERE id = ?")->execute([$carId]);
                    $message = '🗑 Машина удалена.';
                }
            }
        }

        // --- Турниры ---
        if (in_array($action, ['add_tournament', 'edit_tournament', 'delete_tournament', 'force_start_tournament', 'cancel_tournament']) && hasPermission($user, 'manage_cars')) {
            if ($action === 'add_tournament') {
                $name = trim($_POST['name'] ?? '');
                $startAt = trim($_POST['start_at'] ?? '');
                $endAt   = trim($_POST['end_at'] ?? '');
                $tType   = $_POST['tournament_type'] ?? 'all';
                if (!in_array($tType, ['all','levels','classes','brands'])) $tType = 'all';

                $minLevel = max(1, (int)($_POST['min_level'] ?? 1));
                $maxLevel = min(50, max($minLevel, (int)($_POST['max_level'] ?? 50)));

                $allowedClasses = [];
                if (!empty($_POST['allowed_classes']) && is_array($_POST['allowed_classes'])) foreach ($_POST['allowed_classes'] as $c) $allowedClasses[] = trim($c);
                $allowedBrands = [];
                if (!empty($_POST['allowed_brands']) && is_array($_POST['allowed_brands'])) foreach ($_POST['allowed_brands'] as $b) $allowedBrands[] = trim($b);

                $desc = trim($_POST['description'] ?? '');
                if ($desc === '') {
                    if ($tType === 'levels')       $desc = 'Турнир по уровням. Участвуют игроки с ' . $minLevel . ' по ' . $maxLevel . ' уровень.';
                    elseif ($tType === 'classes')  $desc = 'Турнир по классам. Участвуют: ' . (empty($allowedClasses) ? 'любые' : implode(', ', $allowedClasses));
                    elseif ($tType === 'brands')   $desc = 'Турнир по маркам. Участвуют: ' . (empty($allowedBrands) ? 'любые' : implode(', ', $allowedBrands));
                    else                           $desc = 'Открытый турнир.';
                }

                if ($name === '' || $startAt === '' || $endAt === '') {
                    $message = 'Заполните название, старт и окончание.'; $msgType = 'error';
                } else {
                    $startTs = strtotime(str_replace('T', ' ', $startAt));
                    $endTs   = strtotime(str_replace('T', ' ', $endAt));
                    if (!$startTs || !$endTs || $endTs <= $startTs) {
                        $message = 'Неверные даты.'; $msgType = 'error';
                    } else {
                        $pdo->prepare("
                            INSERT INTO tournaments 
                            (name, tournament_type, description, start_at, end_at,
                             min_level, max_level, allowed_classes, allowed_brands,
                             entry_fee, prize_1, prize_2, prize_3, prize_other, top_places,
                             max_participants, bracket_size, status, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 10000, 8, 'upcoming', ?)
                        ")->execute([
                            $name, $tType, $desc,
                            date('Y-m-d H:i:s', $startTs),
                            date('Y-m-d H:i:s', $endTs),
                            $minLevel, $maxLevel,
                            empty($allowedClasses) ? null : json_encode($allowedClasses),
                            empty($allowedBrands) ? null : json_encode($allowedBrands),
                            max(0, (float)($_POST['entry_fee'] ?? 0)),
                            max(0, (float)($_POST['prize_1'] ?? 0)),
                            max(0, (float)($_POST['prize_2'] ?? 0)),
                            max(0, (float)($_POST['prize_3'] ?? 0)),
                            max(0, (float)($_POST['prize_other'] ?? 0)),
                            max(1, (int)($_POST['top_places'] ?? 3)),
                            $user['id'],
                        ]);
                        $message = '🏆 Турнир создан.';
                    }
                }
            }
            if ($action === 'edit_tournament') {
                $tId = (int)($_POST['tournament_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $startAt = trim($_POST['start_at'] ?? '');
                $endAt   = trim($_POST['end_at'] ?? '');
                $tType   = $_POST['tournament_type'] ?? 'all';
                if (!in_array($tType, ['all','levels','classes','brands'])) $tType = 'all';

                $minLevel = max(1, (int)($_POST['min_level'] ?? 1));
                $maxLevel = min(50, max($minLevel, (int)($_POST['max_level'] ?? 50)));

                $allowedClasses = [];
                if (!empty($_POST['allowed_classes']) && is_array($_POST['allowed_classes'])) foreach ($_POST['allowed_classes'] as $c) $allowedClasses[] = trim($c);
                $allowedBrands = [];
                if (!empty($_POST['allowed_brands']) && is_array($_POST['allowed_brands'])) foreach ($_POST['allowed_brands'] as $b) $allowedBrands[] = trim($b);

                if ($tId > 0 && $name !== '' && $startAt !== '' && $endAt !== '') {
                    $pdo->prepare("
                        UPDATE tournaments SET name=?, tournament_type=?, description=?, start_at=?, end_at=?,
                            min_level=?, max_level=?, allowed_classes=?, allowed_brands=?,
                            entry_fee=?, prize_1=?, prize_2=?, prize_3=?, prize_other=?, top_places=?
                        WHERE id=?
                    ")->execute([
                        $name, $tType, trim($_POST['description'] ?? ''),
                        date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $startAt))),
                        date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $endAt))),
                        $minLevel, $maxLevel,
                        empty($allowedClasses) ? null : json_encode($allowedClasses),
                        empty($allowedBrands) ? null : json_encode($allowedBrands),
                        max(0, (float)($_POST['entry_fee'] ?? 0)),
                        max(0, (float)($_POST['prize_1'] ?? 0)),
                        max(0, (float)($_POST['prize_2'] ?? 0)),
                        max(0, (float)($_POST['prize_3'] ?? 0)),
                        max(0, (float)($_POST['prize_other'] ?? 0)),
                        max(1, (int)($_POST['top_places'] ?? 3)),
                        $tId,
                    ]);
                    $message = '✅ Турнир обновлён.';
                }
            }
            if ($action === 'delete_tournament') {
                $tId = (int)($_POST['tournament_id'] ?? 0);
                if ($tId > 0) {
                    $pdo->prepare("DELETE FROM tournament_participants WHERE tournament_id = ?")->execute([$tId]);
                    $pdo->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$tId]);
                    $message = '🗑 Турнир удалён.';
                }
            }
            if ($action === 'force_start_tournament') {
                $tId = (int)($_POST['tournament_id'] ?? 0);
                $message = ($tId > 0 && forceStartTournament($pdo, $tId)) ? '⚔ Турнир запущен!' : 'Не удалось.';
            }
            if ($action === 'cancel_tournament') {
                $tId = (int)($_POST['tournament_id'] ?? 0);
                $message = ($tId > 0 && cancelTournament($pdo, $tId)) ? '❌ Турнир отменён, взносы возвращены.' : 'Не удалось.';
            }
        }

        // ==========================================
        // СЕЗОНЫ
        // ==========================================
        if (in_array($action, ['add_season', 'edit_season', 'delete_season', 'toggle_season', 'add_task', 'edit_task', 'delete_task', 'add_reward', 'edit_reward', 'delete_reward']) && hasPermission($user, 'manage_cars')) {

            if ($action === 'add_season') {
                $name     = trim($_POST['name'] ?? '');
                $desc     = trim($_POST['description'] ?? '');
                $carId    = trim($_POST['car_id'] ?? '');
                $unlock   = max(1, min(50, (int)($_POST['car_unlock_level'] ?? 10)));
                $startAt  = trim($_POST['start_at'] ?? '');
                $endAt    = trim($_POST['end_at'] ?? '');

                if ($name === '' || $carId === '' || $startAt === '' || $endAt === '') {
                    $message = 'Заполните все обязательные поля.'; $msgType = 'error';
                } else {
                    $car = getCarById($pdo, $carId);
                    if (!$car) {
                        $message = 'Машина с ID "' . e($carId) . '" не найдена.'; $msgType = 'error';
                    } else {
                        $pdo->prepare("
                            INSERT INTO seasons (name, description, car_id, car_unlock_level, start_at, end_at, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, 1)
                        ")->execute([$name, $desc, $carId, $unlock, $startAt, $endAt]);
                        $message = '🏆 Сезон создан (ID: ' . $pdo->lastInsertId() . ').';
                    }
                }
            }

            if ($action === 'edit_season') {
                $sId        = (int)($_POST['season_id'] ?? 0);
                $name       = trim($_POST['name'] ?? '');
                $desc       = trim($_POST['description'] ?? '');
                $carId      = trim($_POST['car_id'] ?? '');
                $unlock     = max(1, min(50, (int)($_POST['car_unlock_level'] ?? 10)));
                $startAt    = trim($_POST['start_at'] ?? '');
                $endAt      = trim($_POST['end_at'] ?? '');
                $isActive   = !empty($_POST['is_active']) ? 1 : 0;

                if ($sId > 0 && $name !== '' && $carId !== '' && $startAt !== '' && $endAt !== '') {
                    $pdo->prepare("
                        UPDATE seasons SET name=?, description=?, car_id=?, car_unlock_level=?, start_at=?, end_at=?, is_active=?
                        WHERE id=?
                    ")->execute([$name, $desc, $carId, $unlock, $startAt, $endAt, $isActive, $sId]);
                    $message = '✅ Сезон обновлён.';
                }
            }

            if ($action === 'delete_season') {
                $sId = (int)($_POST['season_id'] ?? 0);
                if ($sId > 0) {
                    $pdo->prepare("DELETE FROM seasons WHERE id = ?")->execute([$sId]);
                    $pdo->prepare("DELETE FROM season_tasks WHERE season_id = ?")->execute([$sId]);
                    $pdo->prepare("DELETE FROM season_rewards WHERE season_id = ?")->execute([$sId]);
                    $message = '🗑 Сезон удалён.';
                }
            }

            if ($action === 'toggle_season') {
                $sId = (int)($_POST['season_id'] ?? 0);
                if ($sId > 0) {
                    $pdo->prepare("UPDATE seasons SET is_active = 1 - is_active WHERE id = ?")->execute([$sId]);
                    $message = '🔄 Статус сезона изменён.';
                }
            }

            // --- Добавить задание ---
            if ($action === 'add_task') {
                $sId    = (int)($_POST['season_id'] ?? 0);
                $title  = trim($_POST['title'] ?? '');
                $desc   = trim($_POST['description'] ?? '');
                $type   = $_POST['type'] ?? 'race';
                $target = max(1, (int)($_POST['target'] ?? 10));
                $xp     = max(1, (int)($_POST['xp_reward'] ?? 20));
                $usdt   = max(0, (float)($_POST['usdt_reward'] ?? 0));

                $allowed = ['race','win','tour_ride','tour_win','buy_car'];
                if (!in_array($type, $allowed)) $type = 'race';

                if ($sId > 0 && $title !== '') {
                    $pdo->prepare("INSERT INTO season_tasks (season_id, title, description, type, target, xp_reward, usdt_reward) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$sId, $title, $desc, $type, $target, $xp, $usdt]);
                    $message = '📋 Задание добавлено.';
                } else {
                    $message = 'Заполните название.'; $msgType = 'error';
                }
            }

            // --- Редактировать задание ---
            if ($action === 'edit_task') {
                $tId    = (int)($_POST['task_id'] ?? 0);
                $title  = trim($_POST['title'] ?? '');
                $desc   = trim($_POST['description'] ?? '');
                $type   = $_POST['type'] ?? 'race';
                $target = max(1, (int)($_POST['target'] ?? 10));
                $xp     = max(1, (int)($_POST['xp_reward'] ?? 20));
                $usdt   = max(0, (float)($_POST['usdt_reward'] ?? 0));

                $allowed = ['race','win','tour_ride','tour_win','buy_car'];
                if (!in_array($type, $allowed)) $type = 'race';

                if ($tId > 0 && $title !== '') {
                    $pdo->prepare("UPDATE season_tasks SET title=?, description=?, type=?, target=?, xp_reward=?, usdt_reward=? WHERE id=?")
                        ->execute([$title, $desc, $type, $target, $xp, $usdt, $tId]);
                    $message = '✅ Задание обновлено.';
                } else {
                    $message = 'Заполните название.'; $msgType = 'error';
                }
            }

            // --- Удалить задание ---
            if ($action === 'delete_task') {
                $tId = (int)($_POST['task_id'] ?? 0);
                if ($tId > 0) {
                    $pdo->prepare("DELETE FROM season_tasks WHERE id = ?")->execute([$tId]);
                    $pdo->prepare("DELETE FROM user_season_tasks WHERE task_id = ?")->execute([$tId]);
                    $message = '🗑 Задание удалено.';
                }
            }

            // --- Добавить приз ---
            if ($action === 'add_reward') {
                $sId  = (int)($_POST['season_id'] ?? 0);
                $lvl  = max(1, (int)($_POST['level'] ?? 1));
                $usdt = max(0, (float)($_POST['usdt'] ?? 0));
                $desc = trim($_POST['description'] ?? '');

                if ($sId > 0) {
                    $pdo->prepare("INSERT INTO season_rewards (season_id, level, usdt, description) VALUES (?, ?, ?, ?)")
                        ->execute([$sId, $lvl, $usdt, $desc]);
                    $message = '💰 Приз добавлен.';
                }
            }

            // --- Редактировать приз ---
            if ($action === 'edit_reward') {
                $rId  = (int)($_POST['reward_id'] ?? 0);
                $lvl  = max(1, (int)($_POST['level'] ?? 1));
                $usdt = max(0, (float)($_POST['usdt'] ?? 0));
                $desc = trim($_POST['description'] ?? '');

                if ($rId > 0) {
                    $pdo->prepare("UPDATE season_rewards SET level=?, usdt=?, description=? WHERE id=?")
                        ->execute([$lvl, $usdt, $desc, $rId]);
                    $message = '✅ Награда обновлена.';
                }
            }

            // --- Удалить приз ---
            if ($action === 'delete_reward') {
                $rId = (int)($_POST['reward_id'] ?? 0);
                if ($rId > 0) {
                    $pdo->prepare("DELETE FROM season_rewards WHERE id = ?")->execute([$rId]);
                    $message = '🗑 Приз удалён.';
                }
            }
        }

        // --- Удалить игрока ---
        if ($action === 'delete_user' && hasPermission($user, 'delete_users')) {
            $uid = (int)($_POST['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $target = $stmt->fetch();

            if ($uid <= 0 || $uid === (int)$user['id']) $message = 'Нельзя удалить себя.';
            elseif ($uid === SUPER_ADMIN_ID) $message = 'Нельзя удалить супер-админа.';
            elseif ($target && (int)$target['role'] === ROLE_ADMIN && !$isSuperAdmin) $message = 'Только супер-админ может удалять админов.';
            else {
                $pdo->prepare("DELETE FROM user_cars WHERE user_id = ?")->execute([$uid]);
                $pdo->prepare("DELETE FROM race_limits WHERE user_id = ?")->execute([$uid]);
                $pdo->prepare("DELETE FROM races WHERE challenger_id = ? OR opponent_id = ?")->execute([$uid, $uid]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
                $message = '🗑 Игрок удалён.';
            }
        }
    }
}

// ==========================================
// ДАННЫЕ
// ==========================================
$csrfToken = generateCsrfToken();

$stats = [
    'users'        => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'banned'       => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn(),
    'cars'         => (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE is_active = 1")->fetchColumn(),
    'races'        => (int)$pdo->query("SELECT COUNT(*) FROM races")->fetchColumn(),
    'races_today'  => (int)$pdo->query("SELECT COUNT(*) FROM races WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'usdt_total'   => (float)$pdo->query("SELECT COALESCE(SUM(usdt),0) FROM users")->fetchColumn(),
    'tournaments'  => (int)$pdo->query("SELECT COUNT(*) FROM tournaments")->fetchColumn(),
    'tours_active' => (int)$pdo->query("SELECT COUNT(*) FROM tournaments WHERE status = 'active'")->fetchColumn(),
    'seasons'      => (int)$pdo->query("SELECT COUNT(*) FROM seasons")->fetchColumn(),
    'seasons_active'=> (int)$pdo->query("SELECT COUNT(*) FROM seasons WHERE is_active = 1 AND start_at <= CURDATE() AND end_at >= CURDATE()")->fetchColumn(),
];

$allowedSort = ['id'=>'u.id', 'level'=>'u.level', 'usdt'=>'u.usdt', 'date'=>'u.created_at'];
$orderBy = $allowedSort[$sort] ?? 'u.id';
$where = ''; $params = [];
if ($search !== '') {
    $where = "WHERE u.username LIKE ? OR u.id = ?";
    $params[] = '%' . $search . '%';
    $params[] = (int)$search;
}
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS car_name, c.brand AS car_brand
    FROM users u
    LEFT JOIN cars c ON c.id = u.car_id
    $where
    ORDER BY CASE WHEN u.id = " . SUPER_ADMIN_ID . " THEN 0 WHEN u.role = 0 THEN 99 ELSE u.role END ASC, $orderBy $sortDir
    LIMIT 100
");
$stmt->execute($params);
$players = $stmt->fetchAll();

$cars = cacheGetAllCars($pdo);

$tournaments = $pdo->query("
    SELECT t.*, (SELECT COUNT(*) FROM tournament_participants tp WHERE tp.tournament_id = t.id) AS participants_count
    FROM tournaments t
    ORDER BY CASE t.status WHEN 'active' THEN 1 WHEN 'upcoming' THEN 2 WHEN 'draft' THEN 3 WHEN 'finished' THEN 4 ELSE 5 END ASC, t.start_at DESC
    LIMIT 100
")->fetchAll();

$seasons = $pdo->query("
    SELECT s.*, c.name AS car_name, c.brand AS car_brand
    FROM seasons s
    LEFT JOIN cars c ON c.id = s.car_id
    ORDER BY s.start_at DESC
    LIMIT 50
")->fetchAll();

$allBrands = [];
foreach ($cars as $c) if (!empty($c['brand']) && !in_array($c['brand'], $allBrands)) $allBrands[] = $c['brand'];
sort($allBrands);

$pageTitle = 'Админ-панель';
?>
<?php include 'header.php'; ?>

<div class="top-bar">
    <div><?= isSuperAdmin($user) ? '👑 Главный админ' : getRoleName($user['role']) ?></div>
    <div><span class="usdt">💵 <?= formatUSDT($user['usdt']) ?> USDT</span></div>
</div>

<div class="admin-tabs">
    <a href="?tab=stats" class="<?= $tab === 'stats' ? 'active' : '' ?>">📊<span>Стата</span></a>
    <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">👥<span>Игроки</span></a>
    <?php if (hasPermission($user, 'manage_cars')): ?>
        <a href="?tab=cars"    class="<?= $tab === 'cars'    ? 'active' : '' ?>">🚗<span>Машины</span></a>
        <a href="?tab=tours"   class="<?= $tab === 'tours'   ? 'active' : '' ?>">🏆<span>Турниры</span></a>
        <a href="?tab=seasons" class="<?= $tab === 'seasons' ? 'active' : '' ?>">🌟<span>Сезоны</span></a>
    <?php endif; ?>
    <a href="garage.php" class="admin-tab-exit">←<span>Игра</span></a>
</div>

<div class="admin-my-perms">
    <div class="admin-my-perms-label">Ваши права:</div>
    <div class="admin-my-perms-list">
        <?php if (isSuperAdmin($user)): ?>
            <span class="perm-chip perm-chip-full">👑 ВСЕ ПРАВА</span>
        <?php else:
            $hasAny = false;
            foreach (getAllPermissions() as $key => $label):
                if (hasPermission($user, $key)):
                    $hasAny = true;
                    echo '<span class="perm-chip">' . e(explode(' ', $label, 2)[1] ?? $label) . '</span>';
                endif;
            endforeach;
            if (!$hasAny) echo '<span class="perm-chip perm-chip-none">Нет разрешений</span>';
        endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div style="padding:10px 12px;"><div class="message <?= $msgType ?>"><?= $message ?></div></div>
<?php endif; ?>

<!-- ============ СТАТИСТИКА ============ -->
<?php if ($tab === 'stats'): ?>
    <div class="admin-stats">
        <div class="admin-stat"><div class="admin-stat-icon">👥</div><div class="admin-stat-value"><?= $stats['users'] ?></div><div class="admin-stat-label">Игроков</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">🚫</div><div class="admin-stat-value" style="color:#f85149;"><?= $stats['banned'] ?></div><div class="admin-stat-label">Забанено</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">🚗</div><div class="admin-stat-value"><?= $stats['cars'] ?></div><div class="admin-stat-label">Машин</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">🏁</div><div class="admin-stat-value"><?= $stats['races'] ?></div><div class="admin-stat-label">Гонок</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">📅</div><div class="admin-stat-value"><?= $stats['races_today'] ?></div><div class="admin-stat-label">Сегодня</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">🏆</div><div class="admin-stat-value"><?= $stats['tournaments'] ?></div><div class="admin-stat-label">Турниров</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">⚔</div><div class="admin-stat-value" style="color:#f85149;"><?= $stats['tours_active'] ?></div><div class="admin-stat-label">Активных</div></div>
        <div class="admin-stat"><div class="admin-stat-icon">🌟</div><div class="admin-stat-value" style="color:#d29922;"><?= $stats['seasons_active'] ?>/<?= $stats['seasons'] ?></div><div class="admin-stat-label">Сезонов</div></div>
        <div class="admin-stat admin-stat-green"><div class="admin-stat-icon">💰</div><div class="admin-stat-value"><?= formatUSDT($stats['usdt_total']) ?></div><div class="admin-stat-label">USDT в обороте</div></div>
    </div>
<?php endif; ?>

<!-- ============ ИГРОКИ ============ -->
<?php if ($tab === 'users'): ?>
    <form method="GET" class="admin-search-bar">
        <input type="hidden" name="tab" value="users">
        <input type="text" name="q" placeholder="🔍 Ник или ID..." value="<?= e($search) ?>">
        <select name="sort">
            <option value="id"    <?= $sort === 'id'    ? 'selected' : '' ?>>По ID</option>
            <option value="level" <?= $sort === 'level' ? 'selected' : '' ?>>По уровню</option>
            <option value="usdt"  <?= $sort === 'usdt'  ? 'selected' : '' ?>>По USDT</option>
            <option value="date"  <?= $sort === 'date'  ? 'selected' : '' ?>>По дате</option>
        </select>
        <select name="dir">
            <option value="asc"  <?= $sortDir === 'ASC'  ? 'selected' : '' ?>>↑</option>
            <option value="desc" <?= $sortDir === 'DESC' ? 'selected' : '' ?>>↓</option>
        </select>
        <button type="submit">🔍</button>
    </form>

    <div class="admin-section-title">👥 Игроки (<?= count($players) ?>)</div>

    <div class="admin-player-list">
    <?php foreach ($players as $p):
        $pCars = getUserCars($pdo, $p['id']);
        $pRole = (int)$p['role'];
        $pBanned = !empty($p['is_banned']);
        $isMe = ((int)$p['id'] === (int)$user['id']);
        $isSuperTarget = ((int)$p['id'] === SUPER_ADMIN_ID);
        $canEdit = hasPermission($user, 'edit_users') && ($isAdmin || $pRole === 0);

        $pPerms = [];
        if (!empty($p['permissions'])) { $d = json_decode($p['permissions'], true); if (is_array($d)) $pPerms = $d; }

        $roleBadge = '';
        if ($isSuperTarget)                 $roleBadge = '<span class="role-badge role-super">👑 SUPER</span>';
        elseif ($pRole === ROLE_ADMIN)      $roleBadge = '<span class="role-badge role-admin">👑 ADMIN</span>';
        elseif ($pRole === ROLE_MODERATOR)  $roleBadge = '<span class="role-badge role-mod">🛡 MOD</span>';
        $banBadge = $pBanned ? '<span class="role-badge role-ban">🚫 БАН</span>' : '';
    ?>
        <details class="admin-card">
            <summary class="admin-card-summary">
                <div class="admin-card-avatar" style="background:<?= $isSuperTarget ? 'linear-gradient(135deg,#f0c674,#d29922)' : ($pRole === 1 ? 'linear-gradient(135deg,#d29922,#b8860b)' : ($pRole === 2 ? 'linear-gradient(135deg,#58a6ff,#1f6feb)' : 'linear-gradient(135deg,#30363d,#21262d)')) ?>">
                    <?= mb_strtoupper(mb_substr($p['username'], 0, 1)) ?>
                </div>
                <div class="admin-card-info">
                    <div class="admin-card-nick"><?= e($p['username']) ?> <?= $roleBadge ?> <?= $banBadge ?></div>
                    <div class="admin-card-meta">#<?= (int)$p['id'] ?> · Ур. <?= (int)$p['level'] ?> · 💵 <?= formatUSDT($p['usdt']) ?> · 🏆 <?= (int)$p['rating'] ?></div>
                </div>
                <div class="admin-card-arrow">▸</div>
            </summary>

            <div class="admin-card-body">
                <?php if (!$canEdit): ?>
                    <div class="admin-warning">⚠ Недостаточно прав для редактирования</div>
                <?php else: ?>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                        <div class="admin-grid">
                            <div class="admin-field"><label>Уровень</label><input type="number" name="level" value="<?= (int)$p['level'] ?>" min="1" max="50"></div>
                            <div class="admin-field"><label>Опыт</label><input type="number" name="exp" value="<?= (int)$p['exp'] ?>" min="0"></div>
                            <div class="admin-field"><label>USDT</label><input type="number" name="usdt" value="<?= (int)$p['usdt'] ?>" min="0"></div>
                            <div class="admin-field"><label>🏆 Рейтинг</label><input type="number" name="rating" value="<?= (int)$p['rating'] ?>" min="0"></div>
                        </div>
                        <div class="admin-field">
                            <label>Текущая машина</label>
                            <select name="car_id">
                                <option value="">— нет —</option>
                                <?php foreach ($cars as $c): ?>
                                    <option value="<?= e($c['id']) ?>" <?= $p['car_id'] === $c['id'] ? 'selected' : '' ?>><?= e($c['brand'] . ' ' . $c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="admin-btn admin-btn-primary admin-btn-full">💾 Сохранить</button>
                    </form>
                <?php endif; ?>

                <?php if (hasPermission($user, 'quick_actions') && $canEdit): ?>
                    <div class="admin-subtitle">⚡ Быстрые действия</div>
                    <div class="admin-quick-row">
                        <?php foreach ([['usdt_1000','+1000 💵'],['usdt_10000','+10k 💵'],['level_1','+1 ⭐'],['level_5','+5 ⭐'],['reset_exp','↻ XP']] as $q): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="quick_action">
                                <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="quick" value="<?= $q[0] ?>">
                                <button class="admin-quick <?= $q[0]==='reset_exp' ? 'admin-quick-danger' : '' ?>"><?= $q[1] ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="admin-subtitle">🚗 Гараж (<?= count($pCars) ?>)</div>
                <div class="admin-chips">
                    <?php if (empty($pCars)): ?>
                        <span class="admin-chip-empty">Пусто</span>
                    <?php else: foreach ($pCars as $pc): ?>
                        <span class="admin-chip">
                            <?= e($pc['brand'] . ' ' . $pc['name']) ?>
                            <?php if (hasPermission($user, 'remove_cars')): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="remove_car">
                                    <input type="hidden" name="uc_id" value="<?= (int)$pc['uc_id'] ?>">
                                    <button class="admin-chip-x">×</button>
                                </form>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; endif; ?>
                </div>

                <?php if (hasPermission($user, 'give_cars')): ?>
                    <form method="POST" class="admin-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="give_car">
                        <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                        <select name="car_id" required>
                            <option value="">+ выдать машину...</option>
                            <?php foreach ($cars as $c): ?>
                                <option value="<?= e($c['id']) ?>"><?= e($c['brand'] . ' ' . $c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="admin-btn admin-btn-success">Выдать</button>
                    </form>
                <?php endif; ?>

                <?php if (hasPermission($user, 'reset_password') && $canEdit): ?>
                    <div class="admin-subtitle">🔐 Сброс пароля</div>
                    <form method="POST" class="admin-inline-form" onsubmit="return confirm('Сменить пароль?');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                        <input type="text" name="new_password" placeholder="Новый пароль" required minlength="4">
                        <button class="admin-btn admin-btn-primary">Сменить</button>
                    </form>
                <?php endif; ?>

                <?php if (canManageRoles($user) && !$isMe && !$isSuperTarget): ?>
                    <div class="admin-subtitle">👑 Роль и права</div>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="set_role">
                        <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                        <div class="admin-field">
                            <label>Роль</label>
                            <select name="role" id="role_<?= (int)$p['id'] ?>" onchange="togglePerms(this, <?= (int)$p['id'] ?>)">
                                <option value="0" <?= $pRole === 0 ? 'selected' : '' ?>>👤 Игрок</option>
                                <option value="1" <?= $pRole === 1 ? 'selected' : '' ?>>👑 Администратор</option>
                                <option value="2" <?= $pRole === 2 ? 'selected' : '' ?>>🛡 Модератор</option>
                            </select>
                        </div>
                        <div class="admin-perms-block" id="perms_<?= (int)$p['id'] ?>" style="<?= $pRole === 0 ? 'display:none;' : '' ?>">
                            <div class="admin-perms-title">Что может делать:</div>
                            <?php foreach (getAllPermissions() as $key => $label): ?>
                                <label class="admin-perm-row">
                                    <input type="checkbox" name="perms[<?= e($key) ?>]" value="1" <?= !empty($pPerms[$key]) ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="admin-btn admin-btn-warn admin-btn-full" style="margin-top:8px;">👑 Применить</button>
                    </form>
                <?php endif; ?>

                <?php if (!$isMe && !$isSuperTarget): ?>
                    <?php if (hasPermission($user, 'ban_users') && ($isAdmin || $pRole === 0)): ?>
                        <div class="admin-subtitle">⚠ Опасные действия</div>
                        <?php if ($pBanned): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="unban_user">
                                <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                <button class="admin-btn admin-btn-success admin-btn-full">✅ Разбанить</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" onsubmit="return confirm('Забанить?');">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="ban_user">
                                <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                <div class="admin-inline-form">
                                    <input type="text" name="reason" placeholder="Причина">
                                    <button class="admin-btn admin-btn-warn">🚫</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (hasPermission($user, 'delete_users') && ($isAdmin || $pRole === 0)): ?>
                        <form method="POST" onsubmit="return confirm('Удалить игрока?');" style="margin-top:8px;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                            <button class="admin-btn admin-btn-danger admin-btn-full">🗑 Удалить игрока</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============ МАШИНЫ ============ -->
<?php if ($tab === 'cars' && hasPermission($user, 'manage_cars')): ?>
    <details class="admin-card">
        <summary class="admin-card-summary">
            <div class="admin-card-avatar" style="background:linear-gradient(135deg,#2ea043,#238636);">+</div>
            <div class="admin-card-info"><div class="admin-card-nick">Добавить машину</div><div class="admin-card-meta">Новая модель</div></div>
            <div class="admin-card-arrow">▸</div>
        </summary>
        <div class="admin-card-body">
            <form method="POST" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_car">
                <div class="admin-grid">
                    <div class="admin-field"><label>ID *</label><input type="text" name="car_id" required placeholder="vaz_2110" maxlength="50"></div>
                    <div class="admin-field"><label>Название *</label><input type="text" name="name" required placeholder="ВАЗ 2110"></div>
                    <div class="admin-field"><label>Марка</label><input type="text" name="brand"></div>
                    <div class="admin-field"><label>Класс</label><input type="text" name="class" placeholder="E" maxlength="5"></div>
                    <div class="admin-field"><label>Цена</label><input type="number" name="price" placeholder="1500" min="0"></div>
                    <div class="admin-field"><label>л.с.</label><input type="number" name="hp" placeholder="92" min="0"></div>
                    <div class="admin-field"><label>Мин. ур.</label><input type="number" name="min_level" value="1" min="1" max="50"></div>
                    <div class="admin-field"><label>Цвет</label><input type="text" name="default_color" value="#c0392b" maxlength="7"></div>
                    <div class="admin-field admin-field-wide"><label>Картинка * <small>(файл из /car/)</small></label><input type="text" name="image" required placeholder="Vaz_2110.png"></div>
                </div>
                <div class="admin-field"><label>Описание</label><textarea name="description" rows="2"></textarea></div>
                <label class="admin-checkbox"><input type="checkbox" name="is_starter" value="1"><span>Стартовая машина</span></label>
                <button type="submit" class="admin-btn admin-btn-success admin-btn-full">➕ Добавить</button>
            </form>
        </div>
    </details>

    <div class="admin-section-title">🚗 Каталог (<?= count($cars) ?>)</div>

    <div class="admin-player-list">
    <?php foreach ($cars as $c): ?>
        <details class="admin-card">
            <summary class="admin-card-summary">
                <div class="admin-card-preview"><?= renderCarImage($c['image'], $c['default_color'], 0) ?></div>
                <div class="admin-card-info">
                    <div class="admin-card-nick">
                        <?= e($c['brand'] . ' ' . $c['name']) ?>
                        <?php if ($c['is_starter']): ?><span class="role-badge role-starter">СТАРТ</span><?php endif; ?>
                        <?php if (!$c['is_active']): ?><span class="role-badge role-ban">СКРЫТА</span><?php endif; ?>
                    </div>
                    <div class="admin-card-meta">#<?= e($c['id']) ?> · Ур.<?= (int)$c['min_level'] ?> · <?= formatUSDT($c['price']) ?> 💵 · <?= (int)$c['hp'] ?> л.с.</div>
                </div>
                <div class="admin-card-arrow">▸</div>
            </summary>
            <div class="admin-card-body">
                <form method="POST" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="edit_car">
                    <input type="hidden" name="car_id" value="<?= e($c['id']) ?>">
                    <div class="admin-grid">
                        <div class="admin-field"><label>Название</label><input type="text" name="name" value="<?= e($c['name']) ?>" required></div>
                        <div class="admin-field"><label>Марка</label><input type="text" name="brand" value="<?= e($c['brand']) ?>"></div>
                        <div class="admin-field"><label>Класс</label><input type="text" name="class" value="<?= e($c['class']) ?>" maxlength="5"></div>
                        <div class="admin-field"><label>Цена</label><input type="number" name="price" value="<?= (int)$c['price'] ?>" min="0"></div>
                        <div class="admin-field"><label>л.с.</label><input type="number" name="hp" value="<?= (int)$c['hp'] ?>" min="0"></div>
                        <div class="admin-field"><label>Мин. ур.</label><input type="number" name="min_level" value="<?= (int)$c['min_level'] ?>" min="1" max="50"></div>
                        <div class="admin-field admin-field-wide"><label>Картинка</label><input type="text" name="image" value="<?= e($c['image']) ?>"></div>
                    </div>
                    <div class="admin-field"><label>Описание</label><textarea name="description" rows="2"><?= e($c['description']) ?></textarea></div>
                    <label class="admin-checkbox"><input type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>><span>Активна</span></label>
                    <button type="submit" class="admin-btn admin-btn-primary admin-btn-full">💾 Сохранить</button>
                </form>
                <form method="POST" onsubmit="return confirm('Удалить?');" style="margin-top:8px;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_car">
                    <input type="hidden" name="car_id" value="<?= e($c['id']) ?>">
                    <button class="admin-btn admin-btn-danger admin-btn-full">🗑 Удалить</button>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============ ТУРНИРЫ ============ -->
<?php if ($tab === 'tours' && hasPermission($user, 'manage_cars')): ?>
    <details class="admin-card">
        <summary class="admin-card-summary">
            <div class="admin-card-avatar" style="background:linear-gradient(135deg,#d29922,#b8860b);">🏆</div>
            <div class="admin-card-info"><div class="admin-card-nick">Создать турнир</div><div class="admin-card-meta">Детальная настройка</div></div>
            <div class="admin-card-arrow">▸</div>
        </summary>
        <div class="admin-card-body">
            <form method="POST" class="admin-form" id="addTourForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_tournament">

                <div class="admin-subtitle" style="margin-top:0;">📝 Основное</div>
                <div class="admin-field"><label>Название *</label><input type="text" name="name" required maxlength="100"></div>
                <div class="admin-grid">
                    <div class="admin-field"><label>Начало (МСК) *</label><input type="datetime-local" name="start_at" required></div>
                    <div class="admin-field"><label>Окончание (МСК) *</label><input type="datetime-local" name="end_at" required></div>
                </div>

                <div class="admin-subtitle">🎯 Тип турнира</div>
                <div class="admin-checkbox-grid" style="grid-template-columns:1fr 1fr;">
                    <label class="admin-checkbox"><input type="radio" name="tournament_type" value="all" checked onchange="toggleTT('add', this.value)"><span>🌐 Все</span></label>
                    <label class="admin-checkbox"><input type="radio" name="tournament_type" value="levels" onchange="toggleTT('add', this.value)"><span>⭐ По уровням</span></label>
                    <label class="admin-checkbox"><input type="radio" name="tournament_type" value="classes" onchange="toggleTT('add', this.value)"><span>🏷 По классам</span></label>
                    <label class="admin-checkbox"><input type="radio" name="tournament_type" value="brands" onchange="toggleTT('add', this.value)"><span>🚗 По маркам</span></label>
                </div>

                <div class="tour-type-block" id="tt_levels_add" style="display:none;">
                    <div class="admin-grid" style="margin-top:8px;">
                        <div class="admin-field"><label>Мин. ур.</label><input type="number" name="min_level" value="1" min="1" max="50"></div>
                        <div class="admin-field"><label>Макс. ур.</label><input type="number" name="max_level" value="50" min="1" max="50"></div>
                    </div>
                </div>

                <div class="tour-type-block" id="tt_classes_add" style="display:none;margin-top:8px;">
                    <div class="admin-checkbox-grid">
                        <?php foreach (['E','D','C','B','A','S'] as $cl): ?>
                            <label class="admin-checkbox admin-checkbox-item"><input type="checkbox" name="allowed_classes[]" value="<?= $cl ?>"><span>Класс <?= $cl ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tour-type-block" id="tt_brands_add" style="display:none;margin-top:8px;">
                    <div class="admin-checkbox-grid">
                        <?php foreach ($allBrands as $b): ?>
                            <label class="admin-checkbox admin-checkbox-item"><input type="checkbox" name="allowed_brands[]" value="<?= e($b) ?>"><span><?= e($b) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-field" style="margin-top:10px;">
                    <label>Описание (опц.)</label>
                    <textarea name="description" rows="2" placeholder="Автогенерация если пусто"></textarea>
                </div>

                <div class="admin-subtitle">💰 Призы</div>
                <div class="admin-grid">
                    <div class="admin-field"><label>Взнос</label><input type="number" name="entry_fee" value="0" min="0"></div>
                    <div class="admin-field"><label>Призовых мест</label><input type="number" name="top_places" value="3" min="1" max="10000"></div>
                    <div class="admin-field"><label>🥇 1</label><input type="number" name="prize_1" value="0" min="0"></div>
                    <div class="admin-field"><label>🥈 2</label><input type="number" name="prize_2" value="0" min="0"></div>
                    <div class="admin-field"><label>🥉 3</label><input type="number" name="prize_3" value="0" min="0"></div>
                    <div class="admin-field"><label>🎁 4+</label><input type="number" name="prize_other" value="0" min="0"></div>
                </div>

                <button type="submit" class="admin-btn admin-btn-warn admin-btn-full" style="margin-top:10px;">🏆 Создать</button>
            </form>
            <script>
            function toggleTT(p, v){['levels','classes','brands'].forEach(t=>{var e=document.getElementById('tt_'+t+'_'+p);if(e)e.style.display=(t===v)?'block':'none';});}
            </script>
        </div>
    </details>

    <div class="admin-section-title">🏆 Турниры (<?= count($tournaments) ?>)</div>

    <div class="admin-player-list">
    <?php foreach ($tournaments as $t):
        $statusText = ''; $statusClass = '';
        if ($t['status'] === 'upcoming')      { $statusText = '📅 АНОНС';    $statusClass = 'status-upcoming'; }
        elseif ($t['status'] === 'active')    { $statusText = '⚔ LIVE';     $statusClass = 'status-active'; }
        elseif ($t['status'] === 'finished')  { $statusText = '🏁 ИТОГИ';   $statusClass = 'status-finished'; }
        elseif ($t['status'] === 'cancelled') { $statusText = '❌ ОТМЕНЁН'; $statusClass = 'status-finished'; }

        $startDt = date('Y-m-d\TH:i', strtotime($t['start_at']));
        $endDt   = !empty($t['end_at']) ? date('Y-m-d\TH:i', strtotime($t['end_at'])) : '';
        $tType   = $t['tournament_type'] ?? 'all';

        $acArr = []; if (!empty($t['allowed_classes'])) { $d = json_decode($t['allowed_classes'], true); if (is_array($d)) $acArr = $d; }
        $abArr = []; if (!empty($t['allowed_brands']))  { $d = json_decode($t['allowed_brands'], true); if (is_array($d)) $abArr = $d; }
    ?>
        <details class="admin-card">
            <summary class="admin-card-summary">
                <div class="admin-card-avatar" style="background:linear-gradient(135deg,#d29922,#b8860b);">🏆</div>
                <div class="admin-card-info">
                    <div class="admin-card-nick"><?= e($t['name']) ?> <span class="tour-card-status <?= $statusClass ?>"><?= $statusText ?></span></div>
                    <div class="admin-card-meta">#<?= (int)$t['id'] ?> · <?= e(getTournamentTypeShort($t)) ?> · <?= date('d.m H:i', strtotime($t['start_at'])) ?><?= !empty($t['end_at']) ? ' → ' . date('d.m H:i', strtotime($t['end_at'])) : '' ?> · топ <?= (int)$t['top_places'] ?></div>
                </div>
                <div class="admin-card-arrow">▸</div>
            </summary>

            <div class="admin-card-body">
                <div class="admin-tour-actions">
                    <a href="tournament.php?id=<?= (int)$t['id'] ?>" class="admin-btn admin-btn-primary" target="_blank">👁 Открыть</a>
                    <?php if ($t['status'] === 'upcoming'): ?>
                        <form method="POST" onsubmit="return confirm('Запустить?');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="force_start_tournament">
                            <input type="hidden" name="tournament_id" value="<?= (int)$t['id'] ?>">
                            <button class="admin-btn admin-btn-success">⚔ Запустить</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($t['status'], ['upcoming','active'])): ?>
                        <form method="POST" onsubmit="return confirm('Отменить?');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="cancel_tournament">
                            <input type="hidden" name="tournament_id" value="<?= (int)$t['id'] ?>">
                            <button class="admin-btn admin-btn-warn">❌ Отменить</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (in_array($t['status'], ['upcoming','draft'])): ?>
                    <div class="admin-subtitle">✏ Редактирование</div>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="edit_tournament">
                        <input type="hidden" name="tournament_id" value="<?= (int)$t['id'] ?>">
                        <div class="admin-field"><label>Название</label><input type="text" name="name" value="<?= e($t['name']) ?>" required></div>
                        <div class="admin-grid">
                            <div class="admin-field"><label>Начало</label><input type="datetime-local" name="start_at" value="<?= e($startDt) ?>" required></div>
                            <div class="admin-field"><label>Окончание</label><input type="datetime-local" name="end_at" value="<?= e($endDt) ?>" required></div>
                        </div>
                        <div class="admin-checkbox-grid" style="grid-template-columns:1fr 1fr;">
                            <?php foreach (['all'=>'🌐 Все','levels'=>'⭐ По ур.','classes'=>'🏷 По классам','brands'=>'🚗 По маркам'] as $k=>$l): ?>
                                <label class="admin-checkbox">
                                    <input type="radio" name="tournament_type" value="<?= $k ?>" <?= $tType === $k ? 'checked' : '' ?> onchange="toggleTT(<?= (int)$t['id'] ?>, this.value)">
                                    <span><?= $l ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="tour-type-block" id="tt_levels_<?= (int)$t['id'] ?>" style="<?= $tType==='levels' ? 'display:block;' : 'display:none;' ?>margin-top:8px;">
                            <div class="admin-grid">
                                <div class="admin-field"><label>Мин. ур.</label><input type="number" name="min_level" value="<?= (int)$t['min_level'] ?>" min="1" max="50"></div>
                                <div class="admin-field"><label>Макс. ур.</label><input type="number" name="max_level" value="<?= (int)$t['max_level'] ?>" min="1" max="50"></div>
                            </div>
                        </div>
                        <div class="tour-type-block" id="tt_classes_<?= (int)$t['id'] ?>" style="<?= $tType==='classes' ? 'display:block;' : 'display:none;' ?>margin-top:8px;">
                            <div class="admin-checkbox-grid">
                                <?php foreach (['E','D','C','B','A','S'] as $cl): ?>
                                    <label class="admin-checkbox admin-checkbox-item"><input type="checkbox" name="allowed_classes[]" value="<?= $cl ?>" <?= in_array($cl, $acArr) ? 'checked' : '' ?>><span>Класс <?= $cl ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="tour-type-block" id="tt_brands_<?= (int)$t['id'] ?>" style="<?= $tType==='brands' ? 'display:block;' : 'display:none;' ?>margin-top:8px;">
                            <div class="admin-checkbox-grid">
                                <?php foreach ($allBrands as $b): ?>
                                    <label class="admin-checkbox admin-checkbox-item"><input type="checkbox" name="allowed_brands[]" value="<?= e($b) ?>" <?= in_array($b, $abArr) ? 'checked' : '' ?>><span><?= e($b) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="admin-field" style="margin-top:8px;"><label>Описание</label><textarea name="description" rows="2"><?= e($t['description']) ?></textarea></div>
                        <div class="admin-grid">
                            <div class="admin-field"><label>Взнос</label><input type="number" name="entry_fee" value="<?= (int)$t['entry_fee'] ?>" min="0"></div>
                            <div class="admin-field"><label>Призовых</label><input type="number" name="top_places" value="<?= (int)$t['top_places'] ?>" min="1" max="10000"></div>
                            <div class="admin-field"><label>🥇</label><input type="number" name="prize_1" value="<?= (int)$t['prize_1'] ?>" min="0"></div>
                            <div class="admin-field"><label>🥈</label><input type="number" name="prize_2" value="<?= (int)$t['prize_2'] ?>" min="0"></div>
                            <div class="admin-field"><label>🥉</label><input type="number" name="prize_3" value="<?= (int)$t['prize_3'] ?>" min="0"></div>
                            <div class="admin-field"><label>🎁 4+</label><input type="number" name="prize_other" value="<?= (int)$t['prize_other'] ?>" min="0"></div>
                        </div>
                        <button type="submit" class="admin-btn admin-btn-primary admin-btn-full">💾 Сохранить</button>
                    </form>
                <?php else: ?>
                    <div class="admin-warning">Редактирование доступно только для «Анонс»</div>
                <?php endif; ?>

                <form method="POST" onsubmit="return confirm('Удалить?');" style="margin-top:8px;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_tournament">
                    <input type="hidden" name="tournament_id" value="<?= (int)$t['id'] ?>">
                    <button class="admin-btn admin-btn-danger admin-btn-full">🗑 Удалить</button>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============ СЕЗОНЫ ============ -->
<?php if ($tab === 'seasons' && hasPermission($user, 'manage_cars')): ?>
    <details class="admin-card">
        <summary class="admin-card-summary">
            <div class="admin-card-avatar" style="background:linear-gradient(135deg,#f0c674,#d29922);">🌟</div>
            <div class="admin-card-info"><div class="admin-card-nick">Создать сезон</div><div class="admin-card-meta">Новый игровой сезон</div></div>
            <div class="admin-card-arrow">▸</div>
        </summary>
        <div class="admin-card-body">
            <form method="POST" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_season">

                <div class="admin-subtitle" style="margin-top:0;">📝 Основное</div>
                <div class="admin-field"><label>Название *</label><input type="text" name="name" required placeholder="Октябрь 2026" maxlength="100"></div>
                <div class="admin-field"><label>Описание</label><textarea name="description" rows="2" placeholder="Например: Эксклюзивная машина сезона!"></textarea></div>
                <div class="admin-grid">
                    <div class="admin-field"><label>Начало (дата) *</label><input type="date" name="start_at" required></div>
                    <div class="admin-field"><label>Окончание (дата) *</label><input type="date" name="end_at" required></div>
                </div>

                <div class="admin-subtitle">🚗 Машина сезона</div>
                <div class="admin-field">
                    <label>ID машины из каталога *</label>
                    <select name="car_id" required>
                        <option value="">— выбери машину —</option>
                        <?php foreach ($cars as $c): ?>
                            <option value="<?= e($c['id']) ?>"><?= e($c['brand'] . ' ' . $c['name']) ?> (#<?= e($c['id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-grid">
                    <div class="admin-field"><label>Открывается на уровне</label><input type="number" name="car_unlock_level" value="10" min="1" max="50"></div>
                </div>
                <div style="font-size:10px;color:#8b949e;margin-top:4px;text-align:left;">
                    Машина выдаётся бесплатно при достижении указанного уровня сезона.
                </div>

                <button type="submit" class="admin-btn admin-btn-warn admin-btn-full" style="margin-top:10px;">🌟 Создать сезон</button>
            </form>
        </div>
    </details>

    <div class="admin-section-title">🌟 Сезоны (<?= count($seasons) ?>)</div>

    <div class="admin-player-list">
    <?php foreach ($seasons as $s):
        $isActive = !empty($s['is_active']);
        $today = date('Y-m-d');
        $statusText = '';
        $statusClass = '';
        if (!$isActive) { $statusText = '⏸ ОТКЛЮЧЁН'; $statusClass = 'status-finished'; }
        elseif ($s['start_at'] > $today) { $statusText = '📅 СКОРО'; $statusClass = 'status-upcoming'; }
        elseif ($s['end_at'] < $today)   { $statusText = '🏁 ЗАВЕРШЁН'; $statusClass = 'status-finished'; }
        else { $statusText = '⚔ АКТИВЕН'; $statusClass = 'status-active'; }

        $stmt = $pdo->prepare("SELECT * FROM season_tasks WHERE season_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$s['id']]);
        $sTasks = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM season_rewards WHERE season_id = ? ORDER BY level ASC");
        $stmt->execute([$s['id']]);
        $sRewards = $stmt->fetchAll();
    ?>
        <details class="admin-card">
            <summary class="admin-card-summary">
                <div class="admin-card-avatar" style="background:linear-gradient(135deg,#f0c674,#d29922);">🌟</div>
                <div class="admin-card-info">
                    <div class="admin-card-nick">
                        <?= e($s['name']) ?>
                        <span class="tour-card-status <?= $statusClass ?>"><?= $statusText ?></span>
                    </div>
                    <div class="admin-card-meta">
                        #<?= (int)$s['id'] ?> · <?= date('d.m.Y', strtotime($s['start_at'])) ?> — <?= date('d.m.Y', strtotime($s['end_at'])) ?>
                        · <?= e($s['car_brand'] . ' ' . $s['car_name']) ?>
                        · уровень <?= (int)$s['car_unlock_level'] ?>
                        · заданий: <?= count($sTasks) ?> · призов: <?= count($sRewards) ?>
                    </div>
                </div>
                <div class="admin-card-arrow">▸</div>
            </summary>

            <div class="admin-card-body">
                <div class="admin-subtitle" style="margin-top:0;">✏ Сезон</div>
                <form method="POST" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="edit_season">
                    <input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>">

                    <div class="admin-field"><label>Название</label><input type="text" name="name" value="<?= e($s['name']) ?>" required></div>
                    <div class="admin-field"><label>Описание</label><textarea name="description" rows="2"><?= e($s['description']) ?></textarea></div>
                    <div class="admin-grid">
                        <div class="admin-field"><label>Начало</label><input type="date" name="start_at" value="<?= e($s['start_at']) ?>" required></div>
                        <div class="admin-field"><label>Окончание</label><input type="date" name="end_at" value="<?= e($s['end_at']) ?>" required></div>
                    </div>
                    <div class="admin-field">
                        <label>Машина сезона</label>
                        <select name="car_id" required>
                            <?php foreach ($cars as $c): ?>
                                <option value="<?= e($c['id']) ?>" <?= $s['car_id'] === $c['id'] ? 'selected' : '' ?>><?= e($c['brand'] . ' ' . $c['name']) ?> (#<?= e($c['id']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-grid">
                        <div class="admin-field"><label>Открывается на уровне</label><input type="number" name="car_unlock_level" value="<?= (int)$s['car_unlock_level'] ?>" min="1" max="50"></div>
                    </div>
                    <div style="font-size:10px;color:#8b949e;margin-top:4px;text-align:left;">
                        Машина выдаётся бесплатно на этом уровне сезона.
                    </div>
                    <label class="admin-checkbox" style="margin-top:8px;"><input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>><span>Активен</span></label>
                    <button type="submit" class="admin-btn admin-btn-primary admin-btn-full">💾 Сохранить</button>
                </form>

                <div class="admin-tour-actions" style="margin-top:8px;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_season">
                        <input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>">
                        <button class="admin-btn admin-btn-warn">🔄 <?= $isActive ? 'Отключить' : 'Включить' ?></button>
                    </form>
                </div>

                <!-- ЗАДАНИЯ -->
                <div class="admin-subtitle">📋 Задания (<?= count($sTasks) ?>)</div>

                <?php if (!empty($sTasks)): foreach ($sTasks as $st):
                    $typeLabel = [
                        'race'      => '🏁 Гонки',
                        'win'       => '🏆 Победы',
                        'tour_ride' => '⚔ Заезды в турнирах',
                        'tour_win'  => '🥇 Победы в турнирах',
                        'buy_car'   => '🚗 Покупки машин',
                    ][$st['type']] ?? $st['type'];
                ?>
                    <details class="admin-mini-card">
                        <summary class="admin-mini-summary">
                            <div class="admin-mini-title">📋 <?= e($st['title']) ?></div>
                            <div class="admin-mini-meta">
                                <?= $typeLabel ?> · <?= (int)$st['target'] ?> · +<?= (int)$st['xp_reward'] ?> XP<?= (float)($st['usdt_reward'] ?? 0) > 0 ? ' · +' . formatUSDT($st['usdt_reward']) . ' 💵' : '' ?>
                            </div>
                            <div class="admin-mini-arrow">▸</div>
                        </summary>
                        <div class="admin-mini-body">
                            <form method="POST" class="admin-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="edit_task">
                                <input type="hidden" name="task_id" value="<?= (int)$st['id'] ?>">

                                <div class="admin-field"><label>Название</label><input type="text" name="title" value="<?= e($st['title']) ?>" required></div>
                                <div class="admin-field"><label>Описание</label><input type="text" name="description" value="<?= e($st['description']) ?>"></div>
                                <div class="admin-grid">
                                    <div class="admin-field">
                                        <label>Тип</label>
                                        <select name="type">
                                            <option value="race"      <?= $st['type']==='race'?'selected':'' ?>>🏁 Гонки</option>
                                            <option value="win"       <?= $st['type']==='win'?'selected':'' ?>>🏆 Победы</option>
                                            <option value="tour_ride" <?= $st['type']==='tour_ride'?'selected':'' ?>>⚔ Заезды в турнирах</option>
                                            <option value="tour_win"  <?= $st['type']==='tour_win'?'selected':'' ?>>🥇 Победы в турнирах</option>
                                            <option value="buy_car"   <?= $st['type']==='buy_car'?'selected':'' ?>>🚗 Покупки машин</option>
                                        </select>
                                    </div>
                                    <div class="admin-field"><label>Цель</label><input type="number" name="target" value="<?= (int)$st['target'] ?>" min="1"></div>
                                </div>
                                <div class="admin-grid">
                                    <div class="admin-field"><label>XP</label><input type="number" name="xp_reward" value="<?= (int)$st['xp_reward'] ?>" min="1"></div>
                                    <div class="admin-field"><label>USDT</label><input type="number" name="usdt_reward" value="<?= (int)($st['usdt_reward'] ?? 0) ?>" min="0"></div>
                                </div>
                                <button type="submit" class="admin-btn admin-btn-primary admin-btn-full">💾 Сохранить</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Удалить задание?');" style="margin-top:6px;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?= (int)$st['id'] ?>">
                                <button class="admin-btn admin-btn-danger admin-btn-full">🗑 Удалить задание</button>
                            </form>
                        </div>
                    </details>
                <?php endforeach; endif; ?>

                <form method="POST" class="admin-form" style="margin-top:8px;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_task">
                    <input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>">

                    <div class="admin-field"><label>Название задания *</label><input type="text" name="title" required placeholder="Первые шаги"></div>
                    <div class="admin-field"><label>Описание</label><input type="text" name="description" placeholder="Сделай 10 гонок"></div>
                    <div class="admin-grid">
                        <div class="admin-field">
                            <label>Тип</label>
                            <select name="type">
                                <option value="race">🏁 Гонки (кол-во)</option>
                                <option value="win">🏆 Победы</option>
                                <option value="tour_ride">⚔ Заезды в турнирах</option>
                                <option value="tour_win">🥇 Победы в турнирах</option>
                                <option value="buy_car">🚗 Покупки машин</option>
                            </select>
                        </div>
                        <div class="admin-field"><label>Цель</label><input type="number" name="target" value="10" min="1"></div>
                    </div>
                    <div class="admin-grid">
                        <div class="admin-field"><label>XP за выполнение</label><input type="number" name="xp_reward" value="20" min="1"></div>
                        <div class="admin-field"><label>USDT за выполнение</label><input type="number" name="usdt_reward" value="0" min="0"></div>
                    </div>

                    <button type="submit" class="admin-btn admin-btn-success admin-btn-full">➕ Добавить задание</button>
                </form>

                <!-- ПРИЗЫ -->
                <div class="admin-subtitle">💰 Призы за уровни (<?= count($sRewards) ?>)</div>

                <?php if (!empty($sRewards)): foreach ($sRewards as $sr): ?>
                    <details class="admin-mini-card">
                        <summary class="admin-mini-summary">
                            <div class="admin-mini-title">💰 Уровень <?= (int)$sr['level'] ?></div>
                            <div class="admin-mini-meta">
                                +<?= formatUSDT($sr['usdt']) ?> 💵<?= !empty($sr['description']) ? ' · ' . e($sr['description']) : '' ?>
                            </div>
                            <div class="admin-mini-arrow">▸</div>
                        </summary>
                        <div class="admin-mini-body">
                            <form method="POST" class="admin-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="edit_reward">
                                <input type="hidden" name="reward_id" value="<?= (int)$sr['id'] ?>">

                                <div class="admin-grid">
                                    <div class="admin-field"><label>Уровень</label><input type="number" name="level" value="<?= (int)$sr['level'] ?>" min="1" max="50"></div>
                                    <div class="admin-field"><label>USDT</label><input type="number" name="usdt" value="<?= (int)$sr['usdt'] ?>" min="0"></div>
                                </div>
                                <div class="admin-field"><label>Описание (опц.)</label><input type="text" name="description" value="<?= e($sr['description']) ?>" placeholder="Например: Главный приз"></div>

                                <button type="submit" class="admin-btn admin-btn-primary admin-btn-full">💾 Сохранить</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Удалить приз?');" style="margin-top:6px;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_reward">
                                <input type="hidden" name="reward_id" value="<?= (int)$sr['id'] ?>">
                                <button class="admin-btn admin-btn-danger admin-btn-full">🗑 Удалить приз</button>
                            </form>
                        </div>
                    </details>
                <?php endforeach; endif; ?>

                <form method="POST" class="admin-form" style="margin-top:8px;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_reward">
                    <input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>">

                    <div class="admin-grid">
                        <div class="admin-field"><label>Уровень</label><input type="number" name="level" value="1" min="1" max="50"></div>
                        <div class="admin-field"><label>USDT</label><input type="number" name="usdt" value="100" min="0"></div>
                    </div>
                    <div class="admin-field"><label>Описание (опц.)</label><input type="text" name="description" placeholder="Приз 1-го сезона"></div>

                    <button type="submit" class="admin-btn admin-btn-success admin-btn-full">➕ Добавить приз</button>
                </form>

                <form method="POST" onsubmit="return confirm('Удалить сезон полностью?');" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_season">
                    <input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>">
                    <button class="admin-btn admin-btn-danger admin-btn-full">🗑 Удалить сезон</button>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="content" style="padding:15px;">
    <a href="garage.php" class="btn btn-block">← Вернуться в игру</a>
</div>

<?php include 'footer.php'; ?>