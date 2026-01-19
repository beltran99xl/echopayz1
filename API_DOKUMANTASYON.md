# EchoPayz API Dokümantasyonu

## İçindekiler
1. [Genel Bilgiler](#genel-bilgiler)
2. [Kimlik Doğrulama](#kimlik-doğrulama)
3. [Yatırım (Deposit) API](#yatırım-deposit-api)
4. [Çekim (Withdrawal) API](#çekim-withdrawal-api)
5. [Callback Sistemi](#callback-sistemi)
6. [Hata Kodları](#hata-kodları)
7. [Örnekler](#örnekler)

---

## Genel Bilgiler

### Base URL
```
https://api.echopayz.com/v1
```

### İstek Formatı
- **Content-Type**: `application/json`
- **Method**: `POST` veya `GET`
- **Encoding**: UTF-8

### Yanıt Formatı
Tüm API yanıtları JSON formatındadır:

```json
{
  "success": true,
  "data": { ... }
}
```

veya hata durumunda:

```json
{
  "success": false,
  "error": "Hata mesajı",
  "code": 400
}
```

### Tutar Formatı
- Tüm tutarlar **TL cinsinden** gönderilir ve saklanır
- Örnek: `6300.00` = 6.300 TL
- Ondalık ayırıcı: nokta (`.`)
- Maksimum 2 ondalık basamak

---

## Kimlik Doğrulama

### API Key ve Secret
Her site için benzersiz bir `api_key` ve `api_secret` değeri oluşturulur. Bu değerler panel üzerinden görüntülenebilir.

### İmza Doğrulama (HMAC-SHA256)

Her API isteği için aşağıdaki header'lar gönderilmelidir:

| Header | Açıklama | Zorunlu |
|--------|----------|---------|
| `X-API-Key` | Site API Key | Evet |
| `X-Signature` | HMAC-SHA256 imzası | Evet |
| `X-Timestamp` | Unix timestamp (saniye) | Evet |
| `X-Nonce` | Benzersiz string (her istek için farklı) | Evet |

### İmza Oluşturma

İmza oluşturma adımları:

1. **Payload oluştur**:
   ```
   METHOD|PATH|TIMESTAMP|NONCE|BODY_JSON
   ```
   
   - `METHOD`: HTTP method (GET, POST, vb.) - BÜYÜK HARF
   - `PATH`: İstek yolu (örn: `/v1/deposits`)
   - `TIMESTAMP`: Unix timestamp (string)
   - `NONCE`: Benzersiz string (her istek için farklı)
   - `BODY_JSON`: Request body JSON string (boş ise boş string)

2. **Body JSON hazırlama**:
   - Tüm body parametrelerini al
   - Alfabetik olarak sırala (key bazlı)
   - JSON encode et (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`)

3. **İmza hesapla**:
   ```php
   $signature = hash_hmac('sha256', $payload, $api_secret);
   ```

### Örnek İmza Hesaplama

**PHP Örneği:**
```php
function generateSignature($method, $path, $timestamp, $nonce, $body, $apiSecret) {
    // Body'yi sırala ve JSON'a çevir
    ksort($body);
    $bodyJson = !empty($body) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    
    // Payload oluştur
    $payload = strtoupper($method) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $bodyJson;
    
    // İmza hesapla
    return hash_hmac('sha256', $payload, $apiSecret);
}

// Kullanım
$method = 'POST';
$path = '/v1/deposits';
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$body = [
    'reference_id' => 'REF123',
    'amount' => 1000.00,
    'customer_id' => 'CUST001'
];
$apiSecret = 'your_api_secret';

$signature = generateSignature($method, $path, $timestamp, $nonce, $body, $apiSecret);
```

**cURL Örneği:**
```bash
TIMESTAMP=$(date +%s)
NONCE=$(openssl rand -hex 16)
METHOD="POST"
PATH="/v1/deposits"
BODY='{"reference_id":"REF123","amount":1000.00,"customer_id":"CUST001"}'
API_SECRET="your_api_secret"

# Body'yi sırala (jq ile)
SORTED_BODY=$(echo $BODY | jq -c 'to_entries | sort_by(.key) | from_entries')

# Payload oluştur
PAYLOAD="${METHOD}|${PATH}|${TIMESTAMP}|${NONCE}|${SORTED_BODY}"

# İmza hesapla
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

# İstek gönder
curl -X POST https://api.echopayz.com/v1/deposits \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key" \
  -H "X-Signature: $SIGNATURE" \
  -H "X-Timestamp: $TIMESTAMP" \
  -H "X-Nonce: $NONCE" \
  -d "$BODY"
```

### Güvenlik Notları

1. **Timestamp Toleransı**: İstek zamanı ile sunucu zamanı arasındaki fark maksimum 300 saniye (5 dakika) olmalıdır.
2. **Nonce Tekrarı**: Her nonce sadece bir kez kullanılabilir. Aynı nonce ile tekrar istek gönderilirse reddedilir.
3. **API Secret**: API secret asla istemci tarafında veya public kodda saklanmamalıdır.

---

## Yatırım (Deposit) API

### 1. Yatırım Talebi Oluştur

Yeni bir yatırım talebi oluşturur ve müşteriye ödeme için IBAN bilgisi döner.

**Endpoint:** `POST /v1/deposits`

**Request Body:**
```json
{
  "reference_id": "REF123456789",      // Zorunlu: Benzersiz referans ID (max 100 karakter)
  "amount": 1000.00,                    // Zorunlu: Tutar (TL cinsinden, min: 1)
  "currency": "TRY",                    // Opsiyonel: Para birimi (varsayılan: TRY)
  "customer_id": "CUST001",             // Opsiyonel: Müşteri ID (max 100 karakter)
  "customer_name": "Ahmet Yılmaz",      // Opsiyonel: Müşteri adı (max 255 karakter)
  "customer_ip": "192.168.1.1",         // Opsiyonel: Müşteri IP adresi
  "callback_url": "https://example.com/callback",  // Opsiyonel: Özel callback URL
  "extra_data": {                       // Opsiyonel: Ekstra veriler
    "order_id": "ORD123",
    "user_id": 456
  }
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "transaction_id": "DEP1A2B3C4D5E6F7G8H9I0J",
    "reference_id": "REF123456789",
    "payment_url": "https://panel.echopayz.com/deposit/DEP1A2B3C4D5E6F7G8H9I0J",
    "amount": 1000.00,
    "net_amount": 980.00,
    "commission_rate": 2.00,
    "commission_amount": 20.00,
    "currency": "TRY",
    "status": "pending",
    "iban": "TR330006100519786457841326",
    "bank": "Akbank",
    "holder_name": "EchoPayz Ödeme",
    "expires_at": "2024-01-15T10:10:00+00:00",
    "created_at": "2024-01-15T10:00:00+00:00"
  }
}
```

**Hata Durumları:**

| HTTP Kodu | Açıklama |
|-----------|----------|
| 400 | Geçersiz parametreler veya IBAN bulunamadı |
| 401 | Kimlik doğrulama hatası |
| 403 | Müşteri engellenmiş |
| 409 | Aynı reference_id ile bekleyen işlem var |
| 500 | Sunucu hatası |

**Önemli Notlar:**

1. **IBAN Seçimi**: Sistem, tutara uygun (min/max limit kontrolü) ve aktif bir IBAN seçer.
2. **Süre Sınırı**: Her yatırım talebi 10 dakika içinde ödenmeli, aksi halde `expired` durumuna geçer.
3. **Duplicate Kontrolü**: Aynı `reference_id` ile bekleyen veya onaylanmış işlem varsa hata döner.
4. **Müşteri Kontrolü**: Aynı `customer_id` ile bekleyen işlem varsa hata döner.
5. **Banned Kontrolü**: IP veya customer_id engellenmişse işlem reddedilir.

### 2. Yatırım Durumu Sorgula

Transaction ID ile yatırım durumunu sorgular.

**Endpoint:** `GET /v1/deposits/{transactionId}`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "transaction_id": "DEP1A2B3C4D5E6F7G8H9I0J",
    "reference_id": "REF123456789",
    "amount": 1000.00,
    "net_amount": 980.00,
    "commission_rate": 2.00,
    "commission_amount": 20.00,
    "currency": "TRY",
    "status": "approved",
    "iban": "TR330006100519786457841326",
    "bank": "Akbank",
    "holder_name": "EchoPayz Ödeme",
    "customer_id": "CUST001",
    "approved_at": "2024-01-15T10:05:00+00:00",
    "rejected_at": null,
    "rejection_reason": null,
    "expires_at": "2024-01-15T10:10:00+00:00",
    "created_at": "2024-01-15T10:00:00+00:00"
  }
}
```

**Status Değerleri:**
- `pending`: Beklemede (ödeme yapılmadı)
- `processing`: Kontrol ediliyor
- `approved`: Onaylandı (bakiye site hesabına eklendi)
- `rejected`: Reddedildi
- `expired`: Süresi doldu (10 dakika içinde ödeme yapılmadı)
- `cancelled`: İptal edildi

### 3. Referans ID ile Yatırım Sorgula

Reference ID ile yatırım durumunu sorgular.

**Endpoint:** `GET /v1/deposits/reference/{referenceId}`

**Response:** Yukarıdaki ile aynı format.

### 4. Yatırım İptal Et

Beklemedeki bir yatırımı iptal eder.

**Endpoint:** `POST /v1/deposits/{transactionId}/cancel`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "transaction_id": "DEP1A2B3C4D5E6F7G8H9I0J",
    "status": "cancelled"
  }
}
```

**Not:** Sadece `pending` durumundaki yatırımlar iptal edilebilir.

---

## Çekim (Withdrawal) API

### 1. Çekim Talebi Oluştur

Yeni bir çekim talebi oluşturur.

**Endpoint:** `POST /v1/withdrawals`

**Request Body:**
```json
{
  "reference_id": "WTH123456789",       // Zorunlu: Benzersiz referans ID (max 100 karakter)
  "amount": 500.00,                     // Zorunlu: Tutar (TL cinsinden, min: 1)
  "currency": "TRY",                    // Opsiyonel: Para birimi (varsayılan: TRY)
  "customer_id": "CUST001",             // Opsiyonel: Müşteri ID (max 100 karakter)
  "customer_name": "Ahmet Yılmaz",      // Zorunlu: Müşteri adı (max 255 karakter)
  "customer_iban": "TR330006100519786457841326",  // Zorunlu: 26 karakter IBAN
  "customer_bank": "Akbank",            // Opsiyonel: Banka adı (max 100 karakter)
  "customer_ip": "192.168.1.1",         // Opsiyonel: Müşteri IP adresi
  "callback_url": "https://example.com/callback",  // Opsiyonel: Özel callback URL
  "extra_data": {                       // Opsiyonel: Ekstra veriler
    "order_id": "ORD123"
  }
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "transaction_id": "WTH1A2B3C4D5E6F7G8H9I0J",
    "reference_id": "WTH123456789",
    "amount": 500.00,
    "net_amount": 495.00,
    "commission_amount": 5.00,
    "currency": "TRY",
    "status": "pending",
    "customer_name": "Ahmet Yılmaz",
    "created_at": "2024-01-15T10:00:00+00:00"
  }
}
```

**Hata Durumları:**

| HTTP Kodu | Açıklama |
|-----------|----------|
| 400 | Geçersiz parametreler, limit aşımı veya IBAN formatı hatalı |
| 401 | Kimlik doğrulama hatası |
| 403 | IP, müşteri veya IBAN engellenmiş |
| 409 | Aynı reference_id ile bekleyen işlem var |
| 500 | Sunucu hatası |

**Önemli Notlar:**

1. **IBAN Formatı**: IBAN 26 karakter olmalıdır (TR + 24 karakter). Boşluklar otomatik temizlenir.
2. **Limit Kontrolü**: Site için tanımlı min/max çekim limitleri kontrol edilir.
3. **Bakiye Kontrolü**: Çekim onaylandığında site bakiyesi kontrol edilir (onay aşamasında).
4. **Duplicate Kontrolü**: Aynı `reference_id` ile bekleyen veya onaylanmış işlem varsa hata döner.
5. **Banned Kontrolü**: IP, customer_id veya IBAN engellenmişse işlem reddedilir.

### 2. Çekim Durumu Sorgula

Transaction ID ile çekim durumunu sorgular.

**Endpoint:** `GET /v1/withdrawals/{transactionId}`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "transaction_id": "WTH1A2B3C4D5E6F7G8H9I0J",
    "reference_id": "WTH123456789",
    "amount": 500.00,
    "net_amount": 495.00,
    "commission_amount": 5.00,
    "currency": "TRY",
    "status": "paid",
    "customer_id": "CUST001",
    "customer_name": "Ahmet Yılmaz",
    "approved_at": "2024-01-15T10:05:00+00:00",
    "paid_at": "2024-01-15T10:10:00+00:00",
    "rejected_at": null,
    "rejection_reason": null,
    "created_at": "2024-01-15T10:00:00+00:00"
  }
}
```

**Status Değerleri:**
- `pending`: Beklemede (onay bekliyor)
- `processing`: İşleniyor (onaylandı, ödeme yapılacak)
- `paid`: Ödendi (para transfer edildi)
- `rejected`: Reddedildi
- `cancelled`: İptal edildi

### 3. Referans ID ile Çekim Sorgula

Reference ID ile çekim durumunu sorgular.

**Endpoint:** `GET /v1/withdrawals/reference/{referenceId}`

**Response:** Yukarıdaki ile aynı format.

### 4. Çekim İptal Et

Beklemedeki bir çekimi iptal eder.

**Endpoint:** `POST /v1/withdrawals/{transactionId}/cancel`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "transaction_id": "WTH1A2B3C4D5E6F7G8H9I0J",
    "status": "cancelled"
  }
}
```

**Not:** Sadece `pending` durumundaki çekimler iptal edilebilir.

---

## Callback Sistemi

### Genel Bilgiler

Callback sistemi, yatırım ve çekim işlemlerinin durum değişikliklerinde sitenize otomatik olarak bildirim gönderir.

### Callback URL

Callback URL iki şekilde belirlenir:
1. **Site genel callback URL**: Panel'de site ayarlarından tanımlanır
2. **İşlem bazlı callback URL**: Her işlemde `callback_url` parametresi ile gönderilebilir

İşlem bazlı URL varsa o kullanılır, yoksa site genel URL'i kullanılır.

### Callback Gönderim Zamanları

#### Yatırım (Deposit) Callback'leri:
- **`deposit_approved`**: Yatırım onaylandığında
- **`deposit_rejected`**: Yatırım reddedildiğinde

#### Çekim (Withdrawal) Callback'leri:
- **`withdrawal_approved`**: Çekim onaylandığında (processing durumuna geçtiğinde)
- **`withdrawal_paid`**: Çekim ödendiğinde
- **`withdrawal_rejected`**: Çekim reddedildiğinde
- **`withdrawal_cancelled`**: Çekim iptal edildiğinde

### Callback İstek Formatı

**Method:** `POST`

**Headers:**
```
Content-Type: application/json; charset=utf-8
X-Signature: <HMAC-SHA256 imzası>
X-Timestamp: <Unix timestamp>
X-Nonce: <Benzersiz nonce>
X-Transaction-Type: deposit|withdrawal
User-Agent: EchoPayz-Callback/1.0
```

**İmza Hesaplama:**
```php
$payload = json_encode($callbackData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$signatureData = "{$timestamp}|{$nonce}|{$payload}";
$signature = hash_hmac('sha256', $signatureData, $api_secret);
```

**Request Body (Yatırım - Onaylandı):**
```json
{
  "type": "deposit",
  "event": "deposit_approved",
  "transaction_id": "DEP1A2B3C4D5E6F7G8H9I0J",
  "reference_id": "REF123456789",
  "status": "approved",
  "amount": 1000.00,
  "currency": "TRY",
  "customer_id": "CUST001",
  "customer_name": "Ahmet Yılmaz",
  "approved_at": "2024-01-15T10:05:00+00:00",
  "rejected_at": null,
  "rejection_reason": null
}
```

**Request Body (Yatırım - Reddedildi):**
```json
{
  "type": "deposit",
  "event": "deposit_rejected",
  "transaction_id": "DEP1A2B3C4D5E6F7G8H9I0J",
  "reference_id": "REF123456789",
  "status": "rejected",
  "amount": 1000.00,
  "currency": "TRY",
  "customer_id": "CUST001",
  "customer_name": "Ahmet Yılmaz",
  "approved_at": null,
  "rejected_at": "2024-01-15T10:05:00+00:00",
  "rejection_reason": "Ödeme eşleşmedi"
}
```

**Request Body (Çekim - Onaylandı):**
```json
{
  "type": "withdrawal",
  "event": "withdrawal_approved",
  "transaction_id": "WTH1A2B3C4D5E6F7G8H9I0J",
  "reference_id": "WTH123456789",
  "status": "processing",
  "amount": 500.00,
  "currency": "TRY",
  "customer_id": "CUST001",
  "customer_name": "Ahmet Yılmaz",
  "approved_at": "2024-01-15T10:05:00+00:00",
  "paid_at": null,
  "rejected_at": null,
  "rejection_reason": null
}
```

**Request Body (Çekim - Ödendi):**
```json
{
  "type": "withdrawal",
  "event": "withdrawal_paid",
  "transaction_id": "WTH1A2B3C4D5E6F7G8H9I0J",
  "reference_id": "WTH123456789",
  "status": "paid",
  "amount": 500.00,
  "currency": "TRY",
  "customer_id": "CUST001",
  "customer_name": "Ahmet Yılmaz",
  "approved_at": "2024-01-15T10:05:00+00:00",
  "paid_at": "2024-01-15T10:10:00+00:00",
  "rejected_at": null,
  "rejection_reason": null
}
```

**Request Body (Çekim - Reddedildi):**
```json
{
  "type": "withdrawal",
  "event": "withdrawal_rejected",
  "transaction_id": "WTH1A2B3C4D5E6F7G8H9I0J",
  "reference_id": "WTH123456789",
  "status": "rejected",
  "amount": 500.00,
  "currency": "TRY",
  "customer_id": "CUST001",
  "customer_name": "Ahmet Yılmaz",
  "approved_at": null,
  "paid_at": null,
  "rejected_at": "2024-01-15T10:05:00+00:00",
  "rejection_reason": "Bakiye yetersiz"
}
```

### Callback Yanıt Formatı

Callback endpoint'iniz **2xx** (200-299) HTTP status code döndürmelidir. Aksi halde callback tekrar denenir.

**Başarılı Yanıt:**
```json
{
  "success": true,
  "message": "Callback alındı"
}
```

**Hata Yanıtı (Tekrar Denenecek):**
```json
{
  "success": false,
  "error": "İşlem bulunamadı"
}
```

### Callback İmza Doğrulama

Callback alındığında mutlaka imza doğrulaması yapılmalıdır:

```php
function verifyCallbackSignature($timestamp, $nonce, $body, $signature, $apiSecret) {
    $signatureData = "{$timestamp}|{$nonce}|{$body}";
    $expectedSignature = hash_hmac('sha256', $signatureData, $apiSecret);
    return hash_equals($expectedSignature, $signature);
}

// Kullanım
$timestamp = $_SERVER['HTTP_X_TIMESTAMP'];
$nonce = $_SERVER['HTTP_X_NONCE'];
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'];
$apiSecret = 'your_api_secret';

if (!verifyCallbackSignature($timestamp, $nonce, $body, $signature, $apiSecret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Geçersiz imza']);
    exit;
}
```

### Callback Tekrar Deneme Mekanizması

- Callback başarısız olursa (2xx dışı yanıt veya timeout) otomatik olarak tekrar denenir
- Maksimum **5 deneme** yapılır
- Denemeler arası süre: **5, 10, 20, 40, 80 dakika** (exponential backoff)
- 5 deneme sonunda başarısız olursa callback `failed` durumuna geçer

### Callback Örnek Endpoint (PHP)

```php
<?php
// callback.php

header('Content-Type: application/json');

// İmza doğrulama
$timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
$nonce = $_SERVER['HTTP_X_NONCE'] ?? '';
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$body = file_get_contents('php://input');

$apiSecret = 'your_api_secret'; // Site API secret

$signatureData = "{$timestamp}|{$nonce}|{$body}";
$expectedSignature = hash_hmac('sha256', $signatureData, $apiSecret);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Geçersiz imza']);
    exit;
}

// Body'yi parse et
$data = json_decode($body, true);

// İşlemi işle
$transactionId = $data['transaction_id'];
$status = $data['status'];
$event = $data['event'];

// Veritabanında işlemi güncelle
// ...

// Başarılı yanıt
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Callback alındı'
]);
```

---

## Hata Kodları

| HTTP Kodu | Açıklama |
|-----------|----------|
| 200 | Başarılı |
| 201 | Oluşturuldu |
| 400 | Geçersiz istek (parametre hatası, limit aşımı, vb.) |
| 401 | Kimlik doğrulama hatası (geçersiz imza, süresi dolmuş timestamp, vb.) |
| 403 | Yetkilendirme hatası (engellenmiş müşteri, aktif olmayan site, vb.) |
| 404 | Kayıt bulunamadı |
| 409 | Çakışma (duplicate reference_id, vb.) |
| 429 | Rate limit aşıldı |
| 500 | Sunucu hatası |

### Hata Mesajları

Hata yanıtlarında `error` alanında detaylı mesaj bulunur:

```json
{
  "success": false,
  "error": "Bu referans ID ile bekleyen veya onaylanmış bir işlem zaten var.",
  "code": 409
}
```

---

## Örnekler

### PHP - Yatırım Oluşturma

```php
<?php

class EchoPayzClient {
    private $apiKey;
    private $apiSecret;
    private $baseUrl = 'https://api.echopayz.com/v1';
    
    public function __construct($apiKey, $apiSecret) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }
    
    private function generateSignature($method, $path, $timestamp, $nonce, $body) {
        ksort($body);
        $bodyJson = !empty($body) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $payload = strtoupper($method) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $bodyJson;
        return hash_hmac('sha256', $payload, $this->apiSecret);
    }
    
    public function createDeposit($referenceId, $amount, $customerId = null, $customerName = null) {
        $method = 'POST';
        $path = '/v1/deposits';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        
        $body = [
            'reference_id' => $referenceId,
            'amount' => $amount,
            'currency' => 'TRY',
        ];
        
        if ($customerId) {
            $body['customer_id'] = $customerId;
        }
        if ($customerName) {
            $body['customer_name'] = $customerName;
        }
        
        $signature = $this->generateSignature($method, $path, $timestamp, $nonce, $body);
        
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
                'X-Signature: ' . $signature,
                'X-Timestamp: ' . $timestamp,
                'X-Nonce: ' . $nonce,
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    public function getDepositStatus($transactionId) {
        $method = 'GET';
        $path = '/v1/deposits/' . $transactionId;
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        
        $body = [];
        $signature = $this->generateSignature($method, $path, $timestamp, $nonce, $body);
        
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'X-Signature: ' . $signature,
                'X-Timestamp: ' . $timestamp,
                'X-Nonce: ' . $nonce,
            ],
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}

// Kullanım
$client = new EchoPayzClient('your_api_key', 'your_api_secret');

// Yatırım oluştur
$result = $client->createDeposit('REF123', 1000.00, 'CUST001', 'Ahmet Yılmaz');
if ($result['success']) {
    echo "Transaction ID: " . $result['data']['transaction_id'] . "\n";
    echo "IBAN: " . $result['data']['iban'] . "\n";
    echo "Payment URL: " . $result['data']['payment_url'] . "\n";
} else {
    echo "Hata: " . $result['error'] . "\n";
}

// Durum sorgula
$status = $client->getDepositStatus('DEP1A2B3C4D5E6F7G8H9I0J');
if ($status['success']) {
    echo "Status: " . $status['data']['status'] . "\n";
}
```

### Node.js - Çekim Oluşturma

```javascript
const crypto = require('crypto');
const https = require('https');

class EchoPayzClient {
    constructor(apiKey, apiSecret) {
        this.apiKey = apiKey;
        this.apiSecret = apiSecret;
        this.baseUrl = 'api.echopayz.com';
    }
    
    generateSignature(method, path, timestamp, nonce, body) {
        const sortedBody = Object.keys(body).sort().reduce((acc, key) => {
            acc[key] = body[key];
            return acc;
        }, {});
        
        const bodyJson = Object.keys(sortedBody).length > 0 
            ? JSON.stringify(sortedBody) 
            : '';
        
        const payload = `${method.toUpperCase()}|${path}|${timestamp}|${nonce}|${bodyJson}`;
        return crypto.createHmac('sha256', this.apiSecret).update(payload).digest('hex');
    }
    
    makeRequest(method, path, body = {}) {
        return new Promise((resolve, reject) => {
            const timestamp = Math.floor(Date.now() / 1000).toString();
            const nonce = crypto.randomBytes(16).toString('hex');
            const signature = this.generateSignature(method, path, timestamp, nonce, body);
            
            const bodyJson = JSON.stringify(body);
            
            const options = {
                hostname: this.baseUrl,
                path: path,
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key': this.apiKey,
                    'X-Signature': signature,
                    'X-Timestamp': timestamp,
                    'X-Nonce': nonce,
                },
            };
            
            const req = https.request(options, (res) => {
                let data = '';
                res.on('data', (chunk) => {
                    data += chunk;
                });
                res.on('end', () => {
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        reject(e);
                    }
                });
            });
            
            req.on('error', reject);
            
            if (bodyJson) {
                req.write(bodyJson);
            }
            
            req.end();
        });
    }
    
    async createWithdrawal(referenceId, amount, customerName, customerIban) {
        return this.makeRequest('POST', '/v1/withdrawals', {
            reference_id: referenceId,
            amount: amount,
            currency: 'TRY',
            customer_name: customerName,
            customer_iban: customerIban,
        });
    }
    
    async getWithdrawalStatus(transactionId) {
        return this.makeRequest('GET', `/v1/withdrawals/${transactionId}`);
    }
}

// Kullanım
const client = new EchoPayzClient('your_api_key', 'your_api_secret');

client.createWithdrawal('WTH123', 500.00, 'Ahmet Yılmaz', 'TR330006100519786457841326')
    .then(result => {
        if (result.success) {
            console.log('Transaction ID:', result.data.transaction_id);
            console.log('Status:', result.data.status);
        } else {
            console.error('Hata:', result.error);
        }
    })
    .catch(error => {
        console.error('İstek hatası:', error);
    });
```

### Python - Callback Endpoint

```python
import hmac
import hashlib
import json
from flask import Flask, request, jsonify

app = Flask(__name__)

API_SECRET = 'your_api_secret'

def verify_signature(timestamp, nonce, body, signature):
    signature_data = f"{timestamp}|{nonce}|{body}"
    expected_signature = hmac.new(
        API_SECRET.encode(),
        signature_data.encode(),
        hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected_signature, signature)

@app.route('/callback', methods=['POST'])
def callback():
    # Header'ları al
    timestamp = request.headers.get('X-Timestamp')
    nonce = request.headers.get('X-Nonce')
    signature = request.headers.get('X-Signature')
    transaction_type = request.headers.get('X-Transaction-Type')
    
    # Body'yi al
    body = request.get_data(as_text=True)
    
    # İmza doğrula
    if not verify_signature(timestamp, nonce, body, signature):
        return jsonify({
            'success': False,
            'error': 'Geçersiz imza'
        }), 401
    
    # JSON parse et
    data = json.loads(body)
    
    # İşlemi işle
    transaction_id = data.get('transaction_id')
    event = data.get('event')
    status = data.get('status')
    
    print(f"Callback alındı: {event} - {transaction_id} - {status}")
    
    # Veritabanında işlemi güncelle
    # ...
    
    # Başarılı yanıt
    return jsonify({
        'success': True,
        'message': 'Callback alındı'
    }), 200

if __name__ == '__main__':
    app.run(port=5000)
```

---

## Sık Sorulan Sorular (SSS)

### 1. Yatırım işlemi ne kadar sürede onaylanır?
Yatırım işlemleri manuel olarak kontrol edilir ve onaylanır. Süre, işlem hacmine ve kontrol sürecine bağlıdır.

### 2. Çekim işlemi ne kadar sürede ödenir?
Çekim işlemleri onaylandıktan sonra işleme alınır ve ödeme yapılır. Ödeme süresi banka transfer süresine bağlıdır.

### 3. Callback almadım, ne yapmalıyım?
- Callback URL'inizin erişilebilir olduğundan emin olun
- İmza doğrulamasının doğru yapıldığından emin olun
- Panel'de callback loglarını kontrol edin
- Gerekirse manuel olarak callback tekrar gönderilebilir

### 4. Aynı reference_id ile tekrar istek gönderebilir miyim?
Hayır. Aynı `reference_id` ile bekleyen veya onaylanmış bir işlem varsa yeni istek reddedilir. Her işlem için benzersiz bir `reference_id` kullanmalısınız.

### 5. IBAN formatı nasıl olmalı?
IBAN 26 karakter olmalıdır: `TR` + 24 karakter. Boşluklar otomatik temizlenir.

### 6. Komisyon oranları nasıl belirlenir?
Komisyon oranları site ayarlarından belirlenir. Her işlem için komisyon otomatik hesaplanır ve `commission_amount` alanında gösterilir.

### 7. Rate limit var mı?
Evet, API istekleri için rate limit uygulanır. Aşırı istek durumunda 429 (Too Many Requests) hatası döner.

---

## Destek

Sorularınız için:
- **Email**: support@echopayz.com
- **Panel**: https://panel.echopayz.com
- **Dokümantasyon**: Bu dosya

---

**Son Güncelleme:** 2024-01-15
**Versiyon:** 1.0

