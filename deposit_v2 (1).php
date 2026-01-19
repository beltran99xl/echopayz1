<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Veritabanı bağlantısı (prod'ta env değişkenleri kullanın)
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
        'rid' => uniqid(),
        'data' => [
            'status' => 'error',
            'message' => 'Database connection failed'
        ]
    ]);
    exit;
}

// Yardımcı fonksiyonu dahil et
require_once 'get_user_id.php';

// POST verilerini al
$input = json_decode(file_get_contents('php://input'), true);

// RID oluştur (Request ID)
$rid = isset($input['rid']) ? $input['rid'] : uniqid();

// Parametreleri al
$params = isset($input['params']) ? $input['params'] : [];

// Amount kontrolü
if (!isset($params['amount']) || !is_numeric($params['amount'])) {
    echo json_encode([
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'status' => 'error',
            'message' => 'Amount is required'
        ]
    ]);
    exit;
}

$amount = (float)$params['amount'];
$service = isset($params['service']) ? (int)$params['service'] : null;
$payer = isset($params['payer']) ? $params['payer'] : [];

// Owner ID'yi al (header veya getUserIdFromRequest)
$owner_id = null;
$headers = getallheaders();
if (isset($headers['X-User-Id'])) {
    $owner_id = (int)$headers['X-User-Id'];
} elseif (isset($_SERVER['HTTP_X_USER_ID'])) {
    $owner_id = (int)$_SERVER['HTTP_X_USER_ID'];
}
if (!$owner_id && isset($payer['ownerId'])) {
    $owner_id = (int)$payer['ownerId'];
}
if (!$owner_id) {
    $owner_id = getUserIdFromRequest($pdo);
}

if (!$owner_id) {
    echo json_encode([
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'status' => 'error',
            'message' => 'Owner ID is required or session expired'
        ]
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
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'User not found'
            ]
        ]);
        exit;
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['is_banned'] == 1) {
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'Account is banned'
            ]
        ]);
        exit;
    }

    if (!$service) {
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'Payment method is required'
            ]
        ]);
        exit;
    }

    // payments.json dosyasını oku
    $payments_file = './payments.json';
    if (!file_exists($payments_file)) {
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'Payments configuration not found'
            ]
        ]);
        exit;
    }

    $payments_data = json_decode(file_get_contents($payments_file), true);
    if (!is_array($payments_data)) {
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'Invalid payments configuration'
            ]
        ]);
        exit;
    }

    // Payment method'u bul
    $payment_method = null;
    foreach ($payments_data as $method) {
        if (isset($method['paymentId']) && (int)$method['paymentId'] === $service) {
            $payment_method = $method;
            break;
        }
    }

    if (!$payment_method) {
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'Payment method not found'
            ]
        ]);
        exit;
    }

    // Provider tipi: iframe | api | manual (default manual)
    $provider = isset($payment_method['provider']) ? $payment_method['provider'] : 'manual';
    $provider_config = isset($payment_method['provider_config']) ? $payment_method['provider_config'] : [];

    // Generic iframe provider: frontend için action URL döndür
    if ($provider === 'iframe') {
        $action = $provider_config['action'] ?? ($payment_method['action'] ?? null);
        if (!$action) {
            echo json_encode([
                'code' => 0,
                'rid' => $rid,
                'data' => [
                    'status' => 'error',
                    'message' => 'Iframe action URL is not configured for this payment method'
                ]
            ]);
            exit;
        }

        // Optionally store a deposit request with token placeholder (status 0 pending)
        $insert_query = "INSERT INTO paracek (uye, banka, miktar, tarih, durum, turi, user_id, firma_key, token) 
                        VALUES (?, ?, ?, NOW(), 0, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([
            $user['username'] ?? ($user['firstName'] ?? 'User'),
            $payment_method['displayName'] ?? $payment_method['name'] ?? 'Unknown',
            $amount,
            $payment_method['name'] ?? 'deposit',
            $owner_id,
            '', // firma_key placeholder
            ''  // token placeholder
        ]);

        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'ok',
                'message' => null,
                'name' => $payment_method['name'] ?? '',
                'paymentId' => (string)$service,
                'action' => $action,
                'method' => $provider_config['method'] ?? 'GET',
                'fields' => $provider_config['fields'] ?? []
            ]
        ]);
        exit;
    }

    // Generic API provider: üçüncü taraf API çağrısı yap
    if ($provider === 'api') {
        if (empty($provider_config['api_url'])) {
            echo json_encode([
                'code' => 0,
                'rid' => $rid,
                'data' => [
                    'status' => 'error',
                    'message' => 'API provider is not configured (api_url missing)'
                ]
            ]);
            exit;
        }

        // Hazırla payload (provider spesifik alanları provider_config docs'ınıza göre ekleyin)
        $api_payload = [
            'amount' => $amount,
            'currency' => $provider_config['currency'] ?? 'TRY',
            'user_id' => (string)$owner_id,
            'user_name' => $user['username'] ?? '',
            'reference' => $provider_config['reference_prefix'] ?? 'DEP_' . time() . '_' . $owner_id
        ];
        // Merge payer extra fields if any
        if (!empty($payer) && is_array($payer)) {
            $api_payload = array_merge($api_payload, $payer);
        }

        $ch = curl_init(rtrim($provider_config['api_url'], '/') . ($provider_config['endpoint'] ?? '/deposit'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $json_payload = json_encode($api_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);

        $curl_headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        // Eğer provider_config içinde api_key veya headers varsa ekle
        if (isset($provider_config['api_key_header'])) {
            $curl_headers[] = $provider_config['api_key_header'] . ': ' . ($provider_config['api_key'] ?? '');
        } elseif (isset($provider_config['api_key'])) {
            // default header
            $curl_headers[] = 'X-API-KEY: ' . $provider_config['api_key'];
        }
        if (!empty($provider_config['headers']) && is_array($provider_config['headers'])) {
            foreach ($provider_config['headers'] as $k => $v) {
                $curl_headers[] = $k . ': ' . $v;
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $api_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            echo json_encode([
                'code' => 0,
                'rid' => $rid,
                'data' => [
                    'status' => 'error',
                    'message' => 'Provider API connection error: ' . $curl_error
                ]
            ]);
            exit;
        }

        if ($http_code < 200 || $http_code >= 300) {
            $err = $api_response;
            $decoded = json_decode($api_response, true);
            if (is_array($decoded) && isset($decoded['message'])) {
                $err = $decoded['message'];
            }
            echo json_encode([
                'code' => 0,
                'rid' => $rid,
                'data' => [
                    'status' => 'error',
                    'message' => 'Provider API request failed: ' . $err,
                    'http_code' => $http_code
                ]
            ]);
            exit;
        }

        $api_result = json_decode($api_response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Eğer JSON değilse raw dönebiliriz veya hata gösterebiliriz
            $api_result = ['raw' => $api_response];
        }

        // Veritabanına kaydet (token veya reference gelmişse)
        $token_or_ref = '';
        if (isset($api_result['token'])) $token_or_ref = $api_result['token'];
        if (isset($api_result['reference'])) $token_or_ref = $api_result['reference'];
        if (isset($api_result['data']['token'])) $token_or_ref = $api_result['data']['token'];

        $insert_query = "INSERT INTO paracek (uye, banka, miktar, tarih, durum, turi, user_id, firma_key, token) 
                        VALUES (?, ?, ?, NOW(), 0, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([
            $user['username'] ?? ($user['firstName'] ?? 'User'),
            $payment_method['displayName'] ?? $payment_method['name'] ?? 'Unknown',
            $amount,
            $payment_method['name'] ?? 'deposit',
            $owner_id,
            '', // firma_key placeholder
            $token_or_ref
        ]);

        // Eğer api_result içinde iframe link varsa döndür
        if (isset($api_result['iframe']['link'])) {
            echo json_encode([
                'code' => 0,
                'rid' => $rid,
                'data' => [
                    'status' => 'ok',
                    'name' => $payment_method['name'] ?? '',
                    'paymentId' => (string)$service,
                    'action' => $api_result['iframe']['link'],
                    'method' => $api_result['iframe']['method'] ?? 'GET',
                    'fields' => $api_result['iframe']['fields'] ?? []
                ]
            ]);
            exit;
        }

        // Varsa token döndür
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'ok',
                'name' => $payment_method['name'] ?? '',
                'paymentId' => (string)$service,
                'token' => $token_or_ref,
                'action' => $provider_config['return_url'] ?? null,
                'method' => 'GET',
                'fields' => []
            ]
        ]);
        exit;
    }

    // manual provider (default): veritabanına kaydet, admin onayı bekleyecek
    $insert_query = "INSERT INTO paracek (uye, banka, miktar, tarih, durum, turi, user_id, firma_key, iban, hesap) 
                    VALUES (?, ?, ?, NOW(), 0, ?, ?, ?, ?, ?)";
    $insert_stmt = $pdo->prepare($insert_query);
    $insert_stmt->execute([
        $user['username'] ?? ($user['firstName'] ?? 'User'),
        $payment_method['displayName'] ?? $payment_method['name'] ?? 'Manual',
        $amount,
        $payment_method['name'] ?? 'deposit',
        $owner_id,
        '', // firma_key
        $payer['iban'] ?? '',
        $payer['account_name'] ?? ''
    ]);

    echo json_encode([
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'status' => 'ok',
            'message' => 'Deposit request created (manual processing)',
            'name' => $payment_method['name'] ?? '',
            'paymentId' => (string)$service
        ]
    ]);
    exit;

} catch(PDOException $e) {
    echo json_encode([
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'status' => 'error',
            'message' => 'Database query failed: ' . $e->getMessage()
        ]
    ]);
} catch(Exception $e) {
    echo json_encode([
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'status' => 'error',
            'message' => $e->getMessage()
        ]
    ]);
}
?>