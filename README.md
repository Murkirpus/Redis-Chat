# 💬 Гостевой Чат на PHP + Redis

Защищённый публичный чат без регистрации с умным обновлением и эмодзи.

## 🚀 Возможности

- ✅ **Без регистрации** - просто укажите имя и пишите
- ✅ **Умное обновление** - проверка новых сообщений каждые 7 секунд (без перезагрузки всех данных)
- ✅ **800+ эмодзи** - 7 категорий смайликов
- ✅ **Полная защита**:
  - XSS (экранирование на входе и выходе)
  - CSRF (токены с проверкой времени)
  - Rate Limiting (3 сообщения/минуту на пользователя)
  - IP Flood (10 сообщений/минуту с одного IP)
- ✅ **TTL сообщений** - автоудаление через 24 часа
- ✅ **Защита от переполнения** - лимит 10,000 сообщений в Redis
- ✅ **Счётчик онлайн** - показывает активных пользователей
- ✅ **Адаптивный дизайн** - работает на мобильных
- ✅ **Автоссылки** - URL автоматически становятся кликабельными

## 📋 Требования

- PHP 7.4+
- Redis Server
- PHP Redis расширение
- Веб-сервер (Apache/Nginx)

## ⚙️ Установка

### 1. Установите Redis:
```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install redis-server php-redis

# CentOS/RHEL
sudo yum install redis php-redis

# macOS
brew install redis
```

### 2. Запустите Redis:
```bash
# Linux
sudo service redis-server start

# macOS
brew services start redis

# Проверка
redis-cli ping  # должно вернуть PONG
```

### 3. Установите файл:
```bash
# Скопируйте index.php в директорию веб-сервера
sudo cp index.php /var/www/html/chat/

# Установите права
sudo chmod 644 /var/www/html/chat/index.php
```

### 4. Откройте в браузере:
```
http://localhost/chat/
```

## 🔧 Конфигурация

Откройте `index.php` и измените константы в секции `КОНФИГУРАЦИЯ`:

```php
// Redis подключение
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');

// Лимиты
define('MAX_MESSAGE_LENGTH', 500);        // Длина сообщения
define('MESSAGE_RATE_LIMIT', 3);          // Сообщений в минуту
define('MAX_MESSAGES_TOTAL', 10000);      // Макс сообщений в Redis
define('MESSAGE_TTL', 86400);             // TTL 24 часа

// Интервалы обновления (в JavaScript)
const CHECK_INTERVAL = 7000;              // Проверка новых (7 сек)
const STATS_UPDATE_INTERVAL = 15000;      // Обновление статистики (15 сек)
```

## 📊 Статистика защиты

- **XSS**: двойное экранирование (strip_tags + htmlspecialchars)
- **CSRF**: токены с SHA-256 и проверкой времени жизни
- **Rate Limit**: Redis-based ограничения
- **Memory**: контроль использования RAM (лимит 100MB)
- **TTL**: автоочистка старых сообщений
- **IP Hash**: SHA-256 хеширование для конфиденциальности

## 🎨 Технологии

- **Backend**: PHP 7.4+
- **Storage**: Redis (Sorted Sets для эффективной работы с TTL)
- **Frontend**: Vanilla JavaScript (без фреймворков)
- **Security**: CSRF tokens, XSS filtering, Rate limiting
- **UI**: CSS3 с градиентами и анимациями

## 📱 Особенности

- **Smart Updates**: загрузка только новых сообщений
- **Emoji Picker**: 7 категорий, 800+ эмодзи
- **Auto-scroll**: умная прокрутка к новым сообщениям
- **Link Detection**: автоматическое преобразование URL
- **Character Counter**: визуальная индикация лимита
- **Responsive**: адаптация под все экраны

## 🛡️ Безопасность

Чат включает защиту от:
- XSS атак
- CSRF атак
- SQL инъекций (не используется SQL)
- Флуда сообщений
- Переполнения памяти
- Спама повторяющихся символов

## 📝 Лицензия

MIT License - используйте свободно!

## 🤝 Автор

Создано с ❤️ и PHP

---

**Готово к использованию! Просто запустите и наслаждайтесь защищённым чатом! 🚀**
