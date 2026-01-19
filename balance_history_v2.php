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
        'data' => ['error' => 'Database connection failed']
    ]);
    exit;
}

// POST verisini al
$input = file_get_contents('php://input');
$request = json_decode($input, true);

$params = $request['params'] ?? [];
$rid = $request['rid'] ?? uniqid();
$where = $params['where'] ?? [];
$type = $where['type'] ?? null;
$from_date = $where['from_date'] ?? null;
$to_date = $where['to_date'] ?? null;

// User ID'yi session'dan veya istekten al
$user_id = $_SESSION['user_id'] ?? $request['user_id'] ?? null;

try {
    $allData = [];
    
    // 1. YATIRIM İŞLEMLERİ (type: 3) - parayatir tablosu
    if ($type === null || $type === 999 || $type === 3) {
        $sql = "SELECT * FROM parayatir WHERE 1=1";
        $bindings = [];
        
        if ($user_id) {
            $sql .= " AND user_id = ?";
            $bindings[] = $user_id;
        }
        
        if ($from_date !== null && $to_date !== null) {
            $sql .= " AND UNIX_TIMESTAMP(tarih) >= ? AND UNIX_TIMESTAMP(tarih) <= ?";
            $bindings[] = $from_date;
            $bindings[] = $to_date;
        }
        
        $sql .= " AND durum = 2 ORDER BY tarih DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($deposits as $deposit) {
            $allData[] = [
                'Amount' => (float)$deposit['miktar'],
                'Balance' => 0,
                'DocumentId' => (int)$deposit['id'],
                'TransactionId' => $deposit['referans'],
                'DocumentTypeId' => 3,
                'DocumentTypeName' => 'Yatırım',
                'Created' => strtotime($deposit['tarih']),
                'Game' => null,
                'ProductCategoryId' => null,
                'Product' => null,
                'PaymentSystemId' => null,
                'PaymentSystemName' => ucfirst($deposit['tur']),
                'BuddyId' => null,
                'BuddyLogin' => null,
                'ThirdPartyId' => $deposit['token'],
                'HasChildren' => false
            ];
        }
    }
    
    // 2. ÇEKİM İŞLEMLERİ (type: 1) - paracek tablosu
    if ($type === null || $type === 999 || $type === 1) {
        $sql = "SELECT * FROM paracek WHERE 1=1";
        $bindings = [];
        
        if ($user_id) {
            $sql .= " AND user_id = ?";
            $bindings[] = $user_id;
        }
        
        if ($from_date !== null && $to_date !== null) {
            $sql .= " AND UNIX_TIMESTAMP(tarih) >= ? AND UNIX_TIMESTAMP(tarih) <= ?";
            $bindings[] = $from_date;
            $bindings[] = $to_date;
        }
        
        $sql .= " AND durum = 2 ORDER BY tarih DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($withdrawals as $withdrawal) {
            $allData[] = [
                'Amount' => -(float)$withdrawal['miktar'],
                'Balance' => 0,
                'DocumentId' => (int)$withdrawal['id'],
                'TransactionId' => $withdrawal['token'] ?? 'WD' . $withdrawal['id'],
                'DocumentTypeId' => 1,
                'DocumentTypeName' => 'Çekim Talebi',
                'Created' => strtotime($withdrawal['tarih']),
                'Game' => null,
                'ProductCategoryId' => null,
                'Product' => null,
                'PaymentSystemId' => null,
                'PaymentSystemName' => ucfirst($withdrawal['turi']),
                'BuddyId' => null,
                'BuddyLogin' => null,
                'ThirdPartyId' => $withdrawal['iban'],
                'HasChildren' => false
            ];
        }
    }
    
    // 3. CASINO İŞLEMLERİ (type: 131) - transactions tablosu
    if ($type === null || $type === 999 || $type === 131) {
        $sql = "SELECT * FROM transactions WHERE 1=1";
        $bindings = [];
        
        if ($user_id) {
            $sql .= " AND user_id = ?";
            $bindings[] = $user_id;
        }
        
        if ($from_date !== null && $to_date !== null) {
            $sql .= " AND UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) <= ?";
            $bindings[] = $from_date;
            $bindings[] = $to_date;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 500";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($transactions as $transaction) {
            $amount = 0;
            $documentTypeName = '';
            
            if ($transaction['type'] === 'bet') {
                // Bahis - negatif miktar
                $amount = -(float)$transaction['amount'];
                $documentTypeName = 'Casino Bahis';
            } elseif ($transaction['type'] === 'win') {
                // Kazanç - pozitif miktar
                $amount = (float)$transaction['type_money'];
                $documentTypeName = 'Casino Kazanç';
            } else {
                continue; // Diğer tipleri atla
            }
            
            $allData[] = [
                'Amount' => $amount,
                'Balance' => 0,
                'DocumentId' => (int)$transaction['id'],
                'TransactionId' => $transaction['transaction_id'],
                'DocumentTypeId' => 131,
                'DocumentTypeName' => $documentTypeName,
                'Created' => strtotime($transaction['created_at']),
                'Game' => $transaction['game'] ?? 'Casino',
                'ProductCategoryId' => 2,
                'Product' => ucfirst($transaction['providers'] ?? 'Casino'),
                'PaymentSystemId' => null,
                'PaymentSystemName' => null,
                'BuddyId' => null,
                'BuddyLogin' => null,
                'ThirdPartyId' => $transaction['round_id'],
                'HasChildren' => false
            ];
        }
    }
    
    // Tarihe göre sırala (en yeni en üstte)
    usort($allData, function($a, $b) {
        return $b['Created'] - $a['Created'];
    });
    
    $filteredData = $allData;

    // Response oluştur
    $response = [
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'result' => 0,
            'result_text' => 'Success',
            'details' => $filteredData
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch(PDOException $e) {
    error_log("balance_history_v2.php error: " . $e->getMessage());
    
    echo json_encode([
        'code' => 1,
        'rid' => $rid,
        'data' => [
            'result' => 1,
            'result_text' => 'Database error: ' . $e->getMessage(),
            'details' => []
        ]
    ]);
}
