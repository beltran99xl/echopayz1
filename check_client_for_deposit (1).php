<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id');

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
        'result' => 1,
        'details' => [
            'StepId' => null,
            'State' => null
        ],
        'message' => 'Database connection failed'
    ]);
    exit;
}

// Yardımcı fonksiyonu dahil et
require_once 'get_user_id.php';

// POST verilerini al
$input = json_decode(file_get_contents('php://input'), true);

// Owner ID'yi al
$owner_id = getUserIdFromRequest($pdo);

if (!$owner_id) {
    echo json_encode([
        'result' => 1,
        'details' => [
            'StepId' => null,
            'State' => null
        ],
        'message' => 'User not found'
    ]);
    exit;
}

try {
    // Kullanıcıyı kontrol et
    $query = "SELECT * FROM kullanicilar WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$owner_id]);
    
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'result' => 1,
            'details' => [
                'StepId' => null,
                'State' => null
            ],
            'message' => 'User not found'
        ]);
        exit;
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Kullanıcı banned mi kontrol et
    if ($user['is_banned'] == 1) {
        echo json_encode([
            'result' => 1,
            'details' => [
                'StepId' => 21,
                'State' => 5
            ],
            'message' => 'Account is banned'
        ]);
        exit;
    }
    
    // Şu an için aktif step yoksa null döndür
    // İleride terms and conditions gibi kontroller eklenebilir
    echo json_encode([
        'result' => 0,
        'details' => [
            'StepId' => null,
            'State' => null
        ],
        'message' => 'OK'
    ]);
    
} catch(PDOException $e) {
    echo json_encode([
        'result' => 1,
        'details' => [
            'StepId' => null,
            'State' => null
        ],
        'message' => 'Database query failed: ' . $e->getMessage()
    ]);
}
?>

