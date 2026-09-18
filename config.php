<?php
// ==========================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'iliaygtm_race');
define('DB_USER', 'iliaygtm_race');
define('DB_PASS', '!T5BExGA5i4&');

// ==========================================
// НАСТРОЙКИ ИГРЫ
// ==========================================
define('GAME_NAME_1', 'Мир');
define('GAME_NAME_2', 'Скорости');
define('GAME_COPYRIGHT', 'Мир Скорости');
define('GAME_YEAR', '2026');

// ==========================================
// БЕЗОПАСНОСТЬ
// ==========================================
define('SESSION_SALT', 'D:c<w[qaIU|4<9:Z+GhF){G}{YZpR]gW');
define('SESSION_LIFETIME', 259200);

// ==========================================
// ПОДКЛЮЧЕНИЕ К БД
// ==========================================
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,   // постоянное подключение (быстрее)
        ]
    );
} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    die('Сервер временно недоступен. Попробуйте позже.');
}