<?php
require_once 'config.php';
require_once 'functions.php';

startSecureSession();

// Уже вошёл — редирект
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT car_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $carId = $stmt->fetchColumn();
    header('Location: ' . ($carId ? 'garage.php' : 'choose_car.php'));
    exit;
}

$error = '';
$success = '';
$action = (($_GET['action'] ?? 'login') === 'register') ? 'register' : 'login';

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'timeout') $error = 'Сессия истекла. Войдите заново.';
    if ($_GET['error'] === 'session') $error = 'Ошибка безопасности. Войдите заново.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $formAction = $_POST['action'] ?? 'login';

        // ==========================================
        // РЕГИСТРАЦИЯ
        // ==========================================
        if ($formAction === 'register') {
            $action = 'register';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($username === '' || $password === '' || $confirm === '') {
                $error = 'Заполните все поля!';
            } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                $error = 'Логин: 3-20 символов (латиница, цифры, _)';
            } elseif (strlen($password) < 4) {
                $error = 'Пароль минимум 4 символа!';
            } elseif ($password !== $confirm) {
                $error = 'Пароли не совпадают!';
            } else {
                // Проверка на занятость
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = 'Этот логин уже занят!';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, usdt) VALUES (?, ?, 500)");
                    if ($stmt->execute([$username, $hash])) {
                        $newId = (int)$pdo->lastInsertId();
                        session_regenerate_id(true);
                        $_SESSION['user_id']      = $newId;
                        $_SESSION['username']     = $username;
                        $_SESSION['fingerprint']  = getFingerprint();
                        $_SESSION['last_activity']= time();
                        $_SESSION['regenerated']  = time();
                        header('Location: choose_car.php');
                        exit;
                    }
                    $error = 'Ошибка регистрации. Попробуйте позже.';
                }
            }
        }
        // ==========================================
        // ВХОД
        // ==========================================
        elseif ($formAction === 'login') {
            if ($username === '' || $password === '') {
                $error = 'Введите логин и пароль!';
            } else {
                $stmt = $pdo->prepare("SELECT id, username, password, car_id FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']      = (int)$user['id'];
                    $_SESSION['username']     = $user['username'];
                    $_SESSION['fingerprint']  = getFingerprint();
                    $_SESSION['last_activity']= time();
                    $_SESSION['regenerated']  = time();
                    header('Location: ' . ($user['car_id'] ? 'garage.php' : 'choose_car.php'));
                    exit;
                }
                $error = 'Неверный логин или пароль!';
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Вход';
?>
<?php include 'header.php'; ?>

<div class="nav-tabs">
    <a href="?action=login"    class="<?= $action === 'login'    ? 'active' : '' ?>">Вход</a>
    <a href="?action=register" class="<?= $action === 'register' ? 'active' : '' ?>">Регистрация</a>
</div>

<div class="content">
    <?php if ($error): ?><div class="message error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="message success"><?= e($success) ?></div><?php endif; ?>

    <?php if ($action === 'login'): ?>
        <form method="POST" action="auth.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Логин</label>
                <input type="text" name="username" required autocomplete="off" maxlength="20" placeholder="Ваш никнейм">
            </div>
            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" required autocomplete="off" placeholder="Ваш пароль">
            </div>
            <button type="submit" class="btn btn-block">Войти в игру</button>
        </form>
    <?php else: ?>
        <form method="POST" action="auth.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="register">
            <div class="form-group">
                <label>Логин</label>
                <input type="text" name="username" required autocomplete="off" maxlength="20" pattern="[a-zA-Z0-9_]{3,20}" placeholder="От 3 до 20 символов">
            </div>
            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" required autocomplete="off" minlength="4" placeholder="Минимум 4 символа">
            </div>
            <div class="form-group">
                <label>Подтвердите пароль</label>
                <input type="password" name="confirm_password" required autocomplete="off" minlength="4" placeholder="Повторите пароль">
            </div>
            <button type="submit" class="btn btn-block">Создать аккаунт</button>
        </form>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>