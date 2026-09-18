<?php
require_once 'config.php';
require_once 'functions.php';

$user = requireAuth($pdo);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Отправить сообщение ---
        if ($action === 'send') {
            if (!canUseChat($user)) {
                $error = '🚫 Чат доступен с ' . CHAT_MIN_LEVEL . ' уровня.';
            } elseif (isChatBanned($pdo, $user['id'])) {
                $error = '🚫 Вы забанены в чате.';
            } else {
                $text = trim($_POST['message'] ?? '');
                if (mb_strlen($text) < 1) {
                    $error = 'Сообщение пустое.';
                } elseif (mb_strlen($text) > 500) {
                    $error = 'Максимум 500 символов.';
                } elseif (!empty($user['last_message_at']) && (time() - strtotime($user['last_message_at'])) < 5) {
                    $error = '⏳ Подождите 5 сек.';
                } else {
                    $pdo->prepare("INSERT INTO chat_messages (user_id, message) VALUES (?, ?)")
                        ->execute([$user['id'], $text]);
                    $pdo->prepare("UPDATE users SET last_message_at = NOW() WHERE id = ?")
                        ->execute([$user['id']]);
                    header('Location: bar.php'); exit;
                }
            }
        }

        // --- Удалить сообщение ---
        if ($action === 'delete_message' && hasPermission($user, 'ban_users')) {
            $msgId = (int)($_POST['msg_id'] ?? 0);
            $pdo->prepare("UPDATE chat_messages SET is_deleted=1, deleted_by=?, deleted_at=NOW() WHERE id=?")
                ->execute([$user['id'], $msgId]);
            $success = '🗑 Сообщение удалено.';
        }

        // --- Забанить в чате ---
        if ($action === 'ban_chat' && hasPermission($user, 'ban_users')) {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $duration = (int)($_POST['duration'] ?? 0);
            $reason   = trim($_POST['reason'] ?? '');

            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();

            if ($targetId === (int)$user['id']) {
                $error = 'Нельзя забанить себя.';
            } elseif ((int)$targetId === SUPER_ADMIN_ID) {
                $error = 'Нельзя забанить главного админа.';
            } elseif ($target && (int)$target['role'] === ROLE_ADMIN && !isSuperAdmin($user)) {
                $error = 'Только супер-админ может банить админов.';
            } elseif ($target && (int)$target['role'] > 0 && !isAdmin($user)) {
                $error = 'Модератор не может банить персонал.';
            } else {
                chatBanUser($pdo, $targetId, $user['id'], $reason ?: null, $duration);
                $success = '🚫 Игрок забанен в чате.';
            }
        }
    }
}

$messages = getChatMessages($pdo, 50);
$myBan    = isChatBanned($pdo, $user['id']);
$isStaff  = hasPermission($user, 'ban_users');
$csrfToken = generateCsrfToken();
$pageTitle = 'Бар';
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
    <a href="bar.php" class="active">Бар</a>
    <a href="tournaments.php">Турниры</a>
</div>

<div class="section-header">
    <h2>🍺 Бар</h2>
    <a href="district.php" class="btn-add" style="background:linear-gradient(180deg,#484f58,#30363d);border-color:#484f58;font-size:14px;">←</a>
</div>

<?php if ($error): ?><div style="padding:10px 12px;"><div class="message error"><?= e($error) ?></div></div><?php endif; ?>
<?php if ($success): ?><div style="padding:10px 12px;"><div class="message success"><?= e($success) ?></div></div><?php endif; ?>

<?php if (!canUseChat($user)): ?>
    <div style="padding:15px;">
        <div class="message error">
            🔒 Чат доступен с <b><?= CHAT_MIN_LEVEL ?></b> уровня.<br>
            <small>Твой уровень: <?= (int)$user['level'] ?></small>
        </div>
    </div>
<?php elseif ($myBan): ?>
    <div class="chat-ban-banner">
        <div style="font-size:24px;">🚫</div>
        <div style="font-weight:bold;margin:6px 0;">Вы забанены в чате</div>
        <?php if (!empty($myBan['reason'])): ?>
            <div style="font-size:12px;color:#c9d1d9;">Причина: <?= e($myBan['reason']) ?></div>
        <?php endif; ?>
        <div style="font-size:11px;color:#8b949e;margin-top:6px;">
            <?php if (!empty($myBan['is_permanent'])): ?>
                Срок: <b style="color:#f85149;">навсегда</b>
            <?php else: ?>
                До: <b style="color:#d29922;"><?= date('d.m.Y H:i', strtotime($myBan['until_date'])) ?></b>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

    <form method="POST" class="chat-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="send">
        <input type="text" name="message" maxlength="500" placeholder="Написать..." required autocomplete="off">
        <button type="submit">➤</button>
    </form>

    <div class="chat-messages">
        <?php if (empty($messages)): ?>
            <div class="chat-empty">Пока сообщений нет. Будь первым!</div>
        <?php else: foreach ($messages as $m):
            $isDeleted = !empty($m['is_deleted']);
            $role      = (int)$m['role'];
            $authorId  = (int)($m['author_id'] ?? $m['user_id']);
            $isSuper   = ($authorId === SUPER_ADMIN_ID);
            $nickColor = getChatNickColor($role);
            $isMine    = ((int)$m['user_id'] === (int)$user['id']);

            $badge = '';
            if ($isSuper)                 $badge = '<span class="role-badge role-super">👑 SUPER</span>';
            elseif ($role === ROLE_ADMIN) $badge = '<span class="role-badge role-admin">👑 ADMIN</span>';
            elseif ($role === ROLE_MODERATOR) $badge = '<span class="role-badge role-mod">🛡 MOD</span>';
        ?>
            <div class="chat-msg <?= $isMine ? 'chat-msg-mine' : '' ?> <?= $isDeleted ? 'chat-msg-deleted' : '' ?>">
                <div class="chat-msg-head">
                    <span class="chat-nick" style="color:<?= e($nickColor) ?>;"><?= e($m['username']) ?></span>
                    <?= $badge ?>
                    <span class="chat-time"><?= date('H:i', strtotime($m['created_at'])) ?></span>
                    <?php if ($isStaff): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_message">
                            <input type="hidden" name="msg_id" value="<?= (int)$m['id'] ?>">
                            <button class="chat-del" title="Удалить">✕</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="chat-text">
                    <?= $isDeleted ? '<i style="color:#484f58;">сообщение удалено</i>' : nl2br(e($m['message'])) ?>
                </div>
                <?php if ($isStaff && !$isDeleted): ?>
                    <div class="chat-ban-row">
                        <form method="POST" class="chat-ban-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="ban_chat">
                            <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                            <select name="duration">
                                <?php foreach (getBanDurations() as $sec => $label): ?>
                                    <option value="<?= (int)$sec ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="reason" placeholder="Причина">
                            <button class="chat-ban-btn">🚫</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

<?php endif; ?>

<div class="content" style="padding:15px;">
    <a href="district.php" class="btn btn-block">← В район</a>
</div>

<?php include 'footer.php'; ?>