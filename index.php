<?php
require_once 'config.php';
require_once 'functions.php';

startSecureSession();

if (!empty($_SESSION['user_id'])) {
    // Проверяем куда идти — в гараж или на выбор машины
    $stmt = $pdo->prepare("SELECT car_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $carId = $stmt->fetchColumn();
    header('Location: ' . ($carId ? 'garage.php' : 'choose_car.php'));
} else {
    header('Location: auth.php');
}
exit;