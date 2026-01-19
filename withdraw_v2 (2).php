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

// Payer bilgilerini al
$payer = isset($params['payer']) ? $params['payer'] : [];

// Owner ID'yi al - önce header'dan, sonra parametrelerden, sonra session/token'dan
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

    // Kullanıcı banned mi kontrol et
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

    // Bakiye kontrolü
    if ((float)$user['ana_bakiye'] < $amount) {
        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'error',
                'message' => 'Insufficient balance'
            ]
        ]);
        exit;
    }

    // Service (paymentId) kontrolü
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

    $provider = isset($payment_method['provider']) ? $payment_method['provider'] : 'manual';
    $provider_config = isset($payment_method['provider_config']) ? $payment_method['provider_config'] : [];

    // Eğer provider api ise üçüncü taraf API'ye istek at
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

        // Çekim bilgilerini validate et provider'a göre (ör. iban gibi)
        if (!empty($provider_config['required']) && is_array($provider_config['required'])) {
            foreach ($provider_config['required'] as $field) {
                if (empty($payer[$field])) {
                    echo json_encode([
                        'code' => 0,
                        'rid' => $rid,
                        'data' => [
                            'status' => 'error',
                            'message' => ucfirst($field) . ' is required'
                        ]
                    ]);
                    exit;
                }
            }
        }

        // Önce veritabanına beklemede kayıt ekle ve bakiyeyi düş
        $insert_query = "INSERT INTO paracek (uye, banka, miktar, tarih, durum, turi, user_id, firma_key, iban, hesap) 
                        VALUES (?, ?, ?, NOW(), 0, ?, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([
            $user['username'] ?? ($user['firstName'] ?? 'User'),
            $payment_method['displayName'] ?? $payment_method['name'] ?? 'API Withdraw',
            $amount,
            $payment_method['name'] ?? 'withdraw',
            $owner_id,
            '', // firma_key
            $payer['iban'] ?? '',
            $payer['bank_name'] ?? ($payer['account_name'] ?? '')
        ]);
        $withdraw_id = $pdo->lastInsertId();

        // Bakiyeyi düş (beklemede)
        $update_balance_query = "UPDATE kullanicilar SET ana_bakiye = ana_bakiye - ? WHERE id = ?";
        $update_balance_stmt = $pdo->prepare($update_balance_query);
        $update_balance_stmt->execute([$amount, $owner_id]);

        // Provider API çağrısı
        $api_payload = [
            'amount' => $amount,
            'currency' => $provider_config['currency'] ?? 'TRY',
            'user_id' => (string)$owner_id,
            'user_name' => $user['username'] ?? '',
            'reference' => $provider_config['reference_prefix'] ?? 'WD_' . time() . '_' . $owner_id,
            'withdraw_id' => (string)$withdraw_id
        ];
        $api_payload = array_merge($api_payload, $payer);

        $ch = curl_init(rtrim($provider_config['api_url'], '/') . ($provider_config['endpoint'] ?? '/withdrawal'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $curl_headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (isset($provider_config['api_key_header'])) {
            $curl_headers[] = $provider_config['api_key_header'] . ': ' . ($provider_config['api_key'] ?? '');
        } elseif (isset($provider_config['api_key'])) {
            $curl_headers[] = 'X-API-KEY: ' . $provider_config['api_key'];
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $api_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            // Rollback bakiye
            $rollback_balance_query = "UPDATE kullanicilar SET ana_bakiye = ana_bakiye + ? WHERE id = ?";
            $rollback_balance_stmt = $pdo->prepare($rollback_balance_query);
            $rollback_balance_stmt->execute([$amount, $owner_id]);

            // İşlemi iptal et
            $update_withdraw_query = "UPDATE paracek SET durum = 5 WHERE id = ?";
            $update_withdraw_stmt = $pdo->prepare($update_withdraw_query);
            $update_withdraw_stmt->execute([$withdraw_id]);

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
            // Rollback bakiye
            $rollback_balance_query = "UPDATE kullanicilar SET ana_bakiye = ana_bakiye + ? WHERE id = ?";
            $rollback_balance_stmt = $pdo->prepare($rollback_balance_query);
            $rollback_balance_stmt->execute([$amount, $owner_id]);

            $update_withdraw_query = "UPDATE paracek SET durum = 5 WHERE id = ?";
            $update_withdraw_stmt = $pdo->prepare($update_withdraw_query);
            $update_withdraw_stmt->execute([$withdraw_id]);

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
            $api_result = ['raw' => $api_response];
        }

        // Eğer provider başarılı ise token/reference ile güncelle
        $token = $api_result['token'] ?? $api_result['reference'] ?? ($api_result['data']['token'] ?? '');
        if ($token) {
            $update_token_query = "UPDATE paracek SET token = ? WHERE id = ?";
            $update_token_stmt = $pdo->prepare($update_token_query);
            $update_token_stmt->execute([$token, $withdraw_id]);
        }

        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'ok',
                'message' => $api_result['message'] ?? 'Withdrawal request created successfully',
                'name' => $payment_method['name'] ?? '',
                'paymentId' => (string)$service,
                'token' => $token,
                'withdrawId' => $withdraw_id
            ]
        ]);
        exit;
    }

    // iframe provider: frontend'e action döndür, veritabanına kaydet (optional)
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

        // Insert withdrawal request and deduct balance
        $insert_query = "INSERT INTO paracek (uye, banka, miktar, tarih, durum, turi, user_id, firma_key, iban, hesap) 
                        VALUES (?, ?, ?, NOW(), 0, ?, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([
            $user['username'] ?? ($user['firstName'] ?? 'User'),
            $payment_method['displayName'] ?? $payment_method['name'] ?? 'Iframe Withdraw',
            $amount,
            $payment_method['name'] ?? 'withdraw',
            $owner_id,
            '', // firma_key
            $payer['iban'] ?? '',
            $payer['bank_name'] ?? ''
        ]);
        $withdraw_id = $pdo->lastInsertId();

        // Bakiyeyi düş
        $update_balance_query = "UPDATE kullanicilar SET ana_bakiye = ana_bakiye - ? WHERE id = ?";
        $update_balance_stmt = $pdo->prepare($update_balance_query);
        $update_balance_stmt->execute([$amount, $owner_id]);

        echo json_encode([
            'code' => 0,
            'rid' => $rid,
            'data' => [
                'status' => 'ok',
                'name' => $payment_method['name'] ?? '',
                'paymentId' => (string)$service,
                'action' => $action,
                'method' => $provider_config['method'] ?? 'GET',
                'fields' => $provider_config['fields'] ?? [],
                'withdrawId' => $withdraw_id
            ]
        ]);
        exit;
    }

    // manual provider (default): veritabanına kaydet ve bakiye düş
    $insert_query = "INSERT INTO paracek (uye, banka, miktar, tarih, durum, turi, user_id, firma_key, iban, hesap) 
                    VALUES (?, ?, ?, NOW(), 0, ?, ?, ?, ?, ?)";
    $insert_stmt = $pdo->prepare($insert_query);
    $insert_stmt->execute([
        $user['username'] ?? ($user['firstName'] ?? 'User'),
        $payment_method['displayName'] ?? $payment_method['name'] ?? 'Manual Withdraw',
        $amount,
        $payment_method['name'] ?? 'withdraw',
        $owner_id,
        '', // firma_key
        $payer['iban'] ?? '',
        $payer['bank_name'] ?? ''
    ]);
    $withdraw_id = $pdo->lastInsertId();

    // Bakiyeyi düş
    $update_balance_query = "UPDATE kullanicilar SET ana_bakiye = ana_bakiye - ? WHERE id = ?";
    $update_balance_stmt = $pdo->prepare($update_balance_query);
    $update_balance_stmt->execute([$amount, $owner_id]);

    echo json_encode([
        'code' => 0,
        'rid' => $rid,
        'data' => [
            'status' => 'ok',
            'message' => 'Withdrawal request created (manual processing)',
            'name' => $payment_method['name'] ?? '',
            'paymentId' => (string)$service,
            'withdrawId' => $withdraw_id
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