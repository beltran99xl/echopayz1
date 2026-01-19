<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// payments.json dosyasını oku
$payments_file = './payments.json';

if (!file_exists($payments_file)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Payments configuration not found'
    ]);
    exit;
}

$payments_data = json_decode(file_get_contents($payments_file), true);

if (!is_array($payments_data)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid payments configuration'
    ]);
    exit;
}

// Deposit ve Withdraw metodlarının SADECE paymentId'lerini ayır
$deposit_ids = [];
$withdraw_ids = [];

foreach ($payments_data as $method) {
    $payment_id = isset($method['paymentId']) ? (int)$method['paymentId'] : null;
    if (!$payment_id) continue;

    // Deposit kriterleri: depositFormFields ve info.minDeposit var mı?
    $has_deposit_form = !empty($method['depositFormFields']);
    $has_deposit_info = false;
    if (isset($method['info']) && is_array($method['info'])) {
        foreach ($method['info'] as $currency => $info) {
            if (isset($info['minDeposit'])) {
                $has_deposit_info = true;
                break;
            }
        }
    }
    $has_deposit_translation = false;
    if (isset($method['translations']['default']['deposit'])) {
        $deposit_text = trim($method['translations']['default']['deposit']);
        $has_deposit_translation = !empty($deposit_text);
    }

    // Withdraw kriterleri
    $has_withdraw_form = !empty($method['withdrawFormFields']);
    $has_withdraw_info = false;
    if (isset($method['info']) && is_array($method['info'])) {
        foreach ($method['info'] as $currency => $info) {
            if (isset($info['minWithdraw'])) {
                $has_withdraw_info = true;
                break;
            }
        }
    }
    $has_withdraw_translation = false;
    if (isset($method['translations']['default']['withdraw'])) {
        $withdraw_text = trim($method['translations']['default']['withdraw']);
        $has_withdraw_translation = !empty($withdraw_text);
    }

    // Eğer metod disabled ise atla
    if (isset($method['enabled']) && !$method['enabled']) continue;

    if ($has_deposit_form && $has_deposit_info && $has_deposit_translation) {
        $deposit_ids[] = $payment_id;
    }
    if ($has_withdraw_form && $has_withdraw_info && $has_withdraw_translation) {
        $withdraw_ids[] = $payment_id;
    }
}

echo json_encode([
    'status' => 'ok',
    'deposit' => $deposit_ids,
    'withdraw' => $withdraw_ids
]);
?>