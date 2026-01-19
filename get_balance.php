<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Veritabanı bağlantısı
$host = 'localhost';
$dbname = 'klasbet';
$username = 'klasbet';
$password = 'KlasBet9000.';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode([
        'code' => 0,
        'result' => '1',
        'result_text' => 'Database connection failed',
        'details' => ['Key' => 'Database connection error']
    ]);
    exit;
}

// POST verilerini al
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['user_id'])) {
    echo json_encode([
        'code' => 0,
        'result' => '1',
        'result_text' => 'User ID is required',
        'details' => ['Key' => 'Missing user ID']
    ]);
    exit;
}

$user_id = (int)$input['user_id'];

try {
    // Kullanıcının güncel bakiyesini al
    $query = "SELECT ana_bakiye, spor_bonus, casino_bonus, klas_poker FROM kullanicilar WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'code' => 0,
            'result' => '1',
            'result_text' => 'User not found',
            'details' => ['Key' => 'User not found']
        ]);
        exit;
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // WebSocket formatında yanıt döndür
    echo json_encode([
        'code' => 0,
        'rid' => uniqid(),
        'data' => [
            'result' => 0,
            'result_text' => null,
            'details' => [
                [
                    'ClientId' => $user_id,
                    'CurrencyId' => 'TRY',
                    'Balance' => (float)$user['ana_bakiye']
                ]
            ]
        ]
    ]);
    
} catch(PDOException $e) {
    echo json_encode([
        'code' => 0,
        'result' => '1',
        'result_text' => 'Database query failed',
        'details' => ['Key' => 'Database error']
    ]);
}
?>
