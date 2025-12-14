<?php
session_start();
require_once '../includes/config.php';

// Force JSON response
header('Content-Type: application/json; charset=utf-8');

// Check login
if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
if(!$data){
    echo json_encode(['success'=>false,'message'=>'No data received']);
    exit;
}

$in_id = isset($data['in_id']) ? intval($data['in_id']) : null;
$out_id = isset($data['out_id']) ? intval($data['out_id']) : null;
$time_in = $data['time_in'] ?? null;
$time_out = $data['time_out'] ?? null;
$hours_decimal = floatval($data['hours_decimal'] ?? 0);
$salary = floatval($data['salary'] ?? 0);
$ot_salary = floatval($data['ot_salary'] ?? 0);

try{
    if($in_id && $time_in){
        $stmt = $pdo->prepare("UPDATE dtr_logs 
            SET timestamp=:ts, working_hours=:wh, salary=:s, ot_salary=:ot, is_edited=1 
            WHERE id=:id AND action='time_in'
        ");
        $stmt->execute([
            ':ts'=>date('Y-m-d H:i:s', strtotime($time_in)),
            ':wh'=>$hours_decimal,
            ':s'=>$salary,
            ':ot'=>$ot_salary,
            ':id'=>$in_id
        ]);
    }

    if($out_id && $time_out){
        $stmt = $pdo->prepare("UPDATE dtr_logs 
            SET timestamp=:ts, is_edited=1 
            WHERE id=:id AND action='time_out'
        ");
        $stmt->execute([
            ':ts'=>date('Y-m-d H:i:s', strtotime($time_out)),
            ':id'=>$out_id
        ]);
    }

    echo json_encode(['success'=>true]);
}catch(PDOException $e){
    // Catch any DB errors
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
