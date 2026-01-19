<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// POST verisini al
$input = file_get_contents('php://input');
$request = json_decode($input, true);

$params = $request['params'] ?? [];
$rid = $request['rid'] ?? uniqid();
$id = $params['id'] ?? null;

// İptal edilemeyecek ID'ler (örnek: ödeme yapılmış olanlar)
$cannotCancelIds = [5002]; // Status=3 (Paid) olan

if ($id === null) {
    $response = [
        'code' => 1,
        'rid' => $rid,
        'result' => 1,
        'msg' => 'ID is required'
    ];
} elseif (in_array($id, $cannotCancelIds)) {
    // İptal edilemez
    $response = [
        'code' => 0,
        'rid' => $rid,
        'result' => 2070, // Bu kod frontend'de kontrol ediliyor
        'msg' => 'This type of Withdrawal Requests cannot be Cancelled'
    ];
} else {
    // Başarılı iptal
    $response = [
        'code' => 0,
        'rid' => $rid,
        'result' => 0,
        'result_status' => 'OK',
        'msg' => 'Withdrawal cancelled successfully'
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

