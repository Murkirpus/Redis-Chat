<?php
// ============================================================================
// ГОСТЕВОЙ ЧАТ НА PHP + REDIS
// С умной проверкой новых сообщений и смайликами 😊
// ============================================================================

session_start();

// ============================================================================
// КОНФИГУРАЦИЯ
// ============================================================================

define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');

define('MAX_MESSAGE_LENGTH', 500);        
define('MIN_MESSAGE_LENGTH', 1);          
define('MAX_MESSAGES_DISPLAY', 50);       
define('MESSAGE_RATE_LIMIT', 3);          
define('USERNAME_MAX_LENGTH', 30);        
define('USERNAME_MIN_LENGTH', 2);         
define('SESSION_LIFETIME', 3600);         

define('MESSAGE_TTL', 86400);             // TTL сообщений (24 часа)
define('CLEANUP_INTERVAL', 3600);         // Интервал очистки (1 час)

// === ЗАЩИТА ОТ ПЕРЕПОЛНЕНИЯ ===
define('MAX_MESSAGES_TOTAL', 10000);      // Максимум сообщений в Redis
define('MAX_MESSAGES_SOFT_LIMIT', 8000);  // Мягкий лимит (начало очистки)
define('CLEANUP_BATCH_SIZE', 1000);       // Удалять по 1000 старых сообщений
define('MAX_REDIS_MEMORY_MB', 10);       // Максимум памяти Redis (МБ)
define('FLOOD_PROTECTION_WINDOW', 60);    // Окно антифлуда (секунд)
define('MAX_MESSAGES_PER_IP', 10);        // Макс сообщений с одного IP в окне

date_default_timezone_set('Europe/Moscow');

// ============================================================================
// КЛАСС: REDIS MANAGER
// ============================================================================

class RedisManager {
    private $redis;
    private $connected = false;
    
    public function __construct() {
        try {
            $this->redis = new Redis();
            $this->connected = $this->redis->connect(REDIS_HOST, REDIS_PORT);
            
            if (REDIS_PASSWORD) {
                $this->redis->auth(REDIS_PASSWORD);
            }
            
            // Запускаем очистку при инициализации
            $this->cleanupOldMessages();
            $this->enforceMessageLimit();
            
        } catch (Exception $e) {
            error_log("Redis connection error: " . $e->getMessage());
            $this->connected = false;
        }
    }
    
    public function isConnected() {
        return $this->connected;
    }
    
    /**
     * Добавление сообщения с проверкой лимитов
     */
    public function addMessage($username, $message, $clientIp = null) {
        if (!$this->connected) return false;
        
        // 1. Проверка флуда по IP
        if ($clientIp && !$this->checkIpFloodProtection($clientIp)) {
            return [
                'error' => 'flood',
                'message' => 'Слишком много сообщений с вашего IP. Подождите минуту.'
            ];
        }
        
        // 2. Проверка общего количества сообщений
        $currentCount = $this->getMessageCount();
        if ($currentCount >= MAX_MESSAGES_TOTAL) {
            // Экстренная очистка
            $this->emergencyCleanup();
            
            // Проверяем снова
            $currentCount = $this->getMessageCount();
            if ($currentCount >= MAX_MESSAGES_TOTAL) {
                return [
                    'error' => 'limit',
                    'message' => 'Достигнут лимит сообщений. Попробуйте позже.'
                ];
            }
        }
        
        // 3. Проверка использования памяти Redis
        if (!$this->checkMemoryUsage()) {
            $this->emergencyCleanup();
            return [
                'error' => 'memory',
                'message' => 'Недостаточно памяти. Попробуйте позже.'
            ];
        }
        
        // 4. Мягкий лимит - начинаем постепенную очистку
        if ($currentCount >= MAX_MESSAGES_SOFT_LIMIT) {
            $this->softCleanup();
        }
        
        // 5. Создаём и сохраняем сообщение
        $timestamp = time();
        $messageData = [
            'id' => uniqid('msg_', true),
            'username' => $username,
            'message' => $message,
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s', $timestamp),
            'ip' => $clientIp ? $this->hashIp($clientIp) : null
        ];
        
        // Используем sorted set для эффективного управления по времени
        $this->redis->zAdd(
            'chat:messages:sorted',
            $timestamp,
            json_encode($messageData)
        );
        
        // Записываем IP для антифлуд защиты
        if ($clientIp) {
            $this->trackIpMessage($clientIp);
        }
        
        return $messageData;
    }
    
    /**
     * Получение сообщений (все или только новые)
     */
    public function getMessages($limit = MAX_MESSAGES_DISPLAY, $afterTimestamp = null) {
        if (!$this->connected) return [];
        
        // Удаляем устаревшие сообщения при каждом запросе
        $minTimestamp = time() - MESSAGE_TTL;
        $this->redis->zRemRangeByScore('chat:messages:sorted', 0, $minTimestamp);
        
        // Если указан timestamp, получаем только сообщения новее него
        if ($afterTimestamp !== null) {
            $messages = $this->redis->zRangeByScore(
                'chat:messages:sorted',
                $afterTimestamp + 1, // Строго больше
                '+inf',
                ['limit' => [0, $limit]]
            );
        } else {
            // Получаем последние N сообщений
            $messages = $this->redis->zRevRange('chat:messages:sorted', 0, $limit - 1);
        }
        
        $result = [];
        foreach ($messages as $msg) {
            $decoded = json_decode($msg, true);
            if ($decoded) {
                // Убираем IP из публичного вывода
                unset($decoded['ip']);
                $result[] = $decoded;
            }
        }
        
        // Если не получали новые, результат уже в правильном порядке
        // Если получали все - разворачиваем
        return $afterTimestamp === null ? array_reverse($result) : $result;
    }
    
    /**
     * Получить timestamp последнего сообщения
     */
    public function getLastMessageTimestamp() {
        if (!$this->connected) return 0;
        
        $messages = $this->redis->zRevRange('chat:messages:sorted', 0, 0, true);
        
        if (empty($messages)) {
            return 0;
        }
        
        return (int) array_values($messages)[0];
    }
    
    /**
     * Периодическая очистка старых сообщений
     */
    private function cleanupOldMessages() {
        if (!$this->connected) return;
        
        $lastCleanupKey = 'chat:last_cleanup';
        $lastCleanup = $this->redis->get($lastCleanupKey);
        
        if (!$lastCleanup || (time() - $lastCleanup) > CLEANUP_INTERVAL) {
            $minTimestamp = time() - MESSAGE_TTL;
            $removed = $this->redis->zRemRangeByScore('chat:messages:sorted', 0, $minTimestamp);
            
            $this->redis->set($lastCleanupKey, time());
            $this->redis->expire($lastCleanupKey, CLEANUP_INTERVAL);
            
            if ($removed > 0) {
                error_log("Cleaned up {$removed} old messages");
            }
        }
    }
    
    /**
     * Принудительное ограничение количества сообщений
     */
    private function enforceMessageLimit() {
        if (!$this->connected) return;
        
        $count = $this->getMessageCount();
        
        if ($count > MAX_MESSAGES_TOTAL) {
            // Удаляем самые старые сообщения, оставляя только MAX_MESSAGES_TOTAL
            $toRemove = $count - MAX_MESSAGES_TOTAL;
            $this->redis->zRemRangeByRank('chat:messages:sorted', 0, $toRemove - 1);
            error_log("Enforced message limit: removed {$toRemove} messages");
        }
    }
    
    /**
     * Мягкая очистка - удаляет часть старых сообщений
     */
    private function softCleanup() {
        if (!$this->connected) return;
        
        $count = $this->getMessageCount();
        $toRemove = min(500, $count - MAX_MESSAGES_SOFT_LIMIT + 500);
        
        if ($toRemove > 0) {
            $this->redis->zRemRangeByRank('chat:messages:sorted', 0, $toRemove - 1);
            error_log("Soft cleanup: removed {$toRemove} messages");
        }
    }
    
    /**
     * Экстренная очистка - удаляет большое количество старых сообщений
     */
    private function emergencyCleanup() {
        if (!$this->connected) return;
        
        $count = $this->getMessageCount();
        $toRemove = min(CLEANUP_BATCH_SIZE, $count - MAX_MESSAGES_SOFT_LIMIT);
        
        if ($toRemove > 0) {
            $this->redis->zRemRangeByRank('chat:messages:sorted', 0, $toRemove - 1);
            error_log("EMERGENCY cleanup: removed {$toRemove} messages");
        }
    }
    
    /**
     * Проверка использования памяти Redis
     */
    private function checkMemoryUsage() {
        if (!$this->connected) return true;
        
        try {
            $info = $this->redis->info('memory');
            
            if (isset($info['used_memory'])) {
                $usedMemoryMb = $info['used_memory'] / (1024 * 1024);
                
                if ($usedMemoryMb > MAX_REDIS_MEMORY_MB) {
                    error_log("Redis memory usage high: {$usedMemoryMb}MB");
                    return false;
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error checking Redis memory: " . $e->getMessage());
            return true;
        }
    }
    
    /**
     * Защита от флуда по IP
     */
    private function checkIpFloodProtection($ip) {
        if (!$this->connected) return true;
        
        $key = "flood:ip:" . $this->hashIp($ip);
        $count = $this->redis->get($key);
        
        if ($count && $count >= MAX_MESSAGES_PER_IP) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Запись сообщения от IP
     */
    private function trackIpMessage($ip) {
        if (!$this->connected) return;
        
        $key = "flood:ip:" . $this->hashIp($ip);
        $this->redis->incr($key);
        $this->redis->expire($key, FLOOD_PROTECTION_WINDOW);
    }
    
    /**
     * Хеширование IP для конфиденциальности
     */
    private function hashIp($ip) {
        return hash('sha256', $ip . 'chat_salt_' . date('Y-m-d'));
    }
    
    /**
     * Получить количество сообщений
     */
    public function getMessageCount() {
        if (!$this->connected) return 0;
        return $this->redis->zCard('chat:messages:sorted');
    }
    
    /**
     * Проверка rate limit
     */
    public function checkRateLimit($identifier) {
        if (!$this->connected) return ['allowed' => true];
        
        $key = "rate_limit:{$identifier}";
        $count = $this->redis->get($key);
        
        if ($count >= MESSAGE_RATE_LIMIT) {
            $ttl = $this->redis->ttl($key);
            return ['allowed' => false, 'wait' => $ttl];
        }
        
        $this->redis->incr($key);
        $this->redis->expire($key, 60);
        
        return ['allowed' => true];
    }
    
    /**
     * Получить количество онлайн пользователей
     */
    public function getOnlineCount() {
        if (!$this->connected) return 0;
        
        $key = 'chat:online';
        $this->redis->zRemRangeByScore($key, 0, time() - 300);
        
        return $this->redis->zCard($key);
    }
    
    /**
     * Обновить статус онлайн
     */
    public function updateOnlineStatus($sessionId) {
        if (!$this->connected) return;
        
        $key = 'chat:online';
        $this->redis->zAdd($key, time(), $sessionId);
        $this->redis->expire($key, 600);
    }
    
    /**
     * Получить статистику Redis
     */
    public function getRedisStats() {
        if (!$this->connected) {
            return [
                'connected' => false,
                'messages' => 0,
                'memory_mb' => 0,
                'memory_percent' => 0
            ];
        }
        
        $messageCount = $this->getMessageCount();
        $memoryInfo = $this->redis->info('memory');
        $usedMemoryMb = isset($memoryInfo['used_memory']) 
            ? round($memoryInfo['used_memory'] / (1024 * 1024), 2)
            : 0;
        
        return [
            'connected' => true,
            'messages' => $messageCount,
            'messages_limit' => MAX_MESSAGES_TOTAL,
            'messages_percent' => round(($messageCount / MAX_MESSAGES_TOTAL) * 100, 1),
            'memory_mb' => $usedMemoryMb,
            'memory_limit_mb' => MAX_REDIS_MEMORY_MB,
            'memory_percent' => round(($usedMemoryMb / MAX_REDIS_MEMORY_MB) * 100, 1)
        ];
    }
}

// ============================================================================
// КЛАСС: SECURITY MANAGER
// ============================================================================

class SecurityManager {
    
    public function escape($string) {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    public function cleanMessage($message) {
        // Удаляем HTML теги
        $message = strip_tags($message);
        
        // Удаляем управляющие символы
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);
        
        // Нормализуем пробелы
        $message = trim($message);
        $message = preg_replace('/\s+/', ' ', $message);
        
        return $message;
    }
    
    public function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        
        return $_SESSION['csrf_token'];
    }
    
    public function verifyCsrfToken($token) {
        if (!isset($_SESSION['csrf_token']) || !isset($token)) {
            return false;
        }
        
        if (time() - ($_SESSION['csrf_token_time'] ?? 0) > SESSION_LIFETIME) {
            $this->regenerateCsrfToken();
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public function regenerateCsrfToken() {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return $this->generateCsrfToken();
    }
    
    public function validateUsername($username) {
        $username = trim($username);
        
        if (empty($username)) {
            return ['valid' => false, 'error' => 'Имя не может быть пустым'];
        }
        
        if (mb_strlen($username) < USERNAME_MIN_LENGTH) {
            return ['valid' => false, 'error' => 'Имя слишком короткое (минимум ' . USERNAME_MIN_LENGTH . ' символа)'];
        }
        
        if (mb_strlen($username) > USERNAME_MAX_LENGTH) {
            return ['valid' => false, 'error' => 'Имя слишком длинное (максимум ' . USERNAME_MAX_LENGTH . ' символов)'];
        }
        
        if (!preg_match('/^[\p{L}\p{N}\s_-]+$/u', $username)) {
            return ['valid' => false, 'error' => 'Имя содержит недопустимые символы'];
        }
        
        return ['valid' => true, 'username' => $username];
    }
    
    public function validateMessage($message) {
        $message = trim($message);
        
        if (empty($message)) {
            return ['valid' => false, 'error' => 'Сообщение не может быть пустым'];
        }
        
        $length = mb_strlen($message);
        
        if ($length < MIN_MESSAGE_LENGTH) {
            return ['valid' => false, 'error' => 'Сообщение слишком короткое'];
        }
        
        if ($length > MAX_MESSAGE_LENGTH) {
            return ['valid' => false, 'error' => 'Сообщение слишком длинное (максимум ' . MAX_MESSAGE_LENGTH . ' символов)'];
        }
        
        // Проверка на спам (повторяющиеся символы)
        if (preg_match('/(.)\1{20,}/', $message)) {
            return ['valid' => false, 'error' => 'Сообщение содержит слишком много повторяющихся символов'];
        }
        
        return ['valid' => true, 'message' => $message];
    }
    
    public function getClientIdentifier() {
        if (!isset($_SESSION['client_id'])) {
            $_SESSION['client_id'] = bin2hex(random_bytes(16));
        }
        
        return $_SESSION['client_id'];
    }
}

// ============================================================================
// КЛАСС: CHAT MANAGER
// ============================================================================

class ChatManager {
    private $redis;
    private $security;
    
    public function __construct() {
        $this->redis = new RedisManager();
        $this->security = new SecurityManager();
    }
    
    public function sendMessage($username, $message, $csrfToken) {
        // Проверка CSRF
        if (!$this->security->verifyCsrfToken($csrfToken)) {
            return ['success' => false, 'error' => 'Неверный CSRF токен. Обновите страницу.'];
        }
        
        // Валидация имени
        $usernameValidation = $this->security->validateUsername($username);
        if (!$usernameValidation['valid']) {
            return ['success' => false, 'error' => $usernameValidation['error']];
        }
        $username = $usernameValidation['username'];
        
        // Валидация сообщения
        $messageValidation = $this->security->validateMessage($message);
        if (!$messageValidation['valid']) {
            return ['success' => false, 'error' => $messageValidation['error']];
        }
        $message = $messageValidation['message'];
        
        // Проверка rate limit
        $clientId = $this->security->getClientIdentifier();
        $rateLimitCheck = $this->redis->checkRateLimit($clientId);
        
        if (!$rateLimitCheck['allowed']) {
            $wait = $rateLimitCheck['wait'] ?? 60;
            return [
                'success' => false, 
                'error' => "Слишком много сообщений. Подождите {$wait} секунд.",
                'wait' => $wait
            ];
        }
        
        // Очистка от XSS
        $username = $this->security->cleanMessage($username);
        $message = $this->security->cleanMessage($message);
        
        // Получаем IP пользователя
        $clientIp = $this->getClientIp();
        
        // Сохранение сообщения (с проверкой лимитов внутри)
        $savedMessage = $this->redis->addMessage($username, $message, $clientIp);
        
        // Проверяем на ошибки переполнения
        if (is_array($savedMessage) && isset($savedMessage['error'])) {
            return ['success' => false, 'error' => $savedMessage['message']];
        }
        
        if ($savedMessage) {
            // Обновляем статус онлайн
            $this->redis->updateOnlineStatus($clientId);
            
            return [
                'success' => true,
                'message' => $savedMessage
            ];
        }
        
        return ['success' => false, 'error' => 'Ошибка сохранения сообщения'];
    }
    
    /**
     * Получить IP клиента
     */
    private function getClientIp() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            // Cloudflare
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Прокси
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            // Nginx
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return filter_var(trim($ip), FILTER_VALIDATE_IP) ? $ip : '';
    }
    
    public function getMessages($afterTimestamp = null) {
        $messages = $this->redis->getMessages(MAX_MESSAGES_DISPLAY, $afterTimestamp);
        
        // Экранируем все данные для защиты от XSS
        foreach ($messages as &$msg) {
            $msg['username'] = $this->security->escape($msg['username']);
            $msg['message'] = $this->security->escape($msg['message']);
        }
        
        return $messages;
    }
    
    public function getLastMessageTimestamp() {
        return $this->redis->getLastMessageTimestamp();
    }
    
    public function checkNewMessages($lastTimestamp) {
        $currentTimestamp = $this->redis->getLastMessageTimestamp();
        return [
            'hasNew' => $currentTimestamp > $lastTimestamp,
            'lastTimestamp' => $currentTimestamp
        ];
    }
    
    public function getStats() {
        $clientId = $this->security->getClientIdentifier();
        $this->redis->updateOnlineStatus($clientId);
        
        $redisStats = $this->redis->getRedisStats();
        
        return [
            'online' => $this->redis->getOnlineCount(),
            'messages' => $this->redis->getMessageCount(),
            'messages_limit' => MAX_MESSAGES_TOTAL,
            'messages_percent' => $redisStats['messages_percent'],
            'ttl_hours' => MESSAGE_TTL / 3600,
            'memory_mb' => $redisStats['memory_mb'],
            'memory_limit_mb' => $redisStats['memory_limit_mb'],
            'memory_percent' => $redisStats['memory_percent']
        ];
    }
    
    public function getCsrfToken() {
        return $this->security->generateCsrfToken();
    }
}

// ============================================================================
// API ОБРАБОТКА
// ============================================================================

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    $chat = new ChatManager();
    $action = $_GET['api'] ?? '';
    
    switch ($action) {
        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $result = $chat->sendMessage(
                $data['username'] ?? '',
                $data['message'] ?? '',
                $data['csrf_token'] ?? ''
            );
            
            echo json_encode($result);
            break;
            
        case 'messages':
            $afterTimestamp = isset($_GET['after']) ? (int)$_GET['after'] : null;
            $messages = $chat->getMessages($afterTimestamp);
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'lastTimestamp' => $chat->getLastMessageTimestamp()
            ]);
            break;
            
        case 'check_new':
            $lastTimestamp = isset($_GET['last']) ? (int)$_GET['last'] : 0;
            $result = $chat->checkNewMessages($lastTimestamp);
            echo json_encode([
                'success' => true,
                'hasNew' => $result['hasNew'],
                'lastTimestamp' => $result['lastTimestamp']
            ]);
            break;
            
        case 'stats':
            $stats = $chat->getStats();
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;
            
        case 'token':
            $token = $chat->getCsrfToken();
            echo json_encode([
                'success' => true,
                'token' => $token
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
    exit;
}

// ============================================================================
// HTML СТРАНИЦА
// ============================================================================

$chat = new ChatManager();
$csrfToken = $chat->getCsrfToken();
$stats = $chat->getStats();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>💬 Гостевой Чат</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .chat-container {
            width: 100%;
            max-width: 800px;
            height: 90vh;
            max-height: 700px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .chat-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .chat-header h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .online-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
        }

        .online-dot {
            width: 10px;
            height: 10px;
            background: #4ade80;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .chat-info {
            display: flex;
            gap: 15px;
            font-size: 12px;
            opacity: 0.9;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }

        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .loading {
            text-align: center;
            color: #999;
            padding: 40px;
        }

        .message {
            margin-bottom: 15px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-header {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 5px;
        }

        .message-username {
            font-weight: 600;
            color: #667eea;
            font-size: 14px;
        }

        .message-time {
            font-size: 12px;
            color: #999;
        }

        .message-content {
            background: white;
            padding: 12px 15px;
            border-radius: 15px;
            border-left: 3px solid #667eea;
            word-wrap: break-word;
            line-height: 1.5;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .message-content a {
            color: #667eea;
            text-decoration: underline;
        }

        .chat-input-container {
            padding: 20px;
            background: white;
            border-top: 1px solid #e5e7eb;
        }

        .input-group {
            position: relative;
            margin-bottom: 10px;
        }

        .input-group:last-of-type {
            margin-bottom: 5px;
        }

        #usernameInput {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        #usernameInput:focus {
            outline: none;
            border-color: #667eea;
        }

        #messageInput {
            width: 100%;
            padding: 12px 95px 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: none;
            transition: border-color 0.3s;
            max-height: 100px;
        }

        #messageInput:focus {
            outline: none;
            border-color: #667eea;
        }

        #messageInput.warning {
            border-color: #f59e0b;
        }

        #messageInput.danger {
            border-color: #ef4444;
        }

        .input-buttons {
            position: absolute;
            right: 8px;
            bottom: 8px;
            display: flex;
            gap: 5px;
        }

        .emoji-button, .send-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s, box-shadow 0.2s;
            font-size: 20px;
        }

        .emoji-button:hover, .send-button:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .emoji-button:active, .send-button:active {
            transform: scale(0.95);
        }

        .send-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Панель эмодзи */
        .emoji-picker {
            position: absolute;
            bottom: 160px;
            right: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 15px;
            width: 320px;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
            animation: popIn 0.2s ease-out;
        }

        .emoji-picker.active {
            display: block;
        }

        @keyframes popIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(10px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .emoji-picker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .emoji-picker-title {
            font-weight: 600;
            color: #667eea;
            font-size: 14px;
        }

        .emoji-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #999;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }

        .emoji-close:hover {
            background: #f3f4f6;
        }

        .emoji-categories {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .emoji-category {
            background: #f3f4f6;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
            transition: all 0.2s;
            color: #666;
        }

        .emoji-category:hover {
            background: #e5e7eb;
        }

        .emoji-category.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 5px;
        }

        .emoji-item {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .emoji-item:hover {
            background: #f3f4f6;
            transform: scale(1.2);
        }

        .char-counter {
            font-size: 12px;
            color: #999;
            text-align: right;
            transition: color 0.3s;
        }

        .char-counter.warning {
            color: #f59e0b;
            font-weight: 600;
        }

        .char-counter.danger {
            color: #ef4444;
            font-weight: 600;
        }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 14px;
            animation: shake 0.5s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        @media (max-width: 768px) {
            body {
                padding: 0;
            }
            
            .chat-container {
                height: 100vh;
                max-height: none;
                border-radius: 0;
            }
            
            .chat-header h1 {
                font-size: 20px;
            }

            .chat-info {
                font-size: 11px;
            }

            .emoji-picker {
                width: calc(100% - 40px);
                right: 20px;
                left: 20px;
            }

            .emoji-grid {
                grid-template-columns: repeat(7, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <div class="header-top">
                <h1>💬 Гостевой Чат</h1>
                <div class="online-indicator">
                    <span class="online-dot"></span>
                    <span id="onlineCount"><?php echo $stats['online']; ?></span> онлайн
                </div>
            </div>
            <div class="chat-info">
                <div class="info-item">
                    📝 <span id="messageCount"><?php echo $stats['messages']; ?></span> / <?php echo number_format(MAX_MESSAGES_TOTAL); ?>
                </div>
                <div class="info-item">
                    ⏱️ TTL: <?php echo $stats['ttl_hours']; ?>ч
                </div>
                <div class="info-item">
                    💾 RAM: <span id="memoryUsage"><?php echo $stats['memory_mb']; ?></span>MB
                </div>
                <div class="info-item">
                    🔒 XSS · CSRF · Rate Limit · IP Flood
                </div>
            </div>
        </div>
        
        <div class="chat-messages" id="chatMessages">
            <div class="loading">Загрузка сообщений...</div>
        </div>
        
        <!-- Панель эмодзи -->
        <div class="emoji-picker" id="emojiPicker">
            <div class="emoji-picker-header">
                <span class="emoji-picker-title">Выберите смайлик</span>
                <button class="emoji-close" id="emojiClose">×</button>
            </div>
            <div class="emoji-categories">
                <button class="emoji-category active" data-category="smileys">😊 Эмоции</button>
                <button class="emoji-category" data-category="gestures">👋 Жесты</button>
                <button class="emoji-category" data-category="animals">🐱 Животные</button>
                <button class="emoji-category" data-category="food">🍕 Еда</button>
                <button class="emoji-category" data-category="activities">⚽ Активности</button>
                <button class="emoji-category" data-category="objects">💡 Объекты</button>
                <button class="emoji-category" data-category="symbols">❤️ Символы</button>
            </div>
            <div class="emoji-grid" id="emojiGrid"></div>
        </div>
        
        <div class="chat-input-container">
            <div class="input-group">
                <input 
                    type="text" 
                    id="usernameInput" 
                    placeholder="Ваше имя (мин <?php echo USERNAME_MIN_LENGTH; ?>, макс <?php echo USERNAME_MAX_LENGTH; ?> символов)" 
                    maxlength="<?php echo USERNAME_MAX_LENGTH; ?>"
                    autocomplete="off"
                >
            </div>
            
            <div class="input-group">
                <textarea 
                    id="messageInput" 
                    placeholder="Напишите сообщение (макс <?php echo MAX_MESSAGE_LENGTH; ?> символов)..." 
                    maxlength="<?php echo MAX_MESSAGE_LENGTH; ?>"
                    rows="1"
                ></textarea>
                <div class="input-buttons">
                    <button id="emojiButton" class="emoji-button" title="Добавить смайлик">😊</button>
                    <button id="sendButton" class="send-button" title="Отправить">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div class="char-counter" id="charCounter">
                <span id="charCount">0</span> / <?php echo MAX_MESSAGE_LENGTH; ?>
            </div>
        </div>
    </div>

    <script>
        const MAX_LENGTH = <?php echo MAX_MESSAGE_LENGTH; ?>;
        const WARNING_THRESHOLD = Math.floor(MAX_LENGTH * 0.8);
        const DANGER_THRESHOLD = Math.floor(MAX_LENGTH * 0.95);
        const CHECK_INTERVAL = 7000;
        const STATS_UPDATE_INTERVAL = 15000;

        // Коллекция эмодзи по категориям
        const EMOJI_DATA = {
            smileys: ['😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃', '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😙', '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭', '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '🤥', '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '🥵', '🥶', '😎', '🤓', '🧐', '😕', '😟', '🙁', '☹️', '😮', '😯', '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭', '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬'],
            gestures: ['👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '🖕', '👇', '☝️', '👍', '👎', '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅', '🤳', '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃', '🧠', '🦷', '🦴', '👀', '👁️', '👅', '👄'],
            animals: ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮', '🐷', '🐽', '🐸', '🐵', '🙈', '🙉', '🙊', '🐒', '🐔', '🐧', '🐦', '🐤', '🐣', '🐥', '🦆', '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄', '🐝', '🐛', '🦋', '🐌', '🐞', '🐜', '🦟', '🦗', '🕷️', '🦂', '🐢', '🐍', '🦎', '🦖', '🦕', '🐙', '🦑', '🦐', '🦞', '🦀', '🐡', '🐠', '🐟', '🐬', '🐳', '🐋', '🦈', '🐊', '🐅', '🐆', '🦓', '🦍', '🦧', '🐘', '🦛', '🦏', '🐪', '🐫', '🦒', '🦘', '🐃', '🐂', '🐄', '🐎', '🐖', '🐏', '🐑', '🦙', '🐐', '🦌', '🐕', '🐩', '🦮', '🐈', '🐓', '🦃', '🦚', '🦜', '🦢', '🦩', '🕊️', '🐇', '🦝', '🦨', '🦡', '🦦', '🦥', '🐁', '🐀', '🐿️', '🦔'],
            food: ['🍎', '🍏', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🍈', '🍒', '🍑', '🥭', '🍍', '🥥', '🥝', '🍅', '🍆', '🥑', '🥦', '🥬', '🥒', '🌶️', '🌽', '🥕', '🧄', '🧅', '🥔', '🍠', '🥐', '🥯', '🍞', '🥖', '🥨', '🧀', '🥚', '🍳', '🧈', '🥞', '🧇', '🥓', '🥩', '🍗', '🍖', '🦴', '🌭', '🍔', '🍟', '🍕', '🥪', '🥙', '🧆', '🌮', '🌯', '🥗', '🥘', '🥫', '🍝', '🍜', '🍲', '🍛', '🍣', '🍱', '🥟', '🦪', '🍤', '🍙', '🍚', '🍘', '🍥', '🥠', '🥮', '🍢', '🍡', '🍧', '🍨', '🍦', '🥧', '🧁', '🍰', '🎂', '🍮', '🍭', '🍬', '🍫', '🍿', '🍩', '🍪', '🌰', '🥜', '🍯', '🥛', '🍼', '☕', '🍵', '🧃', '🥤', '🍶', '🍺', '🍻', '🥂', '🍷', '🥃', '🍸', '🍹', '🧉', '🍾', '🧊'],
            activities: ['⚽', '🏀', '🏈', '⚾', '🥎', '🎾', '🏐', '🏉', '🥏', '🎱', '🪀', '🏓', '🏸', '🏒', '🏑', '🥍', '🏏', '🥅', '⛳', '🪁', '🏹', '🎣', '🤿', '🥊', '🥋', '🎽', '🛹', '🛷', '⛸️', '🥌', '🎿', '⛷️', '🏂', '🪂', '🏋️', '🤼', '🤸', '🤺', '⛹️', '🤾', '🏌️', '🏇', '🧘', '🏊', '🏄', '🚣', '🧗', '🚵', '🚴', '🏆', '🥇', '🥈', '🥉', '🏅', '🎖️', '🎗️', '🎫', '🎟️', '🎪', '🎭', '🎨', '🎬', '🎤', '🎧', '🎼', '🎹', '🥁', '🎷', '🎺', '🎸', '🪕', '🎻', '🎲', '♟️', '🎯', '🎳', '🎮', '🎰', '🧩'],
            objects: ['⌚', '📱', '📲', '💻', '⌨️', '🖥️', '🖨️', '🖱️', '🖲️', '🕹️', '🗜️', '💾', '💿', '📀', '📼', '📷', '📸', '📹', '🎥', '📽️', '🎞️', '📞', '☎️', '📟', '📠', '📺', '📻', '🎙️', '🎚️', '🎛️', '🧭', '⏱️', '⏲️', '⏰', '🕰️', '⌛', '⏳', '📡', '🔋', '🔌', '💡', '🔦', '🕯️', '🪔', '🧯', '🛢️', '💸', '💵', '💴', '💶', '💷', '💰', '💳', '💎', '⚖️', '🧰', '🔧', '🔨', '⚒️', '🛠️', '⛏️', '🔩', '⚙️', '🧱', '⛓️', '🧲', '🔫', '💣', '🧨', '🪓', '🔪', '🗡️', '⚔️', '🛡️', '🚬', '⚰️', '⚱️', '🏺', '🔮', '📿', '🧿', '💈', '⚗️', '🔭', '🔬', '🕳️', '💊', '💉', '🩸', '🩹', '🩺', '🌡️', '🧬', '🦠', '🧫', '🧪', '🌋', '🗿', '🛎️', '🧳', '⌛', '⏳', '⌚', '⏰', '⏱️', '⏲️', '🕰️'],
            symbols: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️', '✝️', '☪️', '🕉️', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐', '⛎', '♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓', '🆔', '⚛️', '🉑', '☢️', '☣️', '📴', '📳', '🈶', '🈚', '🈸', '🈺', '🈷️', '✴️', '🆚', '💮', '🉐', '㊙️', '㊗️', '🈴', '🈵', '🈹', '🈲', '🅰️', '🅱️', '🆎', '🆑', '🅾️', '🆘', '❌', '⭕', '🛑', '⛔', '📛', '🚫', '💯', '💢', '♨️', '🚷', '🚯', '🚳', '🚱', '🔞', '📵', '🚭', '❗', '❕', '❓', '❔', '‼️', '⁉️', '🔅', '🔆', '〽️', '⚠️', '🚸', '🔱', '⚜️', '🔰', '♻️', '✅', '🈯', '💹', '❇️', '✳️', '❎', '🌐', '💠', 'Ⓜ️', '🌀', '💤', '🏧', '🚾', '♿', '🅿️', '🈳', '🈂️', '🛂', '🛃', '🛄', '🛅', '🚹', '🚺', '🚼', '🚻', '🚮', '🎦', '📶', '🈁', '🔣', 'ℹ️', '🔤', '🔡', '🔠', '🆖', '🆗', '🆙', '🆒', '🆕', '🆓', '0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟', '🔢', '#️⃣', '*️⃣', '⏏️', '▶️', '⏸️', '⏯️', '⏹️', '⏺️', '⏭️', '⏮️', '⏩', '⏪', '⏫', '⏬', '◀️', '🔼', '🔽', '➡️', '⬅️', '⬆️', '⬇️', '↗️', '↘️', '↙️', '↖️', '↕️', '↔️', '↪️', '↩️', '⤴️', '⤵️', '🔀', '🔁', '🔂', '🔄', '🔃', '🎵', '🎶', '➕', '➖', '➗', '✖️', '♾️', '💲', '💱', '™️', '©️', '®️', '〰️', '➰', '➿', '🔚', '🔙', '🔛', '🔝', '🔜', '✔️', '☑️', '🔘', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⚫', '⚪', '🟤', '🔺', '🔻', '🔸', '🔹', '🔶', '🔷', '🔳', '🔲', '▪️', '▫️', '◾', '◽', '◼️', '◻️', '🟥', '🟧', '🟨', '🟩', '🟦', '🟪', '⬛', '⬜', '🟫', '🔈', '🔇', '🔉', '🔊', '🔔', '🔕', '📣', '📢', '👁️‍🗨️', '💬', '💭', '🗯️', '♠️', '♣️', '♥️', '♦️', '🃏', '🎴', '🀄', '🕐', '🕑', '🕒', '🕓', '🕔', '🕕', '🕖', '🕗', '🕘', '🕙', '🕚', '🕛', '🕜', '🕝', '🕞', '🕟', '🕠', '🕡', '🕢', '🕣', '🕤', '🕥', '🕦', '🕧']
        };

        class GuestChat {
            constructor() {
                this.csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                this.lastMessageTimestamp = 0;
                this.checkInterval = null;
                this.statsInterval = null;
                this.isLoading = false;
                this.currentEmojiCategory = 'smileys';
                
                this.initElements();
                this.attachEvents();
                this.initEmojiPicker();
                this.loadMessages();
                this.updateStats();
                this.startAutoCheck();
                
                const savedUsername = localStorage.getItem('chat_username');
                if (savedUsername) {
                    this.usernameInput.value = savedUsername;
                }
            }
            
            initElements() {
                this.messagesContainer = document.getElementById('chatMessages');
                this.messageInput = document.getElementById('messageInput');
                this.usernameInput = document.getElementById('usernameInput');
                this.sendButton = document.getElementById('sendButton');
                this.charCount = document.getElementById('charCount');
                this.charCounter = document.getElementById('charCounter');
                this.onlineCount = document.getElementById('onlineCount');
                this.messageCount = document.getElementById('messageCount');
                this.memoryUsage = document.getElementById('memoryUsage');
                this.emojiButton = document.getElementById('emojiButton');
                this.emojiPicker = document.getElementById('emojiPicker');
                this.emojiClose = document.getElementById('emojiClose');
                this.emojiGrid = document.getElementById('emojiGrid');
            }
            
            attachEvents() {
                this.sendButton.addEventListener('click', () => this.sendMessage());
                
                this.messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
                
                this.messageInput.addEventListener('input', () => {
                    this.updateCharCount();
                    this.autoResize();
                });
                
                this.usernameInput.addEventListener('input', () => {
                    localStorage.setItem('chat_username', this.usernameInput.value);
                });
                
                // Эмодзи события
                this.emojiButton.addEventListener('click', () => this.toggleEmojiPicker());
                this.emojiClose.addEventListener('click', () => this.hideEmojiPicker());
                
                // Закрытие эмодзи панели при клике вне её
                document.addEventListener('click', (e) => {
                    if (!this.emojiPicker.contains(e.target) && e.target !== this.emojiButton) {
                        this.hideEmojiPicker();
                    }
                });
                
                window.addEventListener('focus', () => {
                    this.checkForNewMessages();
                });
            }
            
            initEmojiPicker() {
                // Инициализация категорий
                const categories = document.querySelectorAll('.emoji-category');
                categories.forEach(cat => {
                    cat.addEventListener('click', (e) => {
                        categories.forEach(c => c.classList.remove('active'));
                        cat.classList.add('active');
                        this.currentEmojiCategory = cat.dataset.category;
                        this.renderEmojis();
                    });
                });
                
                // Отрисовка эмодзи первой категории
                this.renderEmojis();
            }
            
            renderEmojis() {
                const emojis = EMOJI_DATA[this.currentEmojiCategory] || [];
                this.emojiGrid.innerHTML = '';
                
                emojis.forEach(emoji => {
                    const button = document.createElement('button');
                    button.className = 'emoji-item';
                    button.textContent = emoji;
                    button.addEventListener('click', () => this.insertEmoji(emoji));
                    this.emojiGrid.appendChild(button);
                });
            }
            
            toggleEmojiPicker() {
                this.emojiPicker.classList.toggle('active');
            }
            
            hideEmojiPicker() {
                this.emojiPicker.classList.remove('active');
            }
            
            insertEmoji(emoji) {
                const cursorPos = this.messageInput.selectionStart;
                const textBefore = this.messageInput.value.substring(0, cursorPos);
                const textAfter = this.messageInput.value.substring(cursorPos);
                
                this.messageInput.value = textBefore + emoji + textAfter;
                this.messageInput.selectionStart = this.messageInput.selectionEnd = cursorPos + emoji.length;
                
                this.messageInput.focus();
                this.updateCharCount();
                this.autoResize();
                
                // Не закрываем панель, чтобы можно было добавить несколько эмодзи
            }
            
            updateCharCount() {
                const length = this.messageInput.value.length;
                this.charCount.textContent = length;
                
                this.charCounter.classList.remove('warning', 'danger');
                this.messageInput.classList.remove('warning', 'danger');
                
                if (length >= DANGER_THRESHOLD) {
                    this.charCounter.classList.add('danger');
                    this.messageInput.classList.add('danger');
                } else if (length >= WARNING_THRESHOLD) {
                    this.charCounter.classList.add('warning');
                    this.messageInput.classList.add('warning');
                }
            }
            
            autoResize() {
                this.messageInput.style.height = 'auto';
                this.messageInput.style.height = this.messageInput.scrollHeight + 'px';
            }
            
            async sendMessage() {
                const username = this.usernameInput.value.trim();
                const message = this.messageInput.value.trim();
                
                if (!username || !message) {
                    this.showError('Заполните имя и сообщение');
                    return;
                }
                
                this.sendButton.disabled = true;
                this.hideEmojiPicker();
                
                try {
                    const response = await fetch('?api=send', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            username: username,
                            message: message,
                            csrf_token: this.csrfToken
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        this.messageInput.value = '';
                        this.updateCharCount();
                        this.autoResize();
                        
                        await this.loadNewMessages();
                        this.updateStats();
                    } else {
                        this.showError(data.error || 'Ошибка отправки сообщения');
                        
                        if (data.error && data.error.includes('CSRF')) {
                            await this.refreshCsrfToken();
                        }
                    }
                } catch (error) {
                    this.showError('Ошибка соединения с сервером');
                    console.error('Error:', error);
                } finally {
                    this.sendButton.disabled = false;
                }
            }
            
            async loadMessages() {
                if (this.isLoading) return;
                this.isLoading = true;
                
                try {
                    const response = await fetch('?api=messages');
                    const data = await response.json();
                    
                    if (data.success) {
                        this.lastMessageTimestamp = data.lastTimestamp || 0;
                        this.renderMessages(data.messages, false);
                    }
                } catch (error) {
                    console.error('Error loading messages:', error);
                } finally {
                    this.isLoading = false;
                }
            }
            
            async loadNewMessages() {
                if (this.isLoading) return;
                this.isLoading = true;
                
                try {
                    const response = await fetch(`?api=messages&after=${this.lastMessageTimestamp}`);
                    const data = await response.json();
                    
                    if (data.success && data.messages.length > 0) {
                        this.lastMessageTimestamp = data.lastTimestamp || this.lastMessageTimestamp;
                        this.appendMessages(data.messages);
                    }
                } catch (error) {
                    console.error('Error loading new messages:', error);
                } finally {
                    this.isLoading = false;
                }
            }
            
            async checkForNewMessages() {
                if (this.isLoading) return;
                
                try {
                    const response = await fetch(`?api=check_new&last=${this.lastMessageTimestamp}`);
                    const data = await response.json();
                    
                    if (data.success && data.hasNew) {
                        await this.loadNewMessages();
                    }
                } catch (error) {
                    console.error('Error checking new messages:', error);
                }
            }
            
            async updateStats() {
                try {
                    const response = await fetch('?api=stats');
                    const data = await response.json();
                    
                    if (data.success) {
                        this.onlineCount.textContent = data.stats.online;
                        this.messageCount.textContent = data.stats.messages;
                        this.memoryUsage.textContent = data.stats.memory_mb;
                    }
                } catch (error) {
                    console.error('Error updating stats:', error);
                }
            }
            
            renderMessages(messages, shouldScroll = true) {
                if (messages.length === 0) {
                    this.messagesContainer.innerHTML = '<div class="loading">Пока нет сообщений. Будьте первым! 😊</div>';
                    return;
                }
                
                const wasScrolledToBottom = shouldScroll ? this.isScrolledToBottom() : true;
                
                this.messagesContainer.innerHTML = '';
                
                messages.forEach(msg => {
                    const messageEl = this.createMessageElement(msg);
                    this.messagesContainer.appendChild(messageEl);
                });
                
                if (wasScrolledToBottom) {
                    this.scrollToBottom();
                }
            }
            
            appendMessages(messages) {
                if (messages.length === 0) return;
                
                const wasScrolledToBottom = this.isScrolledToBottom();
                
                const loading = this.messagesContainer.querySelector('.loading');
                if (loading) {
                    loading.remove();
                }
                
                messages.forEach(msg => {
                    if (!this.messagesContainer.querySelector(`[data-id="${msg.id}"]`)) {
                        const messageEl = this.createMessageElement(msg);
                        this.messagesContainer.appendChild(messageEl);
                    }
                });
                
                if (wasScrolledToBottom) {
                    this.scrollToBottom();
                }
            }
            
            createMessageElement(msg) {
                const div = document.createElement('div');
                div.className = 'message';
                div.dataset.id = msg.id;
                
                const time = new Date(msg.date).toLocaleTimeString('ru-RU', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                div.innerHTML = `
                    <div class="message-header">
                        <span class="message-username">${msg.username}</span>
                        <span class="message-time">${time}</span>
                    </div>
                    <div class="message-content">${this.linkify(msg.message)}</div>
                `;
                
                return div;
            }
            
            linkify(text) {
                const urlRegex = /(https?:\/\/[^\s]+)/g;
                return text.replace(urlRegex, (url) => {
                    return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
                });
            }
            
            async refreshCsrfToken() {
                try {
                    const response = await fetch('?api=token');
                    const data = await response.json();
                    
                    if (data.success) {
                        this.csrfToken = data.token;
                        document.querySelector('meta[name="csrf-token"]').content = data.token;
                    }
                } catch (error) {
                    console.error('Error refreshing CSRF token:', error);
                }
            }
            
            showError(message) {
                const existingError = document.querySelector('.error-message');
                if (existingError) {
                    existingError.remove();
                }
                
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                errorDiv.textContent = message;
                
                this.messagesContainer.parentElement.insertBefore(
                    errorDiv,
                    this.messagesContainer.nextSibling
                );
                
                setTimeout(() => errorDiv.remove(), 5000);
            }
            
            isScrolledToBottom() {
                const threshold = 100;
                const position = this.messagesContainer.scrollTop + this.messagesContainer.clientHeight;
                const height = this.messagesContainer.scrollHeight;
                return position > height - threshold;
            }
            
            scrollToBottom() {
                this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
            }
            
            startAutoCheck() {
                this.checkInterval = setInterval(() => {
                    this.checkForNewMessages();
                }, CHECK_INTERVAL);
                
                this.statsInterval = setInterval(() => {
                    this.updateStats();
                }, STATS_UPDATE_INTERVAL);
            }
            
            destroy() {
                if (this.checkInterval) {
                    clearInterval(this.checkInterval);
                }
                if (this.statsInterval) {
                    clearInterval(this.statsInterval);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            window.chat = new GuestChat();
        });
    </script>
</body>
</html>
