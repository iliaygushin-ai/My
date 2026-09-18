<?php
if (!defined('DB_HOST')) { http_response_code(403); die('Access denied'); }
$pageTitle = $pageTitle ?? GAME_COPYRIGHT;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= e($pageTitle) ?> — <?= GAME_COPYRIGHT ?></title>
    <link rel="stylesheet" href="style.css?v=3">
</head>
<body>
<div class="wap-container">
    <div class="header">
        <?= GAME_NAME_1 ?><br><span><?= GAME_NAME_2 ?></span>
    </div>