<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
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
        'code' => 1,
        'rid' => uniqid(),
        'result_status' => 'ERROR',
        'message' => 'Database connection failed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Yardımcı fonksiyonu dahil et
require_once 'get_user_id.php';

// POST verisini al
$input = file_get_contents('php://input');
$request = json_decode($input, true);

$rid = $request['rid'] ?? uniqid();

// Kullanıcı ID'sini al
$user_id = getUserIdFromRequest($pdo);

if (!$user_id) {
    echo json_encode([
        'code' => 1,
        'rid' => $rid,
        'result_status' => 'ERROR',
        'message' => 'User not authenticated'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Çekim taleplerini veritabanından al
    $query = "SELECT 
        id,
        miktar as amount,
        banka as payment_system,
        iban as account_number,
        durum as status,
        UNIX_TIMESTAMP(tarih) as created,
        turi as payment_type,
        aciklama as details,
        user_id,
        not as reject_reason,
        onay_zamani,
        geri_alma_suresi
    FROM paracek 
    WHERE user_id = ? 
    ORDER BY tarih DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ödeme sistem ID'lerini map'le (isteğe göre payments.json'dan alabilirsiniz)
    $paymentSystemIds = [
        'papara' => 5,
        'havale' => 3,
        'eft' => 3,
        'crypto' => 7,
        'btc' => 7,
        'payco' => 8
    ];

    $withdrawalRequests = [];
    foreach ($results as $row) {
        // Ödeme türüne göre payment_system_id belirle
        $paymentType = strtolower($row['payment_type'] ?? '');
        $paymentSystemId = $paymentSystemIds[$paymentType] ?? 1;

        $withdrawal = [
            'id' => (int)$row['id'],
            'amount' => (float)$row['amount'],
            'currency' => 'TRY',
            'status' => (int)$row['status'],
            'created' => (int)$row['created'],
            'payment_system' => $row['payment_system'],
            'payment_system_id' => $paymentSystemId,
            'account_number' => $row['account_number'] ?: '',
            'user_id' => (int)$row['user_id'],
            'details' => $row['details'] ?: ''
        ];

        // Reddedilmiş işlemler için ret sebebi ekle
        if ($row['status'] == -2 && !empty($row['reject_reason'])) {
            $withdrawal['reject_reason'] = $row['reject_reason'];
        }

        $withdrawalRequests[] = $withdrawal;
    }

    // Response oluştur
    $response = [
        'code' => 0,
        'rid' => $rid,
        'result_status' => 'OK',
        'withdrawal_requests' => [
            'request' => $withdrawalRequests
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch(PDOException $e) {
    echo json_encode([
        'code' => 1,
        'rid' => $rid,
        'result_status' => 'ERROR',
        'message' => 'Database query failed: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>