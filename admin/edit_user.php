<?php
session_start();
require_once '../includes/config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = $_POST['id'];
    $username = $_POST['username'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];
    $position = $_POST['position'];  
    $salary = $_POST['salary'];
    $overtime_rate = $_POST['overtime_rate']; // Added OT rate

    if ($id && $username && $phone && $role && $position && $salary && $overtime_rate !== '') {

        $stmt = $pdo->prepare("
            UPDATE users 
            SET username = ?, phone = ?, role = ?, position = ?, salary = ?, overtime_rate = ?
            WHERE id = ?
        ");

        $stmt->execute([$username, $phone, $role, $position, $salary, $overtime_rate, $id]);

        header("Location: user_management.php?edited=success");
        exit;

    } else {
        header("Location: user_management.php?edited=error");
        exit;
    }

} else {
    header("Location: user_management.php");
    exit;
}
