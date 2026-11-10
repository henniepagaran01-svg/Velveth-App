<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error'=>'unauthorized']); exit;
}
$app_id = (int)($_GET['app_id'] ?? 0);
if (!$app_id) { echo json_encode([]); exit; }

$docs = $conn->query("SELECT * FROM documents WHERE application_id=$app_id ORDER BY created_at ASC");
$result = [];
while ($d = $docs->fetch_assoc()) {
    $type = $d['doc_type'] ?? '';
    $isSelfieWithId = stripos($type, 'selfie with id') !== false;
    $result[] = [
        'id'        => $d['id'],
        'doc_type'  => $d['doc_type'],
        'file_name' => $d['file_name'],
        'file_path' => $d['file_path'],
        'status'    => $d['status'],
        'created_at'=> $d['created_at'],
        'is_image'  => preg_match('/\.(jpg|jpeg|png|gif)$/i', $d['file_name']??'') ? true : false,
        'is_selfie' => !$isSelfieWithId && stripos($type, 'selfie') !== false,
        'is_selfie_with_id' => $isSelfieWithId,
        'is_id'     => stripos($type, 'government id') !== false || stripos($type, 'id card') !== false,
    ];
}
echo json_encode($result);
