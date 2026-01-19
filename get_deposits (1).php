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

$rid = $request['rid'] ?? uniqid();

// Örnek yatırım talepleri
$depositRequests = [
    [
        'id' => 6001,
        'amount' => 500.00,
        'currency' => 'TRY',
        'status' => 3, // Paid
        'created' => time() - 7200, // 2 saat önce
        'payment_system' => 'Papara',
        'payment_system_id' => 5,
        'account_number' => '1234567890',
        'user_id' => 12345,
        'details' => 'Papara ile yatırım'
    ],
    [
        'id' => 6002,
        'amount' => 1000.00,
        'currency' => 'TRY',
        'status' => 3, // Paid
        'created' => time() - 86400, // 1 gün önce
        'payment_system' => 'Havale/EFT',
        'payment_system_id' => 3,
        'account_number' => 'TR123456789012345678901234',
        'user_id' => 12345,
        'details' => 'Banka havalesi'
    ],
    [
        'id' => 6003,
        'amount' => 250.00,
        'currency' => 'TRY',
        'status' => 2, // Pending
        'created' => time() - 1800, // 30 dakika önce
        'payment_system' => 'Papara',
        'payment_system_id' => 5,
        'account_number' => '9876543210',
        'user_id' => 12345,
        'details' => 'Bekleyen yatırım'
    ],
    [
        'id' => 6004,
        'amount' => 750.00,
        'currency' => 'TRY',
        'status' => 3, // Paid
        'created' => time() - 172800, // 2 gün önce
        'payment_system' => 'Kredi Kartı',
        'payment_system_id' => 1,
        'account_number' => '****1234',
        'user_id' => 12345,
        'details' => 'Kredi kartı ile yatırım'
    ],
    [
        'id' => 6005,
        'amount' => 300.00,
        'currency' => 'TRY',
        'status' => 0, // New
        'created' => time() - 900, // 15 dakika önce
        'payment_system' => 'Papara',
        'payment_system_id' => 5,
        'account_number' => '5555666677',
        'user_id' => 12345,
        'details' => 'Yeni yatırım talebi'
    ]
];

// Response oluştur
$response = [
    'code' => 0,
    'rid' => $rid,
    'result_status' => 'OK',
    'deposits_requests' => [
        'request' => $depositRequests
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);

