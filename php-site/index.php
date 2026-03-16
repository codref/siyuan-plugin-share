<?php
declare(strict_types=1);

$exampleVersion = null;
$exampleCentralStatsUrl = null;
if (file_exists(__DIR__ . '/config.example.php')) {
    $exampleConfig = require __DIR__ . '/config.example.php';
    if (is_array($exampleConfig)) {
        if (array_key_exists('site_version', $exampleConfig)) {
            $exampleVersion = trim((string)($exampleConfig['site_version'] ?? ''));
        }
        if (array_key_exists('central_stats_url', $exampleConfig)) {
            $exampleCentralStatsUrl = trim((string)($exampleConfig['central_stats_url'] ?? ''));
        }
    }
}

$config = [
    'app_name' => 'SiYuan Note Share',
    'site_version' => '',
    'central_stats_url' => '',
    'db_path' => __DIR__ . '/storage/app.db',
    'uploads_dir' => __DIR__ . '/uploads',
    'allow_registration' => true,
    'default_storage_limit_mb' => 1024,
    'session_lifetime_days' => 30,
    'chunk_ttl_seconds' => 7200,
    'chunk_cleanup_probability' => 0.05,
    'chunk_cleanup_limit' => 20,
    'min_chunk_size_kb' => 256,
    'max_chunk_size_mb' => 8,
    'captcha_enabled' => true,
    'email_verification_enabled' => false,
    'email_from' => 'no-reply@example.com',
    'email_from_name' => 'SiYuan Note Share',
    'email_subject' => 'Email Verification Code',
    'email_reset_subject' => 'Password Reset Verification Code',
    'smtp_enabled' => false,
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_user' => '',
    'smtp_pass' => '',
];

if (file_exists(__DIR__ . '/config.php')) {
    $local = require __DIR__ . '/config.php';
    if (is_array($local)) {
        $config = array_merge($config, $local);
    }
}
if ($exampleVersion !== null) {
    $config['site_version'] = $exampleVersion;
}
if ($exampleCentralStatsUrl !== null) {
    $config['central_stats_url'] = $exampleCentralStatsUrl;
}

$sessionDays = (int)($config['session_lifetime_days'] ?? 30);
if ($sessionDays <= 0) {
    $sessionDays = 30;
}
$sessionLifetime = $sessionDays * 86400;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.cookie_lifetime', (string)$sessionLifetime);
session_set_cookie_params($sessionLifetime, '/', '', false, true);
session_start();
date_default_timezone_set('UTC');

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Shanghai');
}

require_once __DIR__ . '/vendor/Parsedown.php';

function base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');
    return $dir === '/' ? '' : $dir;
}

function base_url(): string {
    static $customBaseUrl = null;
    if ($customBaseUrl === null) {
        $customBaseUrl = trim((string)get_setting('site_base_url', ''));
        if ($customBaseUrl !== '') {
            $customBaseUrl = rtrim($customBaseUrl, '/');
        }
    }
    if ($customBaseUrl !== null && $customBaseUrl !== '') {
        return $customBaseUrl;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_path();
}

function redirect(string $path): void {
    header('Location: ' . base_path() . $path);
    exit;
}

function enqueue_background_task(callable $task): void {
    register_shutdown_function(function () use ($task) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $task();
    });
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo) {
        return $pdo;
    }
    $dbDir = dirname($config['db_path']);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }
    $dsn = 'sqlite:' . $config['db_path'];
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "user",
        api_key_hash TEXT,
        api_key_prefix TEXT,
        api_key_last4 TEXT,
        disabled INTEGER NOT NULL DEFAULT 0,
        storage_limit_bytes INTEGER NOT NULL DEFAULT 0,
        storage_used_bytes INTEGER NOT NULL DEFAULT 0,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        email_verified INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS shares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        doc_id TEXT,
        notebook_id TEXT,
        password_hash TEXT,
        expires_at INTEGER,
        access_count INTEGER NOT NULL DEFAULT 0,
        visitor_limit INTEGER NOT NULL DEFAULT 0,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        deleted_at TEXT,
        FOREIGN KEY(user_id) REFERENCES users(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        doc_id TEXT NOT NULL,
        title TEXT NOT NULL,
        icon TEXT,
        hpath TEXT,
        parent_id TEXT,
        sort_index INTEGER NOT NULL DEFAULT 0,
        markdown TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        content_hash TEXT,
        meta_hash TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        doc_id TEXT,
        asset_path TEXT NOT NULL,
        file_path TEXT NOT NULL,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        asset_hash TEXT,
        created_at TEXT NOT NULL,
        UNIQUE(share_id, asset_path),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        upload_id TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL,
        share_id INTEGER,
        type TEXT NOT NULL,
        doc_id TEXT,
        notebook_id TEXT,
        slug TEXT,
        title TEXT,
        password_hash TEXT,
        expires_at INTEGER,
        visitor_limit INTEGER NOT NULL DEFAULT 0,
        asset_manifest TEXT,
        doc_manifest TEXT,
        upload_mode TEXT,
        patch_manifest TEXT,
        status TEXT NOT NULL DEFAULT "pending",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_upload_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        upload_id TEXT NOT NULL,
        doc_id TEXT NOT NULL,
        title TEXT NOT NULL,
        icon TEXT,
        hpath TEXT,
        parent_id TEXT,
        sort_index INTEGER NOT NULL DEFAULT 0,
        markdown TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        content_hash TEXT,
        meta_hash TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER NOT NULL,
        key TEXT NOT NULL,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        PRIMARY KEY(user_id, key),
        FOREIGN KEY(user_id) REFERENCES users(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_access_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        share_id INTEGER NOT NULL,
        doc_id TEXT,
        doc_title TEXT,
        visitor_id TEXT,
        ip TEXT,
        ip_country TEXT,
        ip_country_code TEXT,
        ip_region TEXT,
        ip_city TEXT,
        referer TEXT,
        created_at TEXT NOT NULL,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_access_user_time ON share_access_logs (user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_access_share_time ON share_access_logs (share_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_access_visitor_time ON share_access_logs (visitor_id, created_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        parent_id INTEGER,
        user_id INTEGER,
        visitor_id TEXT,
        email TEXT NOT NULL,
        content TEXT NOT NULL,
        ip TEXT,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_comments_share ON share_comments (share_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_comments_parent ON share_comments (parent_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        visitor_id TEXT NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE(share_id, visitor_id),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_visitors_share ON share_visitors (share_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_access_geo_cache (
        ip TEXT PRIMARY KEY,
        country TEXT,
        country_code TEXT,
        region TEXT,
        city TEXT,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        share_title TEXT NOT NULL,
        share_slug TEXT NOT NULL,
        share_user_id INTEGER NOT NULL,
        reporter_user_id INTEGER,
        report_email TEXT,
        visitor_id TEXT,
        ip TEXT,
        reason_type TEXT NOT NULL,
        reason_detail TEXT,
        created_at TEXT NOT NULL,
        handled_at TEXT,
        handled_by INTEGER,
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_reports_share ON share_reports (share_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_reports_handled ON share_reports (handled_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS instance_heartbeats (
        instance_id TEXT PRIMARY KEY,
        first_seen TEXT NOT NULL,
        last_seen TEXT NOT NULL,
        version TEXT,
        ip TEXT
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_instance_heartbeats_seen ON instance_heartbeats (last_seen)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS recycled_share_ids (
        share_id INTEGER PRIMARY KEY,
        created_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS email_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        code_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        used_at TEXT,
        ip TEXT
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        code_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        used_at TEXT,
        ip TEXT,
        FOREIGN KEY(user_id) REFERENCES users(id)
    );');

    ensure_column($pdo, 'users', 'storage_limit_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'storage_used_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'must_change_password', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'email_verified', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'last_active_at', 'TEXT');
    ensure_column($pdo, 'shares', 'password_hash', 'TEXT');
    ensure_column($pdo, 'shares', 'expires_at', 'INTEGER');
    ensure_column($pdo, 'shares', 'access_count', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'visitor_limit', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'size_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'comment_notify', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_reports', 'report_email', 'TEXT');
    ensure_column($pdo, 'share_uploads', 'visitor_limit', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_uploads', 'upload_mode', 'TEXT');
    ensure_column($pdo, 'share_uploads', 'patch_manifest', 'TEXT');
    ensure_column($pdo, 'share_uploads', 'doc_manifest', 'TEXT');
    ensure_column($pdo, 'share_upload_docs', 'icon', 'TEXT');
    ensure_column($pdo, 'share_upload_docs', 'content_hash', 'TEXT');
    ensure_column($pdo, 'share_upload_docs', 'meta_hash', 'TEXT');
    ensure_column($pdo, 'share_docs', 'size_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_docs', 'icon', 'TEXT');
    ensure_column($pdo, 'share_docs', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_docs', 'parent_id', 'TEXT');
    ensure_column($pdo, 'share_docs', 'sort_index', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_docs', 'content_hash', 'TEXT');
    ensure_column($pdo, 'share_docs', 'meta_hash', 'TEXT');
    ensure_column($pdo, 'share_assets', 'size_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_assets', 'asset_hash', 'TEXT');

    seed_default_settings($pdo);
    seed_default_admin($pdo);
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($cols as $col) {
        if (isset($col['name']) && $col['name'] === $column) {
            return true;
        }
    }
    return false;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!table_has_column($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function fetch_setting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : null;
}

function ensure_setting(PDO $pdo, string $key, string $value): void {
    $existing = fetch_setting($pdo, $key);
    if ($existing !== null) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now(),
    ]);
}

function seed_default_settings(PDO $pdo): void {
    global $config;
    $defaultLimit = (int)$config['default_storage_limit_mb'] * 1024 * 1024;
    ensure_setting($pdo, 'allow_registration', $config['allow_registration'] ? '1' : '0');
    ensure_setting($pdo, 'default_storage_limit_bytes', (string)$defaultLimit);
    ensure_setting($pdo, 'captcha_enabled', $config['captcha_enabled'] ? '1' : '0');
    ensure_setting($pdo, 'email_verification_enabled', $config['email_verification_enabled'] ? '1' : '0');
    ensure_setting($pdo, 'email_from', (string)$config['email_from']);
    ensure_setting($pdo, 'email_from_name', (string)$config['email_from_name']);
    ensure_setting($pdo, 'email_subject', (string)$config['email_subject']);
    ensure_setting($pdo, 'email_reset_subject', (string)$config['email_reset_subject']);
    ensure_setting($pdo, 'smtp_enabled', $config['smtp_enabled'] ? '1' : '0');
    ensure_setting($pdo, 'smtp_host', (string)$config['smtp_host']);
    ensure_setting($pdo, 'smtp_port', (string)$config['smtp_port']);
    ensure_setting($pdo, 'smtp_secure', (string)$config['smtp_secure']);
    ensure_setting($pdo, 'smtp_user', (string)$config['smtp_user']);
    ensure_setting($pdo, 'smtp_pass', (string)$config['smtp_pass']);
    ensure_setting($pdo, 'banned_words', '');
    ensure_setting($pdo, 'site_icp', '');
    ensure_setting($pdo, 'site_contact_email', '');
    ensure_setting($pdo, 'site_base_url', '');
    ensure_setting($pdo, 'site_head_html', '');
    ensure_setting($pdo, 'access_stats_default_enabled', '1');
    ensure_setting($pdo, 'access_stats_default_retention_days', '7');
}

function seed_default_admin(PDO $pdo): void {
    $count = (int)$pdo->query('SELECT COUNT(*) AS cnt FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $now = now();
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
        VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
    $stmt->execute([
        ':username' => 'admin',
        ':email' => '',
        ':password_hash' => password_hash('123456', PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':api_key_hash' => null,
        ':api_key_prefix' => null,
        ':api_key_last4' => null,
        ':disabled' => 0,
        ':storage_limit_bytes' => 0,
        ':storage_used_bytes' => 0,
        ':must_change_password' => 1,
        ':email_verified' => 1,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        echo 'CSRF validation failed.';
        exit;
    }
}

function flash(string $key, ?string $value = null): ?string {
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }
    $val = $_SESSION['flash'][$key] ?? null;
    if (isset($_SESSION['flash'][$key])) {
        unset($_SESSION['flash'][$key]);
    }
    return $val;
}

function get_setting(string $key, ?string $default = null): ?string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && array_key_exists('value', $row)) {
        return $row['value'];
    }
    return $default;
}

function set_setting(string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)
        ON CONFLICT(key) DO UPDATE SET value = :value_update, updated_at = :updated_at_update');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now(),
        ':value_update' => $value,
        ':updated_at_update' => now(),
    ]);
}

function get_bool_setting(string $key, bool $default = false): bool {
    $value = get_setting($key, $default ? '1' : '0');
    return (int)$value === 1;
}

function get_user_setting(int $userId, string $key, ?string $default = null): ?string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT value FROM user_settings WHERE user_id = :uid AND key = :key LIMIT 1');
    $stmt->execute([
        ':uid' => $userId,
        ':key' => $key,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && array_key_exists('value', $row)) {
        return $row['value'];
    }
    return $default;
}

function set_user_setting(int $userId, string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, key, value, updated_at)
        VALUES (:uid, :key, :value, :updated_at)
        ON CONFLICT(user_id, key) DO UPDATE SET value = :value_update, updated_at = :updated_at_update');
    $stmt->execute([
        ':uid' => $userId,
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now(),
        ':value_update' => $value,
        ':updated_at_update' => now(),
    ]);
}

function access_stats_default_enabled(): bool {
    return get_bool_setting('access_stats_default_enabled', true);
}

function access_stats_default_retention_days(): int {
    $raw = (int)get_setting('access_stats_default_retention_days', '7');
    return max(1, min(365, $raw));
}

function access_stats_enabled(int $userId): bool {
    $value = get_user_setting($userId, 'access_stats_enabled', null);
    if ($value === null) {
        return access_stats_default_enabled();
    }
    return (int)$value === 1;
}

function access_stats_retention_days(int $userId): int {
    $value = get_user_setting($userId, 'access_stats_retention_days', null);
    if ($value === null) {
        return access_stats_default_retention_days();
    }
    $days = (int)$value;
    return max(1, min(365, $days));
}

function allow_registration(): bool {
    return get_bool_setting('allow_registration', true);
}

function captcha_enabled(): bool {
    return get_bool_setting('captcha_enabled', true);
}

function email_verification_enabled(): bool {
    return get_bool_setting('email_verification_enabled', false);
}

function smtp_enabled(): bool {
    return get_bool_setting('smtp_enabled', false);
}

function email_verification_available(): bool {
    return email_verification_enabled() && smtp_enabled();
}

function default_storage_limit_bytes(): int {
    $value = (int)get_setting('default_storage_limit_bytes', '0');
    return max(0, $value);
}

function get_banned_words_raw(): string {
    return (string)get_setting('banned_words', '');
}

function get_banned_words(): array {
    $raw = get_banned_words_raw();
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode('|', $raw));
    return array_values(array_filter($parts, fn($word) => $word !== ''));
}

function string_pos_ci(string $text, string $needle): ?int {
    if (function_exists('mb_stripos')) {
        $pos = mb_stripos($text, $needle, 0, 'UTF-8');
        return $pos === false ? null : (int)$pos;
    }
    $pos = stripos($text, $needle);
    return $pos === false ? null : (int)$pos;
}

function string_len(string $text): int {
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($text, 'UTF-8');
    }
    return strlen($text);
}

function string_sub(string $text, int $start, int $length): string {
    if (function_exists('mb_substr')) {
        return (string)mb_substr($text, $start, $length, 'UTF-8');
    }
    return substr($text, $start, $length);
}

function find_banned_word(string $text, array $words): ?array {
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $pos = string_pos_ci($text, $word);
        if ($pos !== null) {
            return ['word' => $word, 'pos' => $pos];
        }
    }
    return null;
}

function normalize_plain_text(string $text): string {
    $text = preg_replace('/\R+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function extract_snippet(string $text, string $word, int $radius = 40): string {
    $plain = normalize_plain_text($text);
    if ($plain === '') {
        return '';
    }
    $pos = string_pos_ci($plain, $word);
    if ($pos === null) {
        return string_sub($plain, 0, min(120, string_len($plain)));
    }
    $start = max(0, $pos - $radius);
    $length = min(string_len($plain) - $start, $radius * 2 + string_len($word));
    return string_sub($plain, $start, $length);
}

function parse_expires_at($raw): ?int {
    if ($raw === null || $raw === '' || $raw === false) {
        return null;
    }
    if (is_numeric($raw)) {
        $ts = (int)$raw;
        if ($ts > 1000000000000) {
            $ts = (int)floor($ts / 1000);
        }
        return $ts > 0 ? $ts : null;
    }
    $ts = strtotime((string)$raw);
    return $ts ? $ts : null;
}

function parse_visitor_limit($raw): ?int {
    if ($raw === null || $raw === '' || $raw === false) {
        return null;
    }
    if (is_numeric($raw)) {
        return max(0, (int)$raw);
    }
    return null;
}

function extract_front_matter(string $markdown): array {
    $meta = [];
    $body = $markdown;
    if (preg_match('/\A---\s*\R(.*?)\R---\s*\R/s', $markdown, $matches)) {
        $raw = trim((string)$matches[1]);
        $body = substr($markdown, strlen($matches[0]));
        foreach (preg_split('/\R/', $raw) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key === '') {
                continue;
            }
            $meta[strtolower($key)] = trim($value, " \t\"'");
        }
    }
    return ['meta' => $meta, 'body' => $body];
}

function format_meta_date(?string $raw): string {
    if (!$raw) {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts) {
        return date('Y-m-d H:i', $ts);
    }
    return $raw;
}

function render_meta_chips(array $meta): string {
    $created = $meta['date'] ?? $meta['created'] ?? $meta['created_at'] ?? '';
    $updated = $meta['lastmod'] ?? $meta['updated'] ?? $meta['modified'] ?? $meta['last_modified'] ?? '';
    $chips = [];
    if ($created !== '') {
        $chips[] = ['label' => 'Created', 'value' => format_meta_date($created)];
    }
    if ($updated !== '') {
        $chips[] = ['label' => 'Updated', 'value' => format_meta_date($updated)];
    }
    if (empty($chips)) {
        return '';
    }
    $html = '<div class="kb-meta">';
    foreach ($chips as $chip) {
        $label = htmlspecialchars($chip['label']);
        $value = htmlspecialchars($chip['value']);
        $html .= "<span class=\"kb-chip\"><strong>{$label}</strong> {$value}</span>";
    }
    $html .= '</div>';
    return $html;
}

function format_share_datetime(?string $raw): string {
    if (!$raw) {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts) {
        return date('Y-m-d H:i', $ts);
    }
    return $raw;
}

function mask_email(string $email): string {
    $email = trim($email);
    if ($email === '') {
        return '';
    }
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return str_repeat('*', max(2, strlen($email)));
    }
    [$local, $domain] = $parts;
    $local = trim($local);
    if ($local === '') {
        return str_repeat('*', 3) . '@' . $domain;
    }
    $len = strlen($local);
    if ($len <= 1) {
        $masked = $local . '**';
    } else {
        $maskLen = max(1, (int)floor($len / 2));
        $keepLen = $len - $maskLen;
        $keepStart = max(1, (int)ceil($keepLen / 2));
        $keepEnd = max(1, $keepLen - $keepStart);
        if ($keepStart + $keepEnd >= $len) {
            $keepStart = 1;
            $keepEnd = $len > 1 ? 1 : 0;
        }
        $masked = substr($local, 0, $keepStart)
            . str_repeat('*', $len - $keepStart - $keepEnd)
            . substr($local, $len - $keepEnd);
    }
    return $masked . '@' . $domain;
}

function calculate_comment_size(string $email, string $content): int {
    $size = strlen($email) + strlen($content);
    return max(1, $size);
}

function share_comment_size(int $shareId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_comments WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    return (int)$stmt->fetchColumn();
}

function comment_asset_prefix(): string {
    return 'comment-files/';
}

function share_comment_asset_size(int $shareId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_assets WHERE share_id = :share_id AND asset_path LIKE :prefix');
    $stmt->execute([
        ':share_id' => $shareId,
        ':prefix' => comment_asset_prefix() . '%',
    ]);
    return (int)$stmt->fetchColumn();
}

function extract_comment_asset_paths(string $content, int $shareId): array {
    $content = trim($content);
    if ($content === '') {
        return [];
    }
    $prefix = comment_asset_prefix() . $shareId . '/';
    $paths = [];
    $patterns = [
        '#(?:https?://[^\s\)\"\'<>]+)?[^\s\)\"\'<>]*?(?:/uploads/|uploads/)' . preg_quote($prefix, '#') . '([^\s\)\"\'<>]+)#i',
        '#(?:https?://[^\s\)\"\'<>]+)?[^\s\)\"\'<>]*?(?:/res/|res/)' . preg_quote($prefix, '#') . '([^\s\)\"\'<>]+)#i',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $content, $matches)) {
            continue;
        }
        foreach ($matches[1] as $suffix) {
            $suffix = preg_replace('/[?#].*$/', '', (string)$suffix);
            $decoded = rawurldecode((string)$suffix);
            $path = sanitize_asset_path($prefix . $decoded);
            if ($path === '') {
                continue;
            }
            $paths[$path] = true;
        }
    }
    return array_keys($paths);
}

function comment_asset_reference_needles(string $path): array {
    $normalized = ltrim(sanitize_asset_path($path), '/');
    if ($normalized === '') {
        return [];
    }
    $encoded = encode_path_segments($normalized);
    $needles = [
        'uploads/' . $normalized,
        'uploads/' . $encoded,
        '/res/' . $normalized,
        '/res/' . $encoded,
    ];
    $out = [];
    foreach ($needles as $needle) {
        if ($needle === '' || isset($out[$needle])) {
            continue;
        }
        $out[$needle] = true;
    }
    return array_keys($out);
}

function filter_unused_comment_assets(int $shareId, array $paths, array $excludeIds): array {
    if (empty($paths)) {
        return [];
    }
    $pdo = db();
    $unused = [];
    foreach ($paths as $path) {
        $needles = comment_asset_reference_needles((string)$path);
        if (empty($needles)) {
            continue;
        }
        $likeSql = [];
        $params = [$shareId];
        foreach ($needles as $needle) {
            $likeSql[] = 'content LIKE ?';
            $params[] = '%' . $needle . '%';
        }
        $sql = 'SELECT COUNT(*) FROM share_comments WHERE share_id = ? AND (' . implode(' OR ', $likeSql) . ')';
        if (!empty($excludeIds)) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_merge($params, $excludeIds);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();
        if ($count <= 0) {
            $unused[] = $path;
        }
    }
    return $unused;
}

function sum_share_asset_sizes(int $shareId, array $paths): int {
    if (empty($paths)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($paths), '?'));
    $params = $paths;
    array_unshift($params, $shareId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function delete_comment_assets(int $shareId, array $paths): int {
    if (empty($paths)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($paths), '?'));
    $params = $paths;
    array_unshift($params, $shareId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT asset_path, file_path, size_bytes FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        return 0;
    }
    global $config;
    $total = 0;
    foreach ($rows as $row) {
        $filePath = (string)($row['file_path'] ?? '');
        $size = (int)($row['size_bytes'] ?? 0);
        if ($filePath !== '') {
            $fullPath = $config['uploads_dir'] . '/' . ltrim($filePath, '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
        $total += $size;
    }
    $del = $pdo->prepare('DELETE FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
    $del->execute($params);
    return $total;
}

function adjust_share_size(int $shareId, int $delta): void {
    if ($delta === 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE shares SET size_bytes = CASE
        WHEN size_bytes + :delta < 0 THEN 0
        ELSE size_bytes + :delta
    END WHERE id = :id');
    $stmt->execute([
        ':delta' => $delta,
        ':id' => $shareId,
    ]);
}

function render_share_stats(array $share, string $extraHtml = ''): string {
    $count = (int)($share['access_count'] ?? 0);
    $created = format_share_datetime((string)($share['created_at'] ?? ''));
    $expiresAt = (int)($share['expires_at'] ?? 0);
    $visitorLimit = (int)($share['visitor_limit'] ?? 0);
    $chips = [];
    $chips[] = ['label' => 'Access Count', 'value' => $count . ' times'];
    if ($created !== '') {
        $chips[] = ['label' => 'Creation Time', 'value' => $created];
    }
    if ($expiresAt > 0) {
        $chips[] = ['label' => 'Expiration Time', 'value' => date('Y-m-d H:i', $expiresAt)];
    }
    if ($visitorLimit > 0) {
        $chips[] = ['label' => 'Visitor Limit', 'value' => $visitorLimit . ' people'];
    }
    if (empty($chips) && $extraHtml === '') {
        return '';
    }
    $html = '<div class="kb-meta">';
    foreach ($chips as $chip) {
        $label = htmlspecialchars($chip['label']);
        $value = htmlspecialchars($chip['value']);
        $html .= "<span class=\"kb-chip\"><strong>{$label}</strong> {$value}</span>";
    }
    if ($extraHtml !== '') {
        $html .= $extraHtml;
    }
    $html .= '</div>';
    return $html;
}

function format_bytes(float $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $idx = 0;
    $val = (float)$bytes;
    while ($val >= 1024 && $idx < count($units) - 1) {
        $val /= 1024;
        $idx++;
    }
    return sprintf('%.2f %s', $val, $units[$idx]);
}

function normalize_page_size($raw, int $default = 10): int {
    $allowed = [10, 50, 200, 1000];
    $size = (int)$raw;
    if (!in_array($size, $allowed, true)) {
        $size = $default;
    }
    return $size;
}

function paginate(int $total, int $page, int $size): array {
    $pages = max(1, (int)ceil($total / max(1, $size)));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $size;
    return [$page, $size, $pages, $offset];
}

function build_query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return base_path() . '/admin' . ($qs ? '?' . $qs : '');
}

function build_admin_query_url(string $hash, array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    $hashPart = $hash !== '' ? '#' . $hash : '';
    return base_path() . '/admin' . ($qs ? '?' . $qs : '') . $hashPart;
}

function build_dashboard_query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return base_path() . '/dashboard' . ($qs ? '?' . $qs : '') . '#shares';
}

function build_access_stats_query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return base_path() . '/dashboard' . ($qs ? '?' . $qs : '') . '#access-stats';
}

function render_hidden_inputs(array $values): string {
    $html = '';
    foreach ($values as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= '<input type="hidden" name="' . htmlspecialchars((string)$key) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
    return $html;
}

function bytes_from_mb(int $mb): int {
    if ($mb <= 0) {
        return 0;
    }
    return $mb * 1024 * 1024;
}

function mb_from_bytes(int $bytes): int {
    if ($bytes <= 0) {
        return 0;
    }
    return (int)floor($bytes / 1024 / 1024);
}

function render_share_icon_defs(): string {
    return <<<'SVG'
<svg class="kb-icon-defs" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden">
  <symbol id="sps-tree-arrow-collapsed" viewBox="0 0 24 24" fill="none">
    <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="sps-tree-arrow-expanded" viewBox="0 0 24 24" fill="none">
    <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="sps-tree-collapse-all" viewBox="0 0 24 24" fill="none">
    <path d="M4 20L11 13M11 13V17M11 13H7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M20 4L13 11M13 11V7M13 11H17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="sps-tree-expand-all" viewBox="0 0 24 24" fill="none">
    <path d="M11 13L4 20M4 20V16M4 20H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M13 11L20 4M20 4V8M20 4H16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
</svg>
SVG;
}

function render_page(string $title, string $content, ?array $user = null, string $baseHref = '', array $options = []): void {
    global $config;
    $base = base_path();
    $app = htmlspecialchars($config['app_name']);
    $pageTitle = htmlspecialchars($title);
    $userName = $user ? htmlspecialchars($user['username']) : '';
    $layout = isset($options['layout']) ? (string)$options['layout'] : ($user ? 'app' : 'public');
    $titleHtml = $pageTitle;
    if (!empty($options['title_html'])) {
        $titleHtml = (string)$options['title_html'];
    }
    $layoutClass = 'layout-' . preg_replace('/[^a-z0-9_-]+/i', '', $layout);
    $includeMarkdown = !empty($options['markdown']);
    $navKey = (string)($options['nav'] ?? '');
    if ($user && $layout !== 'auth' && $layout !== 'share') {
        maybe_send_instance_heartbeat();
    }
    echo "<!doctype html>";
    echo "<html lang='zh-CN'>";
    echo "<head>";
    echo "<meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>{$pageTitle} - {$app}</title>";
    if ($baseHref !== '') {
        $safeBase = htmlspecialchars($baseHref);
        echo "<base href='{$safeBase}'>";
    }
    if ($includeMarkdown) {
        echo "<link rel='stylesheet' href='{$base}/assets/vendor/github-markdown.min.css'>";
        echo "<link rel='stylesheet' href='{$base}/assets/vendor/katex.min.css'>";
        echo "<link rel='stylesheet' href='{$base}/assets/vendor/highlight.min.css'>";
    }
    echo "<link rel='stylesheet' href='{$base}/assets/style.css'>";
    $siteHeadHtml = (string)get_setting('site_head_html', '');
    if ($siteHeadHtml !== '') {
        echo $siteHeadHtml;
    }
    echo "</head>";
    echo "<body class='{$layoutClass}'>";

    if ($layout === 'auth') {
        echo "<div class='auth-shell'>";
        echo $content;
        echo "</div>";
    } elseif ($layout === 'share') {
        echo "<div class='share-page'>";
        echo render_share_icon_defs();
        echo $content;
        echo "<footer class='share-footer'>Powered by <a href='https://github.com/b8l8u8e8/siyuan-plugin-share' target='_blank' rel='noopener noreferrer'>b8l8u8e8</a></footer>";
        echo "<button class='share-side-trigger' type='button' data-share-drawer-open aria-label='Open sidebar'><svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M4 6h16v2H4zM4 11h16v2H4zM4 16h16v2H4z'/></svg><span>Nav</span></button>";
        echo "<div class='share-side-backdrop' data-share-drawer-close></div>";
        echo "<button class='scroll-top' type='button' data-scroll-top aria-label='Back to top'><svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M12 2c-2.76 0-5 2.24-5 5v2.5L4 13l4.5 1L12 22l3.5-8L20 13l-3-3.5V7c0-2.76-2.24-5-5-5zm0 3a2 2 0 0 1 2 2v1.5l-2 2-2-2V7a2 2 0 0 1 2-2z'/></svg></button>";
        echo "</div>";
    } elseif ($user) {
        $navItems = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => $base . '/dashboard'],
            ['key' => 'shares', 'label' => 'Shares', 'href' => $base . '/dashboard#shares'],
            ['key' => 'access-stats', 'label' => 'Access Stats', 'href' => $base . '/dashboard#access-stats'],
            ['key' => 'account', 'label' => 'Account Settings', 'href' => $base . '/account'],
        ];
        if (($user['role'] ?? '') === 'admin') {
            $reportBadge = pending_report_count();
            $navItems = [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => $base . '/dashboard'],
                ['key' => 'account', 'label' => 'Account Settings', 'href' => $base . '/account'],
                ['key' => 'admin-home', 'label' => 'Statistics', 'href' => $base . '/admin-home'],
                ['key' => 'admin-settings', 'label' => 'Site Settings', 'href' => $base . '/admin#settings'],
                ['key' => 'admin-announcements', 'label' => 'Announcements', 'href' => $base . '/admin#announcements'],
                ['key' => 'admin-reports', 'label' => 'Report Management', 'href' => $base . '/admin#reports', 'badge' => $reportBadge],
                ['key' => 'admin-users', 'label' => 'User Management', 'href' => $base . '/admin#users'],
                ['key' => 'admin-shares', 'label' => 'Share Management', 'href' => $base . '/admin#shares'],
                ['key' => 'admin-chunks', 'label' => 'Chunk Cleanup', 'href' => $base . '/admin#chunks'],
                ['key' => 'admin-scan', 'label' => 'Banned Word Scan', 'href' => $base . '/admin#scan'],
            ];
        }
        echo "<div class='app-shell'>";
        echo "<aside class='app-sidebar'>";
        echo "<div class='app-logo'>{$app}</div>";
        echo "<nav class='app-nav'>";
        foreach ($navItems as $item) {
            $active = $navKey === $item['key'] ? ' is-active' : '';
            $label = htmlspecialchars($item['label']);
            $badge = (int)($item['badge'] ?? 0);
            $hrefRaw = (string)$item['href'];
            $hashPos = strpos($hrefRaw, '#');
            $pathOnly = $hashPos === false ? $hrefRaw : substr($hrefRaw, 0, $hashPos);
            $hash = $hashPos === false ? '' : substr($hrefRaw, $hashPos + 1);
            $href = htmlspecialchars($hrefRaw);
            $dataAttrs = ' data-nav-key="' . htmlspecialchars((string)$item['key']) . '"';
            $dataAttrs .= ' data-nav-path="' . htmlspecialchars($pathOnly) . '"';
            if ($hash !== '') {
                $dataAttrs .= ' data-nav-hash="' . htmlspecialchars($hash) . '"';
            }
            echo "<a class='nav-item{$active}' href='{$href}'{$dataAttrs}><span class='nav-dot'></span><span class='nav-label'>{$label}</span>";
            if ($badge > 0) {
                echo "<span class='nav-badge'>{$badge}</span>";
            }
            echo "</a>";
        }
        echo "</nav>";
        echo "</aside>";
        echo "<div class='app-main'>";
        echo "<header class='app-topbar'>";
        echo "<div class='topbar-left'>";
        echo "<button class='app-side-trigger' type='button' data-app-drawer-open aria-label='Open navigation'><svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M4 6h16v2H4zM4 11h16v2H4zM4 16h16v2H4z'/></svg></button>";
        echo "<div class='topbar-title'>{$titleHtml}</div>";
        echo "</div>";
        echo "<div class='topbar-right'>";
        echo "<span class='user-pill'>{$userName}</span>";
        echo "<form method='post' action='{$base}/logout' class='inline-form'>";
        echo "<input type='hidden' name='csrf' value='" . csrf_token() . "'>";
        echo "<button class='button ghost' type='submit'>Logout</button>";
        echo "</form>";
        echo "</div>";
        echo "</header>";
        echo "<main class='app-content'>";
        echo $content;
        echo "</main>";
        echo "<footer class='app-footer'>Powered by <a href='https://github.com/b8l8u8e8/siyuan-plugin-share' target='_blank' rel='noopener noreferrer'>b8l8u8e8</a></footer>";
        echo "</div>";
        echo "</div>";
        echo "<div class='app-side-backdrop' data-app-drawer-close></div>";
    } else {
        echo "<div class='public-shell'>";
        echo $content;
        echo "</div>";
    }

    if ($user && $layout === 'app') {
        $announcements = get_active_announcements();
        if (should_show_announcement_modal($announcements)) {
            echo render_announcement_modal($announcements);
        }
    }

    echo "<script defer src='{$base}/assets/app.js'></script>";
    if ($includeMarkdown) {
        echo "<script defer src='{$base}/assets/vendor/markdown-it.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-task-lists.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-emoji.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-footnote.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-deflist.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-mark.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-sub.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-sup.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-abbr.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-ins.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-container.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-anchor.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/katex.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/highlight.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/mermaid.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/pako.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/echarts.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/abcjs-basic-min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/raphael.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/flowchart.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/viz.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/full.render.js' onload=\"window.dispatchEvent(new Event('sps:markdown-ready'))\"></script>";
    }
    echo "</body>";
    echo "</html>";
    exit;
}

function render_404_page(): void {
    $base = base_path();
    $content = '<div class="error-page">';
    $content .= '<div class="error-page__scene">';
    $content .= '<div class="error-page__stars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>';
    $content .= '<div class="error-page__planet"><div class="error-page__planet-body"></div><div class="error-page__planet-ring"></div></div>';
    $content .= '<div class="error-page__satellite"></div>';
    $content .= '<div class="error-page__comet"></div>';
    $content .= '</div>';
    $content .= '<h1 class="error-page__code">404</h1>';
    $content .= '<h2 class="error-page__title">Page Not Found</h2>';
    $content .= '<p class="error-page__desc">The page you are looking for does not exist or may have been removed.</p>';
    $content .= '<a class="error-page__back" href="' . $base . '/">';
    $content .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';
    $content .= 'Back to Home</a>';
    $content .= '</div>';
    render_page('Page Not Found', $content, null);
}

function render_share_not_found_page(): void {
    $base = base_path();
    $content = '<div class="error-page">';
    $content .= '<div class="error-page__scene">';
    $content .= '<div class="error-page__stars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>';
    $content .= '<div class="error-page__signal"><i></i><i></i><i></i><i></i><div class="error-page__signal-core"></div></div>';
    $content .= '</div>';
    $content .= '<h2 class="error-page__title">Share Not Found</h2>';
    $content .= '<p class="error-page__desc">This share link does not exist or has been deleted by the owner.</p>';
    $content .= '<a class="error-page__back" href="' . $base . '/">';
    $content .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';
    $content .= 'Back to Home</a>';
    $content .= '</div>';
    render_page('Share Not Found', $content, null);
}

function generate_captcha_code(int $length = 5): string {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_at'] = time();
    return $code;
}

function captcha_url(): string {
    $base = base_path();
    return $base . '/captcha?ts=' . time();
}

function check_captcha(string $input): bool {
    if (!captcha_enabled()) {
        return true;
    }
    $expected = $_SESSION['captcha_code'] ?? '';
    $input = strtoupper(trim($input));
    $ok = $expected !== '' && $input === strtoupper($expected);
    if (!$ok) {
        generate_captcha_code();
    }
    return $ok;
}

function render_captcha_image(): void {
    if (!captcha_enabled()) {
        http_response_code(404);
        exit;
    }
    if (!function_exists('imagecreatetruecolor')) {
        http_response_code(500);
        echo 'GD extension required.';
        exit;
    }
    $code = generate_captcha_code();
    $width = 120;
    $height = 40;
    $scale = 1.2;
    $outWidth = (int)round($width * $scale);
    $outHeight = (int)round($height * $scale);
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        http_response_code(500);
        exit;
    }
    $bg = imagecolorallocate($image, 245, 248, 255);
    $fg = imagecolorallocate($image, 50, 88, 160);
    $noise = imagecolorallocate($image, 200, 210, 230);
    imagefilledrectangle($image, 0, 0, $width, $height, $bg);
    for ($i = 0; $i < 6; $i++) {
        imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $noise);
    }
    for ($i = 0; $i < 60; $i++) {
        imagesetpixel($image, random_int(0, $width), random_int(0, $height), $noise);
    }
    $x = 10;
    $y = 10;
    $chars = str_split($code);
    foreach ($chars as $char) {
        imagestring($image, 5, $x, $y + random_int(-3, 3), $char, $fg);
        $x += 18;
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $output = $image;
    if ($outWidth !== $width || $outHeight !== $height) {
        $scaled = imagecreatetruecolor($outWidth, $outHeight);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
        imagedestroy($image);
        $output = $scaled;
    }
    imagepng($output);
    imagedestroy($output);
    exit;
}

const SHARE_SLUG_MIN_LENGTH = 6;
const SHARE_SLUG_MAX_LENGTH = 32;

function sanitize_slug(string $slug): string {
    return strtolower(trim($slug));
}

function parse_requested_share_slug_or_fail($raw): string {
    $slug = sanitize_slug((string)$raw);
    if ($slug === '') {
        return '';
    }
    if (!preg_match('/^[a-z0-9]+$/', $slug)) {
        api_response(400, [
            'errorKey' => 'share.slug.invalid_chars',
            'min' => SHARE_SLUG_MIN_LENGTH,
            'max' => SHARE_SLUG_MAX_LENGTH,
        ], 'Invalid share link suffix');
    }
    $len = strlen($slug);
    if ($len < SHARE_SLUG_MIN_LENGTH || $len > SHARE_SLUG_MAX_LENGTH) {
        api_response(400, [
            'errorKey' => 'share.slug.invalid_length',
            'min' => SHARE_SLUG_MIN_LENGTH,
            'max' => SHARE_SLUG_MAX_LENGTH,
        ], 'Invalid share link suffix length');
    }
    return $slug;
}

function share_slug_exists(PDO $pdo, string $slug, int $excludeShareId = 0): bool {
    if ($slug === '') {
        return false;
    }
    if ($excludeShareId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM shares WHERE slug = :slug AND deleted_at IS NULL AND id != :id LIMIT 1');
        $stmt->execute([
            ':slug' => $slug,
            ':id' => $excludeShareId,
        ]);
        return !!$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT id FROM shares WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    return !!$stmt->fetchColumn();
}

function ensure_share_slug_available_or_fail(PDO $pdo, string $slug, int $excludeShareId = 0): void {
    if (!share_slug_exists($pdo, $slug, $excludeShareId)) {
        return;
    }
    api_response(409, [
        'errorKey' => 'share.slug.conflict',
        'min' => SHARE_SLUG_MIN_LENGTH,
        'max' => SHARE_SLUG_MAX_LENGTH,
    ], 'Share link already exists');
}

function allocate_random_share_slug_or_fail(PDO $pdo): string {
    for ($i = 0; $i < 20; $i++) {
        $slug = sanitize_slug(bin2hex(random_bytes(4)));
        if (!share_slug_exists($pdo, $slug)) {
            return $slug;
        }
    }
    api_response(500, [
        'errorKey' => 'share.slug.generate_failed',
        'min' => SHARE_SLUG_MIN_LENGTH,
        'max' => SHARE_SLUG_MAX_LENGTH,
    ], 'Unable to allocate unique share link');
}

function generate_api_key(): array {
    $raw = bin2hex(random_bytes(24));
    $hash = password_hash($raw, PASSWORD_DEFAULT);
    $prefix = substr($raw, 0, 8);
    $last4 = substr($raw, -4);
    return [$raw, $hash, $prefix, $last4];
}

function get_user_limit_bytes(array $user): int {
    $userLimit = isset($user['storage_limit_bytes']) ? (int)$user['storage_limit_bytes'] : 0;
    if ($userLimit > 0) {
        return $userLimit;
    }
    return default_storage_limit_bytes();
}

function recalculate_user_storage(int $userId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM shares WHERE user_id = :uid AND deleted_at IS NULL');
    $stmt->execute([':uid' => $userId]);
    $shareTotal = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $logStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid');
    $logStmt->execute([':uid' => $userId]);
    $logTotal = (int)($logStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $total = $shareTotal + $logTotal;
    $update = $pdo->prepare('UPDATE users SET storage_used_bytes = :total WHERE id = :id');
    $update->execute([':total' => $total, ':id' => $userId]);
    return $total;
}

function adjust_user_storage(int $userId, int $delta): void {
    if ($delta === 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET storage_used_bytes = CASE
        WHEN storage_used_bytes + :delta < 0 THEN 0
        ELSE storage_used_bytes + :delta
    END WHERE id = :id');
    $stmt->execute([
        ':delta' => $delta,
        ':id' => $userId,
    ]);
}

function get_user_by_id(int $userId): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_client_ip(): string {
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];
    foreach ($candidates as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
            $value = $parts[0] ?? '';
        }
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function get_visitor_id(): string {
    $existing = trim((string)($_COOKIE['sps_uv'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }
    $id = bin2hex(random_bytes(12));
    setcookie('sps_uv', $id, time() + 86400 * 365, '/', '', false, true);
    $_COOKIE['sps_uv'] = $id;
    return $id;
}

function http_get_json(string $url): ?array {
    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'header' => "User-Agent: SiyuanShareAccessStats\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $converted = null;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,GBK,GB2312,GB18030');
    }
    if ($converted === null && function_exists('iconv')) {
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $raw);
        if ($converted === false || $converted === '') {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $raw);
        }
    }
    if ($converted) {
        $json = json_decode($converted, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return null;
}

function http_post_json(string $url, array $payload): ?array {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 2,
            'header' => "User-Agent: SiyuanShareAccessStats\r\nContent-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $converted = null;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,GBK,GB2312,GB18030');
    }
    if ($converted === null && function_exists('iconv')) {
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $raw);
        if ($converted === false || $converted === '') {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $raw);
        }
    }
    if ($converted) {
        $json = json_decode($converted, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return null;
}

function min_chunk_size_bytes(): int {
    global $config;
    $kb = (int)($config['min_chunk_size_kb'] ?? 256);
    if ($kb <= 0) {
        $kb = 256;
    }
    return $kb * 1024;
}

function max_chunk_size_bytes(): int {
    global $config;
    $mb = (int)($config['max_chunk_size_mb'] ?? 8);
    if ($mb <= 0) {
        $mb = 8;
    }
    return $mb * 1024 * 1024;
}

function chunk_size_limits(): array {
    $min = min_chunk_size_bytes();
    $max = max_chunk_size_bytes();
    if ($max < $min) {
        $max = $min;
    }
    return [$min, $max];
}

function site_version(): string {
    global $config;
    return trim((string)($config['site_version'] ?? ''));
}

function central_stats_url(): string {
    global $config;
    $raw = trim((string)($config['central_stats_url'] ?? ''));
    return rtrim($raw, '/');
}

function get_instance_id(): string {
    try {
        $existing = trim((string)get_setting('instance_id', ''));
        if ($existing !== '') {
            return $existing;
        }
        $generated = bin2hex(random_bytes(16));
        set_setting('instance_id', $generated);
        return $generated;
    } catch (Throwable $e) {
        return bin2hex(random_bytes(16));
    }
}

function maybe_send_instance_heartbeat(): void {
    $base = central_stats_url();
    if ($base === '') {
        return;
    }
    $interval = 10800;
    $lastAttempt = (int)get_setting('instance_heartbeat_attempt', '0');
    if ($lastAttempt > 0 && (time() - $lastAttempt) < $interval) {
        return;
    }
    set_setting('instance_heartbeat_attempt', (string)time());
    $payload = [
        'instance_id' => get_instance_id(),
        'version' => site_version(),
        'timestamp' => time(),
    ];
    $resp = http_post_json($base . '/api/instances/heartbeat', $payload);
    if (is_array($resp) && (int)($resp['code'] ?? 1) === 0) {
        set_setting('instance_heartbeat_at', (string)time());
    }
}

function fetch_latest_release_info(): ?array {
    $cachePath = __DIR__ . '/storage/release_cache.json';
    $ttl = 3600;
    if (is_file($cachePath) && (time() - filemtime($cachePath) < $ttl)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $latest = [
        'version' => '',
        'url' => '',
        'has_php_site' => false,
        'fetched_at' => time(),
    ];
    $perPage = 30;
    $page = 1;
    while (true) {
        $url = 'https://api.github.com/repos/b8l8u8e8/siyuan-plugin-share/releases?per_page=' . $perPage . '&page=' . $page;
        $data = http_get_json($url);
        if (!$data || !is_array($data)) {
            if ($page === 1) {
                return null;
            }
            break;
        }
        if (empty($data)) {
            break;
        }
        $keys = array_keys($data);
        if ($keys !== range(0, count($keys) - 1)) {
            if ($page === 1) {
                return null;
            }
            break;
        }
        foreach ($data as $release) {
            if (!is_array($release)) {
                continue;
            }
            $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
            $version = '';
            $hasPhpSite = false;
            foreach ($assets as $asset) {
                $name = (string)($asset['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (stripos($name, 'php-site') === false) {
                    continue;
                }
                $hasPhpSite = true;
                if (preg_match('/php-site-v(.+)\.zip/i', $name, $matches)) {
                    $version = trim((string)($matches[1] ?? ''));
                }
                break;
            }
            if ($hasPhpSite) {
                if ($version === '') {
                    $version = trim((string)($release['tag_name'] ?? $release['name'] ?? ''));
                }
                $latest['version'] = $version;
                $latest['url'] = (string)($release['html_url'] ?? '');
                $latest['has_php_site'] = true;
                $latest['fetched_at'] = time();
                break 2;
            }
        }
        if (count($data) < $perPage) {
            break;
        }
        $page++;
    }
    try {
        ensure_dir(dirname($cachePath));
        file_put_contents($cachePath, json_encode($latest, JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        // ignore cache failure
    }
    return $latest;
}

function site_update_info(): ?array {
    $current = site_version();
    maybe_send_instance_heartbeat();
    $latest = fetch_latest_release_info();
    if (!$latest || empty($latest['has_php_site']) || ($latest['version'] ?? '') === '') {
        return null;
    }
    $latestVersion = trim((string)$latest['version']);
    $currentVersion = trim((string)$current);
    $latestComparable = ltrim($latestVersion, 'vV');
    $currentComparable = ltrim($currentVersion, 'vV');
    $isNewer = false;
    if ($currentComparable === '') {
        $isNewer = true;
    } else {
        $isNewer = version_compare($latestComparable, $currentComparable, '>');
    }
    if (!$isNewer) {
        return null;
    }
    return [
        'version' => $latestVersion,
        'url' => (string)($latest['url'] ?? ''),
    ];
}

function fetch_central_instance_stats(): ?array {
    $base = central_stats_url();
    if ($base === '') {
        return null;
    }
    $cached = null;
    $cachedRaw = (string)get_setting('central_stats_cache', '');
    if ($cachedRaw !== '') {
        $cached = json_decode($cachedRaw, true);
        if (!is_array($cached)) {
            $cached = null;
        }
    }
    $resp = http_get_json($base . '/api/instances/stats');
    if (!is_array($resp) || (int)($resp['code'] ?? 1) !== 0) {
        return $cached;
    }
    $data = $resp['data'] ?? null;
    if (!is_array($data)) {
        return $cached;
    }
    $data['fetched_at'] = now();
    set_setting('central_stats_cache', json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

function build_date_range(int $days): array {
    $days = max(1, $days);
    $dates = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-{$i} days"));
    }
    return $dates;
}

function fill_series(array $dates, array $rows, string $field): array {
    $map = [];
    foreach ($rows as $row) {
        $day = (string)($row['day'] ?? '');
        if ($day === '') {
            continue;
        }
        $map[$day] = (float)($row[$field] ?? 0);
    }
    $series = [];
    foreach ($dates as $day) {
        $series[] = (float)($map[$day] ?? 0);
    }
    return $series;
}

function build_chart_paths(array $values, int $width, int $height, int $padding, float $maxValue): array {
    $count = count($values);
    if ($count === 0) {
        return ['line' => '', 'area' => ''];
    }
    $step = $count > 1 ? ($width - $padding * 2) / ($count - 1) : 0;
    $points = [];
    for ($i = 0; $i < $count; $i++) {
        $val = (float)$values[$i];
        $ratio = $maxValue > 0 ? ($val / $maxValue) : 0;
        $x = $padding + ($step * $i);
        $y = $height - $padding - ($ratio * ($height - $padding * 2));
        $points[] = [$x, $y];
    }
    $line = 'M ' . implode(' L ', array_map(fn($pt) => round($pt[0], 2) . ' ' . round($pt[1], 2), $points));
    $areaPoints = $points;
    $areaPoints[] = [$padding + ($step * ($count - 1)), $height - $padding];
    $areaPoints[] = [$padding, $height - $padding];
    $area = 'M ' . implode(' L ', array_map(fn($pt) => round($pt[0], 2) . ' ' . round($pt[1], 2), $areaPoints)) . ' Z';
    return ['line' => $line, 'area' => $area];
}

function render_chart_svg(array $seriesList, int $width = 360, int $height = 220): string {
    $maxValue = 0;
    foreach ($seriesList as $series) {
        foreach ($series['values'] as $value) {
            $maxValue = max($maxValue, (float)$value);
        }
    }
    if ($maxValue <= 0) {
        $maxValue = 1;
    }
    $padding = 12;
    $gridLines = [];
    foreach ([0.33, 0.66] as $ratio) {
        $y = $height - $padding - ($ratio * ($height - $padding * 2));
        $gridLines[] = '<line x1="' . $padding . '" y1="' . round($y, 2) . '" x2="' . ($width - $padding) . '" y2="' . round($y, 2) . '"></line>';
    }
    $paths = '';
    foreach ($seriesList as $series) {
        $pathsData = build_chart_paths($series['values'], $width, $height, $padding, $maxValue);
        $areaClass = $series['area_class'] ?? '';
        if ($areaClass !== '') {
            $paths .= '<path class="' . $areaClass . '" d="' . $pathsData['area'] . '"></path>';
        }
    }
    foreach ($seriesList as $series) {
        $lineClass = $series['line_class'] ?? '';
        $pathsData = build_chart_paths($series['values'], $width, $height, $padding, $maxValue);
        $paths .= '<path class="' . $lineClass . '" d="' . $pathsData['line'] . '"></path>';
    }
    return '<svg class="admin-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">'
        . '<g class="admin-chart__grid">' . implode('', $gridLines) . '</g>'
        . $paths
        . '</svg>';
}

function render_admin_chart_holder(array $labels, array $series, string $unit, string $fallbackSvg = ''): string {
    $payload = [
        'labels' => $labels,
        'series' => $series,
        'unit' => $unit,
    ];
    $data = htmlspecialchars(
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES
    );
    return '<div class="admin-chart-holder" data-admin-chart="' . $data . '">' . $fallbackSvg . '</div>';
}

function render_kpi_card(string $label, string $value, string $meta, string $iconSvg, string $extraHtml = ''): string {
    return '<div class="admin-kpi">'
        . '<div class="admin-kpi__head"><div><div class="admin-kpi__label">' . $label . '</div>'
        . '<div class="admin-kpi__value">' . $value . '</div></div>'
        . '<div class="admin-kpi__icon">' . $iconSvg . '</div></div>'
        . '<div class="admin-kpi__meta">' . $meta . '</div>'
        . $extraHtml
        . '</div>';
}

function build_topbar_title(string $title, ?array $user): string {
    $titleHtml = htmlspecialchars($title);
    $versionText = site_version();
    if ($versionText !== '') {
        $versionLabel = $versionText;
        if (stripos($versionLabel, 'v') !== 0) {
            $versionLabel = 'v' . $versionLabel;
        }
        $titleHtml .= ' <span class="topbar-version">' . htmlspecialchars($versionLabel) . '</span>';
    }
    if ($user && ($user['role'] ?? '') === 'admin') {
        $update = site_update_info();
        if ($update) {
            $updateVersion = trim((string)($update['version'] ?? ''));
            if ($updateVersion !== '' && stripos($updateVersion, 'v') !== 0) {
                $updateVersion = 'v' . $updateVersion;
            }
            $updateLabel = htmlspecialchars('New version ' . $updateVersion);
            $updateUrl = trim((string)($update['url'] ?? ''));
            if ($updateUrl !== '') {
                $titleHtml .= ' <a class="topbar-version is-update" href="' . htmlspecialchars($updateUrl) . '" target="_blank" rel="noopener noreferrer">' . $updateLabel . '</a>';
            } else {
                $titleHtml .= ' <span class="topbar-version is-update">' . $updateLabel . '</span>';
            }
        }
    }
    return $titleHtml;
}

function country_code_zh_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        'AF' => 'Afghanistan',
        'AL' => 'Albania',
        'DZ' => 'Algeria',
        'AS' => 'American Samoa',
        'AD' => 'Andorra',
        'AO' => 'Angola',
        'AI' => 'Anguilla',
        'AQ' => 'Antarctica',
        'AG' => 'Antigua and Barbuda',
        'AR' => 'Argentina',
        'AM' => 'Armenia',
        'AW' => 'Aruba',
        'AU' => 'Australia',
        'AT' => 'Austria',
        'AZ' => 'Azerbaijan',
        'BS' => 'Bahamas',
        'BH' => 'Bahrain',
        'BD' => 'Bangladesh',
        'BB' => 'Barbados',
        'BY' => 'Belarus',
        'BE' => 'Belgium',
        'BZ' => 'Belize',
        'BJ' => 'Benin',
        'BM' => 'Bermuda',
        'BT' => 'Bhutan',
        'BO' => 'Bolivia',
        'BA' => 'Bosnia and Herzegovina',
        'BW' => 'Botswana',
        'BR' => 'Brazil',
        'IO' => 'British Indian Ocean Territory',
        'BN' => 'Brunei',
        'BG' => 'Bulgaria',
        'BF' => 'Burkina Faso',
        'BI' => 'Burundi',
        'KH' => 'Cambodia',
        'CM' => 'Cameroon',
        'CA' => 'Canada',
        'CV' => 'Cape Verde',
        'KY' => 'Cayman Islands',
        'CF' => 'Central African Republic',
        'TD' => 'Chad',
        'CL' => 'Chile',
        'CN' => 'China',
        'CX' => 'Christmas Island',
        'CC' => 'Cocos (Keeling) Islands',
        'CO' => 'Colombia',
        'KM' => 'Comoros',
        'CG' => 'Republic of the Congo',
        'CD' => 'Democratic Republic of the Congo',
        'CK' => 'Cook Islands',
        'CR' => 'Costa Rica',
        'CI' => 'Ivory Coast',
        'HR' => 'Croatia',
        'CU' => 'Cuba',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DK' => 'Denmark',
        'DJ' => 'Djibouti',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'EC' => 'Ecuador',
        'EG' => 'Egypt',
        'SV' => 'El Salvador',
        'GQ' => 'Equatorial Guinea',
        'ER' => 'Eritrea',
        'EE' => 'Estonia',
        'SZ' => 'Eswatini',
        'ET' => 'Ethiopia',
        'FK' => 'Falkland Islands',
        'FO' => 'Faroe Islands',
        'FJ' => 'Fiji',
        'FI' => 'Finland',
        'FR' => 'France',
        'GF' => 'French Guiana',
        'PF' => 'French Polynesia',
        'TF' => 'French Southern Territories',
        'GA' => 'Gabon',
        'GM' => 'Gambia',
        'GE' => 'Georgia',
        'DE' => 'Germany',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GR' => 'Greece',
        'GL' => 'Greenland',
        'GD' => 'Grenada',
        'GP' => 'Guadeloupe',
        'GU' => 'Guam',
        'GT' => 'Guatemala',
        'GG' => 'Guernsey',
        'GN' => 'Guinea',
        'GW' => 'Guinea-Bissau',
        'GY' => 'Guyana',
        'HT' => 'Haiti',
        'HM' => 'Heard Island and McDonald Islands',
        'HN' => 'Honduras',
        'HK' => 'ChinaHong Kong',
        'HU' => 'Hungary',
        'IS' => 'Iceland',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IR' => 'Iran',
        'IQ' => 'Iraq',
        'IE' => 'Ireland',
        'IM' => 'Isle of Man',
        'IL' => 'Israel',
        'IT' => 'Italy',
        'JM' => 'Jamaica',
        'JP' => 'Japan',
        'JE' => 'Jersey',
        'JO' => 'Jordan',
        'KZ' => 'Kazakhstan',
        'KE' => 'Kenya',
        'KI' => 'Kiribati',
        'KP' => 'North Korea',
        'KR' => 'South Korea',
        'KW' => 'Kuwait',
        'KG' => 'Kyrgyzstan',
        'LA' => 'Laos',
        'LV' => 'Latvia',
        'LB' => 'Lebanon',
        'LS' => 'Lesotho',
        'LR' => 'Liberia',
        'LY' => 'Libya',
        'LI' => 'Liechtenstein',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MO' => 'ChinaMacau',
        'MK' => 'North Macedonia',
        'MG' => 'Madagascar',
        'MW' => 'Malawi',
        'MY' => 'Malaysia',
        'MV' => 'Maldives',
        'ML' => 'Mali',
        'MT' => 'Malta',
        'MH' => 'Marshall Islands',
        'MQ' => 'Martinique',
        'MR' => 'Mauritania',
        'MU' => 'Mauritius',
        'YT' => 'Mayotte',
        'MX' => 'Mexico',
        'FM' => 'Micronesia',
        'MD' => 'Moldova',
        'MC' => 'Monaco',
        'MN' => 'Mongolia',
        'ME' => 'Montenegro',
        'MS' => 'Montserrat',
        'MA' => 'Morocco',
        'MZ' => 'Mozambique',
        'MM' => 'Myanmar',
        'NA' => 'Namibia',
        'NR' => 'Nauru',
        'NP' => 'Nepal',
        'NL' => 'Netherlands',
        'NC' => 'New Caledonia',
        'NZ' => 'New Zealand',
        'NI' => 'Nicaragua',
        'NE' => 'Niger',
        'NG' => 'Nigeria',
        'NU' => 'Niue',
        'NF' => 'Norfolk Island',
        'MP' => 'Northern Mariana Islands',
        'NO' => 'Norway',
        'OM' => 'Oman',
        'PK' => 'Pakistan',
        'PW' => 'Palau',
        'PS' => 'Palestine',
        'PA' => 'Panama',
        'PG' => 'Papua New Guinea',
        'PY' => 'Paraguay',
        'PE' => 'Peru',
        'PH' => 'Philippines',
        'PN' => 'Pitcairn Islands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'PR' => 'Puerto Rico',
        'QA' => 'Qatar',
        'RE' => 'Réunion',
        'RO' => 'Romania',
        'RU' => 'Russia',
        'RW' => 'Rwanda',
        'BL' => 'Saint Barthélemy',
        'SH' => 'Saint Helena',
        'KN' => 'Saint Kitts and Nevis',
        'LC' => 'Saint Lucia',
        'MF' => 'Saint Martin (French)',
        'PM' => 'Saint Pierre and Miquelon',
        'VC' => 'Saint Vincent and the Grenadines',
        'WS' => 'Samoa',
        'SM' => 'San Marino',
        'ST' => 'São Tomé and Príncipe',
        'SA' => 'Saudi Arabia',
        'SN' => 'Senegal',
        'RS' => 'Serbia',
        'SC' => 'Seychelles',
        'SL' => 'Sierra Leone',
        'SG' => 'Singapore',
        'SX' => 'Sint Maarten',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'SB' => 'Solomon Islands',
        'SO' => 'Somalia',
        'ZA' => 'South Africa',
        'GS' => 'South Georgia and the South Sandwich Islands',
        'SS' => 'South Sudan',
        'ES' => 'Spain',
        'LK' => 'Sri Lanka',
        'SD' => 'Sudan',
        'SR' => 'Suriname',
        'SJ' => 'Svalbard and Jan Mayen',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
        'SY' => 'Syria',
        'TW' => 'ChinaTaiwan',
        'TJ' => 'Tajikistan',
        'TZ' => 'Tanzania',
        'TH' => 'Thailand',
        'TL' => 'Timor-Leste',
        'TG' => 'Togo',
        'TK' => 'Tokelau',
        'TO' => 'Tonga',
        'TT' => 'Trinidad and Tobago',
        'TN' => 'Tunisia',
        'TR' => 'Turkey',
        'TM' => 'Turkmenistan',
        'TC' => 'Turks and Caicos Islands',
        'TV' => 'Tuvalu',
        'UG' => 'Uganda',
        'UA' => 'Ukraine',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'UM' => 'U.S. Minor Outlying Islands',
        'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
        'VU' => 'Vanuatu',
        'VA' => 'Vatican City',
        'VE' => 'Venezuela',
        'VN' => 'Vietnam',
        'VG' => 'British Virgin Islands',
        'VI' => 'U.S. Virgin Islands',
        'WF' => 'Wallis and Futuna',
        'EH' => 'Western Sahara',
        'YE' => 'Yemen',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
        'AX' => 'Åland Islands',
        'BQ' => 'Caribbean Netherlands',
        'CW' => 'Curaçao',
        'XK' => 'Kosovo',
    ];
    return $map;
}

function has_non_ascii(string $value): bool {
    return (bool)preg_match('/[\x80-\xFF]/', $value);
}

function normalize_country_name(string $country, string $countryCode): string {
    $code = strtoupper(trim($countryCode));
    if ($code !== '') {
        $map = country_code_zh_map();
        if (isset($map[$code])) {
            return $map[$code];
        }
    }
    return $country;
}

function normalize_us_region(string $region): string {
    $raw = trim($region);
    if ($raw === '') {
        return '';
    }
    $abbrMap = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
        'DC' => 'District of Columbia',
    ];
    $upper = strtoupper($raw);
    if (isset($abbrMap[$upper])) {
        return $abbrMap[$upper];
    }
    $nameMap = [
        'alabama' => 'Alabama',
        'alaska' => 'Alaska',
        'arizona' => 'Arizona',
        'arkansas' => 'Arkansas',
        'california' => 'California',
        'colorado' => 'Colorado',
        'connecticut' => 'Connecticut',
        'delaware' => 'Delaware',
        'florida' => 'Florida',
        'georgia' => 'Georgia',
        'hawaii' => 'Hawaii',
        'idaho' => 'Idaho',
        'illinois' => 'Illinois',
        'indiana' => 'Indiana',
        'iowa' => 'Iowa',
        'kansas' => 'Kansas',
        'kentucky' => 'Kentucky',
        'louisiana' => 'Louisiana',
        'maine' => 'Maine',
        'maryland' => 'Maryland',
        'massachusetts' => 'Massachusetts',
        'michigan' => 'Michigan',
        'minnesota' => 'Minnesota',
        'mississippi' => 'Mississippi',
        'missouri' => 'Missouri',
        'montana' => 'Montana',
        'nebraska' => 'Nebraska',
        'nevada' => 'Nevada',
        'new hampshire' => 'New Hampshire',
        'new jersey' => 'New Jersey',
        'new mexico' => 'New Mexico',
        'new york' => 'New York',
        'north carolina' => 'North Carolina',
        'north dakota' => 'North Dakota',
        'ohio' => 'Ohio',
        'oklahoma' => 'Oklahoma',
        'oregon' => 'Oregon',
        'pennsylvania' => 'Pennsylvania',
        'rhode island' => 'Rhode Island',
        'south carolina' => 'South Carolina',
        'south dakota' => 'South Dakota',
        'tennessee' => 'Tennessee',
        'texas' => 'Texas',
        'utah' => 'Utah',
        'vermont' => 'Vermont',
        'virginia' => 'Virginia',
        'washington' => 'Washington',
        'west virginia' => 'West Virginia',
        'wisconsin' => 'Wisconsin',
        'wyoming' => 'Wyoming',
        'district of columbia' => 'District of Columbia',
        'washington, d.c.' => 'District of Columbia',
    ];
    $lower = strtolower($raw);
    return $nameMap[$lower] ?? $region;
}

function normalize_ip_location(array $location): array {
    $country = trim((string)($location['country'] ?? ''));
    $countryCode = strtoupper(trim((string)($location['country_code'] ?? '')));
    $region = trim((string)($location['region'] ?? ''));
    $city = trim((string)($location['city'] ?? ''));
    $country = normalize_country_name($country, $countryCode);
    if ($countryCode === 'US') {
        $region = normalize_us_region($region);
    }
    return [
        'country' => $country,
        'country_code' => $countryCode,
        'region' => $region,
        'city' => $city,
    ];
}

function lookup_ip_location_cn(string $ip): array {
    $data = http_get_json('http://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true');
    if (!is_array($data)) {
        return ['country' => '', 'country_code' => '', 'region' => '', 'city' => ''];
    }
    $pro = trim((string)($data['pro'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $addr = trim((string)($data['addr'] ?? ''));
    $country = '';
    $region = '';
    $cityOut = '';
    $countryCode = '';
    if ($pro !== '' || $city !== '') {
        $country = 'China';
        $countryCode = 'CN';
        $region = $pro;
        $cityOut = $city;
    } elseif ($addr !== '') {
        $country = preg_replace('/\s+/', ' ', $addr);
        if (strpos($country, 'China') !== false) {
            $countryCode = 'CN';
        }
    }
    return [
        'country' => $country,
        'country_code' => $countryCode,
        'region' => $region,
        'city' => $cityOut,
    ];
}

function lookup_ip_location(string $ip): array {
    $ip = trim($ip);
    if ($ip === '') {
        return ['country' => '', 'country_code' => '', 'region' => '', 'city' => ''];
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_access_geo_cache WHERE ip = :ip LIMIT 1');
    $stmt->execute([':ip' => $ip]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached) {
        $updatedAt = strtotime((string)($cached['updated_at'] ?? ''));
        if ($updatedAt && (time() - $updatedAt) < 86400 * 30) {
            $cachedLocation = [
                'country' => (string)($cached['country'] ?? ''),
                'country_code' => (string)($cached['country_code'] ?? ''),
                'region' => (string)($cached['region'] ?? ''),
                'city' => (string)($cached['city'] ?? ''),
            ];
            $text = ($cachedLocation['country'] ?? '') . ($cachedLocation['region'] ?? '') . ($cachedLocation['city'] ?? '');
            if ($text === '' || has_non_ascii($text)) {
                return normalize_ip_location($cachedLocation);
            }
        }
    }
    $location = lookup_ip_location_cn($ip);
    if (trim((string)($location['country'] ?? '')) === '') {
        $primary = http_get_json('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city&lang=zh-CN');
        if (is_array($primary) && ($primary['status'] ?? '') === 'success') {
            $location = [
                'country' => (string)($primary['country'] ?? ''),
                'country_code' => (string)($primary['countryCode'] ?? ''),
                'region' => (string)($primary['regionName'] ?? ''),
                'city' => (string)($primary['city'] ?? ''),
            ];
        }
    }
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO share_access_geo_cache (ip, country, country_code, region, city, updated_at)
        VALUES (:ip, :country, :code, :region, :city, :updated_at)');
    $location = normalize_ip_location($location);
    $stmt->execute([
        ':ip' => $ip,
        ':country' => $location['country'],
        ':code' => $location['country_code'],
        ':region' => $location['region'],
        ':city' => $location['city'],
        ':updated_at' => now(),
    ]);
    return $location;
}

function format_ip_location(array $location): string {
    $location = normalize_ip_location($location);
    $parts = [];
    foreach (['country', 'region', 'city'] as $key) {
        $val = trim((string)($location[$key] ?? ''));
        if ($val !== '') {
            $parts[] = $val;
        }
    }
    return implode(' / ', $parts);
}

function calculate_access_log_size(array $fields): int {
    $total = 32;
    foreach ($fields as $value) {
        $total += strlen((string)$value);
    }
    return $total;
}

function purge_share_access_logs(int $shareId): ?int {
    if ($shareId <= 0) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id, COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $userId = $row ? (int)$row['user_id'] : null;
    $total = $row ? (int)$row['total'] : 0;
    $pdo->prepare('DELETE FROM share_access_logs WHERE share_id = :sid')->execute([':sid' => $shareId]);
    if ($userId) {
        adjust_user_storage($userId, -$total);
    }
    return $userId;
}

function purge_user_access_logs(int $userId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $pdo->prepare('DELETE FROM share_access_logs WHERE user_id = :uid')->execute([':uid' => $userId]);
    adjust_user_storage($userId, -$total);
    return $total;
}

function cleanup_user_access_logs(int $userId, int $days): void {
    $days = max(1, $days);
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid AND created_at < :cutoff');
    $stmt->execute([
        ':uid' => $userId,
        ':cutoff' => $cutoff,
    ]);
    $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    if ($total <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM share_access_logs WHERE user_id = :uid AND created_at < :cutoff')->execute([
        ':uid' => $userId,
        ':cutoff' => $cutoff,
    ]);
    adjust_user_storage($userId, -$total);
}

function record_share_access(array $share, ?string $docId = null, ?string $docTitle = null): void {
    $userId = (int)($share['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    if (!access_stats_enabled($userId)) {
        return;
    }
    $visitorId = get_visitor_id();
    $ip = get_client_ip();
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $location = lookup_ip_location($ip);
    $size = calculate_access_log_size([
        $docId,
        $docTitle,
        $visitorId,
        $ip,
        $referer,
        $location['country'] ?? '',
        $location['region'] ?? '',
        $location['city'] ?? '',
    ]);
    $user = get_user_by_id($userId);
    if (!$user) {
        return;
    }
    $limit = get_user_limit_bytes($user);
    $used = (int)($user['storage_used_bytes'] ?? 0);
    if ($limit > 0 && ($used + $size) > $limit) {
        set_user_setting($userId, 'access_stats_enabled', '0');
        purge_user_access_logs($userId);
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO share_access_logs
        (user_id, share_id, doc_id, doc_title, visitor_id, ip, ip_country, ip_country_code, ip_region, ip_city, referer, created_at, size_bytes)
        VALUES (:uid, :sid, :doc_id, :doc_title, :visitor_id, :ip, :country, :country_code, :region, :city, :referer, :created_at, :size_bytes)');
    $stmt->execute([
        ':uid' => $userId,
        ':sid' => (int)($share['id'] ?? 0),
        ':doc_id' => $docId,
        ':doc_title' => $docTitle,
        ':visitor_id' => $visitorId,
        ':ip' => $ip,
        ':country' => (string)($location['country'] ?? ''),
        ':country_code' => (string)($location['country_code'] ?? ''),
        ':region' => (string)($location['region'] ?? ''),
        ':city' => (string)($location['city'] ?? ''),
        ':referer' => $referer,
        ':created_at' => now(),
        ':size_bytes' => $size,
    ]);
    adjust_user_storage($userId, $size);
    cleanup_user_access_logs($userId, access_stats_retention_days($userId));
}

function fetch_share_comments(int $shareId): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_comments WHERE share_id = :share_id ORDER BY created_at DESC, id DESC');
    $stmt->execute([':share_id' => $shareId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
}

function build_comment_tree(array $comments): array {
    $items = [];
    foreach ($comments as $comment) {
        $comment['children'] = [];
        $items[(int)$comment['id']] = $comment;
    }
    $roots = [];
    foreach ($items as $id => &$comment) {
        $parentId = (int)($comment['parent_id'] ?? 0);
        if ($parentId > 0 && isset($items[$parentId])) {
            $items[$parentId]['children'][] = &$comment;
        } else {
            $roots[] = &$comment;
        }
    }
    unset($comment);
    $sorter = static function (array $a, array $b): int {
        $aTime = (string)($a['created_at'] ?? '');
        $bTime = (string)($b['created_at'] ?? '');
        if ($aTime === $bTime) {
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        }
        return strcmp($bTime, $aTime);
    };
    $sortTree = static function (array &$nodes) use (&$sortTree, $sorter): void {
        usort($nodes, $sorter);
        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $sortTree($node['children']);
            }
        }
        unset($node);
    };
    $sortTree($roots);
    return $roots;
}

function can_delete_share_comment(array $comment, array $share, ?array $user): bool {
    $viewerId = $user ? (int)($user['id'] ?? 0) : 0;
    if ($viewerId > 0 && $viewerId === (int)$share['user_id']) {
        return true;
    }
    $commentUserId = (int)($comment['user_id'] ?? 0);
    if ($viewerId > 0 && $commentUserId > 0 && $viewerId === $commentUserId) {
        return true;
    }
    $visitorId = get_visitor_id();
    if ($visitorId !== '' && $visitorId === (string)($comment['visitor_id'] ?? '')) {
        return true;
    }
    return false;
}

function render_comment_markdown(string $markdown): string {
    static $parser = null;
    if (!$parser) {
        $parser = new Parsedown();
        if (method_exists($parser, 'setSafeMode')) {
            $parser->setSafeMode(true);
        }
        if (method_exists($parser, 'setBreaksEnabled')) {
            $parser->setBreaksEnabled(true);
        }
    }
    return $parser->text($markdown);
}

function format_comment_content(string $content): string {
    return render_comment_markdown($content);
}

function render_comment_emoji_picker(): string {
    $emojis = ['😀', '😁', '😂', '😅', '😊', '😍', '😘', '😎', '🤔', '😴', '😭', '😡', '👍', '🙏', '🎉', '❤️'];
    $html = '<div class="comment-emoji-panel" data-emoji-panel hidden>';
    foreach ($emojis as $emoji) {
        $html .= '<button class="comment-emoji" type="button" data-emoji="' . htmlspecialchars($emoji, ENT_QUOTES) . '">' . htmlspecialchars($emoji) . '</button>';
    }
    $html .= '</div>';
    return $html;
}

function render_comment_editor_fields(string $content = '', string $textareaName = 'content'): string {
    $html = '<div class="comment-editor" data-comment-editor>';
    $html .= '<div class="comment-toolbar">';
    $html .= '<button class="comment-tool" type="button" data-emoji-toggle aria-label="Emoji" title="Emoji">';
    $html .= '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"></circle><circle cx="9" cy="10" r="1.2" fill="currentColor"></circle><circle cx="15" cy="10" r="1.2" fill="currentColor"></circle><path d="M8 14c1.2 1.2 2.5 1.8 4 1.8 1.5 0 2.8-.6 4-1.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
    $html .= '</button>';
    $html .= '<button class="comment-tool" type="button" data-image-insert aria-label="Image" title="Image">';
    $html .= '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"></rect><circle cx="9" cy="11" r="2" fill="currentColor"></circle><path d="M21 16l-5-5-4 4-2-2-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
    $html .= '</button>';
    $html .= '<input class="comment-image-input" type="file" accept="image/*" data-image-input hidden>';
    $html .= render_comment_emoji_picker();
    $html .= '</div>';
    $html .= '<textarea class="input comment-input" name="' . htmlspecialchars($textareaName) . '" rows="4" placeholder="Write your comment..." required>' . htmlspecialchars($content) . '</textarea>';
    $html .= '</div>';
    return $html;
}

function render_comment_form(string $action, ?string $docId, string $emailValue, ?int $parentId, string $buttonLabel, string $contentValue = ''): string {
    $html = '<form method="post" action="' . htmlspecialchars($action) . '" class="comment-form">';
    $html .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($docId !== null && $docId !== '') {
        $html .= '<input type="hidden" name="doc_id" value="' . htmlspecialchars($docId) . '">';
    }
    if ($parentId) {
        $html .= '<input type="hidden" name="parent_id" value="' . (int)$parentId . '">';
    }
    $html .= '<div class="comment-grid">';
    $html .= '<div><label>Email</label><input class="input" name="email" type="email" value="' . htmlspecialchars($emailValue) . '" placeholder="name@example.com" required></div>';
    $html .= '<div class="comment-wide"><label>Comment</label>' . render_comment_editor_fields($contentValue, 'content') . '</div>';
    if (captcha_enabled()) {
        $html .= '<div class="comment-captcha"><label>Captcha</label><div class="comment-captcha-row">';
        $html .= '<input class="input" name="captcha" placeholder="Captcha" required>';
        $html .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    $html .= '<button class="button primary" type="submit">' . htmlspecialchars($buttonLabel) . '</button>';
    $html .= '</form>';
    return $html;
}

function render_comment_node(array $comment, array $share, ?array $user, string $slug, ?string $docId, int $depth, array $indexMap): string {
    $commentId = (int)($comment['id'] ?? 0);
    $rawEmail = (string)($comment['email'] ?? '');
    $email = mask_email($rawEmail);
    $created = format_share_datetime((string)($comment['created_at'] ?? ''));
    $rawContent = (string)($comment['content'] ?? '');
    $content = format_comment_content($rawContent);
    $isOwner = (int)($comment['user_id'] ?? 0) === (int)$share['user_id'];
    $viewerIsOwner = $user && (int)($user['id'] ?? 0) === (int)$share['user_id'];
    $avatarSeed = $rawEmail !== '' ? $rawEmail : 'Guest';
    $avatarLabel = function_exists('mb_substr')
        ? mb_substr($avatarSeed, 0, 1, 'UTF-8')
        : substr($avatarSeed, 0, 1);
    $authorLabel = $email !== '' ? $email : 'Guest';
    $metaParts = [];
    if ($created !== '') {
        $metaParts[] = $created;
    }
    $metaLabel = $metaParts ? implode(' - ', $metaParts) : '';

    $html = '<div class="comment-item" style="--comment-depth:' . $depth . '" id="comment-' . $commentId . '">';
    $index = $indexMap[$commentId] ?? 0;
    $html .= '<div class="comment-card">';
    $html .= '<div class="comment-body">';
    $html .= '<div class="comment-head">';
    if ($index > 0) {
        $html .= '<span class="comment-index">' . $index . '</span>';
    }
    $html .= '<div class="comment-author">' . htmlspecialchars($authorLabel) . '</div>';
    if ($isOwner) {
        $html .= '<span class="comment-badge">Owner</span>';
    }
    if ($metaLabel !== '') {
        $html .= '<span class="comment-time">' . htmlspecialchars($metaLabel) . '</span>';
    }
    $html .= '<details class="comment-menu">';
    $html .= '<summary class="comment-menu-trigger" aria-label="More Actions">...</summary>';
    $html .= '<div class="comment-menu-list">';
    $html .= '<button class="comment-menu-item" type="button" data-comment-action="edit" data-comment-id="' . $commentId . '" data-comment-content="' . htmlspecialchars($rawContent, ENT_QUOTES) . '" data-comment-email="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '">Edit</button>';
    $html .= '<button class="comment-menu-item" type="button" data-comment-action="delete" data-comment-id="' . $commentId . '" data-comment-email="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '" data-comment-owner="' . ($viewerIsOwner ? '1' : '0') . '">Delete</button>';
    $html .= '</div>';
    $html .= '</details>';
    $html .= '</div>';
    $html .= '<div class="comment-content">' . $content . '</div>';
    $html .= '<div class="comment-footer">';
    $html .= '<button class="comment-reply-btn" type="button" data-comment-action="reply" data-comment-parent-id="' . $commentId . '" data-comment-email="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '" data-comment-author="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '">Reply</button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    if (!empty($comment['children'])) {
        $html .= '<div class="comment-children">';
        foreach ($comment['children'] as $child) {
            $html .= render_comment_node($child, $share, $user, $slug, $docId, $depth + 1, $indexMap);
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_share_comments(array $share, ?array $user, ?string $docId = null): string {
    $shareId = (int)($share['id'] ?? 0);
    $slug = (string)($share['slug'] ?? '');
    if ($shareId <= 0 || $slug === '') {
        return '';
    }
    $comments = fetch_share_comments($shareId);
    $tree = build_comment_tree($comments);
    $order = $comments;
    usort($order, function ($a, $b) {
        $timeA = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $timeB = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($timeA === $timeB) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        }
        return $timeA <=> $timeB;
    });
    $indexMap = [];
    $i = 1;
    foreach ($order as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $indexMap[$id] = $i;
            $i++;
        }
    }
    $emailValue = $user ? (string)($user['email'] ?? '') : '';
    $error = flash('comment_error');
    $info = flash('comment_info');
    $formStateRaw = flash('comment_form');
    $formState = [];
    if ($formStateRaw) {
        $decoded = json_decode($formStateRaw, true);
        if (is_array($decoded)) {
            $formState = $decoded;
        }
    }
    $formMode = (string)($formState['mode'] ?? '');
    $formEmail = (string)($formState['email'] ?? '');
    $formContent = (string)($formState['content'] ?? '');
    $formParentId = (int)($formState['parent_id'] ?? 0);
    $formNote = (string)($formState['note'] ?? '');
    $commentFormEmail = $emailValue;
    $commentFormContent = '';
    if ($formMode === 'comment') {
        if ($formEmail !== '') {
            $commentFormEmail = $formEmail;
        }
        $commentFormContent = $formContent;
    }
    $modalReopen = $formMode === 'reply';
    $modalEmail = $modalReopen ? $formEmail : '';
    $modalContent = $modalReopen ? $formContent : '';
    $modalParentId = $modalReopen ? $formParentId : 0;
    $modalNote = $modalReopen ? $formNote : '';
    $actionBase = base_path() . '/s/' . $slug;
    $uploadAction = $actionBase . '/comment/upload';
    $docValue = ($docId !== null && $docId !== '') ? (string)$docId : '';
    $viewerIsOwner = $user && (int)($user['id'] ?? 0) === (int)$share['user_id'];
    $html = '<section class="share-comments" id="comments" data-comment-upload="' . htmlspecialchars($uploadAction) . '">';
    $html .= '<div class="comment-header">';
    $html .= '<h2>Comment</h2>';
    $html .= '<div class="comment-count">' . count($comments) . ' recordsComment</div>';
    $html .= '</div>';
    if ($error) {
        $html .= '<div class="flash comment-flash error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $html .= '<div class="flash comment-flash">' . htmlspecialchars($info) . '</div>';
    }
    if (empty($tree)) {
        $html .= '<p class="muted">No comments yet. Be the first to leave one.</p>';
    } else {
        $html .= '<div class="comment-list">';
        foreach ($tree as $comment) {
            $html .= render_comment_node($comment, $share, $user, $slug, $docId, 0, $indexMap);
        }
        $html .= '</div>';
    }
    $html .= render_comment_form($actionBase . '/comment', $docId, $commentFormEmail, null, 'Post Comment', $commentFormContent);
    $html .= '<div class="modal comment-modal" data-comment-modal data-comment-action-base="' . htmlspecialchars($actionBase) . '" data-comment-doc-id="' . htmlspecialchars($docValue) . '" data-comment-owner="' . ($viewerIsOwner ? '1' : '0') . '" data-comment-default-email="' . htmlspecialchars($emailValue) . '" data-comment-reopen="' . ($modalReopen ? '1' : '0') . '" data-comment-reopen-mode="reply" data-comment-reopen-parent="' . (int)$modalParentId . '" data-comment-reopen-email="' . htmlspecialchars($modalEmail, ENT_QUOTES) . '" data-comment-reopen-content="' . htmlspecialchars($modalContent, ENT_QUOTES) . '" data-comment-reopen-note="' . htmlspecialchars($modalNote, ENT_QUOTES) . '" hidden>';
    $html .= '<div class="modal-backdrop" data-modal-close></div>';
    $html .= '<div class="modal-card">';
    $html .= '<div class="modal-header" data-comment-modal-title>Reply to Comment</div>';
    $html .= '<div class="modal-body">';
    $html .= '<form method="post" action="' . htmlspecialchars($actionBase . '/comment') . '" data-comment-form>';
    $html .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $html .= '<input type="hidden" name="doc_id" value="' . htmlspecialchars($docValue) . '" data-comment-doc>';
    $html .= '<input type="hidden" name="comment_id" value="" data-comment-id>';
    $html .= '<input type="hidden" name="parent_id" value="" data-comment-parent>';
    $html .= '<div class="comment-modal-note" data-comment-modal-note></div>';
    $html .= '<div class="comment-modal-fields" data-comment-verify>';
    $html .= '<div><label>Email</label><input class="input" name="email" type="email" value="" placeholder="name@example.com" required></div>';
    if (captcha_enabled()) {
        $html .= '<div class="comment-captcha"><label>Captcha</label><div class="comment-captcha-row">';
        $html .= '<input class="input" name="captcha" placeholder="Captcha" required>';
        $html .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="comment-modal-fields" data-comment-editor-wrapper>';
    $html .= render_comment_editor_fields($modalContent, 'content');
    $html .= '</div>';
    $html .= '<div class="modal-actions">';
    $html .= '<button class="button ghost" type="button" data-modal-close>Cancel</button>';
    $html .= '<button class="button primary" type="submit" data-comment-submit>Submit</button>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</section>';
    return $html;
}

function report_reason_options(): array {
    return [
        ['value' => 'illegal', 'label' => 'Illegal content'],
        ['value' => 'spam', 'label' => 'Spam / Advertisement'],
        ['value' => 'infringe', 'label' => 'Copyright infringement'],
        ['value' => 'other', 'label' => 'Other'],
    ];
}

function report_reason_label(string $value): string {
    foreach (report_reason_options() as $option) {
        if ($option['value'] === $value) {
            return $option['label'];
        }
    }
    return $value;
}

function share_report_modal_id(string $slug): string {
    return 'report-modal-' . $slug;
}

function render_share_report_trigger(array $share): string {
    $slug = (string)($share['slug'] ?? '');
    if ($slug === '') {
        return '';
    }
    $modalId = share_report_modal_id($slug);
    return '<button class="kb-chip report-trigger" id="report" type="button" data-report-open data-report-target="' . htmlspecialchars($modalId) . '">Report</button>';
}

function render_share_report_form(array $share, ?array $user, ?string $docId = null): string {
    $slug = (string)($share['slug'] ?? '');
    if ($slug === '') {
        return '';
    }
    $error = flash('report_error');
    $info = flash('report_info');
    $action = base_path() . '/s/' . $slug . '/report';
    $modalId = share_report_modal_id($slug);
    $emailValue = $user ? (string)($user['email'] ?? '') : '';
    $formStateRaw = flash('report_form');
    $formState = [];
    if ($formStateRaw) {
        $decoded = json_decode($formStateRaw, true);
        if (is_array($decoded)) {
            $formState = $decoded;
        }
    }
    $reportEmailValue = $emailValue;
    $formEmail = (string)($formState['email'] ?? '');
    if ($formEmail !== '') {
        $reportEmailValue = $formEmail;
    }
    $formReason = (string)($formState['reason_type'] ?? '');
    $formDetail = (string)($formState['reason_detail'] ?? '');

    $html = '';
    if ($info) {
        $html .= '<div class="flash report-flash">' . htmlspecialchars($info) . '</div>';
    }
    $modalHidden = $error ? '' : ' hidden';
    $html .= '<div class="modal report-modal" id="' . htmlspecialchars($modalId) . '" data-report-modal' . $modalHidden . '>';
    $html .= '<div class="modal-backdrop" data-modal-close></div>';
    $html .= '<div class="modal-card">';
    $html .= '<div class="modal-header">Report Content</div>';
    $html .= '<div class="modal-body">';
    if ($error) {
        $html .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    $html .= '<form method="post" action="' . htmlspecialchars($action) . '" class="report-form">';
    $html .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($docId !== null && $docId !== '') {
        $html .= '<input type="hidden" name="doc_id" value="' . htmlspecialchars($docId) . '">';
    }
    $html .= '<div class="report-grid">';
    $html .= '<div><label>Report Type</label><select class="input" name="reason_type" required>';
    foreach (report_reason_options() as $option) {
        $value = (string)($option['value'] ?? '');
        $selected = ($formReason !== '' && $formReason === $value) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($option['label']) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div><label>Email</label><input class="input" type="email" name="report_email" value="' . htmlspecialchars($reportEmailValue) . '" placeholder="name@example.com" required></div>';
    $html .= '<div class="report-wide"><label>Additional Notes</label><textarea class="input" name="reason_detail" rows="4" placeholder="Please add notes" required>' . htmlspecialchars($formDetail) . '</textarea></div>';
    if (captcha_enabled()) {
        $html .= '<div class="report-captcha">';
        $html .= '<label>Captcha</label><div class="report-captcha-row">';
        $html .= '<input class="input" name="captcha" placeholder="Captcha" required>';
        $html .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="modal-actions">';
    $html .= '<button class="button ghost" type="button" data-modal-close>Cancel</button>';
    $html .= '<button class="button primary" type="submit">Submit Report</button>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function pending_report_count(): int {
    $pdo = db();
    $stmt = $pdo->query('SELECT COUNT(*) FROM share_reports WHERE handled_at IS NULL');
    return (int)$stmt->fetchColumn();
}

function build_share_redirect_path(string $slug, ?string $docId, string $anchor): string {
    $path = '/s/' . $slug;
    if ($docId !== null && $docId !== '') {
        $path .= '/' . rawurlencode($docId);
    }
    if ($anchor !== '') {
        $path .= '#' . $anchor;
    }
    return $path;
}

function collect_comment_tree_ids(array $rows, int $rootId): array {
    $children = [];
    $sizes = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $parent = (int)($row['parent_id'] ?? 0);
        $children[$parent][] = $id;
        $sizes[$id] = (int)($row['size_bytes'] ?? 0);
    }
    $stack = [$rootId];
    $ids = [];
    $total = 0;
    while (!empty($stack)) {
        $id = array_pop($stack);
        if (!isset($sizes[$id])) {
            continue;
        }
        $ids[] = $id;
        $total += $sizes[$id];
        foreach ($children[$id] ?? [] as $childId) {
            $stack[] = $childId;
        }
    }
    return [$ids, $total];
}

function handle_share_comment_upload(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        api_response(404, null, 'Share not found');
    }
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        api_response(403, null, 'Share is closed, cannot upload images');
    }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        api_response(403, null, 'Please enter the access password first');
    }
    $file = $_FILES['image'] ?? null;
    if (!$file || !is_array($file)) {
        api_response(400, null, 'Please select an image file');
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        api_response(400, null, 'Image upload failed');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        api_response(400, null, 'Image upload failed');
    }
    $info = @getimagesize($tmp);
    if (!$info) {
        api_response(400, null, 'Only image files are supported');
    }
    $type = (int)($info[2] ?? 0);
    $ext = strtolower((string)image_type_to_extension($type, false));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            api_response(400, null, 'Only image files are supported');
        }
    }
    $owner = get_user_by_id((int)$share['user_id']);
    if (!$owner) {
        api_response(400, null, 'Share owner not found');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 && is_file($tmp)) {
        $size = (int)filesize($tmp);
    }
    $used = recalculate_user_storage((int)$owner['id']);
    $limit = get_user_limit_bytes($owner);
    if ($limit > 0 && ($used + $size) > $limit) {
        api_response(413, null, 'Insufficient storage space');
    }
    $shareId = (int)$share['id'];
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    global $config;
    $assetPath = comment_asset_prefix() . $shareId . '/' . $filename;
    $dir = $config['uploads_dir'] . '/' . trim(comment_asset_prefix(), '/') . '/' . $shareId;
    ensure_dir($dir);
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        api_response(500, null, 'Failed to save image');
    }
    $actualSize = $size;
    if ($actualSize <= 0 && is_file($target)) {
        $actualSize = (int)filesize($target);
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, created_at)
        VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :created_at)');
    $stmt->execute([
        ':share_id' => $shareId,
        ':doc_id' => null,
        ':asset_path' => $assetPath,
        ':file_path' => $assetPath,
        ':size_bytes' => $actualSize,
        ':created_at' => now(),
    ]);
    adjust_share_size($shareId, $actualSize);
    adjust_user_storage((int)$owner['id'], $actualSize);
    $url = base_path() . '/uploads/' . $assetPath;
    api_response(200, ['url' => $url, 'size' => $actualSize]);
}

function handle_share_search(string $slug): void {
    $share = find_share_by_slug($slug);
    if (!$share) {
        api_response(404, null, 'Share not found');
    }
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        api_response(403, null, 'Share is closed');
    }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        api_response(403, null, 'Please enter the access password first');
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 1) {
        api_response(200, ['results' => []]);
    }
    $pdo = db();
    $shareId = (int)$share['id'];
    $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) {
        api_response(200, ['results' => []]);
    }
    $wordConditions = [];
    $params = [':sid' => $shareId];
    foreach ($words as $i => $word) {
        $pk = ':qw' . $i;
        $likeVal = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $word) . '%';
        $wordConditions[] = "(title LIKE {$pk} ESCAPE \"\\\" OR markdown LIKE {$pk} ESCAPE \"\\\")";
        $params[$pk] = $likeVal;
    }
    $firstWord = $words[0];
    $params[':q_raw'] = $firstWord;
    $params[':q_raw2'] = $firstWord;
    $whereWords = implode(' AND ', $wordConditions);
    $stmt = $pdo->prepare("
        SELECT doc_id, title, hpath,
            CASE
                WHEN INSTR(markdown, :q_raw) > 50 THEN
                    SUBSTR(markdown, INSTR(markdown, :q_raw) - 40, 200)
                WHEN INSTR(markdown, :q_raw) > 0 THEN
                    SUBSTR(markdown, 1, 200)
                ELSE
                    SUBSTR(markdown, 1, 200)
            END AS snippet_raw,
            INSTR(markdown, :q_raw2) AS match_pos
        FROM share_docs
        WHERE share_id = :sid
          AND {$whereWords}
        ORDER BY sort_order ASC, id ASC
        LIMIT 30
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($rows as $row) {
        $raw = $row['snippet_raw'] ?? '';
        $raw = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $raw);
        $snippet = preg_replace('/[#*`~>\[\]!]+/', '', $raw);
        $snippet = trim(preg_replace('/\s+/', ' ', $snippet));
        $matchPos = (int)($row['match_pos'] ?? 0);
        if ($matchPos > 50) {
            $snippet = '...' . $snippet;
        }
        if (mb_strlen($snippet) > 120) {
            $snippet = mb_substr($snippet, 0, 120) . '...';
        }
        $results[] = [
            'docId'   => $row['doc_id'],
            'title'   => $row['title'],
            'hpath'   => $row['hpath'] ?? '',
            'snippet' => $snippet,
        ];
    }
    api_response(200, ['results' => $results]);
}

function handle_share_comment_submit(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo 'Share not found';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'comments');
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        flash('comment_error', 'Share is closed, cannot post comments');
        redirect($redirectPath);
    }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('comment_error', 'Please enter the access password first');
        redirect($redirectPath);
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
    $shareId = (int)$share['id'];
    $parentEmail = '';
    if ($parentId > 0) {
        $pdo = db();
        $check = $pdo->prepare('SELECT email FROM share_comments WHERE id = :id AND share_id = :share_id');
        $check->execute([':id' => $parentId, ':share_id' => $shareId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            flash('comment_error', 'Reply target does not exist');
            redirect($redirectPath);
        }
        $parentEmail = trim((string)($row['email'] ?? ''));
    }
    if (captcha_enabled()) {
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (!check_captcha($captchaInput)) {
            $state = [
                'mode' => $parentId > 0 ? 'reply' : 'comment',
                'email' => $email,
                'content' => $content,
                'parent_id' => $parentId > 0 ? $parentId : 0,
                'note' => $parentEmail !== '' ? mask_email($parentEmail) : '',
            ];
            flash('comment_form', json_encode($state, JSON_UNESCAPED_UNICODE));
            flash('comment_error', 'Incorrect captcha');
            redirect($redirectPath);
        }
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('comment_error', 'Please enter a valid email');
        redirect($redirectPath);
    }
    if ($content === '') {
        flash('comment_error', 'Comment content cannot be empty');
        redirect($redirectPath);
    }
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($contentLength > 2000) {
        flash('comment_error', 'Comment content too long');
        redirect($redirectPath);
    }
    $bannedWords = get_banned_words();
    if (!empty($bannedWords)) {
        $hit = find_banned_word($content, $bannedWords);
        if ($hit) {
            $state = [
                'mode' => $parentId > 0 ? 'reply' : 'comment',
                'email' => $email,
                'content' => $content,
                'parent_id' => $parentId > 0 ? $parentId : 0,
                'note' => $parentEmail !== '' ? mask_email($parentEmail) : '',
            ];
            flash('comment_form', json_encode($state, JSON_UNESCAPED_UNICODE));
            flash('comment_error', 'Triggered banned word: ' . $hit['word']);
            redirect($redirectPath);
        }
    }
    $owner = get_user_by_id((int)$share['user_id']);
    if (!$owner) {
        flash('comment_error', 'Share owner not found');
        redirect($redirectPath);
    }
    $size = calculate_comment_size($email, $content);
    $used = recalculate_user_storage((int)$owner['id']);
    $limit = get_user_limit_bytes($owner);
    if ($limit > 0 && ($used + $size) > $limit) {
        flash('comment_error', 'Insufficient storage space, cannot post comment');
        redirect($redirectPath);
    }
    $viewer = current_user();
    $userId = $viewer ? (int)$viewer['id'] : null;
    $visitorId = get_visitor_id();
    $ip = get_client_ip();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO share_comments (share_id, parent_id, user_id, visitor_id, email, content, ip, size_bytes, created_at)
        VALUES (:share_id, :parent_id, :user_id, :visitor_id, :email, :content, :ip, :size_bytes, :created_at)');
    $stmt->execute([
        ':share_id' => $shareId,
        ':parent_id' => $parentId > 0 ? $parentId : null,
        ':user_id' => $userId,
        ':visitor_id' => $visitorId !== '' ? $visitorId : null,
        ':email' => $email,
        ':content' => $content,
        ':ip' => $ip,
        ':size_bytes' => $size,
        ':created_at' => now(),
    ]);
    $commentId = (int)$pdo->lastInsertId();
    adjust_share_size($shareId, $size);
    adjust_user_storage((int)$owner['id'], $size);
    if ((int)($share['comment_notify'] ?? 0) === 1 && smtp_enabled()) {
        $recipientEmail = '';
        $isReply = false;
        if ($parentId > 0) {
            $recipientEmail = $parentEmail;
            $isReply = true;
        } else {
            $recipientEmail = trim((string)($owner['email'] ?? ''));
        }
        if ($recipientEmail !== '' && $email !== '' && strcasecmp($recipientEmail, $email) === 0) {
            $recipientEmail = '';
        }
        if ($recipientEmail !== '') {
            $commentPayload = [
                'id' => $commentId,
                'email' => $email,
                'content' => $content,
            ];
            enqueue_background_task(function () use ($share, $commentPayload, $recipientEmail, $isReply) {
                send_comment_notification($share, $commentPayload, $recipientEmail, $isReply);
            });
        }
    }
    $anchor = $commentId > 0 ? 'comment-' . $commentId : 'comments';
    flash('comment_info', 'Comment submitted');
    redirect(build_share_redirect_path($slug, $docId, $anchor));
}

function handle_share_comment_delete(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo 'Share not found';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'comments');
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('comment_error', 'Please enter the access password first');
        redirect($redirectPath);
    }
    $commentId = max(0, (int)($_POST['comment_id'] ?? 0));
    if ($commentId <= 0) {
        flash('comment_error', 'Missing comment ID');
        redirect($redirectPath);
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_comments WHERE id = :id AND share_id = :share_id');
    $stmt->execute([':id' => $commentId, ':share_id' => (int)$share['id']]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comment) {
        flash('comment_error', 'Comment not found');
        redirect($redirectPath);
    }
    $viewer = current_user();
    $viewerId = $viewer ? (int)($viewer['id'] ?? 0) : 0;
    $isShareOwner = $viewerId > 0 && $viewerId === (int)$share['user_id'];
    if (!$isShareOwner) {
        if (captcha_enabled()) {
            $captchaInput = (string)($_POST['captcha'] ?? '');
            if (!check_captcha($captchaInput)) {
                flash('comment_error', 'Incorrect captcha');
                redirect($redirectPath);
            }
        }
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('comment_error', 'Please enter a valid email');
            redirect($redirectPath);
        }
        $commentEmail = (string)($comment['email'] ?? '');
        if ($commentEmail === '' || strcasecmp($email, $commentEmail) !== 0) {
            flash('comment_error', 'Email verification failed');
            redirect($redirectPath);
        }
    }
    $shareId = (int)$share['id'];
    $stmt = $pdo->prepare('SELECT id, parent_id, size_bytes, content FROM share_comments WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    [$ids, $totalSize] = collect_comment_tree_ids($rows, $commentId);
    $assetPaths = [];
    if (!empty($ids)) {
        $idLookup = array_fill_keys($ids, true);
        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if (!isset($idLookup[$rowId])) {
                continue;
            }
            $content = (string)($row['content'] ?? '');
            $assetPaths = array_merge($assetPaths, extract_comment_asset_paths($content, $shareId));
        }
        if (!empty($assetPaths)) {
            $assetPaths = array_values(array_unique($assetPaths));
            $assetPaths = filter_unused_comment_assets($shareId, $assetPaths, $ids);
        }
    }
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = $shareId;
        $del = $pdo->prepare('DELETE FROM share_comments WHERE id IN (' . $placeholders . ') AND share_id = ?');
        $del->execute($params);
    }
    $assetSize = delete_comment_assets($shareId, $assetPaths);
    $delta = -$totalSize - $assetSize;
    if ($delta !== 0) {
        adjust_share_size($shareId, $delta);
        adjust_user_storage((int)$share['user_id'], $delta);
    }
    flash('comment_info', 'CommentDeleted');
    redirect($redirectPath);
}

function handle_share_comment_edit(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo 'Share not found';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'comments');
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('comment_error', 'Please enter the access password first');
        redirect($redirectPath);
    }
    $commentId = max(0, (int)($_POST['comment_id'] ?? 0));
    if ($commentId <= 0) {
        flash('comment_error', 'Missing comment ID');
        redirect($redirectPath);
    }
    if (captcha_enabled()) {
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (!check_captcha($captchaInput)) {
            flash('comment_error', 'Incorrect captcha');
            redirect($redirectPath);
        }
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('comment_error', 'Please enter a valid email');
        redirect($redirectPath);
    }
    if ($content === '') {
        flash('comment_error', 'Comment content cannot be empty');
        redirect($redirectPath);
    }
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($contentLength > 2000) {
        flash('comment_error', 'Comment content too long');
        redirect($redirectPath);
    }
    $bannedWords = get_banned_words();
    if (!empty($bannedWords)) {
        $hit = find_banned_word($content, $bannedWords);
        if ($hit) {
            flash('comment_error', 'Triggered banned word: ' . $hit['word']);
            redirect($redirectPath);
        }
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_comments WHERE id = :id AND share_id = :share_id');
    $stmt->execute([':id' => $commentId, ':share_id' => (int)$share['id']]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comment) {
        flash('comment_error', 'Comment not found');
        redirect($redirectPath);
    }
    $commentEmail = (string)($comment['email'] ?? '');
    $commentEmailMasked = $commentEmail !== '' ? mask_email($commentEmail) : '';
    if ($commentEmail === '' || strcasecmp($email, $commentEmail) !== 0) {
        flash('comment_error', 'Email verification failed');
        redirect($redirectPath);
    }
    $shareId = (int)$share['id'];
    $newSize = calculate_comment_size($commentEmail, $content);
    $oldSize = (int)($comment['size_bytes'] ?? 0);
    $oldAssets = extract_comment_asset_paths((string)($comment['content'] ?? ''), $shareId);
    $newAssets = extract_comment_asset_paths($content, $shareId);
    $removeAssets = array_values(array_diff($oldAssets, $newAssets));
    if (!empty($removeAssets)) {
        $removeAssets = filter_unused_comment_assets($shareId, $removeAssets, [$commentId]);
    }
    $removeAssetSize = sum_share_asset_sizes($shareId, $removeAssets);
    $delta = $newSize - $oldSize;
    $netDelta = $delta - $removeAssetSize;
    if ($netDelta > 0) {
        $owner = get_user_by_id((int)$share['user_id']);
        if (!$owner) {
            flash('comment_error', 'Share owner not found');
            redirect($redirectPath);
        }
        $used = recalculate_user_storage((int)$owner['id']);
        $limit = get_user_limit_bytes($owner);
        if ($limit > 0 && ($used + $netDelta) > $limit) {
            flash('comment_error', 'Insufficient storage space to save changes');
            redirect($redirectPath);
        }
    }
    $update = $pdo->prepare('UPDATE share_comments SET content = :content, size_bytes = :size_bytes WHERE id = :id AND share_id = :share_id');
    $update->execute([
        ':content' => $content,
        ':size_bytes' => $newSize,
        ':id' => $commentId,
        ':share_id' => (int)$share['id'],
    ]);
    $deletedAssetSize = delete_comment_assets($shareId, $removeAssets);
    $totalDelta = $delta - $deletedAssetSize;
    if ($totalDelta !== 0) {
        adjust_share_size($shareId, $totalDelta);
        adjust_user_storage((int)$share['user_id'], $totalDelta);
    }
    $anchor = 'comment-' . $commentId;
    flash('comment_info', 'Comment updated');
    redirect(build_share_redirect_path($slug, $docId, $anchor));
}

function handle_share_report_submit(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo 'Share not found';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'report');
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('report_error', 'Please enter the access password first');
        redirect($redirectPath);
    }
    $reportEmail = trim((string)($_POST['report_email'] ?? ''));
    $reasonType = trim((string)($_POST['reason_type'] ?? ''));
    $reasonDetail = trim((string)($_POST['reason_detail'] ?? ''));
    if (captcha_enabled()) {
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (!check_captcha($captchaInput)) {
            $state = [
                'email' => $reportEmail,
                'reason_type' => $reasonType,
                'reason_detail' => $reasonDetail,
            ];
            flash('report_form', json_encode($state, JSON_UNESCAPED_UNICODE));
            flash('report_error', 'Incorrect captcha');
            redirect($redirectPath);
        }
    }
    if ($reportEmail === '' || !filter_var($reportEmail, FILTER_VALIDATE_EMAIL)) {
        flash('report_error', 'Please enter a valid email');
        redirect($redirectPath);
    }
    $validReasons = array_column(report_reason_options(), 'value');
    if ($reasonType === '' || !in_array($reasonType, $validReasons, true)) {
        flash('report_error', 'Please select a report type');
        redirect($redirectPath);
    }
    if ($reasonDetail === '') {
        flash('report_error', 'Please fill in report details');
        redirect($redirectPath);
    }
    $detailLength = function_exists('mb_strlen') ? mb_strlen($reasonDetail, 'UTF-8') : strlen($reasonDetail);
    if ($detailLength > 1000) {
        flash('report_error', 'Report details too long');
        redirect($redirectPath);
    }
    $viewer = current_user();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO share_reports (share_id, share_title, share_slug, share_user_id, reporter_user_id, report_email, visitor_id, ip, reason_type, reason_detail, created_at)
        VALUES (:share_id, :share_title, :share_slug, :share_user_id, :reporter_user_id, :report_email, :visitor_id, :ip, :reason_type, :reason_detail, :created_at)');
    $stmt->execute([
        ':share_id' => (int)$share['id'],
        ':share_title' => (string)($share['title'] ?? $slug),
        ':share_slug' => (string)($share['slug'] ?? $slug),
        ':share_user_id' => (int)$share['user_id'],
        ':reporter_user_id' => $viewer ? (int)$viewer['id'] : null,
        ':report_email' => $reportEmail,
        ':visitor_id' => get_visitor_id(),
        ':ip' => get_client_ip(),
        ':reason_type' => $reasonType,
        ':reason_detail' => $reasonDetail,
        ':created_at' => now(),
    ]);
    flash('report_info', 'Report submitted, thank you for your feedback');
    redirect($redirectPath);
}

function build_from_header(string $from, string $name): string {
    $name = trim($name);
    if ($name === '') {
        return $from;
    }
    $encodedName = '=?UTF-8?B?' . base64_encode($name) . '?=';
    return $encodedName . ' <' . $from . '>';
}

function send_mail(string $email, string $subject, string $body): bool {
    $from = get_setting('email_from', 'no-reply@example.com');
    $fromName = get_setting('email_from_name', 'SiYuan Note Share');
    if (smtp_enabled()) {
        return send_smtp_mail($email, $subject, $body, $from, $fromName);
    }
    $headers = [];
    $headers[] = 'From: ' . build_from_header($from, $fromName);
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    return mail($email, $subject, $body, implode("\r\n", $headers));
}

function send_smtp_mail(string $email, string $subject, string $body, string $from, string $fromName): bool {
    $host = trim((string)get_setting('smtp_host', ''));
    if ($host === '') {
        return false;
    }
    $port = (int)get_setting('smtp_port', '587');
    $secure = strtolower(trim((string)get_setting('smtp_secure', 'tls')));
    $user = (string)get_setting('smtp_user', '');
    $pass = (string)get_setting('smtp_pass', '');
    $GLOBALS['smtp_last_error'] = '';
    try {
        require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';
        require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port > 0 ? $port : 587;
        $mailer->SMTPAuth = $user !== '';
        if ($secure === 'ssl') {
            $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPAutoTLS = false;
        }
        if ($user !== '') {
            $mailer->Username = $user;
            $mailer->Password = $pass;
        }
        $resolvedFrom = $from;
        if ($user !== '' && strcasecmp($from, $user) !== 0) {
            $resolvedFrom = $user;
            $mailer->addReplyTo($from, $fromName ?: $from);
        }
        $mailer->setFrom($resolvedFrom, $fromName ?: $resolvedFrom);
        if ($user !== '') {
            $mailer->Sender = $user;
        }
        $mailer->addAddress($email);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->isHTML(false);
        if (!$mailer->send()) {
            $GLOBALS['smtp_last_error'] = $mailer->ErrorInfo;
            return false;
        }
        return true;
    } catch (Throwable $e) {
        $GLOBALS['smtp_last_error'] = $e->getMessage();
        return false;
    }
}

function send_email_code(string $email, string $code): bool {
    $subject = get_setting('email_subject', 'Email Verification Code');
    $body = "Your email verification code is: {$code}\nValid for 10 minutes. Do not share it.";
    return send_mail($email, $subject, $body);
}

function send_reset_code(string $email, string $code): bool {
    $subject = get_setting('email_reset_subject', 'Password Reset Verification Code');
    $body = "Your password reset code is: {$code}\nValid for 10 minutes. Do not share it.";
    return send_mail($email, $subject, $body);
}

function send_comment_notification(array $share, array $comment, string $recipientEmail, bool $isReply): void {
    $recipientEmail = trim($recipientEmail);
    if ($recipientEmail === '') {
        return;
    }
    $slug = (string)($share['slug'] ?? '');
    if ($slug === '') {
        return;
    }
    $shareTitle = (string)($share['title'] ?? $slug);
    $commentEmail = (string)($comment['email'] ?? '');
    $commentEmailMasked = $commentEmail !== '' ? mask_email($commentEmail) : '';
    $commentContent = (string)($comment['content'] ?? '');
    $commentId = (int)($comment['id'] ?? 0);
    $url = share_url($slug);
    $anchor = $commentId > 0 ? '#comment-' . $commentId : '#comments';
    $subject = $isReply ? 'Your comment received a reply' : 'Your share received a new comment';
    $body = $isReply
        ? "Your comment on share \"{$shareTitle}\" received a reply:\n"
        : "Your share \"{$shareTitle}\" received a new comment:\n";
    if ($commentEmailMasked !== '') {
        $body .= "Comment Email: {$commentEmailMasked}\n";
    }
    if ($commentContent !== '') {
        $body .= "Comment:\n{$commentContent}\n";
    }
    $body .= "View comment: {$url}{$anchor}\n";
    send_mail($recipientEmail, $subject, $body);
}

function create_email_code(string $email, string $ip): string {
    $pdo = db();
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $stmt = $pdo->prepare('INSERT INTO email_codes (email, code_hash, expires_at, created_at, ip)
        VALUES (:email, :code_hash, :expires_at, :created_at, :ip)');
    $stmt->execute([
        ':email' => $email,
        ':code_hash' => $hash,
        ':expires_at' => $expiresAt,
        ':created_at' => now(),
        ':ip' => $ip,
    ]);
    return $code;
}

function verify_email_code(string $email, string $code): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM email_codes WHERE email = :email AND used_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1');
    $stmt->execute([
        ':email' => $email,
        ':now' => now(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (!password_verify($code, $row['code_hash'])) {
        return false;
    }
    $update = $pdo->prepare('UPDATE email_codes SET used_at = :used_at WHERE id = :id');
    $update->execute([':used_at' => now(), ':id' => $row['id']]);
    return true;
}

function create_reset_code(int $userId, string $email, string $ip): string {
    $pdo = db();
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, email, code_hash, expires_at, created_at, ip)
        VALUES (:user_id, :email, :code_hash, :expires_at, :created_at, :ip)');
    $stmt->execute([
        ':user_id' => $userId,
        ':email' => $email,
        ':code_hash' => $hash,
        ':expires_at' => $expiresAt,
        ':created_at' => now(),
        ':ip' => $ip,
    ]);
    return $code;
}

function verify_reset_code(int $userId, string $email, string $code): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE user_id = :user_id AND email = :email AND used_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1');
    $stmt->execute([
        ':user_id' => $userId,
        ':email' => $email,
        ':now' => now(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (!password_verify($code, $row['code_hash'])) {
        return false;
    }
    $update = $pdo->prepare('UPDATE password_resets SET used_at = :used_at WHERE id = :id');
    $update->execute([':used_at' => now(), ':id' => $row['id']]);
    return true;
}

function current_user(): ?array {
    if (empty($_SESSION['user_id']) || empty($_SESSION['password_hash'])) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $sessionHash = (string)($_SESSION['password_hash'] ?? '');
    if ($sessionHash === '' || !hash_equals((string)$row['password_hash'], $sessionHash)) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    return $row;
}

function touch_user_activity(array $user): void {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    $last = trim((string)($user['last_active_at'] ?? ''));
    if ($last !== '') {
        $lastTs = strtotime($last);
        if ($lastTs && (time() - $lastTs) < 600) {
            return;
        }
    }
    if (!empty($_SESSION['last_active_touch'])) {
        $sessionTs = (int)$_SESSION['last_active_touch'];
        if ($sessionTs > 0 && (time() - $sessionTs) < 600) {
            return;
        }
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET last_active_at = :ts WHERE id = :id');
    $stmt->execute([
        ':ts' => now(),
        ':id' => $userId,
    ]);
    $_SESSION['last_active_touch'] = time();
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    if ((int)$user['disabled'] === 1) {
        session_destroy();
        redirect('/login');
    }
    touch_user_activity($user);
    return $user;
}

function require_admin(): array {
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $user;
}

function api_user_from_key(string $key): ?array {
    if ($key === '') {
        return null;
    }
    $prefix = substr($key, 0, 8);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE api_key_prefix = :prefix AND disabled = 0');
    $stmt->execute([':prefix' => $prefix]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        if (!empty($user['api_key_hash']) && password_verify($key, $user['api_key_hash'])) {
            return $user;
        }
    }
    return null;
}

function require_api_user(): array {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'bearer ') === 0) {
            $key = trim(substr($auth, 7));
        }
    }
    $user = api_user_from_key($key);
    if (!$user) {
        api_response(401, null, 'API Key is invalid or expired, please regenerate it from the Dashboard');
    }
    touch_user_activity($user);
    return $user;
}

function api_response(int $status, $data, string $msg = ''): void {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code($status);
    echo json_encode([
        'code' => $status === 200 ? 0 : $status,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function share_url(string $slug): string {
    return base_url() . '/s/' . rawurlencode($slug);
}

function find_share_by_slug(string $slug): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM shares WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_share_by_id(int $shareId): ?array {
    if ($shareId <= 0) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_share_asset_row(int $shareId, string $assetPath): ?array {
    if ($shareId <= 0 || $assetPath === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT asset_path, file_path, size_bytes FROM share_assets WHERE share_id = :sid AND asset_path = :asset_path LIMIT 1');
    $stmt->execute([
        ':sid' => $shareId,
        ':asset_path' => $assetPath,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_active_announcements(): array {
    $pdo = db();
    $stmt = $pdo->query('SELECT * FROM announcements WHERE active = 1 ORDER BY created_at DESC');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function render_announcements_html(): string {
    $items = get_active_announcements();
    if (empty($items)) {
        return '';
    }
    $html = '<div class="card"><h2>Announcements</h2><div class="list">';
    foreach ($items as $item) {
        $title = htmlspecialchars($item['title']);
        $content = nl2br((string)$item['content']);
        $html .= '<div class="list-item"><strong>' . $title . '</strong><div class="muted">' . $content . '</div></div>';
    }
    $html .= '</div></div>';
    return $html;
}

function should_show_announcement_modal(array $items): bool {
    if (empty($items)) {
        return false;
    }
    $today = date('Y-m-d');
    $hidden = $_COOKIE['announcement_hide_date'] ?? '';
    return $hidden !== $today;
}

function render_announcement_modal(array $items): string {
    if (empty($items)) {
        return '';
    }
    $html = '<div class="modal announcement-modal" data-announcement-modal="1">';
    $html .= '<div class="modal-backdrop" data-modal-close="1"></div>';
    $html .= '<div class="modal-card">';
    $html .= '<div class="modal-header">Latest Announcement</div>';
    $html .= '<div class="modal-body">';
    foreach ($items as $item) {
        $title = htmlspecialchars($item['title']);
        $content = nl2br((string)$item['content']);
        $html .= '<div class="announcement-item"><div class="announcement-title">' . $title . '</div><div class="announcement-content">' . $content . '</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="modal-footer">';
    $html .= '<label class="checkbox"><input type="checkbox" data-announcement-hide> Don\'t show again today</label>';
    $html .= '<button class="button primary" data-modal-close="1">Got it</button>';
    $html .= '</div></div></div>';
    return $html;
}

function build_scan_item_link(array $meta): string {
    $itemType = (string)($meta['item_type'] ?? 'doc');
    $slug = (string)($meta['slug'] ?? '');
    if ($itemType === 'comment') {
        $commentId = (int)($meta['comment_id'] ?? 0);
        $commentEmail = (string)($meta['comment_email'] ?? '');
        $commentCreatedAt = (string)($meta['comment_created_at'] ?? '');
        $commentContent = (string)($meta['comment_content'] ?? '');
        $shareTitle = (string)($meta['share_title'] ?? '');
        $label = $commentId > 0 ? 'Comment #' . $commentId : 'Comment';
        $attrs = ' data-admin-comment-edit="1"'
            . ' data-admin-comment-id="' . $commentId . '"'
            . ' data-admin-comment-email="' . htmlspecialchars($commentEmail, ENT_QUOTES) . '"'
            . ' data-admin-comment-created="' . htmlspecialchars(format_share_datetime($commentCreatedAt), ENT_QUOTES) . '"'
            . ' data-admin-comment-share="' . htmlspecialchars($shareTitle, ENT_QUOTES) . '"'
            . ' data-admin-comment-content="' . htmlspecialchars($commentContent, ENT_QUOTES) . '"';
        return '<button type="button" class="scan-comment-link"' . $attrs . '>' . htmlspecialchars($label) . '</button>';
    }
    $docId = (string)($meta['doc_id'] ?? '');
    $docTitle = (string)($meta['doc_title'] ?? '');
    $docLabel = trim($docTitle) !== '' ? $docTitle : $docId;
    $docLabel = $docLabel !== '' ? 'Document: ' . $docLabel : 'Document';
    $docUrl = '';
    if ($slug !== '') {
        $docUrl = $docId !== '' ? base_url() . build_share_redirect_path($slug, $docId, '') : share_url($slug);
    }
    if ($docUrl !== '') {
        return '<a class="scan-comment-link" href="' . htmlspecialchars($docUrl) . '" target="_blank">' . htmlspecialchars($docLabel) . '</a>';
    }
    return htmlspecialchars($docLabel);
}

function build_scan_log_entry(array $meta, ?array $hit): string {
    $prefix = $hit ? 'Matched banned word [' . htmlspecialchars((string)$hit['word']) . ']: ' : 'No match: ';
    $parts = [];
    $shareTitle = trim((string)($meta['share_title'] ?? ''));
    if ($shareTitle !== '') {
        $parts[] = htmlspecialchars($shareTitle);
    }
    $parts[] = build_scan_item_link($meta);
    $username = trim((string)($meta['username'] ?? ''));
    if ($username !== '') {
        $parts[] = htmlspecialchars($username);
    }
    if ((string)($meta['item_type'] ?? '') === 'comment') {
        $commentEmail = trim((string)($meta['comment_email'] ?? ''));
        if ($commentEmail !== '') {
            $parts[] = 'Email: ' . htmlspecialchars($commentEmail);
        }
        $commentCreatedAt = (string)($meta['comment_created_at'] ?? '');
        if ($commentCreatedAt !== '') {
            $parts[] = 'Time: ' . htmlspecialchars(format_share_datetime($commentCreatedAt));
        }
    } else {
        $hpath = trim((string)($meta['hpath'] ?? ''));
        if ($hpath !== '') {
            $parts[] = 'Path: ' . htmlspecialchars($hpath);
        }
    }
    return $prefix . implode(' / ', $parts);
}

function scan_banned_shares(array $words): array {
    if (empty($words)) {
        return [];
    }
    $pdo = db();
    $sql = 'SELECT shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug, shares.user_id,
        users.username, share_docs.doc_id, share_docs.title AS doc_title, share_docs.hpath, share_docs.markdown
        FROM share_docs
        JOIN shares ON share_docs.share_id = shares.id
        JOIN users ON shares.user_id = users.id
        WHERE shares.deleted_at IS NULL
        ORDER BY shares.updated_at DESC';
    $stmt = $pdo->query($sql);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $markdown = (string)($row['markdown'] ?? '');
        if ($markdown === '') {
            continue;
        }
        $hit = find_banned_word($markdown, $words);
        if (!$hit) {
            continue;
        }
        $results[] = [
            'item_type' => 'doc',
            'share_id' => (int)$row['share_id'],
            'share_title' => $row['share_title'],
            'share_type' => $row['type'],
            'slug' => $row['slug'],
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'doc_id' => $row['doc_id'],
            'doc_title' => $row['doc_title'],
            'hpath' => $row['hpath'],
            'word' => $hit['word'],
            'snippet' => extract_snippet($markdown, $hit['word']),
        ];
    }
    $commentSql = 'SELECT share_comments.id AS comment_id, share_comments.email AS comment_email, share_comments.content AS comment_content,
        share_comments.created_at AS comment_created_at, shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug,
        shares.user_id, users.username
        FROM share_comments
        JOIN shares ON share_comments.share_id = shares.id
        JOIN users ON shares.user_id = users.id
        WHERE shares.deleted_at IS NULL
        ORDER BY share_comments.created_at DESC, share_comments.id DESC';
    $commentStmt = $pdo->query($commentSql);
    foreach ($commentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $commentContent = (string)($row['comment_content'] ?? '');
        if ($commentContent === '') {
            continue;
        }
        $hit = find_banned_word($commentContent, $words);
        if (!$hit) {
            continue;
        }
        $results[] = [
            'item_type' => 'comment',
            'share_id' => (int)$row['share_id'],
            'share_title' => $row['share_title'],
            'share_type' => $row['type'],
            'slug' => $row['slug'],
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'doc_id' => null,
            'doc_title' => null,
            'hpath' => null,
            'comment_id' => (int)$row['comment_id'],
            'comment_email' => $row['comment_email'],
            'comment_created_at' => $row['comment_created_at'],
            'comment_content' => $commentContent,
            'word' => $hit['word'],
            'snippet' => extract_snippet($commentContent, $hit['word']),
        ];
    }
    return $results;
}

function count_scannable_share_docs(): int {
    $pdo = db();
    $stmt = $pdo->query('SELECT COUNT(*) FROM share_docs JOIN shares ON share_docs.share_id = shares.id WHERE shares.deleted_at IS NULL');
    return $stmt ? (int)$stmt->fetchColumn() : 0;
}

function count_scannable_comments(): int {
    $pdo = db();
    $stmt = $pdo->query('SELECT COUNT(*) FROM share_comments JOIN shares ON share_comments.share_id = shares.id WHERE shares.deleted_at IS NULL');
    return $stmt ? (int)$stmt->fetchColumn() : 0;
}

function count_scannable_docs(): int {
    return count_scannable_share_docs() + count_scannable_comments();
}

function scan_banned_shares_batch(array $words, int $offset, int $limit): array {
    if (empty($words)) {
        return ['hits' => [], 'logs' => [], 'count' => 0];
    }
    $hits = [];
    $logs = [];
    $count = 0;
    $docCount = count_scannable_share_docs();
    $remaining = $limit;
    $docOffset = $offset;
    if ($docOffset < $docCount) {
        $docLimit = min($remaining, $docCount - $docOffset);
        $pdo = db();
        $sql = 'SELECT shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug, shares.user_id,
            users.username, share_docs.doc_id, share_docs.title AS doc_title, share_docs.hpath, share_docs.markdown
            FROM share_docs
            JOIN shares ON share_docs.share_id = shares.id
            JOIN users ON shares.user_id = users.id
            WHERE shares.deleted_at IS NULL
            ORDER BY share_docs.id ASC
            LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $docLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $docOffset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count += count($rows);
        foreach ($rows as $row) {
            $markdown = (string)($row['markdown'] ?? '');
            $meta = [
                'item_type' => 'doc',
                'share_title' => $row['share_title'],
                'slug' => $row['slug'],
                'doc_id' => $row['doc_id'],
                'doc_title' => $row['doc_title'],
                'hpath' => $row['hpath'],
                'username' => $row['username'],
            ];
            $hit = $markdown !== '' ? find_banned_word($markdown, $words) : null;
            if ($hit) {
                $hits[] = [
                    'item_type' => 'doc',
                    'share_id' => (int)$row['share_id'],
                    'share_title' => $row['share_title'],
                    'share_type' => $row['type'],
                    'slug' => $row['slug'],
                    'user_id' => (int)$row['user_id'],
                    'username' => $row['username'],
                    'doc_id' => $row['doc_id'],
                    'doc_title' => $row['doc_title'],
                    'hpath' => $row['hpath'],
                    'word' => $hit['word'],
                    'snippet' => extract_snippet($markdown, $hit['word']),
                ];
                $logs[] = build_scan_log_entry($meta, $hit);
            } else {
                $logs[] = build_scan_log_entry($meta, null);
            }
        }
        $remaining -= count($rows);
    }
    if ($remaining > 0) {
        $commentOffset = max(0, $offset - $docCount);
        $pdo = $pdo ?? db();
        $sql = 'SELECT share_comments.id AS comment_id, share_comments.email AS comment_email, share_comments.content AS comment_content,
            share_comments.created_at AS comment_created_at, shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug,
            shares.user_id, users.username
            FROM share_comments
            JOIN shares ON share_comments.share_id = shares.id
            JOIN users ON shares.user_id = users.id
            WHERE shares.deleted_at IS NULL
            ORDER BY share_comments.id ASC
            LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $remaining, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $commentOffset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count += count($rows);
        foreach ($rows as $row) {
            $commentContent = (string)($row['comment_content'] ?? '');
            $commentId = (int)($row['comment_id'] ?? 0);
            $commentEmail = trim((string)($row['comment_email'] ?? ''));
            $meta = [
                'item_type' => 'comment',
                'share_title' => $row['share_title'],
                'slug' => $row['slug'],
                'username' => $row['username'],
                'comment_id' => $commentId,
                'comment_email' => $commentEmail,
                'comment_created_at' => $row['comment_created_at'],
                'comment_content' => $commentContent,
            ];
            $hit = $commentContent !== '' ? find_banned_word($commentContent, $words) : null;
            if ($hit) {
                $hits[] = [
                    'item_type' => 'comment',
                    'share_id' => (int)$row['share_id'],
                    'share_title' => $row['share_title'],
                    'share_type' => $row['type'],
                    'slug' => $row['slug'],
                    'user_id' => (int)$row['user_id'],
                    'username' => $row['username'],
                    'doc_id' => null,
                    'doc_title' => null,
                    'hpath' => null,
                    'comment_id' => $commentId,
                    'comment_email' => $commentEmail,
                    'comment_created_at' => $row['comment_created_at'],
                    'comment_content' => $commentContent,
                    'word' => $hit['word'],
                    'snippet' => extract_snippet($commentContent, $hit['word']),
                ];
                $logs[] = build_scan_log_entry($meta, $hit);
            } else {
                $logs[] = build_scan_log_entry($meta, null);
            }
        }
    }
    return ['hits' => $hits, 'logs' => $logs, 'count' => $count];
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function remove_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            remove_dir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function move_dir(string $source, string $target): bool {
    if (!is_dir($source)) {
        return false;
    }
    if (is_dir($target)) {
        remove_dir($target);
    }
    ensure_dir(dirname($target));
    if (@rename($source, $target)) {
        return true;
    }
    ensure_dir($target);
    $items = scandir($source);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $target . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src)) {
            move_dir($src, $dst);
        } else {
            @copy($src, $dst);
        }
    }
    remove_dir($source);
    return true;
}

function chunk_cleanup_settings(): array {
    global $config;
    $ttl = (int)($config['chunk_ttl_seconds'] ?? 7200);
    $prob = (float)($config['chunk_cleanup_probability'] ?? 0.05);
    $limit = (int)($config['chunk_cleanup_limit'] ?? 20);
    if ($ttl < 60) {
        $ttl = 60;
    }
    if ($prob < 0) {
        $prob = 0;
    } elseif ($prob > 1) {
        $prob = 1;
    }
    if ($limit < 1) {
        $limit = 20;
    }
    return [$ttl, $prob, $limit];
}

function latest_mtime(string $path): int {
    if (is_file($path)) {
        return (int)(@filemtime($path) ?: 0);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $items = scandir($path);
    if ($items === false) {
        return (int)(@filemtime($path) ?: 0);
    }
    $latest = 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        $mtime = latest_mtime($child);
        if ($mtime > $latest) {
            $latest = $mtime;
        }
    }
    if ($latest <= 0) {
        $latest = (int)(@filemtime($path) ?: 0);
    }
    return $latest;
}

function cleanup_stale_dirs(string $base, int $ttl, int $limit): void {
    if (!is_dir($base)) {
        return;
    }
    $dirs = scandir($base);
    if ($dirs === false) {
        return;
    }
    $now = time();
    $deleted = 0;
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }
        $path = $base . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $mtime = latest_mtime($path);
        if ($mtime <= 0 || ($now - $mtime) < $ttl) {
            continue;
        }
        remove_dir($path);
        $deleted += 1;
        if ($deleted >= $limit) {
            break;
        }
    }
}

function maybe_cleanup_chunks(): void {
    global $config;
    [$ttl, $prob, $limit] = chunk_cleanup_settings();
    if ($prob <= 0 || $ttl <= 0 || $limit <= 0) {
        return;
    }
    $rand = mt_rand() / mt_getrandmax();
    if ($rand > $prob) {
        return;
    }
    cleanup_stale_dirs($config['uploads_dir'] . '/chunks', $ttl, $limit);
    cleanup_stale_dirs($config['uploads_dir'] . '/staging', $ttl, $limit);
}

function list_stale_chunks(int $ttl): array {
    global $config;
    $base = $config['uploads_dir'] . '/chunks';
    if (!is_dir($base)) {
        return [];
    }
    $dirs = scandir($base);
    if ($dirs === false) {
        return [];
    }
    $now = time();
    $rows = [];
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }
        $path = $base . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $mtime = latest_mtime($path);
        if ($mtime <= 0) {
            continue;
        }
        $age = $now - $mtime;
        if ($age < $ttl) {
            continue;
        }
        $rows[] = [
            'id' => $dir,
            'mtime' => $mtime,
            'age' => $age,
        ];
    }
    usort($rows, function ($a, $b) {
        return $a['mtime'] <=> $b['mtime'];
    });
    return $rows;
}

function sanitize_asset_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    return $path;
}

function sanitize_upload_id(string $uploadId): string {
    $uploadId = trim($uploadId);
    if ($uploadId === '' || !preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        return '';
    }
    return $uploadId;
}

function collect_asset_entries(array $files, array $paths, array $docIds): array {
    $entries = [];
    $seen = [];
    if (empty($files['name'])) {
        return $entries;
    }
    $count = is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        $tmp = $files['tmp_name'][$i] ?? '';
        if ($tmp === '') {
            continue;
        }
        $assetPath = $paths[$i] ?? ($files['name'][$i] ?? '');
        $assetPath = sanitize_asset_path((string)$assetPath);
        if ($assetPath === '' || isset($seen[$assetPath])) {
            continue;
        }
        $seen[$assetPath] = true;
        $entries[] = [
            'tmp' => $tmp,
            'path' => $assetPath,
            'docId' => $docIds[$i] ?? null,
            'size' => (int)($files['size'][$i] ?? 0),
        ];
    }
    return $entries;
}

function allocate_share_id(PDO $pdo): int {
    $stmt = $pdo->query('SELECT share_id FROM recycled_share_ids ORDER BY share_id ASC LIMIT 1');
    $shareId = (int)($stmt ? $stmt->fetchColumn() : 0);
    if ($shareId > 0) {
        $del = $pdo->prepare('DELETE FROM recycled_share_ids WHERE share_id = :share_id');
        $del->execute([':share_id' => $shareId]);
        return $shareId;
    }
    return 0;
}

function recycle_share_id(int $shareId): void {
    if ($shareId <= 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO recycled_share_ids (share_id, created_at) VALUES (:share_id, :created_at)');
    $stmt->execute([
        ':share_id' => $shareId,
        ':created_at' => now(),
    ]);
}

function purge_share_assets(int $shareId, bool $keepCommentFiles = false): void {
    global $config;
    $pdo = db();
    $dir = $config['uploads_dir'] . '/shares/' . $shareId;
    remove_dir($dir);
    if (!$keepCommentFiles) {
        $commentDir = $config['uploads_dir'] . '/' . trim(comment_asset_prefix(), '/') . '/' . $shareId;
        remove_dir($commentDir);
        $stmt = $pdo->prepare('DELETE FROM share_assets WHERE share_id = :share_id');
        $stmt->execute([':share_id' => $shareId]);
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM share_assets WHERE share_id = :share_id AND asset_path NOT LIKE :prefix');
    $stmt->execute([
        ':share_id' => $shareId,
        ':prefix' => comment_asset_prefix() . '%',
    ]);
}

function hard_delete_share(int $shareId): ?int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $stmt->execute([':id' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $userId = (int)$row['user_id'];
    purge_share_assets($shareId);
    purge_share_chunks($shareId);
    purge_share_access_logs($shareId);
    $uploadIds = $pdo->prepare('SELECT upload_id FROM share_uploads WHERE share_id = :share_id');
    $uploadIds->execute([':share_id' => $shareId]);
    $uploadList = array_values(array_filter($uploadIds->fetchAll(PDO::FETCH_COLUMN)));
    if ($uploadList) {
        $placeholders = implode(',', array_fill(0, count($uploadList), '?'));
        $stmt = $pdo->prepare('DELETE FROM share_upload_docs WHERE upload_id IN (' . $placeholders . ')');
        $stmt->execute($uploadList);
    }
    $pdo->prepare('DELETE FROM share_uploads WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_comments WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_reports WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_visitors WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM shares WHERE id = :id')->execute([':id' => $shareId]);
    recycle_share_id($shareId);
    recalculate_user_storage($userId);
    return $userId;
}

function delete_user_account(int $userId): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT email, role FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return false;
    }
    $shareStmt = $pdo->prepare('SELECT id FROM shares WHERE user_id = :user_id');
    $shareStmt->execute([':user_id' => $userId]);
    $shareIds = $shareStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($shareIds as $shareId) {
        hard_delete_share((int)$shareId);
    }
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')->execute([':user_id' => $userId]);
    $pdo->prepare('UPDATE announcements SET created_by = NULL WHERE created_by = :user_id')->execute([':user_id' => $userId]);
    $email = trim((string)($user['email'] ?? ''));
    if ($email !== '') {
        $pdo->prepare('DELETE FROM email_codes WHERE email = :email')->execute([':email' => $email]);
    }
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
    return true;
}

function reset_database(): void {
    global $config;
    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys = OFF;');
    $tables = $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!$table) {
            continue;
        }
        $pdo->exec('DELETE FROM "' . $table . '"');
    }
    $pdo->exec('DELETE FROM sqlite_sequence');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    seed_default_settings($pdo);
    seed_default_admin($pdo);
    remove_dir($config['uploads_dir']);
    ensure_dir($config['uploads_dir']);
    ensure_dir($config['uploads_dir'] . '/shares');
    ensure_dir($config['uploads_dir'] . '/' . trim(comment_asset_prefix(), '/'));
}

function handle_asset_uploads(int $shareId, array $entries): int {
    global $config;
    if (empty($entries)) {
        return 0;
    }
    ensure_dir($config['uploads_dir']);
    $pdo = db();
    $total = 0;
    foreach ($entries as $entry) {
        $tmp = $entry['tmp'];
        $assetPath = (string)($entry['path'] ?? '');
        $assetPath = trim(str_replace('\\', '/', $assetPath));
        $assetPath = ltrim($assetPath, '/');
        if ($assetPath === '' || substr($assetPath, -1) === '/') {
            continue;
        }
        if (!is_uploaded_file($tmp)) {
            continue;
        }
        $docId = $entry['docId'] ?? null;
        $size = (int)($entry['size'] ?? 0);
        $targetDir = $config['uploads_dir'] . '/shares/' . $shareId . '/' . dirname($assetPath);
        ensure_dir($targetDir);
        $targetFile = $config['uploads_dir'] . '/shares/' . $shareId . '/' . $assetPath;
        if (!move_uploaded_file($tmp, $targetFile)) {
            continue;
        }
        $actualSize = $size;
        if ($actualSize <= 0 && is_file($targetFile)) {
            $actualSize = (int)filesize($targetFile);
        }
        $assetHash = '';
        if (is_file($targetFile)) {
            $computed = @hash_file('sha256', $targetFile);
            $assetHash = normalize_hash_hex($computed ?: '');
        }
        $total += $actualSize;
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, asset_hash, created_at)
            VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :asset_hash, :created_at)');
        $stmt->execute([
            ':share_id' => $shareId,
            ':doc_id' => $docId,
            ':asset_path' => $assetPath,
            ':file_path' => 'shares/' . $shareId . '/' . $assetPath,
            ':size_bytes' => $actualSize,
            ':asset_hash' => $assetHash !== '' ? $assetHash : null,
            ':created_at' => now(),
        ]);
    }
    return $total;
}

function normalize_hash_hex($value): string {
    $raw = strtolower(trim((string)$value));
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return '';
    }
    return $raw;
}

function normalize_sort_index_value($value): float {
    if (!is_numeric($value)) {
        return 0;
    }
    $num = (float)$value;
    if (is_infinite($num) || is_nan($num)) {
        return 0;
    }
    return round($num, 6);
}

function build_doc_meta_signature(array $row): string {
    $payload = [
        'title' => (string)($row['title'] ?? ''),
        'hPath' => (string)($row['hPath'] ?? $row['hpath'] ?? ''),
        'parentId' => (string)($row['parentId'] ?? $row['parent_id'] ?? ''),
        'sortIndex' => normalize_sort_index_value($row['sortIndex'] ?? $row['sort_index'] ?? 0),
        'sortOrder' => max(0, (int)($row['sortOrder'] ?? $row['sort_order'] ?? 0)),
        'icon' => normalize_doc_icon_value($row['icon'] ?? ''),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

function compute_doc_content_hash(string $markdown): string {
    return hash('sha256', $markdown);
}

function compute_doc_meta_hash(array $row): string {
    return hash('sha256', build_doc_meta_signature($row));
}

function normalize_doc_id_list($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        $docId = trim((string)$item);
        if ($docId === '' || isset($seen[$docId])) {
            continue;
        }
        $seen[$docId] = true;
        $out[] = $docId;
    }
    return $out;
}

function normalize_asset_path_list($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        $path = sanitize_asset_path((string)$item);
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $out[] = $path;
    }
    return $out;
}

function normalize_incremental_patch($raw): array {
    if (!is_array($raw)) {
        return ['enabled' => false, 'deletedDocIds' => [], 'deletedAssetPaths' => []];
    }
    return [
        'enabled' => !empty($raw['enabled']),
        'deletedDocIds' => normalize_doc_id_list($raw['deletedDocIds'] ?? []),
        'deletedAssetPaths' => normalize_asset_path_list($raw['deletedAssetPaths'] ?? []),
    ];
}

function normalize_asset_manifest($rawAssets): array {
    $assets = [];
    $seen = [];
    if (!is_array($rawAssets)) {
        return $assets;
    }
    foreach ($rawAssets as $item) {
        if (!is_array($item)) {
            continue;
        }
        $path = sanitize_asset_path((string)($item['path'] ?? ''));
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $size = (int)($item['size'] ?? 0);
        if ($size < 0) {
            $size = 0;
        }
        $docId = isset($item['docId']) ? trim((string)($item['docId'])) : null;
        $hash = normalize_hash_hex($item['hash'] ?? '');
        $assets[] = [
            'path' => $path,
            'size' => $size,
            'docId' => $docId,
            'hash' => $hash,
        ];
    }
    return $assets;
}

function doc_chunk_prefix(): string {
    return '__sps_docs/';
}

function is_doc_chunk_path(string $path): bool {
    return str_starts_with($path, doc_chunk_prefix());
}

function normalize_doc_manifest($rawDocs): array {
    $docs = [];
    $seenPath = [];
    $seenDoc = [];
    if (!is_array($rawDocs)) {
        return $docs;
    }
    foreach ($rawDocs as $item) {
        if (!is_array($item)) {
            continue;
        }
        $docId = trim((string)($item['docId'] ?? ''));
        $path = sanitize_asset_path((string)($item['path'] ?? ''));
        if ($docId === '' || $path === '' || !is_doc_chunk_path($path)) {
            continue;
        }
        if (isset($seenPath[$path]) || isset($seenDoc[$docId])) {
            continue;
        }
        $seenPath[$path] = true;
        $seenDoc[$docId] = true;
        $size = (int)($item['size'] ?? 0);
        if ($size < 0) {
            $size = 0;
        }
        $docs[] = [
            'docId' => $docId,
            'path' => $path,
            'size' => $size,
            'hash' => normalize_hash_hex($item['hash'] ?? ''),
        ];
    }
    return $docs;
}

function share_chunks_dir(int $shareId): string {
    global $config;
    return $config['uploads_dir'] . '/chunks/' . $shareId;
}

function upload_chunks_dir(string $uploadId): string {
    global $config;
    return $config['uploads_dir'] . '/chunks/' . $uploadId;
}

function upload_staging_dir(string $uploadId): string {
    global $config;
    return $config['uploads_dir'] . '/staging/' . $uploadId;
}

function purge_upload_session_files(string $uploadId): void {
    remove_dir(upload_chunks_dir($uploadId));
    remove_dir(upload_staging_dir($uploadId));
}

function generate_upload_id(): string {
    return bin2hex(random_bytes(16));
}

function purge_share_chunks(int $shareId): void {
    $dir = share_chunks_dir($shareId);
    remove_dir($dir);
}

function recalculate_share_size(int $shareId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_docs WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    $docSize = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_assets WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    $assetSize = (int)$stmt->fetchColumn();
    $commentSize = share_comment_size($shareId);
    $total = $docSize + $assetSize + $commentSize;
    $stmt = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':size_bytes' => $total,
        ':updated_at' => now(),
        ':id' => $shareId,
    ]);
    return $total;
}

function handle_instance_heartbeat(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        api_response(200, ['ok' => true], '');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_response(405, null, 'Method Not Allowed');
    }
    $payload = parse_json_body();
    $instanceId = trim((string)($payload['instance_id'] ?? ''));
    if ($instanceId === '') {
        api_response(400, null, 'Missing instance_id');
    }
    $version = trim((string)($payload['version'] ?? ''));
    $now = now();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO instance_heartbeats (instance_id, first_seen, last_seen, version, ip)
        VALUES (:id, :first_seen, :last_seen, :version, :ip)
        ON CONFLICT(instance_id) DO UPDATE SET last_seen = :last_seen, version = :version, ip = :ip');
    $stmt->execute([
        ':id' => $instanceId,
        ':first_seen' => $now,
        ':last_seen' => $now,
        ':version' => $version,
        ':ip' => get_client_ip(),
    ]);
    api_response(200, ['instance_id' => $instanceId]);
}

function handle_instance_stats(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        api_response(200, ['ok' => true], '');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_response(405, null, 'Method Not Allowed');
    }
    $pdo = db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM instance_heartbeats')->fetchColumn();
    $cutoff30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $cutoff7 = date('Y-m-d H:i:s', strtotime('-7 days'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM instance_heartbeats WHERE last_seen >= :cutoff');
    $stmt->execute([':cutoff' => $cutoff30]);
    $active30 = (int)$stmt->fetchColumn();
    $stmt->execute([':cutoff' => $cutoff7]);
    $active7 = (int)$stmt->fetchColumn();
    api_response(200, [
        'total' => $total,
        'active_30' => $active30,
        'active_7' => $active7,
        'updated_at' => now(),
    ]);
}

function handle_api(string $path): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        api_response(200, null, '');
    }
    $user = require_api_user();
    $pdo = db();
    global $config;
    maybe_cleanup_chunks();

    if ($path === '/api/v1/auth/verify') {
        [$minChunk, $maxChunk] = chunk_size_limits();
        api_response(200, ['user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ], 'limits' => [
            'minChunkSize' => $minChunk,
            'maxChunkSize' => $maxChunk,
        ], 'features' => [
            'incrementalShare' => true,
            'docChunkUpload' => true,
        ]]);
    }

    if ($path === '/api/v1/shares' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT shares.*, COALESCE(doc_counts.doc_count, 0) AS doc_count
            FROM shares
            LEFT JOIN (SELECT share_id, COUNT(*) AS doc_count FROM share_docs GROUP BY share_id) doc_counts
                ON shares.id = doc_counts.share_id
            WHERE shares.user_id = :uid AND shares.deleted_at IS NULL
            ORDER BY shares.updated_at DESC');
        $stmt->execute([':uid' => $user['id']]);
        $shares = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $shares[] = [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'type' => $row['type'],
                'title' => $row['title'],
                'docId' => $row['doc_id'],
                'notebookId' => $row['notebook_id'],
                'updatedAt' => strtotime($row['updated_at']) * 1000,
                'createdAt' => strtotime($row['created_at']) * 1000,
                'hasPassword' => !empty($row['password_hash']),
                'expiresAt' => $row['expires_at'] ? ((int)$row['expires_at'] * 1000) : null,
                'visitorLimit' => (int)($row['visitor_limit'] ?? 0),
                'includeChildren' => ((string)($row['type'] ?? '') === 'doc') && ((int)($row['doc_count'] ?? 0) > 1),
                'path' => '/s/' . $row['slug'],
                'url' => share_url($row['slug']),
            ];
        }
        api_response(200, ['shares' => $shares]);
    }

    if ($path === '/api/v1/shares/snapshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $shareId = (int)($payload['shareId'] ?? 0);
        if ($shareId <= 0) {
            api_response(400, null, 'Missing share id');
        }
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$share) {
            api_response(404, null, 'Share not found');
        }

        $docs = [];
        $docStmt = $pdo->prepare('SELECT id, doc_id, title, icon, hpath, parent_id, sort_index, sort_order, size_bytes, content_hash, meta_hash
            FROM share_docs WHERE share_id = :sid ORDER BY sort_order ASC, id ASC');
        $docStmt->execute([':sid' => $shareId]);
        $docHashUpdate = $pdo->prepare('UPDATE share_docs SET content_hash = :content_hash, meta_hash = :meta_hash WHERE id = :id');
        $docMarkdownStmt = $pdo->prepare('SELECT markdown FROM share_docs WHERE share_id = :sid AND id = :id LIMIT 1');
        while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
            $contentHash = normalize_hash_hex($row['content_hash'] ?? '');
            $metaHash = normalize_hash_hex($row['meta_hash'] ?? '');
            if ($contentHash === '') {
                $docMarkdownStmt->execute([
                    ':sid' => $shareId,
                    ':id' => (int)($row['id'] ?? 0),
                ]);
                $docMarkdown = (string)($docMarkdownStmt->fetchColumn() ?: '');
                $contentHash = compute_doc_content_hash($docMarkdown);
                unset($docMarkdown);
            }
            if ($metaHash === '') {
                $metaHash = compute_doc_meta_hash($row);
            }
            if (($row['content_hash'] ?? '') !== $contentHash || ($row['meta_hash'] ?? '') !== $metaHash) {
                $docHashUpdate->execute([
                    ':content_hash' => $contentHash,
                    ':meta_hash' => $metaHash,
                    ':id' => (int)($row['id'] ?? 0),
                ]);
            }
            $docs[] = [
                'docId' => (string)($row['doc_id'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'icon' => (string)($row['icon'] ?? ''),
                'hPath' => (string)($row['hpath'] ?? ''),
                'parentId' => (string)($row['parent_id'] ?? ''),
                'sortIndex' => (float)($row['sort_index'] ?? 0),
                'sortOrder' => max(0, (int)($row['sort_order'] ?? 0)),
                'contentHash' => $contentHash,
                'metaHash' => $metaHash,
            ];
            unset($row);
        }

        $assets = [];
        $assetStmt = $pdo->prepare('SELECT id, asset_path, doc_id, file_path, size_bytes, asset_hash FROM share_assets
            WHERE share_id = :sid AND asset_path NOT LIKE :prefix ORDER BY id ASC');
        $assetStmt->execute([
            ':sid' => $shareId,
            ':prefix' => comment_asset_prefix() . '%',
        ]);
        $assetHashUpdate = $pdo->prepare('UPDATE share_assets SET asset_hash = :asset_hash WHERE id = :id');
        while ($row = $assetStmt->fetch(PDO::FETCH_ASSOC)) {
            $assetHash = normalize_hash_hex($row['asset_hash'] ?? '');
            if ($assetHash === '') {
                $filePath = (string)($row['file_path'] ?? '');
                $fullPath = $config['uploads_dir'] . '/' . ltrim($filePath, '/');
                if ($filePath !== '' && is_file($fullPath)) {
                    $computed = @hash_file('sha256', $fullPath);
                    $assetHash = normalize_hash_hex($computed ?: '');
                }
            }
            if ($assetHash !== '' && ($row['asset_hash'] ?? '') !== $assetHash) {
                $assetHashUpdate->execute([
                    ':asset_hash' => $assetHash,
                    ':id' => (int)($row['id'] ?? 0),
                ]);
            }
            $assets[] = [
                'path' => (string)($row['asset_path'] ?? ''),
                'docId' => (string)($row['doc_id'] ?? ''),
                'size' => max(0, (int)($row['size_bytes'] ?? 0)),
                'hash' => $assetHash,
            ];
            unset($row);
        }

        api_response(200, [
            'share' => [
                'id' => (int)($share['id'] ?? 0),
                'type' => (string)($share['type'] ?? ''),
                'docId' => (string)($share['doc_id'] ?? ''),
                'notebookId' => (string)($share['notebook_id'] ?? ''),
            ],
            'docs' => $docs,
            'assets' => $assets,
        ]);
    }

    if ($path === '/api/v1/shares/delete') {
        $payload = parse_json_body();
        $shareId = (int)($payload['shareId'] ?? 0);
        $hardDelete = !empty($payload['hardDelete']);
        if (!$shareId) {
            api_response(400, null, 'Missing share ID');
        }
        if ($hardDelete) {
            $check = $pdo->prepare('SELECT id FROM shares WHERE id = :id AND user_id = :uid');
            $check->execute([
                ':id' => $shareId,
                ':uid' => $user['id'],
            ]);
            if (!$check->fetchColumn()) {
                api_response(404, null, 'Share not found');
            }
            hard_delete_share($shareId);
            api_response(200, ['ok' => true, 'hard' => true]);
        }
        $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id AND user_id = :uid');
        $stmt->execute([
            ':deleted_at' => now(),
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        purge_share_access_logs($shareId);
        recalculate_user_storage((int)$user['id']);
        api_response(200, ['ok' => true]);
    }

    if ($path === '/api/v1/shares/access/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $shareId = (int)($payload['shareId'] ?? 0);
        if (!$shareId) {
            api_response(400, null, 'Missing share id');
        }
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$share) {
            api_response(404, null, 'Share not found');
        }
        $password = trim((string)($payload['password'] ?? ''));
        $clearPassword = !empty($payload['clearPassword']);
        $expiresAt = parse_expires_at($payload['expiresAt'] ?? null);
        $clearExpires = !empty($payload['clearExpires']);
        $visitorLimit = parse_visitor_limit($payload['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($payload['clearVisitorLimit']);
        $slug = parse_requested_share_slug_or_fail($payload['slug'] ?? '');

        $passwordHash = $share['password_hash'] ?? null;
        $expiresValue = isset($share['expires_at']) ? (int)$share['expires_at'] : null;
        $visitorValue = isset($share['visitor_limit']) ? (int)$share['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }
        $newSlug = (string)($share['slug'] ?? '');
        if ($slug !== '' && $slug !== $newSlug) {
            ensure_share_slug_available_or_fail($pdo, $slug, (int)$share['id']);
            $newSlug = $slug;
        }

        $stmt = $pdo->prepare('UPDATE shares SET slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at WHERE id = :id AND user_id = :uid');
        $stmt->execute([
            ':slug' => $newSlug,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresValue,
            ':visitor_limit' => $visitorValue,
            ':updated_at' => now(),
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        if ($visitorValue > 0) {
            seed_share_visitors_from_logs($shareId);
        }
        api_response(200, ['share' => [
            'id' => (int)$share['id'],
            'slug' => $newSlug,
            'url' => share_url($newSlug),
            'hasPassword' => !empty($passwordHash),
            'expiresAt' => $expiresValue ? ($expiresValue * 1000) : null,
            'visitorLimit' => $visitorValue,
        ]]);
    }

    if ($path === '/api/v1/shares/doc/init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $meta = $payload['metadata'] ?? $payload;
        if (!is_array($meta)) {
            api_response(400, null, 'Invalid metadata');
        }
        $docId = trim((string)($meta['docId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $markdown = (string)($meta['markdown'] ?? '');
        $hPath = (string)($meta['hPath'] ?? '');
        $sortOrder = max(0, (int)($meta['sortOrder'] ?? 0));
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        $docs = $meta['docs'] ?? [];
        $hasDocs = is_array($docs) && count($docs) > 0;
        $incrementalPatch = normalize_incremental_patch($meta['incremental'] ?? []);
        $docChunks = normalize_doc_manifest($payload['docChunks'] ?? []);
        $docChunkById = [];
        foreach ($docChunks as $item) {
            $docChunkById[(string)($item['docId'] ?? '')] = $item;
        }
        $hasDocChunks = !empty($docChunkById);
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "doc" AND doc_id = :doc_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':doc_id' => $docId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $useIncremental = !empty($incrementalPatch['enabled']) && !!$existing;
        if ($docId === '' || (!$hasDocs && $markdown === '' && !$hasDocChunks && !$useIncremental)) {
            api_response(400, null, 'Missing document content');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            if ($hasDocs) {
                foreach ($docs as $doc) {
                    $docMarkdown = (string)($doc['markdown'] ?? '');
                    if ($docMarkdown === '') {
                        continue;
                    }
                    $hit = find_banned_word($docMarkdown, $bannedWords);
                    if ($hit) {
                        $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                        api_response(400, null, 'Triggered banned word: ' . $hit['word'] . ' (Document: ' . $docTitle . ')');
                    }
                }
            } else {
                $hit = find_banned_word($markdown, $bannedWords);
                if ($hit) {
                    api_response(400, null, 'Triggered banned word: ' . $hit['word']);
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $assets = normalize_asset_manifest($payload['assets'] ?? []);
        $assetSize = 0;
        foreach ($assets as $asset) {
            $assetSize += (int)($asset['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        if ($hasDocs) {
            foreach ($docs as $index => $doc) {
                $rowDocId = trim((string)($doc['docId'] ?? ''));
                $rowTitle = trim((string)($doc['title'] ?? ''));
                $rowIcon = trim((string)($doc['icon'] ?? ''));
                $rowHpath = (string)($doc['hPath'] ?? '');
                $rowMarkdown = (string)($doc['markdown'] ?? '');
                $rowSort = max(0, (int)($doc['sortOrder'] ?? $index));
                $rowParent = trim((string)($doc['parentId'] ?? ''));
                $rowSortIndex = (float)($doc['sortIndex'] ?? $index);
                if ($rowDocId === '') {
                    continue;
                }
                $chunkMeta = $docChunkById[$rowDocId] ?? null;
                $size = strlen($rowMarkdown);
                if ($size === 0 && $chunkMeta) {
                    $size = max(0, (int)($chunkMeta['size'] ?? 0));
                }
                $rowContentHash = normalize_hash_hex($doc['contentHash'] ?? '');
                if ($rowContentHash === '' && $chunkMeta) {
                    $rowContentHash = normalize_hash_hex($chunkMeta['hash'] ?? '');
                }
                if ($rowContentHash === '') {
                    $rowContentHash = ($rowMarkdown !== '' || !$chunkMeta)
                        ? compute_doc_content_hash($rowMarkdown)
                        : '';
                }
                $rowMetaHash = normalize_hash_hex($doc['metaHash'] ?? '');
                if ($rowMetaHash === '') {
                    $rowMetaHash = compute_doc_meta_hash([
                        'title' => $rowTitle ?: $rowDocId,
                        'icon' => $rowIcon,
                        'hPath' => $rowHpath,
                        'parentId' => $rowParent,
                        'sortIndex' => $rowSortIndex,
                        'sortOrder' => $rowSort,
                    ]);
                }
                $docSizeTotal += $size;
                $docRows[] = [
                    'docId' => $rowDocId,
                    'title' => $rowTitle ?: $rowDocId,
                    'icon' => $rowIcon,
                    'hPath' => $rowHpath,
                    'parentId' => $rowParent,
                    'sortIndex' => $rowSortIndex,
                    'markdown' => $rowMarkdown,
                    'sortOrder' => $rowSort,
                    'size' => $size,
                    'contentHash' => $rowContentHash,
                    'metaHash' => $rowMetaHash,
                ];
            }
            if (empty($docRows) && !$useIncremental) {
                api_response(400, null, 'Missing document content');
            }
        } elseif ($markdown !== '') {
            $docSizeTotal = strlen($markdown);
            $docIcon = trim((string)($meta['icon'] ?? ''));
            $docContentHash = normalize_hash_hex($meta['contentHash'] ?? '');
            if ($docContentHash === '') {
                $docContentHash = compute_doc_content_hash($markdown);
            }
            $docMetaHash = normalize_hash_hex($meta['metaHash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $title ?: $docId,
                    'icon' => $docIcon,
                    'hPath' => $hPath,
                    'parentId' => '',
                    'sortIndex' => 0,
                    'sortOrder' => $sortOrder,
                ]);
            }
            $docRows[] = [
                'docId' => $docId,
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => null,
                'sortIndex' => 0,
                'markdown' => $markdown,
                'sortOrder' => $sortOrder,
                'size' => $docSizeTotal,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        } elseif ($hasDocChunks && isset($docChunkById[$docId])) {
            $docIcon = trim((string)($meta['icon'] ?? ''));
            $chunkMeta = $docChunkById[$docId];
            $docContentHash = normalize_hash_hex($meta['contentHash'] ?? '');
            if ($docContentHash === '') {
                $docContentHash = normalize_hash_hex($chunkMeta['hash'] ?? '');
            }
            $docMetaHash = normalize_hash_hex($meta['metaHash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $title ?: $docId,
                    'icon' => $docIcon,
                    'hPath' => $hPath,
                    'parentId' => '',
                    'sortIndex' => 0,
                    'sortOrder' => $sortOrder,
                ]);
            }
            $docSizeTotal = max(0, (int)($chunkMeta['size'] ?? 0));
            $docRows[] = [
                'docId' => $docId,
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => null,
                'sortIndex' => 0,
                'markdown' => '',
                'sortOrder' => $sortOrder,
                'size' => $docSizeTotal,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        if (empty($docRows) && !$useIncremental) {
            api_response(400, null, 'Missing document content');
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if (!$useIncremental && $limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, 'Storage limit reached');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        $finalSlug = $slug;
        if ($existing) {
            if ($finalSlug && $finalSlug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $finalSlug, (int)$existing['id']);
            }
            if (!$finalSlug) {
                $finalSlug = (string)$existing['slug'];
            }
        } else {
            if (!$finalSlug) {
                $finalSlug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $finalSlug);
            }
        }

        $finalTitle = $title ?: ($existing['title'] ?? $docId);
        $uploadMode = $useIncremental ? 'incremental' : 'full';
        $patchManifest = json_encode(
            $useIncremental
                ? $incrementalPatch
                : ['enabled' => false, 'deletedDocIds' => [], 'deletedAssetPaths' => []],
            JSON_UNESCAPED_SLASHES
        );
        $uploadId = generate_upload_id();
        $stmt = $pdo->prepare('INSERT INTO share_uploads (upload_id, user_id, share_id, type, doc_id, slug, title, password_hash, expires_at, visitor_limit, asset_manifest, doc_manifest, upload_mode, patch_manifest, status, created_at, updated_at)
            VALUES (:upload_id, :user_id, :share_id, "doc", :doc_id, :slug, :title, :password_hash, :expires_at, :visitor_limit, :asset_manifest, :doc_manifest, :upload_mode, :patch_manifest, "pending", :created_at, :updated_at)');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':user_id' => $user['id'],
            ':share_id' => $existing ? (int)$existing['id'] : null,
            ':doc_id' => $docId,
            ':slug' => $finalSlug,
            ':title' => $finalTitle,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresValue,
            ':visitor_limit' => $visitorValue,
            ':asset_manifest' => json_encode($assets, JSON_UNESCAPED_SLASHES),
            ':doc_manifest' => json_encode($docChunks, JSON_UNESCAPED_SLASHES),
            ':upload_mode' => $uploadMode,
            ':patch_manifest' => $patchManifest,
            ':created_at' => now(),
            ':updated_at' => now(),
        ]);
        $insertDoc = $pdo->prepare('INSERT INTO share_upload_docs (upload_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:upload_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $insertDoc->execute([
                ':upload_id' => $uploadId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }
        api_response(200, ['uploadId' => $uploadId, 'slug' => $finalSlug]);
    }

    if ($path === '/api/v1/shares/notebook/init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $meta = $payload['metadata'] ?? $payload;
        if (!is_array($meta)) {
            api_response(400, null, 'Invalid metadata');
        }
        $notebookId = trim((string)($meta['notebookId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $docs = $meta['docs'] ?? [];
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        $incrementalPatch = normalize_incremental_patch($meta['incremental'] ?? []);
        $docChunks = normalize_doc_manifest($payload['docChunks'] ?? []);
        $docChunkById = [];
        foreach ($docChunks as $item) {
            $docChunkById[(string)($item['docId'] ?? '')] = $item;
        }
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "notebook" AND notebook_id = :nid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':nid' => $notebookId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $useIncremental = !empty($incrementalPatch['enabled']) && !!$existing;
        if ($notebookId === '' || !is_array($docs) || (count($docs) === 0 && !$useIncremental)) {
            api_response(400, null, 'Missing notebook or documents');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            foreach ($docs as $doc) {
                $docMarkdown = (string)($doc['markdown'] ?? '');
                if ($docMarkdown === '') {
                    continue;
                }
                $hit = find_banned_word($docMarkdown, $bannedWords);
                if ($hit) {
                    $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                    api_response(400, null, 'Triggered banned word: ' . $hit['word'] . ' (Document: ' . $docTitle . ')');
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $assets = normalize_asset_manifest($payload['assets'] ?? []);
        $assetSize = 0;
        foreach ($assets as $asset) {
            $assetSize += (int)($asset['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        foreach ($docs as $index => $doc) {
            $docId = trim((string)($doc['docId'] ?? ''));
            $docTitle = trim((string)($doc['title'] ?? ''));
            $docIcon = trim((string)($doc['icon'] ?? ''));
            $docHpath = (string)($doc['hPath'] ?? '');
            $docMarkdown = (string)($doc['markdown'] ?? '');
            $docSort = max(0, (int)($doc['sortOrder'] ?? $index));
            $docParent = trim((string)($doc['parentId'] ?? ''));
            $docSortIndex = (float)($doc['sortIndex'] ?? $index);
            if ($docId === '') {
                continue;
            }
            $chunkMeta = $docChunkById[$docId] ?? null;
            $size = strlen($docMarkdown);
            if ($size === 0 && $chunkMeta) {
                $size = max(0, (int)($chunkMeta['size'] ?? 0));
            }
            $docContentHash = normalize_hash_hex($doc['contentHash'] ?? '');
            if ($docContentHash === '' && $chunkMeta) {
                $docContentHash = normalize_hash_hex($chunkMeta['hash'] ?? '');
            }
            if ($docContentHash === '') {
                $docContentHash = ($docMarkdown !== '' || !$chunkMeta)
                    ? compute_doc_content_hash($docMarkdown)
                    : '';
            }
            $docMetaHash = normalize_hash_hex($doc['metaHash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $docTitle ?: $docId,
                    'icon' => $docIcon,
                    'hPath' => $docHpath,
                    'parentId' => $docParent,
                    'sortIndex' => $docSortIndex,
                    'sortOrder' => $docSort,
                ]);
            }
            $docSizeTotal += $size;
            $docRows[] = [
                'docId' => $docId,
                'title' => $docTitle ?: $docId,
                'icon' => $docIcon,
                'hPath' => $docHpath,
                'parentId' => $docParent,
                'sortIndex' => $docSortIndex,
                'markdown' => $docMarkdown,
                'sortOrder' => $docSort,
                'size' => $size,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        if (empty($docRows) && !$useIncremental) {
            api_response(400, null, 'No documents to share');
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if (!$useIncremental && $limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, 'Storage limit reached');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        $finalSlug = $slug;
        if ($existing) {
            if ($finalSlug && $finalSlug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $finalSlug, (int)$existing['id']);
            }
            if (!$finalSlug) {
                $finalSlug = (string)$existing['slug'];
            }
        } else {
            if (!$finalSlug) {
                $finalSlug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $finalSlug);
            }
        }

        $finalTitle = $title ?: ($existing['title'] ?? $notebookId);
        $uploadMode = $useIncremental ? 'incremental' : 'full';
        $patchManifest = json_encode(
            $useIncremental
                ? $incrementalPatch
                : ['enabled' => false, 'deletedDocIds' => [], 'deletedAssetPaths' => []],
            JSON_UNESCAPED_SLASHES
        );
        $uploadId = generate_upload_id();
        $stmt = $pdo->prepare('INSERT INTO share_uploads (upload_id, user_id, share_id, type, notebook_id, slug, title, password_hash, expires_at, visitor_limit, asset_manifest, doc_manifest, upload_mode, patch_manifest, status, created_at, updated_at)
            VALUES (:upload_id, :user_id, :share_id, "notebook", :notebook_id, :slug, :title, :password_hash, :expires_at, :visitor_limit, :asset_manifest, :doc_manifest, :upload_mode, :patch_manifest, "pending", :created_at, :updated_at)');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':user_id' => $user['id'],
            ':share_id' => $existing ? (int)$existing['id'] : null,
            ':notebook_id' => $notebookId,
            ':slug' => $finalSlug,
            ':title' => $finalTitle,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresValue,
            ':visitor_limit' => $visitorValue,
            ':asset_manifest' => json_encode($assets, JSON_UNESCAPED_SLASHES),
            ':doc_manifest' => json_encode($docChunks, JSON_UNESCAPED_SLASHES),
            ':upload_mode' => $uploadMode,
            ':patch_manifest' => $patchManifest,
            ':created_at' => now(),
            ':updated_at' => now(),
        ]);
        $stmt = $pdo->prepare('INSERT INTO share_upload_docs (upload_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:upload_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $stmt->execute([
                ':upload_id' => $uploadId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }
        api_response(200, ['uploadId' => $uploadId, 'slug' => $finalSlug]);
    }

    if ($path === '/api/v1/shares/asset/chunk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uploadId = sanitize_upload_id((string)($_POST['uploadId'] ?? ''));
        $assetPath = sanitize_asset_path((string)($_POST['assetPath'] ?? ''));
        $docId = trim((string)($_POST['assetDocId'] ?? ''));
        $chunkIndex = (int)($_POST['chunkIndex'] ?? -1);
        $totalChunks = (int)($_POST['totalChunks'] ?? 0);
        if ($uploadId === '' || $assetPath === '' || $chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks) {
            api_response(400, null, 'Invalid chunk request');
        }
        $check = $pdo->prepare('SELECT * FROM share_uploads WHERE upload_id = :upload_id AND user_id = :uid AND status = "pending" LIMIT 1');
        $check->execute([
            ':upload_id' => $uploadId,
            ':uid' => $user['id'],
        ]);
        $upload = $check->fetch(PDO::FETCH_ASSOC);
        if (!$upload) {
            api_response(404, null, 'Upload not found');
        }
        $manifest = normalize_asset_manifest(json_decode((string)($upload['asset_manifest'] ?? ''), true));
        $docManifest = normalize_doc_manifest(json_decode((string)($upload['doc_manifest'] ?? ''), true));
        $manifestItem = null;
        $isDocChunk = false;
        foreach ($manifest as $item) {
            $path = sanitize_asset_path((string)($item['path'] ?? ''));
            if ($path !== '' && $path === $assetPath) {
                $manifestItem = $item;
                break;
            }
        }
        if (!$manifestItem) {
            foreach ($docManifest as $item) {
                $path = sanitize_asset_path((string)($item['path'] ?? ''));
                if ($path !== '' && $path === $assetPath) {
                    $manifestItem = $item;
                    $isDocChunk = true;
                    break;
                }
            }
        }
        if (!$manifestItem) {
            api_response(400, null, 'Asset not allowed');
        }
        if ($isDocChunk) {
            $expectedDocId = trim((string)($manifestItem['docId'] ?? ''));
            if ($expectedDocId !== '' && $docId !== '' && $expectedDocId !== $docId) {
                api_response(400, null, 'Document chunk mismatch');
            }
        }
        if (empty($_FILES['chunk'])) {
            api_response(400, null, 'Missing chunk file');
        }
        $file = $_FILES['chunk'];
        $chunkSize = (int)($file['size'] ?? 0);
        [$minChunk, $maxChunk] = chunk_size_limits();
        if ($maxChunk > 0 && $chunkSize > $maxChunk) {
            api_response(413, null, 'Chunk too large');
        }
        if ($minChunk > 0 && $chunkIndex < ($totalChunks - 1) && $chunkSize > 0 && $chunkSize < $minChunk) {
            api_response(400, null, 'Chunk too small');
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            api_response(400, null, 'Invalid chunk upload');
        }

        $chunkDir = upload_chunks_dir($uploadId);
        $chunkPath = $chunkDir . '/' . $assetPath . '.part' . $chunkIndex;
        ensure_dir(dirname($chunkPath));
        if (!move_uploaded_file($tmp, $chunkPath)) {
            api_response(500, null, 'Chunk save failed');
        }

        $complete = false;
        if ($chunkIndex === $totalChunks - 1) {
            $missing = [];
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $chunkDir . '/' . $assetPath . '.part' . $i;
                if (!is_file($part)) {
                    $missing[] = $i;
                }
            }
            if (!empty($missing)) {
                api_response(409, [
                    'missingChunks' => $missing,
                    'uploadId' => $uploadId,
                    'assetPath' => $assetPath,
                    'totalChunks' => $totalChunks,
                ], 'Missing chunk');
            }
            $targetFile = upload_staging_dir($uploadId) . '/' . $assetPath;
            ensure_dir(dirname($targetFile));
            $out = fopen($targetFile, 'wb');
            if ($out === false) {
                api_response(500, null, 'Failed to open target file');
            }
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $chunkDir . '/' . $assetPath . '.part' . $i;
                $in = fopen($part, 'rb');
                if ($in === false) {
                    fclose($out);
                    api_response(500, null, 'Failed to read chunk');
                }
                while (!feof($in)) {
                    $buffer = fread($in, 1048576);
                    if ($buffer === false) {
                        break;
                    }
                    fwrite($out, $buffer);
                }
                fclose($in);
                @unlink($part);
            }
            fclose($out);
            $complete = true;
        }
        $stmt = $pdo->prepare('UPDATE share_uploads SET updated_at = :updated_at WHERE upload_id = :upload_id');
        $stmt->execute([
            ':updated_at' => now(),
            ':upload_id' => $uploadId,
        ]);
        api_response(200, ['complete' => $complete]);
    }

    if ($path === '/api/v1/shares/upload/complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $uploadId = sanitize_upload_id((string)($payload['uploadId'] ?? ''));
        if ($uploadId === '') {
            api_response(400, null, 'Missing upload id');
        }
        $stmt = $pdo->prepare('SELECT * FROM share_uploads WHERE upload_id = :upload_id AND user_id = :uid AND status = "pending" LIMIT 1');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':uid' => $user['id'],
        ]);
        $upload = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$upload) {
            api_response(404, null, 'Upload not found');
        }

        $stagingDir = upload_staging_dir($uploadId);
        $docManifest = normalize_doc_manifest(json_decode((string)($upload['doc_manifest'] ?? ''), true));
        $docChunkById = [];
        foreach ($docManifest as $item) {
            $docChunkById[(string)($item['docId'] ?? '')] = $item;
        }

        $bannedWords = get_banned_words();
        $docStmt = $pdo->prepare('SELECT id, doc_id, title, icon, hpath, parent_id, sort_index, sort_order, size_bytes, content_hash, meta_hash, LENGTH(markdown) AS markdown_len FROM share_upload_docs WHERE upload_id = :upload_id ORDER BY id ASC');
        $docStmt->execute([':upload_id' => $uploadId]);
        $docRows = [];
        $docSizeTotal = 0;
        $chunkFilesToClean = [];
        while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
            $docIdKey = trim((string)($row['doc_id'] ?? ''));
            $docTitle = (string)($row['title'] ?? '');
            $docIcon = (string)($row['icon'] ?? '');
            $docHpath = (string)($row['hpath'] ?? '');
            $docParent = (string)($row['parent_id'] ?? '');
            $docSortIndex = normalize_sort_index_value($row['sort_index'] ?? 0);
            $docSortOrder = max(0, (int)($row['sort_order'] ?? 0));
            $docChunk = $docIdKey !== '' ? ($docChunkById[$docIdKey] ?? null) : null;
            $chunkHash = '';
            $chunkSize = -1;
            $chunkFilePath = null;
            if ($docChunk) {
                $chunkPath = sanitize_asset_path((string)($docChunk['path'] ?? ''));
                $fullChunkPath = $chunkPath !== '' ? ($stagingDir . '/' . $chunkPath) : '';
                if ($fullChunkPath === '' || !is_file($fullChunkPath)) {
                    api_response(400, null, 'Missing document: ' . $docIdKey);
                }
                $chunkSize = (int)filesize($fullChunkPath);
                if ($chunkSize < 0) {
                    $chunkSize = 0;
                }
                $expectedSize = max(0, (int)($docChunk['size'] ?? 0));
                if ($expectedSize > 0 && $chunkSize !== $expectedSize) {
                    api_response(400, null, 'Document size mismatch: ' . $docIdKey);
                }
                $chunkHash = normalize_hash_hex($docChunk['hash'] ?? '');
                if ($chunkHash !== '') {
                    $computedChunkHash = normalize_hash_hex(hash_file('sha256', $fullChunkPath));
                    if ($computedChunkHash !== $chunkHash) {
                        api_response(400, null, 'Document hash mismatch: ' . $docIdKey);
                    }
                }
                $chunkFilePath = $fullChunkPath;
                $chunkFilesToClean[] = $fullChunkPath;
                if (!empty($bannedWords)) {
                    $chunkContent = @file_get_contents($fullChunkPath);
                    if ($chunkContent !== false && $chunkContent !== '') {
                        $hit = find_banned_word((string)$chunkContent, $bannedWords);
                        if ($hit) {
                            $displayTitle = trim($docTitle) ?: $docIdKey;
                            api_response(400, null, 'Banned word detected: ' . $hit['word'] . ' (doc: ' . $displayTitle . ')');
                        }
                    }
                    unset($chunkContent);
                }
            } else {
                if (!empty($bannedWords)) {
                    $inlineMdStmt = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                    $inlineMdStmt->execute([':id' => (int)$row['id']]);
                    $inlineMarkdown = (string)($inlineMdStmt->fetchColumn() ?: '');
                    if ($inlineMarkdown !== '') {
                        $hit = find_banned_word($inlineMarkdown, $bannedWords);
                        if ($hit) {
                            $displayTitle = trim($docTitle) ?: $docIdKey;
                            api_response(400, null, 'Banned word detected: ' . $hit['word'] . ' (doc: ' . $displayTitle . ')');
                        }
                    }
                    unset($inlineMarkdown);
                }
            }
            $docContentHash = normalize_hash_hex($row['content_hash'] ?? '');
            if ($chunkHash !== '') {
                $docContentHash = $chunkHash;
            }
            if ($docContentHash === '' && $chunkFilePath) {
                $docContentHash = normalize_hash_hex(hash_file('sha256', $chunkFilePath));
            }
            if ($docContentHash === '' && !$chunkFilePath) {
                $inlineMdStmt2 = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                $inlineMdStmt2->execute([':id' => (int)$row['id']]);
                $inlineMd2 = (string)($inlineMdStmt2->fetchColumn() ?: '');
                $docContentHash = compute_doc_content_hash($inlineMd2);
                unset($inlineMd2);
            }
            $docMetaHash = normalize_hash_hex($row['meta_hash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $docTitle,
                    'icon' => $docIcon,
                    'hPath' => $docHpath,
                    'parentId' => $docParent,
                    'sortIndex' => $docSortIndex,
                    'sortOrder' => $docSortOrder,
                ]);
            }
            $rowSize = (int)($row['size_bytes'] ?? 0);
            if ($chunkSize >= 0) {
                $rowSize = $chunkSize;
            } elseif ($rowSize <= 0) {
                $rowSize = (int)($row['markdown_len'] ?? 0);
            }
            $docRows[] = [
                'doc_id' => $docIdKey,
                'title' => $docTitle,
                'icon' => $docIcon,
                'hpath' => $docHpath,
                'parent_id' => $docParent !== '' ? $docParent : null,
                'sort_index' => $docSortIndex,
                'sort_order' => $docSortOrder,
                'size_bytes' => $rowSize,
                'content_hash' => $docContentHash,
                'meta_hash' => $docMetaHash,
                '_chunk_file' => $chunkFilePath,
                '_upload_row_id' => (int)$row['id'],
            ];
            $docSizeTotal += $rowSize;
            unset($row);
        }

        $manifest = normalize_asset_manifest(json_decode((string)($upload['asset_manifest'] ?? ''), true));
        $assetSizeTotal = 0;
        $manifestEntries = [];
        foreach ($manifest as $item) {
            $path = sanitize_asset_path((string)($item['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $full = $stagingDir . '/' . $path;
            if (!is_file($full)) {
                api_response(400, null, 'Missing asset: ' . $path);
            }
            $size = (int)filesize($full);
            $assetSizeTotal += $size;
            $assetHash = normalize_hash_hex($item['hash'] ?? '');
            if ($assetHash === '') {
                $computed = @hash_file('sha256', $full);
                $assetHash = normalize_hash_hex($computed ?: '');
            }
            $manifestEntries[] = [
                'path' => $path,
                'docId' => isset($item['docId']) ? trim((string)$item['docId']) : null,
                'size' => $size,
                'hash' => $assetHash,
            ];
        }

        $share = null;
        $shareId = (int)($upload['share_id'] ?? 0);
        if ($shareId > 0) {
            $check = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND user_id = :uid LIMIT 1');
            $check->execute([
                ':id' => $shareId,
                ':uid' => $user['id'],
            ]);
            $share = $check->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$share) {
                $shareId = 0;
            }
        }

        $uploadMode = strtolower(trim((string)($upload['upload_mode'] ?? '')));
        $uploadMode = $uploadMode === 'incremental' ? 'incremental' : 'full';
        if ($uploadMode === 'incremental' && !$share) {
            $uploadMode = 'full';
        }
        if ($uploadMode === 'full' && empty($docRows)) {
            api_response(400, null, 'Missing documents');
        }
        $patchManifest = normalize_incremental_patch(json_decode((string)($upload['patch_manifest'] ?? ''), true));
        $changedDocSet = [];
        foreach ($docRows as $row) {
            $docKey = trim((string)($row['doc_id'] ?? ''));
            if ($docKey !== '') {
                $changedDocSet[$docKey] = true;
            }
        }
        $changedAssetSet = [];
        foreach ($manifestEntries as $entry) {
            $assetKey = sanitize_asset_path((string)($entry['path'] ?? ''));
            if ($assetKey !== '') {
                $changedAssetSet[$assetKey] = true;
            }
        }
        $deletedDocIds = [];
        foreach (($patchManifest['deletedDocIds'] ?? []) as $docKey) {
            $docKey = trim((string)$docKey);
            if ($docKey === '' || isset($changedDocSet[$docKey])) {
                continue;
            }
            $deletedDocIds[] = $docKey;
        }
        $deletedAssetPaths = [];
        foreach (($patchManifest['deletedAssetPaths'] ?? []) as $assetPath) {
            $assetPath = sanitize_asset_path((string)$assetPath);
            if ($assetPath === '' || str_starts_with($assetPath, comment_asset_prefix()) || isset($changedAssetSet[$assetPath])) {
                continue;
            }
            $deletedAssetPaths[] = $assetPath;
        }

        $baseShareSize = $docSizeTotal + $assetSizeTotal;
        $commentSize = share_comment_size($shareId);
        $commentAssetSize = share_comment_asset_size($shareId);
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $existingSize = $share ? (int)($share['size_bytes'] ?? 0) : 0;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if ($uploadMode === 'full') {
            if ($limit > 0 && ($usedWithout + $newShareSize) > $limit) {
                api_response(413, null, 'Storage limit reached');
            }
        } elseif ($share) {
            $oldDocSizes = [];
            $stmt = $pdo->prepare('SELECT doc_id, size_bytes FROM share_docs WHERE share_id = :sid');
            $stmt->execute([':sid' => (int)$share['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $oldDocSizes[(string)($row['doc_id'] ?? '')] = (int)($row['size_bytes'] ?? 0);
            }
            $oldAssetSizes = [];
            $stmt = $pdo->prepare('SELECT asset_path, size_bytes FROM share_assets WHERE share_id = :sid AND asset_path NOT LIKE :prefix');
            $stmt->execute([
                ':sid' => (int)$share['id'],
                ':prefix' => comment_asset_prefix() . '%',
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $oldAssetSizes[(string)($row['asset_path'] ?? '')] = (int)($row['size_bytes'] ?? 0);
            }
            $docDelta = 0;
            foreach ($docRows as $row) {
                $key = (string)($row['doc_id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $docDelta += (int)($row['size_bytes'] ?? 0);
                if (isset($oldDocSizes[$key])) {
                    $docDelta -= (int)$oldDocSizes[$key];
                }
            }
            foreach ($deletedDocIds as $key) {
                if (isset($oldDocSizes[$key])) {
                    $docDelta -= (int)$oldDocSizes[$key];
                }
            }
            $assetDelta = 0;
            foreach ($manifestEntries as $entry) {
                $key = (string)($entry['path'] ?? '');
                if ($key === '') {
                    continue;
                }
                $assetDelta += (int)($entry['size'] ?? 0);
                if (isset($oldAssetSizes[$key])) {
                    $assetDelta -= (int)$oldAssetSizes[$key];
                }
            }
            foreach ($deletedAssetPaths as $key) {
                if (isset($oldAssetSizes[$key])) {
                    $assetDelta -= (int)$oldAssetSizes[$key];
                }
            }
            $projectedSize = max(0, $existingSize + $docDelta + $assetDelta);
            if ($limit > 0 && ($usedWithout + $projectedSize) > $limit) {
                api_response(413, null, 'Storage limit reached');
            }
        }

        $slug = sanitize_slug((string)($upload['slug'] ?? ''));
        if ($slug === '') {
            $slug = allocate_random_share_slug_or_fail($pdo);
        } else {
            ensure_share_slug_available_or_fail($pdo, $slug, $share ? (int)$share['id'] : 0);
        }

        $title = trim((string)($upload['title'] ?? ''));
        $passwordHash = $upload['password_hash'] ?? null;
        $expiresValue = isset($upload['expires_at']) ? (int)$upload['expires_at'] : null;
        $visitorValue = isset($upload['visitor_limit']) ? (int)$upload['visitor_limit'] : 0;
        $type = (string)($upload['type'] ?? 'doc');
        $docId = trim((string)($upload['doc_id'] ?? ''));
        $notebookId = trim((string)($upload['notebook_id'] ?? ''));

        $pdo->beginTransaction();
        try {
            if ($share) {
                $update = $pdo->prepare('UPDATE shares SET title = :title, slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at, deleted_at = NULL WHERE id = :id');
                $update->execute([
                    ':title' => $title !== '' ? $title : ($share['title'] ?? $slug),
                    ':slug' => $slug,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':updated_at' => now(),
                    ':id' => $share['id'],
                ]);
                $shareId = (int)$share['id'];
                if ($uploadMode === 'full') {
                    purge_share_assets($shareId, true);
                    purge_share_chunks($shareId);
                    $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
                }
            } else {
                $shareId = allocate_share_id($pdo);
                if ($shareId > 0) {
                    $stmt = $pdo->prepare('INSERT INTO shares (id, user_id, type, slug, title, doc_id, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                        VALUES (:id, :uid, :type, :slug, :title, :doc_id, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                    $stmt->execute([
                        ':id' => $shareId,
                        ':uid' => $user['id'],
                        ':type' => $type,
                        ':slug' => $slug,
                        ':title' => $title !== '' ? $title : $slug,
                        ':doc_id' => $type === 'doc' ? $docId : null,
                        ':notebook_id' => $type === 'notebook' ? $notebookId : null,
                        ':password_hash' => $passwordHash,
                        ':expires_at' => $expiresValue,
                        ':visitor_limit' => $visitorValue,
                        ':created_at' => now(),
                        ':updated_at' => now(),
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO shares (user_id, type, slug, title, doc_id, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                        VALUES (:uid, :type, :slug, :title, :doc_id, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                    $stmt->execute([
                        ':uid' => $user['id'],
                        ':type' => $type,
                        ':slug' => $slug,
                        ':title' => $title !== '' ? $title : $slug,
                        ':doc_id' => $type === 'doc' ? $docId : null,
                        ':notebook_id' => $type === 'notebook' ? $notebookId : null,
                        ':password_hash' => $passwordHash,
                        ':expires_at' => $expiresValue,
                        ':visitor_limit' => $visitorValue,
                        ':created_at' => now(),
                        ':updated_at' => now(),
                    ]);
                    $shareId = (int)$pdo->lastInsertId();
                }
            }

            $targetDir = $config['uploads_dir'] . '/shares/' . $shareId;
            ensure_dir($targetDir);
            if ($uploadMode === 'full') {
                $insertDoc = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
                    VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
                $fetchMdStmt = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                foreach ($docRows as $row) {
                    $docMarkdown = '';
                    if (!empty($row['_chunk_file']) && is_file($row['_chunk_file'])) {
                        $docMarkdown = (string)@file_get_contents($row['_chunk_file']);
                    } else {
                        $fetchMdStmt->execute([':id' => (int)$row['_upload_row_id']]);
                        $docMarkdown = (string)($fetchMdStmt->fetchColumn() ?: '');
                    }
                    $insertDoc->execute([
                        ':share_id' => $shareId,
                        ':doc_id' => $row['doc_id'],
                        ':title' => $row['title'],
                        ':icon' => isset($row['icon']) && trim((string)$row['icon']) !== '' ? $row['icon'] : null,
                        ':hpath' => $row['hpath'],
                        ':parent_id' => $row['parent_id'] ?? null,
                        ':sort_index' => $row['sort_index'] ?? 0,
                        ':markdown' => $docMarkdown,
                        ':sort_order' => $row['sort_order'] ?? 0,
                        ':size_bytes' => $row['size_bytes'] ?? 0,
                        ':content_hash' => normalize_hash_hex($row['content_hash'] ?? ''),
                        ':meta_hash' => normalize_hash_hex($row['meta_hash'] ?? ''),
                        ':created_at' => now(),
                        ':updated_at' => now(),
                    ]);
                    unset($docMarkdown);
                }
                foreach ($chunkFilesToClean as $cf) {
                    if (is_file($cf)) {
                        @unlink($cf);
                    }
                }
                if (is_dir($stagingDir)) {
                    move_dir($stagingDir, $targetDir);
                }
                if (!empty($manifestEntries)) {
                    $assetStmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, asset_hash, created_at)
                        VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :asset_hash, :created_at)');
                    foreach ($manifestEntries as $entry) {
                        $assetStmt->execute([
                            ':share_id' => $shareId,
                            ':doc_id' => $entry['docId'] !== '' ? $entry['docId'] : null,
                            ':asset_path' => $entry['path'],
                            ':file_path' => 'shares/' . $shareId . '/' . $entry['path'],
                            ':size_bytes' => $entry['size'],
                            ':asset_hash' => normalize_hash_hex($entry['hash'] ?? ''),
                            ':created_at' => now(),
                        ]);
                    }
                }
                $updateSize = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
                $updateSize->execute([
                    ':size_bytes' => $newShareSize,
                    ':updated_at' => now(),
                    ':id' => $shareId,
                ]);
            } else {
                if (!empty($deletedDocIds)) {
                    $placeholders = implode(',', array_fill(0, count($deletedDocIds), '?'));
                    $params = $deletedDocIds;
                    array_unshift($params, $shareId);
                    $stmt = $pdo->prepare('DELETE FROM share_docs WHERE share_id = ? AND doc_id IN (' . $placeholders . ')');
                    $stmt->execute($params);
                }

                $findDoc = $pdo->prepare('SELECT id FROM share_docs WHERE share_id = :share_id AND doc_id = :doc_id LIMIT 1');
                $updateDoc = $pdo->prepare('UPDATE share_docs SET title = :title, icon = :icon, hpath = :hpath, parent_id = :parent_id, sort_index = :sort_index, markdown = :markdown, sort_order = :sort_order, size_bytes = :size_bytes, content_hash = :content_hash, meta_hash = :meta_hash, updated_at = :updated_at WHERE id = :id');
                $insertDoc = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
                    VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
                $fetchMdStmt2 = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                foreach ($docRows as $row) {
                    $docKey = trim((string)($row['doc_id'] ?? ''));
                    if ($docKey === '') {
                        continue;
                    }
                    $docMarkdown = '';
                    if (!empty($row['_chunk_file']) && is_file($row['_chunk_file'])) {
                        $docMarkdown = (string)@file_get_contents($row['_chunk_file']);
                    } else {
                        $fetchMdStmt2->execute([':id' => (int)$row['_upload_row_id']]);
                        $docMarkdown = (string)($fetchMdStmt2->fetchColumn() ?: '');
                    }
                    $findDoc->execute([
                        ':share_id' => $shareId,
                        ':doc_id' => $docKey,
                    ]);
                    $docRowId = (int)($findDoc->fetchColumn() ?: 0);
                    $bind = [
                        ':title' => $row['title'],
                        ':icon' => isset($row['icon']) && trim((string)$row['icon']) !== '' ? $row['icon'] : null,
                        ':hpath' => $row['hpath'],
                        ':parent_id' => $row['parent_id'] ?? null,
                        ':sort_index' => $row['sort_index'] ?? 0,
                        ':markdown' => $docMarkdown,
                        ':sort_order' => $row['sort_order'] ?? 0,
                        ':size_bytes' => $row['size_bytes'] ?? 0,
                        ':content_hash' => normalize_hash_hex($row['content_hash'] ?? ''),
                        ':meta_hash' => normalize_hash_hex($row['meta_hash'] ?? ''),
                        ':updated_at' => now(),
                    ];
                    unset($docMarkdown);
                    if ($docRowId > 0) {
                        $bind[':id'] = $docRowId;
                        $updateDoc->execute($bind);
                    } else {
                        $insertDoc->execute(array_merge($bind, [
                            ':share_id' => $shareId,
                            ':doc_id' => $docKey,
                            ':created_at' => now(),
                        ]));
                    }
                }
                foreach ($chunkFilesToClean as $cf) {
                    if (is_file($cf)) {
                        @unlink($cf);
                    }
                }

                if (!empty($deletedAssetPaths)) {
                    $placeholders = implode(',', array_fill(0, count($deletedAssetPaths), '?'));
                    $params = $deletedAssetPaths;
                    array_unshift($params, $shareId);
                    $select = $pdo->prepare('SELECT file_path FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
                    $select->execute($params);
                    $files = $select->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($files as $filePath) {
                        $fullPath = $config['uploads_dir'] . '/' . ltrim((string)$filePath, '/');
                        if ($fullPath !== '' && is_file($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                    $deleteAsset = $pdo->prepare('DELETE FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
                    $deleteAsset->execute($params);
                }

                if (!empty($manifestEntries)) {
                    $assetStmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, asset_hash, created_at)
                        VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :asset_hash, :created_at)');
                    foreach ($manifestEntries as $entry) {
                        $source = $stagingDir . '/' . $entry['path'];
                        if (!is_file($source)) {
                            continue;
                        }
                        $targetFile = $targetDir . '/' . $entry['path'];
                        ensure_dir(dirname($targetFile));
                        if (!@rename($source, $targetFile)) {
                            if (!@copy($source, $targetFile)) {
                                continue;
                            }
                            @unlink($source);
                        }
                        $assetStmt->execute([
                            ':share_id' => $shareId,
                            ':doc_id' => $entry['docId'] !== '' ? $entry['docId'] : null,
                            ':asset_path' => $entry['path'],
                            ':file_path' => 'shares/' . $shareId . '/' . $entry['path'],
                            ':size_bytes' => $entry['size'],
                            ':asset_hash' => normalize_hash_hex($entry['hash'] ?? ''),
                            ':created_at' => now(),
                        ]);
                    }
                }
                recalculate_share_size($shareId);
            }

            $pdo->prepare('DELETE FROM share_upload_docs WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
            $pdo->prepare('DELETE FROM share_uploads WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
            $pdo->commit();
            foreach ($chunkFilesToClean as $chunkFile) {
                if (is_file($chunkFile)) {
                    @unlink($chunkFile);
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Upload finalize failed for uploadId=' . $uploadId . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            api_response(500, null, 'Upload finalize failed: ' . $e->getMessage());
        }

        if (!empty($visitorValue)) {
            seed_share_visitors_from_logs($shareId);
        }
        purge_upload_session_files($uploadId);
        recalculate_user_storage((int)$user['id']);
        api_response(200, [
            'shareId' => $shareId,
            'slug' => $slug,
            'url' => share_url($slug),
        ]);
    }

    if ($path === '/api/v1/shares/upload/cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $uploadId = sanitize_upload_id((string)($payload['uploadId'] ?? ''));
        if ($uploadId === '') {
            api_response(400, null, 'Missing upload id');
        }
        $stmt = $pdo->prepare('SELECT id FROM share_uploads WHERE upload_id = :upload_id AND user_id = :uid LIMIT 1');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':uid' => $user['id'],
        ]);
        if (!$stmt->fetchColumn()) {
            api_response(404, null, 'Upload not found');
        }
        $pdo->prepare('DELETE FROM share_upload_docs WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
        $pdo->prepare('DELETE FROM share_uploads WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
        purge_upload_session_files($uploadId);
        api_response(200, ['ok' => true]);
    }

    if ($path === '/api/v1/shares/doc') {
        $metaRaw = $_POST['metadata'] ?? '';
        if (!$metaRaw) {
            $metaRaw = json_encode(parse_json_body());
        }
        $meta = json_decode($metaRaw, true);
        if (!is_array($meta)) {
            api_response(400, null, 'Invalid data format');
        }
        $docId = trim((string)($meta['docId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $markdown = (string)($meta['markdown'] ?? '');
        $hPath = (string)($meta['hPath'] ?? '');
        $sortOrder = max(0, (int)($meta['sortOrder'] ?? 0));
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        $docs = $meta['docs'] ?? [];
        $hasDocs = is_array($docs) && count($docs) > 0;
        if ($docId === '' || (!$hasDocs && $markdown === '')) {
            api_response(400, null, 'Missing document content');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            if ($hasDocs) {
                foreach ($docs as $doc) {
                    $docMarkdown = (string)($doc['markdown'] ?? '');
                    if ($docMarkdown === '') {
                        continue;
                    }
                    $hit = find_banned_word($docMarkdown, $bannedWords);
                    if ($hit) {
                        $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                        api_response(400, null, 'Triggered banned word: ' . $hit['word'] . ' (Document: ' . $docTitle . ')');
                    }
                }
            } else {
                $hit = find_banned_word($markdown, $bannedWords);
                if ($hit) {
                    api_response(400, null, 'Triggered banned word: ' . $hit['word']);
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $paths = $_POST['assetPaths'] ?? [];
        $docIds = $_POST['assetDocIds'] ?? [];
        $paths = is_array($paths) ? $paths : [$paths];
        $docIds = is_array($docIds) ? $docIds : [$docIds];
        $entries = [];
        if (!empty($_FILES['assets'])) {
            $entries = collect_asset_entries($_FILES['assets'], $paths, $docIds);
        }
        $assetSize = 0;
        foreach ($entries as $entry) {
            $assetSize += (int)($entry['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        if ($hasDocs) {
            foreach ($docs as $index => $doc) {
                $rowDocId = trim((string)($doc['docId'] ?? ''));
                $rowTitle = trim((string)($doc['title'] ?? ''));
                $rowIcon = trim((string)($doc['icon'] ?? ''));
                $rowHpath = (string)($doc['hPath'] ?? '');
                $rowMarkdown = (string)($doc['markdown'] ?? '');
                $rowSort = max(0, (int)($doc['sortOrder'] ?? $index));
                $rowParent = trim((string)($doc['parentId'] ?? ''));
                $rowSortIndex = (float)($doc['sortIndex'] ?? $index);
                if ($rowDocId === '') {
                    continue;
                }
                $size = strlen($rowMarkdown);
                $rowContentHash = compute_doc_content_hash($rowMarkdown);
                $rowMetaHash = compute_doc_meta_hash([
                    'title' => $rowTitle ?: $rowDocId,
                    'icon' => $rowIcon,
                    'hPath' => $rowHpath,
                    'parentId' => $rowParent,
                    'sortIndex' => $rowSortIndex,
                    'sortOrder' => $rowSort,
                ]);
                $docSizeTotal += $size;
                $docRows[] = [
                    'docId' => $rowDocId,
                    'title' => $rowTitle ?: $rowDocId,
                    'icon' => $rowIcon,
                    'hPath' => $rowHpath,
                    'parentId' => $rowParent,
                    'sortIndex' => $rowSortIndex,
                    'markdown' => $rowMarkdown,
                    'sortOrder' => $rowSort,
                    'size' => $size,
                    'contentHash' => $rowContentHash,
                    'metaHash' => $rowMetaHash,
                ];
            }
            if (empty($docRows)) {
                api_response(400, null, 'Missing document content');
            }
        } else {
            $docSizeTotal = strlen($markdown);
            $docIcon = trim((string)($meta['icon'] ?? ''));
            $docContentHash = compute_doc_content_hash($markdown);
            $docMetaHash = compute_doc_meta_hash([
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => '',
                'sortIndex' => 0,
                'sortOrder' => $sortOrder,
            ]);
            $docRows[] = [
                'docId' => $docId,
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => null,
                'sortIndex' => 0,
                'markdown' => $markdown,
                'sortOrder' => $sortOrder,
                'size' => $docSizeTotal,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "doc" AND doc_id = :doc_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':doc_id' => $docId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if ($limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, 'Insufficient storage space, please free up space and retry');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        if ($existing) {
            if ($slug && $slug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $slug, (int)$existing['id']);
            }
            $newSlug = $slug ?: $existing['slug'];
            $stmt = $pdo->prepare('UPDATE shares SET title = :title, slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at, deleted_at = NULL WHERE id = :id');
            $stmt->execute([
                ':title' => $title ?: $existing['title'],
                ':slug' => $newSlug,
                ':password_hash' => $passwordHash,
                ':expires_at' => $expiresValue,
                ':visitor_limit' => $visitorValue,
                ':updated_at' => now(),
                ':id' => $existing['id'],
            ]);
            $shareId = (int)$existing['id'];
            $slug = $newSlug;
        } else {
            if (!$slug) {
                $slug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $slug);
            }
            $shareId = allocate_share_id($pdo);
            if ($shareId > 0) {
                $stmt = $pdo->prepare('INSERT INTO shares (id, user_id, type, slug, title, doc_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:id, :uid, "doc", :slug, :title, :doc_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':id' => $shareId,
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $docId,
                    ':doc_id' => $docId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO shares (user_id, type, slug, title, doc_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:uid, "doc", :slug, :title, :doc_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $docId,
                    ':doc_id' => $docId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
                $shareId = (int)$pdo->lastInsertId();
            }
        }

        if ($existing) {
            purge_share_assets($shareId, true);
        }
        if (!empty($visitorValue)) {
            seed_share_visitors_from_logs($shareId);
        }
        $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
        $insertDoc = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $insertDoc->execute([
                ':share_id' => $shareId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }

        $actualAssets = handle_asset_uploads($shareId, $entries);
        $finalSize = $docSizeTotal + $actualAssets + share_comment_size($shareId) + share_comment_asset_size($shareId);
        $stmt = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':size_bytes' => $finalSize,
            ':updated_at' => now(),
            ':id' => $shareId,
        ]);
        recalculate_user_storage((int)$user['id']);

        api_response(200, ['share' => [
            'id' => $shareId,
            'slug' => $slug,
            'url' => share_url($slug),
        ]]);
    }

    if ($path === '/api/v1/shares/notebook') {
        $metaRaw = $_POST['metadata'] ?? '';
        if (!$metaRaw) {
            $metaRaw = json_encode(parse_json_body());
        }
        $meta = json_decode($metaRaw, true);
        if (!is_array($meta)) {
            api_response(400, null, 'Invalid data format');
        }
        $notebookId = trim((string)($meta['notebookId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $docs = $meta['docs'] ?? [];
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        if ($notebookId === '' || !is_array($docs) || count($docs) === 0) {
            api_response(400, null, 'Missing notebook ID or document data');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            foreach ($docs as $doc) {
                $docMarkdown = (string)($doc['markdown'] ?? '');
                if ($docMarkdown === '') {
                    continue;
                }
                $hit = find_banned_word($docMarkdown, $bannedWords);
                if ($hit) {
                    $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                    api_response(400, null, 'Triggered banned word: ' . $hit['word'] . ' (Document: ' . $docTitle . ')');
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $paths = $_POST['assetPaths'] ?? [];
        $docIds = $_POST['assetDocIds'] ?? [];
        $paths = is_array($paths) ? $paths : [$paths];
        $docIds = is_array($docIds) ? $docIds : [$docIds];
        $entries = [];
        if (!empty($_FILES['assets'])) {
            $entries = collect_asset_entries($_FILES['assets'], $paths, $docIds);
        }
        $assetSize = 0;
        foreach ($entries as $entry) {
            $assetSize += (int)($entry['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        foreach ($docs as $index => $doc) {
            $docId = trim((string)($doc['docId'] ?? ''));
            $docTitle = trim((string)($doc['title'] ?? ''));
            $docIcon = trim((string)($doc['icon'] ?? ''));
            $docHpath = (string)($doc['hPath'] ?? '');
            $docMarkdown = (string)($doc['markdown'] ?? '');
            $docSort = max(0, (int)($doc['sortOrder'] ?? $index));
            $docParent = trim((string)($doc['parentId'] ?? ''));
            $docSortIndex = (float)($doc['sortIndex'] ?? $index);
            if ($docId === '') {
                continue;
            }
            $size = strlen($docMarkdown);
            $docContentHash = compute_doc_content_hash($docMarkdown);
            $docMetaHash = compute_doc_meta_hash([
                'title' => $docTitle ?: $docId,
                'icon' => $docIcon,
                'hPath' => $docHpath,
                'parentId' => $docParent,
                'sortIndex' => $docSortIndex,
                'sortOrder' => $docSort,
            ]);
            $docSizeTotal += $size;
            $docRows[] = [
                'docId' => $docId,
                'title' => $docTitle ?: $docId,
                'icon' => $docIcon,
                'hPath' => $docHpath,
                'parentId' => $docParent,
                'sortIndex' => $docSortIndex,
                'markdown' => $docMarkdown,
                'sortOrder' => $docSort,
                'size' => $size,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        if (empty($docRows)) {
            api_response(400, null, 'No available documents');
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "notebook" AND notebook_id = :nid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':nid' => $notebookId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if ($limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, 'Insufficient storage space, please free up space and retry');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        if ($existing) {
            if ($slug && $slug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $slug, (int)$existing['id']);
            }
            $newSlug = $slug ?: $existing['slug'];
            $stmt = $pdo->prepare('UPDATE shares SET title = :title, slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at, deleted_at = NULL WHERE id = :id');
            $stmt->execute([
                ':title' => $title ?: $existing['title'],
                ':slug' => $newSlug,
                ':password_hash' => $passwordHash,
                ':expires_at' => $expiresValue,
                ':visitor_limit' => $visitorValue,
                ':updated_at' => now(),
                ':id' => $existing['id'],
            ]);
            $shareId = (int)$existing['id'];
            $slug = $newSlug;
        } else {
            if (!$slug) {
                $slug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $slug);
            }
            $shareId = allocate_share_id($pdo);
            if ($shareId > 0) {
                $stmt = $pdo->prepare('INSERT INTO shares (id, user_id, type, slug, title, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:id, :uid, "notebook", :slug, :title, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':id' => $shareId,
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $notebookId,
                    ':notebook_id' => $notebookId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO shares (user_id, type, slug, title, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:uid, "notebook", :slug, :title, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $notebookId,
                    ':notebook_id' => $notebookId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
                $shareId = (int)$pdo->lastInsertId();
            }
        }

        if ($existing) {
            purge_share_assets($shareId, true);
        }
        if (!empty($visitorValue)) {
            seed_share_visitors_from_logs($shareId);
        }
        $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
        $stmt = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $stmt->execute([
                ':share_id' => $shareId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => (int)$row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }

        $actualAssets = handle_asset_uploads($shareId, $entries);
        $finalSize = $docSizeTotal + $actualAssets + share_comment_size($shareId) + share_comment_asset_size($shareId);
        $stmt = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':size_bytes' => $finalSize,
            ':updated_at' => now(),
            ':id' => $shareId,
        ]);
        recalculate_user_storage((int)$user['id']);

        api_response(200, ['share' => [
            'id' => $shareId,
            'slug' => $slug,
            'url' => share_url($slug),
        ]]);
    }

    api_response(404, null, 'Endpoint not found');
}

function rewrite_asset_links(string $markdown, string $assetBase = ''): string {
    $prefix = $assetBase;
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
    $markdown = str_replace('](/assets/', '](' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](./assets/', '](' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](assets/', '](' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](<assets/', '](<' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](</assets/', '](<' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('src="/assets/', 'src="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('src="./assets/', 'src="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('src="assets/', 'src="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('href="/assets/', 'href="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('href="./assets/', 'href="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('href="assets/', 'href="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](/emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](./emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](<emojis/', '](<' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](</emojis/', '](<' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="/emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="./emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="/emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="./emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](/emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](./emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="/emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="./emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="/emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="./emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    return $markdown;
}

function encode_path_segments(string $path): string {
    $parts = explode('/', $path);
    $encoded = array_map(static fn($part) => rawurlencode($part), $parts);
    return implode('/', $encoded);
}

function sanitize_emoji_token_name(string $token): string {
    $token = trim($token);
    if ($token === '' || $token[0] !== ':' || substr($token, -1) !== ':') {
        return '';
    }
    $name = trim(substr($token, 1, -1));
    if ($name === '' || strpos($name, "\n") !== false || strpos($name, "\r") !== false) {
        return '';
    }
    if (strpos($name, ':') !== false) {
        return '';
    }
    return $name;
}

function normalize_emoji_key(string $name): string {
    $value = strtolower(trim($name));
    if ($value === '') {
        return '';
    }
    $normalized = preg_replace('/[\s_-]+/', '', $value);
    return $normalized ?? '';
}

function resolve_custom_emoji_src(string $name, int $shareId, string $assetBasePath): string {
    global $config;
    static $cache = [];
    $key = $shareId . '|' . $name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $clean = str_replace('\\', '/', $name);
    $clean = ltrim($clean, '/');
    if ($clean === '' || strpos($clean, '..') !== false) {
        $cache[$key] = '';
        return '';
    }
    $decoded = rawurldecode($clean);
    $candidates = [$clean];
    if ($decoded !== '' && $decoded !== $clean) {
        $candidates[] = $decoded;
    }
    $compact = preg_replace('/\s+/', '', $clean);
    if ($compact !== $clean && $compact !== '') {
        $candidates[] = $compact;
    }
    $extensions = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];
    $uploadsDir = (string)($config['uploads_dir'] ?? (__DIR__ . '/uploads'));
    $uploadsDir = rtrim($uploadsDir, '/\\');
    $fsBase = $uploadsDir . '/shares/' . $shareId . '/emojis/';
    foreach ($candidates as $candidate) {
        $hasExt = (bool)preg_match('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', $candidate);
        $names = $hasExt ? [$candidate] : array_merge([$candidate], array_map(
            static fn($ext) => $candidate . '.' . $ext,
            $extensions
        ));
        foreach ($names as $file) {
            $fsPath = $fsBase . $file;
            if (is_file($fsPath)) {
                $rel = 'emojis/' . $file;
                $cache[$key] = $assetBasePath . encode_path_segments($rel);
                return $cache[$key];
            }
        }
    }
    $index = build_share_emoji_index($shareId, $uploadsDir);
    if (!empty($index)) {
        $normalizedIndex = [];
        foreach ($index as $nameKey => $rel) {
            $norm = normalize_emoji_key($nameKey);
            if ($norm !== '' && !isset($normalizedIndex[$norm])) {
                $normalizedIndex[$norm] = $rel;
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($index[$candidate])) {
                $cache[$key] = $assetBasePath . encode_path_segments($index[$candidate]);
                return $cache[$key];
            }
            $lower = strtolower($candidate);
            foreach ($index as $nameKey => $rel) {
                if (strtolower($nameKey) === $lower) {
                    $cache[$key] = $assetBasePath . encode_path_segments($rel);
                    return $cache[$key];
                }
            }
            foreach ($index as $nameKey => $rel) {
                if (str_starts_with($nameKey, $candidate . '-')) {
                    $cache[$key] = $assetBasePath . encode_path_segments($rel);
                    return $cache[$key];
                }
            }
            if (!empty($normalizedIndex)) {
                $normalized = normalize_emoji_key($candidate);
                if ($normalized !== '' && isset($normalizedIndex[$normalized])) {
                    $cache[$key] = $assetBasePath . encode_path_segments($normalizedIndex[$normalized]);
                    return $cache[$key];
                }
            }
        }
    }
    $cache[$key] = '';
    return '';
}

function build_share_emoji_index(int $shareId, string $uploadsDir): array {
    static $cache = [];
    $key = $uploadsDir . '|' . $shareId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $map = [];
    if ($shareId <= 0 || $uploadsDir === '') {
        $cache[$key] = $map;
        return $map;
    }
    $root = rtrim($uploadsDir, '/\\') . '/shares/' . $shareId . '/emojis';
    if (!is_dir($root)) {
        $cache[$key] = $map;
        return $map;
    }
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $fullPath = str_replace('\\', '/', $file->getPathname());
            if (!preg_match('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', $fullPath)) {
                continue;
            }
            $relative = substr($fullPath, strlen(rtrim($root, '/\\')) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            $name = preg_replace('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', '', $relative);
            if ($name === '') {
                continue;
            }
            $map[$name] = 'emojis/' . $relative;
            $baseName = basename($name);
            if ($baseName !== '' && !isset($map[$baseName])) {
                $map[$baseName] = 'emojis/' . $relative;
            }
        }
    } catch (Throwable $err) {
        // ignore
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT asset_path FROM share_assets WHERE share_id = :sid AND asset_path LIKE :prefix');
        $stmt->execute([
            ':sid' => $shareId,
            ':prefix' => 'emojis/%',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $path) {
            $path = sanitize_asset_path((string)$path);
            if ($path === '' || !str_starts_with($path, 'emojis/')) {
                continue;
            }
            $relative = substr($path, strlen('emojis/'));
            if ($relative === '' || substr($relative, -1) === '/') {
                continue;
            }
            $name = preg_replace('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', '', $relative);
            if ($name === '' || isset($map[$name])) {
                continue;
            }
            $map[$name] = $path;
            $baseName = basename($name);
            if ($baseName !== '' && !isset($map[$baseName])) {
                $map[$baseName] = $path;
            }
        }
    } catch (Throwable $err) {
        // ignore
    }
    $cache[$key] = $map;
    return $map;
}

function get_fence_marker(string $text, int $index): string {
    $ch = $text[$index] ?? '';
    if ($ch !== '`' && $ch !== '~') {
        return '';
    }
    $marker = substr($text, $index, 3);
    if ($marker !== '```' && $marker !== '~~~') {
        return '';
    }
    $i = $index - 1;
    while ($i >= 0 && $text[$i] === ' ') {
        $i--;
    }
    if ($i >= 0 && $text[$i] !== "\n") {
        return '';
    }
    return $marker;
}

function get_emoji_token_name_at(string $text, int $index): string {
    $len = strlen($text);
    if ($index < 0 || $index >= $len) {
        return '';
    }
    if ($text[$index] !== ':') {
        return '';
    }
    $end = strpos($text, ':', $index + 1);
    if ($end === false || $end <= $index + 1) {
        return '';
    }
    return sanitize_emoji_token_name(substr($text, $index, $end - $index + 1));
}

function replace_custom_emoji_tokens(string $markdown, int $shareId, string $assetBasePath): string {
    $source = (string)$markdown;
    if ($source === '') {
        return $source;
    }
    $len = strlen($source);
    $out = '';
    $inFence = false;
    $fenceMarker = '';
    $inInline = false;
    $inTag = false;
    $i = 0;
    while ($i < $len) {
        $fence = $inTag ? '' : get_fence_marker($source, $i);
        if (!$inFence && $fence !== '') {
            $inFence = true;
            $fenceMarker = $fence;
            $out .= $fence;
            $i += 3;
            continue;
        }
        if ($inFence && $fence !== '' && $fence === $fenceMarker) {
            $inFence = false;
            $fenceMarker = '';
            $out .= $fence;
            $i += 3;
            continue;
        }
        $ch = $source[$i];
        if (!$inFence) {
            if ($ch === '<') {
                $next = $source[$i + 1] ?? '';
                if ($next === '!' || $next === '/' || ctype_alpha($next)) {
                    $inTag = true;
                    $out .= $ch;
                    $i++;
                    continue;
                }
            }
            if ($inTag) {
                $out .= $ch;
                if ($ch === '>') {
                    $inTag = false;
                }
                $i++;
                continue;
            }
            if ($ch === '`') {
                $inInline = !$inInline;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === "\n") {
                $inInline = false;
            }
            if (!$inInline && $ch === ':') {
                $end = strpos($source, ':', $i + 1);
                if ($end !== false && $end > $i + 1) {
                    $token = substr($source, $i, $end - $i + 1);
                    $name = sanitize_emoji_token_name($token);
                    if ($name !== '') {
                        $src = resolve_custom_emoji_src($name, $shareId, $assetBasePath);
                        if ($src !== '') {
                            $out .= '![](<';
                            $out .= $src;
                            $out .= '>)';
                            $nextName = get_emoji_token_name_at($source, $end + 1);
                            if ($nextName !== '') {
                                $nextSrc = resolve_custom_emoji_src($nextName, $shareId, $assetBasePath);
                                if ($nextSrc !== '') {
                                    $out .= ' ';
                                }
                            }
                            $i = $end + 1;
                            continue;
                        }
                    }
                    $out .= $token;
                    $i = $end + 1;
                    continue;
                }
            }
        }
        $out .= $ch;
        $i++;
    }
    return $out;
}

function insert_adjacent_emoji_image_spacing(string $markdown): string {
    $source = (string)$markdown;
    if ($source === '') {
        return $source;
    }
    return preg_replace(
        '/(!\[[^\]]*]\((?:<)?[^)\s]*emojis\/[^)\s>]+(?:>)?\))(?=!\[[^\]]*]\((?:<)?[^)\s]*emojis\/)/',
        '$1 ',
        $source,
    ) ?? $source;
}

function strip_duplicate_title_heading(string $markdown, string $title): string {
    $title = trim($title);
    if ($title === '') {
        return $markdown;
    }
    $lines = preg_split("/\r\n|\r|\n/", $markdown);
    if (!is_array($lines)) {
        return $markdown;
    }
    $count = count($lines);
    $idx = 0;
    while ($idx < $count && trim($lines[$idx]) === '') {
        $idx++;
    }
    if ($idx >= $count) {
        return $markdown;
    }
    $line = trim($lines[$idx]);
    $next = $idx + 1 < $count ? trim($lines[$idx + 1]) : '';
    $headingText = '';
    $removeLines = 0;
    if (preg_match('/^#{1,6}\s+(.*)$/', $line, $match)) {
        $headingText = trim($match[1]);
        $removeLines = 1;
    } elseif ($line === $title && $next !== '' && preg_match('/^=+$/', $next)) {
        $headingText = $line;
        $removeLines = 2;
    }
    if ($headingText === '' || $headingText !== $title) {
        return $markdown;
    }
    $start = $idx;
    $end = $idx + $removeLines;
    while ($end < $count && trim($lines[$end]) === '') {
        $end++;
    }
    array_splice($lines, $start, $end - $start);
    return implode("\n", $lines);
}

function share_style_is_dangerous(string $style): bool {
    $value = strtolower((string)$style);
    $compact = preg_replace('/[\x00-\x1f\x7f]+/u', '', $value);
    if (!is_string($compact)) {
        $compact = $value;
    }
    return (bool)preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|url\s*\(\s*[\'"]?\s*data\s*:\s*text\/html/i', $compact);
}

function is_safe_share_url(string $value, string $tag = '', string $attr = ''): bool {
    $raw = trim((string)$value);
    if ($raw === '') {
        return true;
    }
    $compact = preg_replace('/[\x00-\x20\x7f\s]+/u', '', $raw);
    if (!is_string($compact) || $compact === '') {
        $compact = $raw;
    }
    $tag = strtolower($tag);
    $attr = strtolower($attr);

    if (preg_match('/^(?:javascript|vbscript)\s*:/i', $compact)) {
        return false;
    }
    if (preg_match('/^data\s*:/i', $compact)) {
        if ($tag === 'img' && $attr === 'src') {
            return (bool)preg_match('/^data:image\/(?:png|gif|jpe?g|webp|bmp|avif);base64,[a-z0-9+\/=\r\n]+$/i', $compact);
        }
        return false;
    }
    if ($tag === 'iframe' && $attr === 'src') {
        if ($compact === '') {
            return true;
        }
        if (preg_match('/^(?:https?:)?\/\//i', $compact)) {
            return true;
        }
        if (str_starts_with($compact, '/') || str_starts_with($compact, './') || str_starts_with($compact, '../')) {
            return true;
        }
        if (preg_match('/^about:blank(?:[?#].*)?$/i', $compact)) {
            return true;
        }
        return false;
    }
    if ($compact[0] === '#') {
        return true;
    }
    if (str_starts_with($compact, '/') && !str_starts_with($compact, '//')) {
        return true;
    }
    if (str_starts_with($compact, './') || str_starts_with($compact, '../')) {
        return true;
    }
    if (str_starts_with($compact, '//')) {
        return true;
    }
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $compact)) {
        return (bool)preg_match('/^(?:https?|mailto|tel):/i', $compact);
    }
    return true;
}

function sanitize_share_html(string $html): string {
    $source = (string)$html;
    if ($source === '') {
        return '';
    }
    if (!class_exists('DOMDocument')) {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $libxmlBackup = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapperId = '__sps_share_html_root__';
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8"><!doctype html><html><body><div id="' . $wrapperId . '">' . $source . '</div></body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($libxmlBackup);
    if (!$loaded) {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $root = null;
    $xpath = new DOMXPath($dom);
    $rootNodes = $xpath->query('//*[@id="' . $wrapperId . '"]');
    if ($rootNodes && $rootNodes->length > 0 && $rootNodes->item(0) instanceof DOMElement) {
        $root = $rootNodes->item(0);
    }
    if (!$root) {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $blockedTags = ['script', 'object', 'embed', 'svg', 'math', 'base', 'meta', 'link'];
    $katexSvgAllowedTags = ['svg', 'path', 'line', 'g'];
    $katexSvgAllowedAttrs = [
        'svg' => ['class', 'style', 'xmlns', 'xmlns:xlink', 'width', 'height', 'viewbox', 'preserveaspectratio', 'role', 'aria-hidden', 'focusable'],
        'path' => ['class', 'style', 'd', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'transform', 'opacity'],
        'line' => ['class', 'style', 'x1', 'y1', 'x2', 'y2', 'fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'transform', 'opacity'],
        'g' => ['class', 'style', 'transform', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity'],
    ];
    $urlAttrs = ['href', 'src', 'xlink:href', 'formaction', 'action', 'poster'];
    $iframeAllowedAttrs = [
        'src',
        'width',
        'height',
        'title',
        'allow',
        'allowfullscreen',
        'loading',
        'referrerpolicy',
        'sandbox',
        'frameborder',
    ];

    $elements = [];
    foreach ($root->getElementsByTagName('*') as $node) {
        $elements[] = $node;
    }

    foreach ($elements as $element) {
        if (!($element instanceof DOMElement)) {
            continue;
        }

        $tag = strtolower($element->tagName);
        $insideSvg = false;
        $insideKatex = false;
        $cursor = $element;
        while ($cursor instanceof DOMElement) {
            if (strtolower($cursor->tagName) === 'svg') {
                $insideSvg = true;
            }
            if (preg_match('/(?:^|\s)katex(?:\s|$)/i', (string)$cursor->getAttribute('class'))) {
                $insideKatex = true;
            }
            $parent = $cursor->parentNode;
            $cursor = $parent instanceof DOMElement ? $parent : null;
        }
        $isKatexSvgNode = $insideSvg && $insideKatex && in_array($tag, $katexSvgAllowedTags, true);
        if (in_array($tag, $blockedTags, true)) {
            if (!($tag === 'svg' && $isKatexSvgNode)) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
                continue;
            }
        } elseif ($insideSvg && !$isKatexSvgNode) {
            if ($element->parentNode) {
                $element->parentNode->removeChild($element);
            }
            continue;
        }

        $attrNames = [];
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                $attrNames[] = $attribute->name;
            }
        }
        $allowedSvgAttrs = $isKatexSvgNode ? ($katexSvgAllowedAttrs[$tag] ?? []) : [];

        foreach ($attrNames as $attrNameRaw) {
            $attrName = strtolower($attrNameRaw);
            $attrValue = (string)$element->getAttribute($attrNameRaw);

            if (str_starts_with($attrName, 'on')) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($attrName === 'srcdoc') {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($isKatexSvgNode && !in_array($attrName, $allowedSvgAttrs, true)) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($attrName === 'style' && share_style_is_dangerous($attrValue)) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($isKatexSvgNode) {
                continue;
            }
            if ($tag === 'iframe' && !in_array($attrName, $iframeAllowedAttrs, true)) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if (in_array($attrName, $urlAttrs, true) && !is_safe_share_url($attrValue, $tag, $attrName)) {
                $element->removeAttribute($attrNameRaw);
            }
        }

        if ($tag === 'iframe') {
            $iframeSrc = trim((string)$element->getAttribute('src'));
            if ($iframeSrc !== '' && !is_safe_share_url($iframeSrc, 'iframe', 'src')) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
            }
        }
    }

    $output = '';
    foreach ($root->childNodes as $child) {
        $rendered = $dom->saveHTML($child);
        if (is_string($rendered)) {
            $output .= $rendered;
        }
    }

    return $output;
}

function render_markdown(string $markdown): string {
    static $parser = null;
    if (!$parser) {
        $parser = new Parsedown();
        if (method_exists($parser, 'setSafeMode')) {
            $parser->setSafeMode(false);
        }
    }
    return sanitize_share_html($parser->text($markdown));
}

function share_requires_password(array $share): bool {
    return !empty($share['password_hash']);
}

function share_is_expired(array $share): bool {
    if (empty($share['expires_at'])) {
        return false;
    }
    return time() > (int)$share['expires_at'];
}

function share_password_hash_by_id(int $shareId): string {
    static $cache = [];
    if ($shareId <= 0) {
        return '';
    }
    if (array_key_exists($shareId, $cache)) {
        return (string)$cache[$shareId];
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT password_hash FROM shares WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $shareId]);
    $value = $stmt->fetchColumn();
    $hash = is_string($value) ? trim($value) : '';
    $cache[$shareId] = $hash;
    return $hash;
}

function share_access_signature(string $passwordHash): string {
    $raw = trim($passwordHash);
    if ($raw === '') {
        return '';
    }
    return hash('sha256', $raw);
}

function share_access_granted(int $shareId): bool {
    if ($shareId <= 0) {
        return false;
    }
    $entry = $_SESSION['share_access'][$shareId] ?? null;
    if (empty($entry)) {
        return false;
    }
    $passwordHash = share_password_hash_by_id($shareId);
    if ($passwordHash === '') {
        // No password now: old session entries are irrelevant.
        return true;
    }
    $expected = share_access_signature($passwordHash);
    if ($expected === '') {
        return false;
    }
    if ($entry === true) {
        // Legacy boolean session format; force one re-verification for protected shares.
        return false;
    }
    if (is_string($entry) && $entry !== '') {
        return hash_equals($expected, $entry);
    }
    if (is_array($entry)) {
        $token = trim((string)($entry['token'] ?? ''));
        if ($token !== '') {
            return hash_equals($expected, $token);
        }
    }
    return false;
}

function grant_share_access(int $shareId): void {
    if ($shareId <= 0) {
        return;
    }
    if (!isset($_SESSION['share_access']) || !is_array($_SESSION['share_access'])) {
        $_SESSION['share_access'] = [];
    }
    $passwordHash = share_password_hash_by_id($shareId);
    if ($passwordHash === '') {
        $_SESSION['share_access'][$shareId] = true;
        return;
    }
    $_SESSION['share_access'][$shareId] = share_access_signature($passwordHash);
}

function share_visitor_limit(array $share): int {
    return max(0, (int)($share['visitor_limit'] ?? 0));
}

function seed_share_visitors_from_logs(int $shareId): void {
    if ($shareId <= 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM share_visitors WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }
    $pdo->prepare('INSERT OR IGNORE INTO share_visitors (share_id, visitor_id, created_at)
        SELECT share_id, visitor_id, MIN(created_at) FROM share_access_logs
        WHERE share_id = :sid AND visitor_id IS NOT NULL AND visitor_id != ""
        GROUP BY visitor_id')->execute([':sid' => $shareId]);
}

function share_visitor_count(int $shareId): int {
    if ($shareId <= 0) {
        return 0;
    }
    seed_share_visitors_from_logs($shareId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM share_visitors WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    return (int)$stmt->fetchColumn();
}

function share_visitor_limit_reached(array $share): bool {
    $limit = share_visitor_limit($share);
    if ($limit <= 0) {
        return false;
    }
    $shareId = (int)($share['id'] ?? 0);
    if ($shareId <= 0) {
        return false;
    }
    seed_share_visitors_from_logs($shareId);
    $visitorId = get_visitor_id();
    $pdo = db();
    if ($visitorId !== '') {
        $stmt = $pdo->prepare('SELECT 1 FROM share_visitors WHERE share_id = :sid AND visitor_id = :vid LIMIT 1');
        $stmt->execute([
            ':sid' => $shareId,
            ':vid' => $visitorId,
        ]);
        if ($stmt->fetchColumn()) {
            return false;
        }
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM share_visitors WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    return (int)$stmt->fetchColumn() >= $limit;
}

function register_share_visitor(int $shareId, string $visitorId): void {
    if ($shareId <= 0 || $visitorId === '') {
        return;
    }
    $pdo = db();
    $pdo->prepare('INSERT OR IGNORE INTO share_visitors (share_id, visitor_id, created_at)
        VALUES (:sid, :vid, :created_at)')->execute([
        ':sid' => $shareId,
        ':vid' => $visitorId,
        ':created_at' => now(),
    ]);
}

function share_inline_asset_extensions(): array {
    return [
        // Images
        'apng',
        'avif',
        'bmp',
        'gif',
        'ico',
        'jpg',
        'jpeg',
        'jxl',
        'png',
        'svg',
        'tif',
        'tiff',
        'webp',
        // Audio
        'aac',
        'flac',
        'm4a',
        'mp3',
        'oga',
        'wav',
        'ogg',
        'opus',
        'weba',
        // Video
        'avi',
        'm4v',
        'mkv',
        'mov',
        'mp4',
        'ogv',
        'webm',
        // Text / documents
        'csv',
        'json',
        'log',
        'srt',
        'txt',
        'md',
        'pdf',
        'vtt',
        'xml',
        'yaml',
        'yml',
    ];
}

function share_executable_asset_extensions(): array {
    return [
        'bat',
        'cgi',
        'cmd',
        'com',
        'cpl',
        'dll',
        'exe',
        'hta',
        'htm',
        'html',
        'jar',
        'js',
        'jse',
        'mjs',
        'msi',
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phar',
        'phtml',
        'pl',
        'ps1',
        'psd1',
        'psm1',
        'py',
        'rb',
        'scr',
        'sh',
        'ts',
        'tsx',
        'vb',
        'vbe',
        'vbs',
        'wsf',
        'wsh',
        'xhtml',
    ];
}

function share_asset_is_executable(string $assetPath): bool {
    $ext = share_asset_extension($assetPath);
    if ($ext === '') {
        return false;
    }
    return in_array($ext, share_executable_asset_extensions(), true);
}

function share_asset_extension(string $assetPath): string {
    $ext = strtolower((string)pathinfo($assetPath, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : '';
}

function share_asset_allow_inline(string $assetPath): bool {
    if (share_asset_is_executable($assetPath)) {
        return false;
    }
    $ext = share_asset_extension($assetPath);
    if ($ext === '') {
        return false;
    }
    return in_array($ext, share_inline_asset_extensions(), true);
}

function detect_share_asset_mime(string $fullPath, string $assetPath): string {
    $ext = share_asset_extension($assetPath);
    $map = [
        'csv' => 'text/csv; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'log' => 'text/plain; charset=UTF-8',
        'srt' => 'text/plain; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8',
        'vtt' => 'text/vtt; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
        'yaml' => 'text/yaml; charset=UTF-8',
        'yml' => 'text/yaml; charset=UTF-8',
        'pdf' => 'application/pdf',
        'apng' => 'image/apng',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jxl' => 'image/jxl',
        'svg' => 'image/svg+xml',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'weba' => 'audio/webm',
        'avi' => 'video/x-msvideo',
        'm4v' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'mp4' => 'video/mp4',
        'ogv' => 'video/ogg',
        'webm' => 'video/webm',
    ];
    $fallback = $map[$ext] ?? 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = (string)@finfo_file($finfo, $fullPath);
            @finfo_close($finfo);
            if ($detected !== '' && strtolower($detected) !== 'application/octet-stream') {
                return $detected;
            }
        }
    }
    return $fallback;
}

function render_share_asset_not_found(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Resource not found';
    exit;
}

function build_share_asset_public_path(array $share, string $assetPath): string {
    $shareId = (int)($share['id'] ?? 0);
    $assetPath = sanitize_asset_path($assetPath);
    if ($shareId <= 0 || $assetPath === '') {
        return '';
    }
    if (str_starts_with($assetPath, comment_asset_prefix())) {
        return '/uploads/' . $assetPath;
    }
    return '/uploads/shares/' . $shareId . '/' . $assetPath;
}

function resolve_asset_resume_redirect_path(array $share): string {
    $raw = trim((string)($_GET['asset'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $shareId = (int)($share['id'] ?? 0);
    if ($shareId <= 0) {
        return '';
    }
    $assetPath = sanitize_asset_path(rawurldecode($raw));
    if ($assetPath === '') {
        return '';
    }
    $asset = find_share_asset_row($shareId, $assetPath);
    if (!$asset) {
        return '';
    }
    return build_share_asset_public_path($share, $assetPath);
}

function redirect_to_share_page(array $share, string $assetPath = ''): void {
    $slug = trim((string)($share['slug'] ?? ''));
    if ($slug === '') {
        render_share_asset_not_found();
    }
    $path = '/s/' . rawurlencode($slug);
    $cleanAsset = sanitize_asset_path($assetPath);
    if ($cleanAsset !== '') {
        $path .= '?asset=' . rawurlencode($cleanAsset);
    }
    redirect($path);
}

function serve_share_asset(array $share, string $assetPath): void {
    global $config;
    $shareId = (int)($share['id'] ?? 0);
    if ($shareId <= 0) {
        render_share_asset_not_found();
    }
    $assetPath = sanitize_asset_path(rawurldecode($assetPath));
    if ($assetPath === '') {
        render_share_asset_not_found();
    }
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        redirect_to_share_page($share, $assetPath);
    }
    if (share_requires_password($share) && !share_access_granted($shareId)) {
        redirect_to_share_page($share, $assetPath);
    }
    if (share_visitor_limit($share) > 0) {
        register_share_visitor($shareId, get_visitor_id());
    }
    $asset = find_share_asset_row($shareId, $assetPath);
    if (!$asset) {
        render_share_asset_not_found();
    }
    $filePath = sanitize_asset_path((string)($asset['file_path'] ?? ''));
    if ($filePath === '') {
        render_share_asset_not_found();
    }
    $uploadsDir = rtrim((string)($config['uploads_dir'] ?? (__DIR__ . '/uploads')), '/\\');
    $fullPath = $uploadsDir . '/' . ltrim($filePath, '/');
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        render_share_asset_not_found();
    }
    $inline = share_asset_allow_inline($assetPath);
    $mime = $inline ? detect_share_asset_mime($fullPath, $assetPath) : 'application/octet-stream';
    $disposition = $inline ? 'inline' : 'attachment';
    $filename = basename($assetPath);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'download.bin';
    }
    $escapedFilename = str_replace(
        ['\\', '"', "\r", "\n"],
        ['\\\\', '\\"', '', ''],
        $filename
    );
    $size = @filesize($fullPath);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition . '; filename="' . $escapedFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');
    if (is_int($size) && $size >= 0) {
        header('Content-Length: ' . (string)$size);
    }
    @readfile($fullPath);
    exit;
}

function parse_legacy_upload_asset_request(string $path): ?array {
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    if ($normalized === '' || !str_starts_with($normalized, 'uploads/')) {
        return null;
    }
    $relative = substr($normalized, strlen('uploads/'));
    if (!is_string($relative) || $relative === '') {
        return null;
    }
    if (preg_match('#^shares/(\d+)/(.*)$#', $relative, $matches)) {
        $shareId = (int)($matches[1] ?? 0);
        $assetSuffix = rawurldecode((string)($matches[2] ?? ''));
        $assetPath = sanitize_asset_path($assetSuffix);
        if ($shareId > 0 && $assetPath !== '') {
            return [
                'shareId' => $shareId,
                'assetPath' => $assetPath,
            ];
        }
    }
    $commentPrefix = trim(comment_asset_prefix(), '/');
    if ($commentPrefix !== '' && preg_match('#^' . preg_quote($commentPrefix, '#') . '/(\d+)/(.*)$#', $relative, $matches)) {
        $shareId = (int)($matches[1] ?? 0);
        $assetSuffix = rawurldecode((string)($matches[2] ?? ''));
        $assetPath = sanitize_asset_path($commentPrefix . '/' . $shareId . '/' . $assetSuffix);
        if ($shareId > 0 && $assetPath !== '') {
            return [
                'shareId' => $shareId,
                'assetPath' => $assetPath,
            ];
        }
    }
    return null;
}

function handle_share_asset_request(string $slug, string $assetPath): void {
    $share = find_share_by_slug($slug);
    if (!$share) {
        render_share_asset_not_found();
    }
    serve_share_asset($share, $assetPath);
}

function handle_legacy_upload_asset_request(string $path): void {
    $parsed = parse_legacy_upload_asset_request($path);
    if (!$parsed) {
        render_share_asset_not_found();
    }
    $shareId = (int)($parsed['shareId'] ?? 0);
    $assetPath = (string)($parsed['assetPath'] ?? '');
    $share = find_share_by_id($shareId);
    if (!$share) {
        render_share_asset_not_found();
    }
    serve_share_asset($share, $assetPath);
}

function build_doc_tree(array $docs, ?string $activeId = null): array {
    $useParent = false;
    foreach ($docs as $doc) {
        if (isset($doc['parent_id']) && trim((string)$doc['parent_id']) !== '') {
            $useParent = true;
            break;
        }
    }
    if ($useParent) {
        $nodes = [];
        foreach ($docs as $docIndex => $doc) {
            $docId = trim((string)($doc['doc_id'] ?? ''));
            if ($docId === '') {
                continue;
            }
            $nodes[$docId] = [
                'title' => (string)($doc['title'] ?? $docId),
                'children' => [],
                'doc' => $doc,
                'contains_active' => false,
                'order' => is_numeric($doc['sort_index'] ?? null) ? (float)$doc['sort_index'] : $docIndex,
                'order_index' => $docIndex,
            ];
        }
        $tree = [];
        foreach ($nodes as $docId => &$node) {
            $parentId = trim((string)($node['doc']['parent_id'] ?? ''));
            if ($parentId !== '' && isset($nodes[$parentId]) && $parentId !== $docId) {
                $nodes[$parentId]['children'][$docId] =& $node;
            } else {
                $tree[$docId] =& $node;
            }
        }
        unset($node);
        mark_doc_tree_active($tree, $activeId);
        sort_doc_tree($tree);
        return $tree;
    }
    $root = ['children' => []];
    foreach ($docs as $docIndex => $doc) {
        $hpath = trim((string)($doc['hpath'] ?? ''), '/');
        $parts = $hpath !== '' ? array_values(array_filter(explode('/', $hpath))) : [];
        if (empty($parts)) {
            $parts = [trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['doc_id'] ?? ''))];
        }
        $docOrder = isset($doc['sort_order']) ? (int)$doc['sort_order'] : $docIndex;
        $docOrderIndex = $docIndex;
        $node =& $root['children'];
        foreach ($parts as $idx => $part) {
            $isLast = $idx === count($parts) - 1;
            $key = $part;
            if (!isset($node[$key])) {
                $node[$key] = [
                    'title' => $part,
                    'children' => [],
                    'doc' => null,
                    'contains_active' => false,
                    'order' => $docOrder,
                    'order_index' => $docOrderIndex,
                ];
            } else {
                $existingOrder = $node[$key]['order'] ?? $docOrder;
                $existingIndex = $node[$key]['order_index'] ?? $docOrderIndex;
                if ($docOrder < $existingOrder || ($docOrder === $existingOrder && $docOrderIndex < $existingIndex)) {
                    $node[$key]['order'] = $docOrder;
                    $node[$key]['order_index'] = $docOrderIndex;
                }
            }
            if ($isLast) {
                $node[$key]['doc'] = $doc;
                $node[$key]['order'] = min($node[$key]['order'] ?? $docOrder, $docOrder);
                $node[$key]['order_index'] = min($node[$key]['order_index'] ?? $docOrderIndex, $docOrderIndex);
            }
            $node =& $node[$key]['children'];
        }
    }
    $tree = $root['children'];
    mark_doc_tree_active($tree, $activeId);
    sort_doc_tree($tree);
    return $tree;
}

function mark_doc_tree_active(array &$nodes, ?string $activeId = null): bool {
    $hasActive = false;
    foreach ($nodes as &$node) {
        $nodeHas = false;
        $doc = $node['doc'] ?? null;
        if ($activeId && $doc && (string)$doc['doc_id'] === (string)$activeId) {
            $nodeHas = true;
        }
        if (!empty($node['children'])) {
            $childHas = mark_doc_tree_active($node['children'], $activeId);
            $nodeHas = $nodeHas || $childHas;
        }
        $node['contains_active'] = $nodeHas;
        if ($nodeHas) {
            $hasActive = true;
        }
    }
    unset($node);
    return $hasActive;
}

function sort_doc_tree(array &$nodes): void {
    foreach ($nodes as &$node) {
        if (!empty($node['children'])) {
            sort_doc_tree($node['children']);
        }
    }
    unset($node);
    uasort($nodes, function ($a, $b) {
        $orderA = $a['order'] ?? PHP_INT_MAX;
        $orderB = $b['order'] ?? PHP_INT_MAX;
        if ($orderA === $orderB) {
            $idxA = $a['order_index'] ?? PHP_INT_MAX;
            $idxB = $b['order_index'] ?? PHP_INT_MAX;
            if ($idxA === $idxB) {
                return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
            }
            return $idxA <=> $idxB;
        }
        return $orderA <=> $orderB;
    });
}

function decode_hex_emoji_string(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
        return $raw;
    }
    $raw = preg_replace('/^u\+/i', '', $raw);
    $raw = str_replace(' ', '', $raw);
    if (!preg_match('/^(?:0x)?[0-9a-f]{4,6}(?:-(?:0x)?[0-9a-f]{4,6})*$/i', $raw)) {
        return $value;
    }
    $parts = explode('-', strtolower($raw));
    $out = '';
    foreach ($parts as $part) {
        $part = preg_replace('/^0x/', '', $part);
        if ($part === '') {
            continue;
        }
        $out .= html_entity_decode('&#x' . $part . ';', ENT_NOQUOTES, 'UTF-8');
    }
    return $out !== '' ? $out : $value;
}

function normalize_doc_icon_value($value): string {
    if ($value === null) {
        return '';
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) ||
            (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        ) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                return normalize_doc_icon_value($decoded);
            }
        }
        return decode_hex_emoji_string($trimmed);
    }
    if (is_numeric($value)) {
        return decode_hex_emoji_string((string)$value);
    }
    if (is_object($value)) {
        $value = (array)$value;
    }
    if (is_array($value)) {
        $keys = ['icon', 'value', 'emoji', 'iconEmoji', 'iconValue', 'path', 'file', 'asset', 'assetPath', 'src', 'url'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = normalize_doc_icon_value($value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        foreach ($value as $item) {
            $candidate = normalize_doc_icon_value($item);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }
    return '';
}

function is_doc_icon_image_value(string $icon): bool {
    if ($icon === '') {
        return false;
    }
    if (preg_match('/^data:image\//i', $icon)) {
        return true;
    }
    if (preg_match('/^https?:\/\//i', $icon)) {
        return true;
    }
    if (strpos($icon, '/') !== false || preg_match('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', $icon)) {
        return true;
    }
    return false;
}

function build_doc_icon_src(string $icon, string $assetBasePath): string {
    if ($icon === '') {
        return '';
    }
    if (preg_match('/^data:image\//i', $icon) || preg_match('/^https?:\/\//i', $icon)) {
        return $icon;
    }
    $path = sanitize_asset_path($icon);
    if ($path === '') {
        return '';
    }
    $prefix = $assetBasePath;
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
    return $prefix . encode_path_segments($path);
}

function render_doc_tree_icon(?array $doc, string $assetBasePath): string {
    $icon = $doc ? normalize_doc_icon_value($doc['icon'] ?? '') : '';
    if ($icon !== '' && is_doc_icon_image_value($icon)) {
        $src = build_doc_icon_src($icon, $assetBasePath);
        if ($src !== '') {
            return '<img class="kb-tree-icon kb-tree-icon--image" src="' . htmlspecialchars($src) . '" alt="">';
        }
    }
    if ($icon !== '' && !is_doc_icon_image_value($icon)) {
        return '<span class="kb-tree-icon kb-tree-icon--emoji">' . htmlspecialchars($icon) . '</span>';
    }
    return '';
}

function render_share_search_box(string $slug): string {
    $html  = '<div class="kb-search" data-share-search>';
    $html .= '<div class="kb-search-input-wrap">';
    $html .= '<svg class="kb-search-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35"/></svg>';
    $html .= '<input class="kb-search-input" type="search" placeholder="Search..." data-share-search-input autocomplete="off" spellcheck="false">';
    $html .= '<button class="kb-search-clear" type="button" data-share-search-clear hidden aria-label="Clear search">&times;</button>';
    $html .= '</div>';
    $html .= '<div class="kb-search-results" data-share-search-results hidden></div>';
    $html .= '</div>';
    return $html;
}

function render_doc_tree(array $nodes, string $slug, ?string $activeId = null, int $level = 0, string $path = '', string $assetBasePath = ''): string {
    if (empty($nodes)) {
        return '';
    }
    $html = '<ul class="kb-tree kb-tree-children" data-level="' . $level . '">';
    foreach ($nodes as $node) {
        $doc = $node['doc'] ?? null;
        $hasChildren = !empty($node['children']);
        $isActive = $doc && $activeId && (string)$doc['doc_id'] === (string)$activeId;
        $shouldOpen = false;
        $nodeLabel = $doc ? (string)($doc['doc_id'] ?? '') : (string)($node['title'] ?? '');
        $pathKey = trim($path . '/' . $nodeLabel, '/');
        $nodeKey = $doc ? ('doc:' . $nodeLabel) : ('folder:' . $pathKey);
        $nodeClass = 'kb-tree-node';
        if ($hasChildren) {
            $nodeClass .= $shouldOpen ? ' is-open' : ' is-collapsed';
        }
        $html .= '<li class="' . $nodeClass . '" data-tree-key="' . htmlspecialchars($nodeKey) . '">';
        $html .= '<div class="kb-tree-row">';
        if ($hasChildren) {
            $html .= '<button class="kb-tree-toggle" type="button" aria-expanded="' . ($shouldOpen ? 'true' : 'false') . '">';
            $html .= '<svg class="kb-tree-toggle-icon kb-tree-toggle-icon--collapsed" viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-arrow-collapsed"></use></svg>';
            $html .= '<svg class="kb-tree-toggle-icon kb-tree-toggle-icon--open" viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-arrow-expanded"></use></svg>';
            $html .= '</button>';
        } else {
            $html .= '<span class="kb-tree-spacer"></span>';
        }
        if ($doc) {
            $docId = (string)$doc['doc_id'];
            $docTitle = htmlspecialchars($doc['title'] ?? $docId);
            $docPath = base_path() . '/s/' . $slug . '/' . rawurlencode($docId);
            $activeClass = $isActive ? ' is-active' : '';
            $docAttrs = ' href="' . $docPath . '" data-doc-id="' . htmlspecialchars($docId) . '" data-share-nav="doc"';
            $html .= '<a class="kb-tree-item' . $activeClass . '"' . $docAttrs . '>';
            $html .= render_doc_tree_icon($doc, $assetBasePath);
            $html .= '<span class="kb-tree-label">' . $docTitle . '</span></a>';
        } else {
            $html .= '<div class="kb-tree-folder">';
            $html .= render_doc_tree_icon(null, $assetBasePath);
            $html .= '<span class="kb-tree-label">' . htmlspecialchars((string)$node['title']) . '</span></div>';
        }
        $html .= '</div>';
        if ($hasChildren) {
            $html .= render_doc_tree($node['children'], $slug, $activeId, $level + 1, $pathKey, $assetBasePath);
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function build_breadcrumbs(string $hpath): array {
    $trimmed = trim($hpath, '/');
    if ($trimmed === '') {
        return [];
    }
    return array_values(array_filter(explode('/', $trimmed)));
}

function is_share_partial_request(): bool {
    if (!empty($_GET['partial']) && (string)$_GET['partial'] === '1') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_SPS_PARTIAL']) && (string)$_SERVER['HTTP_X_SPS_PARTIAL'] === '1') {
        return true;
    }
    return false;
}

function route_share(string $slug, ?string $docId = null): void {
    $share = find_share_by_slug($slug);
    if (!$share) {
        render_share_not_found_page();
        return;
    }
    $shareId = (int)$share['id'];
    $viewer = current_user();
    $shareTitleRaw = (string)$share['title'];
    $shareTitle = htmlspecialchars($shareTitleRaw);
    $redirectPath = '/s/' . $slug . ($docId ? '/' . rawurlencode($docId) : '');
    $resumeAssetPath = resolve_asset_resume_redirect_path($share);
    $isPartial = is_share_partial_request();

    if (share_is_expired($share)) {
        $content = '<div class="share-shell share-shell--single">';
        $content .= '<div class="share-content">';
        $content .= '<div class="share-header"><h1>' . $shareTitle . '</h1></div>';
        $content .= '<div class="share-empty">This share has expired, content is not visible.</div>';
        $content .= '</div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
        return;
    }

    if (share_visitor_limit_reached($share)) {
        $content = '<div class="share-shell share-shell--single">';
        $content .= '<div class="share-content">';
        $content .= '<div class="share-header"><h1>' . $shareTitle . '</h1></div>';
        $content .= '<div class="share-empty">Visitor limit reached, share is closed.</div>';
        $content .= '</div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
        return;
    }

    if (share_requires_password($share) && !share_access_granted($shareId)) {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = trim((string)($_POST['share_password'] ?? ''));
            if ($input !== '' && password_verify($input, (string)$share['password_hash'])) {
                grant_share_access($shareId);
                if ($resumeAssetPath !== '') {
                    redirect($resumeAssetPath);
                }
                redirect($redirectPath);
            }
            $error = 'Incorrect access password';
        }
        $content = '<div class="share-shell share-shell--single">';
        $content .= '<div class="share-content">';
        $content .= '<div class="share-header"><h1>' . $shareTitle . '</h1></div>';
        $content .= '<div class="share-gate">';
        $content .= '<div class="share-gate-note">This share is password protected</div>';
        if ($error) {
            $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
        }
        $content .= '<form method="post" class="share-gate-form">';
        $content .= '<input class="input" type="password" name="share_password" placeholder="Enter access password" required>';
        $content .= '<button class="button primary" type="submit">Verify</button>';
        $content .= '</form></div></div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
        return;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, doc_id, title, icon, hpath, parent_id, sort_index, sort_order, updated_at FROM share_docs WHERE share_id = :sid ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':sid' => $shareId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $docMarkdownStmt = $pdo->prepare('SELECT markdown FROM share_docs WHERE share_id = :sid AND id = :id LIMIT 1');
    $loadDocMarkdown = function (array $doc) use ($docMarkdownStmt, $shareId): string {
        $rowId = (int)($doc['id'] ?? 0);
        if ($rowId <= 0) {
            return '';
        }
        $docMarkdownStmt->execute([
            ':sid' => $shareId,
            ':id' => $rowId,
        ]);
        $markdown = $docMarkdownStmt->fetchColumn();
        return is_string($markdown) ? $markdown : '';
    };
    if (empty($docs)) {
        render_share_not_found_page();
        return;
    }
    $accessCountRecorded = false;
    $touchShareAccess = function () use (&$accessCountRecorded, &$share, $shareId, $pdo): void {
        if ($accessCountRecorded) {
            return;
        }
        if (share_visitor_limit($share) > 0) {
            register_share_visitor($shareId, get_visitor_id());
        }
        $pdo->prepare('UPDATE shares SET access_count = access_count + 1 WHERE id = :id')
            ->execute([':id' => $shareId]);
        $share['access_count'] = (int)($share['access_count'] ?? 0) + 1;
        $accessCountRecorded = true;
    };

    $assetBasePath = base_path() . '/uploads/shares/' . $shareId . '/';

    if ($share['type'] === 'doc') {
        $hasMultipleDocs = count($docs) > 1;
        $activeDocId = $docId ?: (string)($share['doc_id'] ?? ($docs[0]['doc_id'] ?? ''));
        if ($hasMultipleDocs) {
            $doc = null;
            foreach ($docs as $item) {
                if ((string)($item['doc_id'] ?? '') === (string)$activeDocId) {
                    $doc = $item;
                    break;
                }
            }
            if (!$doc && !$docId && !empty($docs)) {
                $doc = $docs[0];
                $activeDocId = (string)($doc['doc_id'] ?? '');
            }
            if (!$doc) {
                render_share_not_found_page();
                return;
            }
            $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $shareTitleRaw;
            $docTitle = htmlspecialchars($docTitleRaw);
            $front = extract_front_matter($loadDocMarkdown($doc));
            $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
            $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
            $markdown = replace_custom_emoji_tokens($markdown, (int)$shareId, $assetBasePath);
            $markdown = insert_adjacent_emoji_image_spacing($markdown);
            $touchShareAccess();
            $reportTrigger = render_share_report_trigger($share);
            $shareMetaHtml = render_share_stats($share, $reportTrigger);
            record_share_access($share, (string)($doc['doc_id'] ?? ''), $docTitleRaw);
            $commentHtml = render_share_comments($share, $viewer, (string)$activeDocId);
            $reportModalHtml = render_share_report_form($share, $viewer, (string)$activeDocId);
            $treeHtml = render_doc_tree(build_doc_tree($docs, $activeDocId), $slug, $activeDocId, 0, '', $assetBasePath);
            $sidebar = '<aside class="kb-sidebar" data-share-sidebar data-share-slug="' . htmlspecialchars($slug) . '">';
            $sidebar .= render_share_search_box($slug);
            $sidebar .= '<div class="kb-side-tabs" data-share-tabs data-share-default="tree">';
            $sidebar .= '<button class="kb-side-tab is-active" type="button" data-share-tab="tree">Document Tree</button>';
            $sidebar .= '<button class="kb-side-tab" type="button" data-share-tab="toc">Directory</button>';
            $sidebar .= '<div class="kb-side-actions" data-share-tree-actions>';
            $sidebar .= '<button class="kb-side-action" type="button" data-tree-collapse title="Collapse all" aria-label="Collapse all"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-collapse-all"></use></svg></button>';
            $sidebar .= '<button class="kb-side-action" type="button" data-tree-expand title="Expand all" aria-label="Expand all"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-expand-all"></use></svg></button>';
            $sidebar .= '</div>';
            $sidebar .= '</div>';
            $sidebar .= '<div class="kb-side-panel" data-share-panel="tree">';
            $sidebar .= '<div class="kb-side-body">' . $treeHtml . '</div>';
            $sidebar .= '</div>';
            $sidebar .= '<div class="kb-side-panel" data-share-panel="toc" data-share-toc="doc" hidden>';
            $sidebar .= '<div class="kb-side-body share-toc-body"></div>';
            $sidebar .= '</div>';
            $sidebar .= '</aside>';
            $base = base_path();
            $crumbs = build_breadcrumbs((string)($doc['hpath'] ?? ''));
            if (!empty($crumbs)) {
                array_pop($crumbs);
            }
            if ($shareTitleRaw !== '' && !empty($crumbs)) {
                while (!empty($crumbs) && $crumbs[0] === $shareTitleRaw) {
                    array_shift($crumbs);
                }
            }
            $filteredCrumbs = [];
            $prevCrumb = null;
            foreach ($crumbs as $crumb) {
                if ($crumb === $prevCrumb) {
                    continue;
                }
                $filteredCrumbs[] = $crumb;
                $prevCrumb = $crumb;
            }
            $crumbs = $filteredCrumbs;
            $breadcrumbsHtml = '<div class="kb-breadcrumbs"><a class="kb-back" href="' . $base . '/s/' . $slug . '" data-doc-id="" data-share-nav="doc">' . htmlspecialchars($shareTitleRaw) . '</a>';
            foreach ($crumbs as $crumb) {
                $breadcrumbsHtml .= '<span>' . htmlspecialchars($crumb) . '</span>';
            }
            $breadcrumbsHtml .= '</div>';
            $mainHtml = '<div class="kb-main">';
            $mainHtml .= '<div class="share-article" data-share-view="preview">';
            $mainHtml .= '<div class="kb-header">' . $breadcrumbsHtml;
            $mainHtml .= '<div class="kb-title-row">';
            $mainHtml .= '<h1 class="kb-title">' . $docTitle . '</h1>';
            $mainHtml .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">Source</button>';
            $mainHtml .= '</div>';
            $mainHtml .= $shareMetaHtml;
            $mainHtml .= '</div>';
            $mainHtml .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($markdown) . '</div>';
            $mainHtml .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown Source">' . htmlspecialchars($markdown) . '</textarea>';
            $mainHtml .= $commentHtml;
            $mainHtml .= $reportModalHtml;
            $mainHtml .= '</div></div>';
            if ($isPartial) {
                api_response(200, [
                    'title' => $docTitleRaw,
                    'docId' => $activeDocId,
                    'html' => $mainHtml,
                ]);
            }
            $content = '<div class="share-shell share-shell--notebook" data-share-doc-id="' . htmlspecialchars($activeDocId) . '">';
            $content .= $sidebar;
            $content .= $mainHtml;
            $content .= '</div>';
            render_page($docTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true]);
            return;
        }

        $doc = $docs[0];
        $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $shareTitleRaw;
        $docTitle = htmlspecialchars($docTitleRaw);
        $front = extract_front_matter($loadDocMarkdown($doc));
        $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
        $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
        $markdown = replace_custom_emoji_tokens($markdown, (int)$shareId, $assetBasePath);
        $markdown = insert_adjacent_emoji_image_spacing($markdown);
        $touchShareAccess();
        $reportTrigger = render_share_report_trigger($share);
        $shareMetaHtml = render_share_stats($share, $reportTrigger);
        record_share_access($share, (string)($doc['doc_id'] ?? ''), $docTitleRaw);
        $commentHtml = render_share_comments($share, $viewer, null);
        $reportModalHtml = render_share_report_form($share, $viewer, null);
        $sidebar = '<aside class="kb-sidebar" data-share-sidebar data-share-slug="' . htmlspecialchars($slug) . '">';
        $sidebar .= render_share_search_box($slug);
        $sidebar .= '<div class="kb-side-tabs" data-share-tabs data-share-default="toc">';
        $sidebar .= '<button class="kb-side-tab is-active" type="button" data-share-tab="toc">Directory</button>';
        $sidebar .= '</div>';
        $sidebar .= '<div class="kb-side-panel" data-share-panel="toc" data-share-toc="doc">';
        $sidebar .= '<div class="kb-side-body share-toc-body"></div>';
        $sidebar .= '</div>';
        $sidebar .= '</aside>';
        $content = '<div class="share-shell share-shell--notebook">';
        $content .= $sidebar;
        $content .= '<div class="kb-main">';
        $content .= '<div class="share-article" data-share-view="preview">';
        $content .= '<div class="kb-header">';
        $content .= '<div class="kb-title-row">';
        $content .= '<h1 class="kb-title">' . $docTitle . '</h1>';
        $content .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">Source</button>';
        $content .= '</div>';
        $content .= $shareMetaHtml;
        $content .= '</div>';
        $content .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($markdown) . '</div>';
        $content .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown Source">' . htmlspecialchars($markdown) . '</textarea>';
        $content .= $commentHtml;
        $content .= $reportModalHtml;
        $content .= '</div></div></div>';
        render_page($docTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true]);
    }

    if ($share['type'] === 'notebook') {
        $treeHtml = render_doc_tree(build_doc_tree($docs, $docId), $slug, $docId, 0, '', $assetBasePath);
        $sidebar = '<aside class="kb-sidebar" data-share-sidebar data-share-slug="' . htmlspecialchars($slug) . '">';
        $sidebar .= render_share_search_box($slug);
        $sidebar .= '<div class="kb-side-tabs" data-share-tabs data-share-default="tree">';
        $sidebar .= '<button class="kb-side-tab is-active" type="button" data-share-tab="tree">Document Tree</button>';
        $sidebar .= '<button class="kb-side-tab" type="button" data-share-tab="toc">Directory</button>';
        $sidebar .= '<div class="kb-side-actions" data-share-tree-actions>';
        $sidebar .= '<button class="kb-side-action" type="button" data-tree-collapse title="Collapse all" aria-label="Collapse all"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-collapse-all"></use></svg></button>';
        $sidebar .= '<button class="kb-side-action" type="button" data-tree-expand title="Expand all" aria-label="Expand all"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-expand-all"></use></svg></button>';
        $sidebar .= '</div>';
        $sidebar .= '</div>';
        $sidebar .= '<div class="kb-side-panel" data-share-panel="tree">';
        $sidebar .= '<div class="kb-side-body">' . $treeHtml . '</div>';
        $sidebar .= '</div>';
        $sidebar .= '<div class="kb-side-panel" data-share-panel="toc" data-share-toc="doc" hidden>';
        $sidebar .= '<div class="kb-side-body share-toc-body"></div>';
        $sidebar .= '</div>';
        $sidebar .= '</aside>';
        $base = base_path();

        if ($docId) {
            $doc = null;
            foreach ($docs as $item) {
                if ((string)$item['doc_id'] === (string)$docId) {
                    $doc = $item;
                    break;
                }
            }
            if (!$doc) {
                render_share_not_found_page();
                return;
            }
            $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $shareTitleRaw;
            $docTitle = htmlspecialchars($docTitleRaw);
            $front = extract_front_matter($loadDocMarkdown($doc));
            $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
            $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
            $markdown = replace_custom_emoji_tokens($markdown, (int)$shareId, $assetBasePath);
            $markdown = insert_adjacent_emoji_image_spacing($markdown);
            $touchShareAccess();
            $reportTrigger = render_share_report_trigger($share);
            $shareMetaHtml = render_share_stats($share, $reportTrigger);
            record_share_access($share, (string)($doc['doc_id'] ?? ''), $docTitleRaw);
            $commentHtml = render_share_comments($share, $viewer, (string)$docId);
            $reportModalHtml = render_share_report_form($share, $viewer, (string)$docId);
            $crumbs = build_breadcrumbs((string)($doc['hpath'] ?? ''));
            if (!empty($crumbs)) {
                array_pop($crumbs);
            }
            $breadcrumbsHtml = '<div class="kb-breadcrumbs"><a class="kb-back" href="' . $base . '/s/' . $slug . '" data-doc-id="" data-share-nav="doc">Directory</a>';
            foreach ($crumbs as $crumb) {
                $breadcrumbsHtml .= '<span>' . htmlspecialchars($crumb) . '</span>';
            }
            $breadcrumbsHtml .= '</div>';
            $mainHtml = '<div class="kb-main">';
            $mainHtml .= '<div class="share-article" data-share-view="preview">';
            $mainHtml .= '<div class="kb-header">' . $breadcrumbsHtml;
            $mainHtml .= '<div class="kb-title-row">';
            $mainHtml .= '<h1 class="kb-title">' . $docTitle . '</h1>';
            $mainHtml .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">Source</button>';
            $mainHtml .= '</div>';
            $mainHtml .= $shareMetaHtml;
            $mainHtml .= '</div>';
            $mainHtml .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($markdown) . '</div>';
            $mainHtml .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown Source">' . htmlspecialchars($markdown) . '</textarea>';
            $mainHtml .= $commentHtml;
            $mainHtml .= $reportModalHtml;
            $mainHtml .= '</div></div>';
            if ($isPartial) {
                api_response(200, [
                    'title' => $docTitleRaw,
                    'docId' => $docId,
                    'html' => $mainHtml,
                ]);
            }
            $content = '<div class="share-shell share-shell--notebook" data-share-doc-id="' . htmlspecialchars((string)$docId) . '">';
            $content .= $sidebar;
            $content .= $mainHtml;
            $content .= '</div>';
            render_page($docTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true]);
        }

        if (!$docId) {
            $touchShareAccess();
            $mainHtml = '<div class="kb-main"><div class="share-empty">Please open a document from the Document Tree first</div></div>';
            if ($isPartial) {
                api_response(200, [
                    'title' => $shareTitleRaw,
                    'docId' => '',
                    'html' => $mainHtml,
                ]);
            }
            $content = '<div class="share-shell share-shell--notebook" data-share-doc-id="">';
            $content .= $sidebar;
            $content .= $mainHtml;
            $content .= '</div>';
            record_share_access($share, null, $shareTitleRaw);
            render_page($shareTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true]);
        }

        $rows = '';
        foreach ($docs as $doc) {
            $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: (string)$doc['doc_id'];
            $docTitle = htmlspecialchars($docTitleRaw);
            $docPath = $base . '/s/' . $slug . '/' . rawurlencode((string)$doc['doc_id']);
            $hPath = trim((string)($doc['hpath'] ?? ''), '/');
            $pathLabel = $hPath !== '' ? $hPath : '/';
            $front = extract_front_matter($loadDocMarkdown($doc));
            $meta = (array)$front['meta'];
            $updatedRaw = $meta['lastmod'] ?? $meta['updated'] ?? $meta['modified'] ?? '';
            $updated = $updatedRaw ? format_meta_date((string)$updatedRaw) : '';
            $rows .= '<a class="kb-dir-row" href="' . $docPath . '" data-doc-id="' . htmlspecialchars((string)$doc['doc_id']) . '" data-share-nav="doc">';
            $rows .= '<div class="kb-dir-title">' . $docTitle . '</div>';
            $rows .= '<div class="kb-dir-path">' . htmlspecialchars($pathLabel) . '</div>';
            $rows .= '<div class="kb-dir-time">' . htmlspecialchars($updated) . '</div>';
            $rows .= '</a>';
        }
        if ($rows === '') {
            $rows = '<div class="share-empty">No documents.</div>';
        } else {
            $rows = '<div class="kb-directory"><div class="kb-dir-head"><div>Title</div><div>Path</div><div>Updated</div></div>' . $rows . '</div>';
        }
        $reportTrigger = render_share_report_trigger($share);
        $reportModalHtml = render_share_report_form($share, $viewer, null);
        $content = '<div class="share-shell share-shell--notebook">';
        $content .= $sidebar;
        $content .= '<div class="kb-main">';
        $content .= '<div class="kb-header">';
        $content .= '<div class="kb-breadcrumbs"><span>Directory</span></div>';
        $content .= '<div class="kb-title-row">';
        $content .= '<h1 class="kb-title">' . $shareTitle . '</h1>';
        $content .= '</div>';
        $content .= '<div class="kb-meta"><span class="kb-chip"><strong>Document</strong> ' . count($docs) . '</span></div>';
        $content .= render_share_stats($share, $reportTrigger);
        $content .= '</div>';
        $content .= $rows;
        $content .= $reportModalHtml;
        $content .= '</div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
    }

    render_share_not_found_page();
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
    if ($path === '') {
        $path = '/';
    }
}

if ($path === '/api/instances/heartbeat') {
    handle_instance_heartbeat();
}

if ($path === '/api/instances/stats') {
    handle_instance_stats();
}

if (strpos($path, '/api/v1/') === 0) {
    handle_api($path);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/res/(.+)$#', $path, $matches)) {
    handle_share_asset_request($matches[1], $matches[2]);
}

if (str_starts_with($path, '/uploads/')) {
    handle_legacy_upload_asset_request($path);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_submit($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment/upload$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_upload($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment/edit$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_edit($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment/delete$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_delete($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/report$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_report_submit($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/search$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_share_search($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)(?:/([^/]+))?$#', $path, $matches)) {
    route_share($matches[1], $matches[2] ?? null);
}

if ($path === '/captcha') {
    render_captcha_image();
}

if ($path === '/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    session_destroy();
    redirect('/');
}

if ($path === '/email-code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!allow_registration()) {
        flash('error', 'Registration is currently not open');
        redirect('/register');
    }
    if (!email_verification_available()) {
        redirect('/register');
    }
    $email = trim((string)($_POST['email'] ?? ($_SESSION['register_email'] ?? '')));
    $_SESSION['register_email'] = $email;
    if (($_SESSION['register_step'] ?? '') !== 'verify') {
        flash('error', 'Please complete registration information first');
        redirect('/register');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email format');
        redirect('/register?step=verify');
    }
    $lastSent = (int)($_SESSION['register_email_code_at'] ?? 0);
    if ($lastSent && (time() - $lastSent) < 60) {
        flash('error', 'Please wait before sending another code');
        redirect('/register?step=verify');
    }
    $code = create_email_code($email, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!send_email_code($email, $code)) {
        flash('error', 'Failed to send code, please check email configuration');
        redirect('/register?step=verify');
    }
    $_SESSION['register_email_code_at'] = time();
    flash('info', 'Code sent, please check your email');
    redirect('/register?step=verify');
}

if ($path === '/login/email/prepare' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!email_verification_available()) {
        redirect('/login');
    }
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $captchaInput = (string)($_POST['captcha'] ?? '');
    $_SESSION['login_email'] = $email;
    $_SESSION['login_tab'] = 'email';
    if (captcha_enabled() && !check_captcha($captchaInput)) {
        flash('error', 'Incorrect captcha');
        redirect('/login?tab=email');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email format');
        redirect('/login?tab=email');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT disabled FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        flash('error', 'This email is not registered');
        redirect('/login?tab=email');
    }
    if ((int)$userRow['disabled'] === 1) {
        flash('error', 'Account has been disabled');
        redirect('/login?tab=email');
    }
    $_SESSION['login_email_step'] = 'verify';
    redirect('/login?tab=email&step=verify');
}

if ($path === '/login/email-code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!email_verification_available()) {
        redirect('/login');
    }
    check_csrf();
    if (($_SESSION['login_email_step'] ?? '') !== 'verify') {
        flash('error', 'Please enter your email first');
        redirect('/login?tab=email');
    }
    $email = trim((string)($_POST['email'] ?? ($_SESSION['login_email'] ?? '')));
    $_SESSION['login_email'] = $email;
    $_SESSION['login_tab'] = 'email';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email format');
        redirect('/login?tab=email&step=verify');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if (!$stmt->fetchColumn()) {
        flash('error', 'This email is not registered');
        redirect('/login?tab=email&step=verify');
    }
    $lastSent = (int)($_SESSION['login_email_code_at'] ?? 0);
    if ($lastSent && (time() - $lastSent) < 60) {
        flash('error', 'Please wait before sending another code');
        redirect('/login?tab=email&step=verify');
    }
    $code = create_email_code($email, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!send_email_code($email, $code)) {
        flash('error', 'Failed to send code, please check email configuration');
        redirect('/login?tab=email&step=verify');
    }
    $_SESSION['login_email_code_at'] = time();
    flash('info', 'Code sent, please check your email');
    redirect('/login?tab=email&step=verify');
}

if ($path === '/login/email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!email_verification_available()) {
        redirect('/login');
    }
    check_csrf();
    if (($_SESSION['login_email_step'] ?? '') !== 'verify') {
        flash('error', 'Please enter your email first');
        redirect('/login?tab=email');
    }
    $email = trim((string)($_POST['email'] ?? ($_SESSION['login_email'] ?? '')));
    $code = trim((string)($_POST['email_code'] ?? ''));
    $_SESSION['login_email'] = $email;
    $_SESSION['login_tab'] = 'email';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email format');
        redirect('/login?tab=email&step=verify');
    }
    if ($code === '' || !verify_email_code($email, $code)) {
        flash('error', 'Incorrect email verification code');
        redirect('/login?tab=email&step=verify');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        flash('error', 'This email is not registered');
        redirect('/login?tab=email&step=verify');
    }
    if ((int)$user['disabled'] === 1) {
        flash('error', 'Account has been disabled');
        redirect('/login?tab=email&step=verify');
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['password_hash'] = $user['password_hash'];
    $_SESSION['login_email_step'] = 'start';
    unset($_SESSION['login_email']);
    if ((int)$user['email_verified'] !== 1) {
        $update = $pdo->prepare('UPDATE users SET email_verified = 1, updated_at = :updated_at WHERE id = :id');
        $update->execute([':updated_at' => now(), ':id' => $user['id']]);
    }
    if ((int)$user['must_change_password'] === 1) {
        flash('info', 'Default password detected, please change your password');
        redirect('/account');
    }
    redirect('/dashboard');
}

if ($path === '/login') {
    global $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $captchaInput = (string)($_POST['captcha'] ?? '');
        $_SESSION['login_username'] = $username;
        $_SESSION['login_tab'] = 'password';
        if (captcha_enabled() && !check_captcha($captchaInput)) {
            flash('error', 'Incorrect captcha');
            redirect('/login');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            flash('error', 'Incorrect username or password');
            redirect('/login');
        }
        if ((int)$user['disabled'] === 1) {
            flash('error', 'Account has been disabled');
            redirect('/login');
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['password_hash'] = $user['password_hash'];
        $_SESSION['login_email_step'] = 'start';
        unset($_SESSION['login_username']);
        if ((int)$user['must_change_password'] === 1) {
            flash('info', 'Default password detected, please change your password');
            redirect('/account');
        }
        redirect('/dashboard');
    }
    $error = flash('error');
    $info = flash('info');
    $brand = htmlspecialchars($config['app_name']);
    $iconUser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>';
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconLock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 8v-2a1 1 0 0 1 2 0v2zm3-8H10V7a2 2 0 0 1 4 0z"/></svg>';
    $iconShield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.6 9.2 8 11 4.4-1.8 8-6 8-11V5z"/></svg>';
    $tabQuery = (string)($_GET['tab'] ?? '');
    if ($tabQuery !== '') {
        $_SESSION['login_tab'] = $tabQuery;
    }
    $stepQuery = (string)($_GET['step'] ?? '');
    if ($stepQuery === 'verify') {
        $_SESSION['login_email_step'] = 'verify';
    } elseif ($stepQuery === 'prepare') {
        $_SESSION['login_email_step'] = 'start';
    }
    $prefillLoginEmail = $_SESSION['login_email'] ?? '';
    $prefillLoginUser = $_SESSION['login_username'] ?? '';
    $loginEmailStep = $_SESSION['login_email_step'] ?? 'start';
    if (!email_verification_available()) {
        $loginEmailStep = 'start';
    }
    if ($loginEmailStep === 'verify' && $prefillLoginEmail === '') {
        $loginEmailStep = 'start';
    }
    // Keep session step aligned with the derived view step to avoid stale bypass paths.
    $_SESSION['login_email_step'] = $loginEmailStep;
    $loginTab = email_verification_available()
        ? (($_SESSION['login_tab'] ?? '') ?: ($loginEmailStep === 'verify' ? 'email' : 'password'))
        : 'password';
    if (!in_array($loginTab, ['password', 'email'], true)) {
        $loginTab = 'password';
    }
    $lastLoginSent = (int)($_SESSION['login_email_code_at'] ?? 0);
    $nextLoginCodeAt = ($lastLoginSent && (time() - $lastLoginSent) < 60) ? ($lastLoginSent + 60) : 0;
    $nextLoginAttr = $nextLoginCodeAt ? ' data-countdown-until="' . ($nextLoginCodeAt * 1000) . '"' : '';

    $content = '<div class="auth-card">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">Login</div>';
    $content .= '<div class="auth-subtitle">Welcome back, please log in to continue</div>';
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    if (email_verification_available()) {
        $content .= '<div class="auth-tabs" data-login-tabs data-login-default="' . $loginTab . '">';
        $content .= '<button class="auth-tab" type="button" data-login-tab="password">Password Login</button>';
        $content .= '<button class="auth-tab" type="button" data-login-tab="email">CaptchaLogin</button>';
        $content .= '</div>';
    }
    $content .= '<form method="post" class="auth-form" data-login-panel="password"' . ($loginTab === 'password' ? '' : ' hidden') . '>';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconUser . '</span><input class="auth-input" name="username" placeholder="Username" value="' . htmlspecialchars((string)$prefillLoginUser) . '" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="password" placeholder="Password" required></div>';
    if (captcha_enabled()) {
        $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="Captcha" required>';
        $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha></div>';
    }
    $content .= '<div class="auth-actions"><a class="link" href="' . base_path() . '/forgot">Forgot Password</a></div>';
    $content .= '<button class="button primary w-full" type="submit">Login</button>';
    $content .= '</form>';
    if (email_verification_available()) {
        if ($loginEmailStep === 'verify') {
            $content .= '<form method="post" class="auth-form" action="' . base_path() . '/login/email" data-login-panel="email"' . ($loginTab === 'email' ? '' : ' hidden') . '>';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="Email" value="' . htmlspecialchars((string)$prefillLoginEmail) . '" readonly></div>';
            $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email_code" placeholder="Email Verification Code" required></div>';
            $content .= '<div class="auth-actions">';
            $content .= '<button class="button ghost" type="submit" formaction="' . base_path() . '/login/email-code" formnovalidate' . $nextLoginAttr . '>Send Email Verification Code</button>';
            $content .= '<a class="link" href="' . base_path() . '/login?tab=email&step=prepare">Change Email</a>';
            $content .= '</div>';
            $content .= '<button class="button primary w-full" type="submit">Login</button>';
            $content .= '</form>';
        } else {
            $content .= '<form method="post" class="auth-form" action="' . base_path() . '/login/email/prepare" data-login-panel="email"' . ($loginTab === 'email' ? '' : ' hidden') . '>';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="Email" value="' . htmlspecialchars((string)$prefillLoginEmail) . '" required></div>';
            if (captcha_enabled()) {
                $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="Captcha" required>';
                $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha></div>';
            }
            $content .= '<button class="button primary w-full" type="submit">Next</button>';
            $content .= '</form>';
        }
    }
    $content .= '<div class="auth-footer">Don\'t have an account? <a class="link" href="' . base_path() . '/register">Register now</a></div>';
    $content .= '</div>';
    render_page('Login', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/register') {
    global $config;
    if (!allow_registration()) {
        render_page('Register', '<div class="auth-card"><div class="auth-title">Registration is not open yet</div></div>', null, '', ['layout' => 'auth']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $step = trim((string)($_POST['step'] ?? 'info'));
        $username = trim((string)($_POST['username'] ?? ($_SESSION['register_username'] ?? '')));
        $email = trim((string)($_POST['email'] ?? ($_SESSION['register_email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $captchaInput = (string)($_POST['captcha'] ?? '');
        $_SESSION['register_username'] = $username;
        $_SESSION['register_email'] = $email;
        if ($step === 'info' && captcha_enabled() && !check_captcha($captchaInput)) {
            flash('error', 'Incorrect captcha');
            redirect('/register');
        }
        if ($step === 'info') {
            if ($username === '' || $password === '') {
                flash('error', 'Username and password cannot be empty');
                redirect('/register');
            }
            if (strlen($password) < 6) {
                flash('error', 'Password must be at least 6 characters');
                redirect('/register');
            }
            if (email_verification_available()) {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('error', 'Invalid email format');
                    redirect('/register');
                }
                $_SESSION['register_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $_SESSION['register_step'] = 'verify';
                redirect('/register?step=verify');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Invalid email format');
                redirect('/register');
            }
            $emailVerified = $email !== '' ? 1 : 0;
            $pdo = db();
            if ($email !== '') {
                $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $checkEmail->execute([':email' => $email]);
                if ($checkEmail->fetch()) {
                    flash('error', 'This email is already registered');
                    redirect('/register');
                }
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
                VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
            try {
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password_hash' => $passwordHash,
                    ':role' => 'user',
                    ':api_key_hash' => null,
                    ':api_key_prefix' => null,
                    ':api_key_last4' => null,
                    ':disabled' => 0,
                    ':storage_limit_bytes' => 0,
                    ':storage_used_bytes' => 0,
                    ':must_change_password' => 0,
                    ':email_verified' => $emailVerified,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
            } catch (PDOException $e) {
                flash('error', 'Username already exists');
                redirect('/register');
            }
            unset($_SESSION['register_step'], $_SESSION['register_password_hash'], $_SESSION['register_email_code_at'], $_SESSION['register_username'], $_SESSION['register_email']);
            $_SESSION['user_id'] = (int)$pdo->lastInsertId();
            $_SESSION['password_hash'] = $passwordHash;
            redirect('/dashboard');
        }

        if (!email_verification_available()) {
            redirect('/register');
        }
        $passwordHash = (string)($_SESSION['register_password_hash'] ?? '');
        if ($passwordHash === '') {
            $_SESSION['register_step'] = 'info';
            flash('error', 'Please fill in registration info first');
            redirect('/register');
        }
        $emailCode = trim((string)($_POST['email_code'] ?? ''));
        if ($username === '') {
            flash('error', 'Username cannot be empty');
            redirect('/register?step=verify');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid email format');
            redirect('/register?step=verify');
        }
        if ($emailCode === '' || !verify_email_code($email, $emailCode)) {
            flash('error', 'Incorrect email verification code');
            redirect('/register?step=verify');
        }
        $pdo = db();
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $email]);
        if ($checkEmail->fetch()) {
            flash('error', 'This email is already registered');
            redirect('/register?step=verify');
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
            VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
        try {
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':role' => 'user',
                ':api_key_hash' => null,
                ':api_key_prefix' => null,
                ':api_key_last4' => null,
                ':disabled' => 0,
                ':storage_limit_bytes' => 0,
                ':storage_used_bytes' => 0,
                ':must_change_password' => 0,
                ':email_verified' => 1,
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        } catch (PDOException $e) {
            flash('error', 'Username already exists');
            redirect('/register?step=verify');
        }
        unset(
            $_SESSION['register_step'],
            $_SESSION['register_password_hash'],
            $_SESSION['register_email_code_at'],
            $_SESSION['register_username'],
            $_SESSION['register_email']
        );
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        $_SESSION['password_hash'] = $passwordHash;
        redirect('/dashboard');
    }
    $error = flash('error');
    $info = flash('info');
    $stepQuery = (string)($_GET['step'] ?? '');
    if ($stepQuery === 'verify') {
        $_SESSION['register_step'] = 'verify';
    } elseif ($stepQuery === 'info') {
        $_SESSION['register_step'] = 'info';
    }
    $registerStep = $_SESSION['register_step'] ?? 'info';
    if (!email_verification_available()) {
        $registerStep = 'info';
    }
    if ($registerStep === 'verify' && empty($_SESSION['register_password_hash'])) {
        $registerStep = 'info';
    }
    $_SESSION['register_step'] = $registerStep;
    $prefillName = $_SESSION['register_username'] ?? '';
    $prefillEmail = $_SESSION['register_email'] ?? '';
    $brand = htmlspecialchars($config['app_name']);
    $iconUser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>';
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconLock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 8v-2a1 1 0 0 1 2 0v2zm3-8H10V7a2 2 0 0 1 4 0z"/></svg>';
    $iconShield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.6 9.2 8 11 4.4-1.8 8-6 8-11V5z"/></svg>';
    $lastSent = (int)($_SESSION['register_email_code_at'] ?? 0);
    $nextCodeAt = ($lastSent && (time() - $lastSent) < 60) ? ($lastSent + 60) : 0;
    $nextCodeAttr = $nextCodeAt ? ' data-countdown-until="' . ($nextCodeAt * 1000) . '"' : '';
    $content = '<div class="auth-card">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">Register</div>';
    $content .= '<div class="auth-subtitle">Fill in info to create account</div>';
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    if (email_verification_available()) {
        $content .= '<div class="auth-steps">';
        $content .= '<div class="auth-step' . ($registerStep === 'info' ? ' is-active' : '') . '"><span>1</span>Fill in Info</div>';
        $content .= '<div class="auth-step' . ($registerStep === 'verify' ? ' is-active' : '') . '"><span>2</span>Email Verification</div>';
        $content .= '</div>';
    }
    if ($registerStep === 'verify' && email_verification_available()) {
        $content .= '<form method="post" class="auth-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<input type="hidden" name="step" value="verify">';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconUser . '</span><input class="auth-input" name="username" placeholder="Username" value="' . htmlspecialchars((string)$prefillName) . '" readonly></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="Email" value="' . htmlspecialchars((string)$prefillEmail) . '" readonly></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email_code" placeholder="Email Verification Code" required></div>';
        $content .= '<div class="auth-actions">';
        $content .= '<button class="button ghost" type="submit" formaction="' . base_path() . '/email-code" formnovalidate' . $nextCodeAttr . '>Send Email Verification Code</button>';
        $content .= '<a class="link" href="' . base_path() . '/register?step=info">Edit Info</a>';
        $content .= '</div>';
        $content .= '<button class="button primary w-full" type="submit">Register</button>';
        $content .= '<div class="auth-footer">Already have an account? <a class="link" href="' . base_path() . '/login">Login</a></div>';
        $content .= '</form>';
    } else {
        $content .= '<form method="post" class="auth-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<input type="hidden" name="step" value="info">';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconUser . '</span><input class="auth-input" name="username" placeholder="Username" value="' . htmlspecialchars((string)$prefillName) . '" required></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="Email" value="' . htmlspecialchars((string)$prefillEmail) . '"' . (email_verification_available() ? ' required' : '') . '></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="password" placeholder="Password" required></div>';
        if (captcha_enabled()) {
            $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="Captcha" required>';
            $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha></div>';
        }
        $content .= '<button class="button primary w-full" type="submit">' . (email_verification_available() ? 'Next' : 'Register') . '</button>';
        $content .= '<div class="auth-footer">Already have an account? <a class="link" href="' . base_path() . '/login">Login</a></div>';
        $content .= '</form>';
    }
    $content .= '</div>';
    render_page('Register', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/forgot') {
    global $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim((string)($_POST['email'] ?? ''));
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (captcha_enabled() && !check_captcha($captchaInput)) {
            flash('error', 'Incorrect captcha');
            redirect('/forgot');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid email format');
            redirect('/forgot');
        }
        $lastSent = (int)($_SESSION['reset_code_at'] ?? 0);
        if ($lastSent && (time() - $lastSent) < 60) {
            flash('error', 'Too frequent, please try again later');
            redirect('/forgot');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && (int)$user['disabled'] !== 1) {
            $code = create_reset_code((int)$user['id'], $email, $_SERVER['REMOTE_ADDR'] ?? '');
            $sent = send_reset_code($email, $code);
            if (!$sent) {
                flash('error', 'Failed to send code, please check email configuration');
                redirect('/forgot');
            }
            $_SESSION['reset_code_at'] = time();
        }
        flash('info', 'If the email exists, a reset code has been sent');
        redirect('/reset');
    }

    $error = flash('error');
    $info = flash('info');
    $brand = htmlspecialchars($config['app_name']);
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconShield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.6 9.2 8 11 4.4-1.8 8-6 8-11V5z"/></svg>';
    $content = '<div class="auth-card">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">Forgot Password</div>';
    $content .= '<div class="auth-subtitle">Enter email to get a reset code</div>';
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    $content .= '<form method="post" class="auth-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="Email" required></div>';
    if (captcha_enabled()) {
        $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="Captcha" required>';
        $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="Captcha" data-captcha></div>';
    }
    $content .= '<button class="button primary w-full" type="submit">Send Reset Code</button>';
    $content .= '<div class="auth-footer"><a class="link" href="' . base_path() . '/login">Back to Login</a></div>';
    $content .= '</form></div>';
    render_page('Forgot Password', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/reset') {
    global $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim((string)($_POST['email'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($email === '' || $code === '' || $password === '' || $confirm === '') {
            flash('error', 'Please fill in all required fields');
            redirect('/reset');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid email format');
            redirect('/reset');
        }
        if (strlen($password) < 6) {
            flash('error', 'Password must be at least 6 characters');
            redirect('/reset');
        }
        if ($password !== $confirm) {
            flash('error', 'Passwords do not match');
            redirect('/reset');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            flash('error', 'Account does not exist');
            redirect('/reset');
        }
        if (!verify_reset_code((int)$user['id'], $email, $code)) {
            flash('error', 'Code is invalid or expired');
            redirect('/reset');
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => now(),
            ':id' => $user['id'],
        ]);
        flash('info', 'Password has been reset, please log in');
        redirect('/login');
    }
    $error = flash('error');
    $info = flash('info');
    $brand = htmlspecialchars($config['app_name']);
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconLock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 8v-2a1 1 0 0 1 2 0v2zm3-8H10V7a2 2 0 0 1 4 0z"/></svg>';
    $content = '<div class="auth-card">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">Reset Password</div>';
    $content .= '<div class="auth-subtitle">Enter email and code to set a new password</div>';
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    $content .= '<form method="post" class="auth-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="Email" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="code" placeholder="Email Verification Code" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="password" placeholder="New Password" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="confirm_password" placeholder="Confirm Password" required></div>';
    $content .= '<button class="button primary w-full" type="submit">Reset Password</button>';
    $content .= '<div class="auth-footer"><a class="link" href="' . base_path() . '/login">Back to Login</a></div>';
    $content .= '</form></div>';
    render_page('Reset Password', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/account/email-code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $email = trim((string)($_POST['new_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid new email');
        redirect('/account');
    }
    if (strcasecmp($email, (string)($user['email'] ?? '')) === 0) {
        flash('error', 'New email cannot be the same as current email');
        redirect('/account');
    }
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
    $check->execute([
        ':email' => $email,
        ':id' => (int)$user['id'],
    ]);
    if ($check->fetchColumn()) {
        flash('error', 'This email is already in use by another account');
        redirect('/account');
    }
    $lastSent = (int)($_SESSION['account_email_code_at'] ?? 0);
    if ($lastSent && (time() - $lastSent) < 60) {
        flash('error', 'Please wait before sending another code');
        redirect('/account');
    }
    $code = create_email_code($email, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!send_email_code($email, $code)) {
        flash('error', 'Failed to send code, please check email configuration');
        redirect('/account');
    }
    $_SESSION['account_email_code_at'] = time();
    $_SESSION['account_email_target'] = $email;
    flash('info', 'Code sent, please check your email');
    redirect('/account');
}

if ($path === '/account/email-change' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $email = trim((string)($_POST['new_email'] ?? ''));
    $code = trim((string)($_POST['email_code'] ?? ''));
    if ($email === '' || $code === '') {
        flash('error', 'Please fill in all required fields');
        redirect('/account');
    }
    $target = (string)($_SESSION['account_email_target'] ?? '');
    if ($target === '' || strcasecmp($target, $email) !== 0) {
        flash('error', 'Please get the email verification code first');
        redirect('/account');
    }
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
    $check->execute([
        ':email' => $email,
        ':id' => (int)$user['id'],
    ]);
    if ($check->fetchColumn()) {
        flash('error', 'This email is already in use by another account');
        redirect('/account');
    }
    if (!verify_email_code($email, $code)) {
        flash('error', 'Incorrect email verification code');
        redirect('/account');
    }
    $stmt = $pdo->prepare('UPDATE users SET email = :email, email_verified = 1, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':email' => $email,
        ':updated_at' => now(),
        ':id' => $user['id'],
    ]);
    unset($_SESSION['account_email_target'], $_SESSION['account_email_code_at']);
    flash('info', 'Email updated');
    redirect('/account');
}

if ($path === '/account') {
    $user = require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($new === '' || $confirm === '') {
            flash('error', 'Please fill in all required fields');
            redirect('/account');
        }
        if (strlen($new) < 6) {
            flash('error', 'New password must be at least 6 characters');
            redirect('/account');
        }
        if ($new !== $confirm) {
            flash('error', 'New passwords do not match');
            redirect('/account');
        }
        if (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Current password is incorrect');
            redirect('/account');
        }
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':hash' => password_hash($new, PASSWORD_DEFAULT),
            ':updated_at' => now(),
            ':id' => $user['id'],
        ]);
        unset($_SESSION['user_id'], $_SESSION['password_hash']);
        session_regenerate_id(true);
        flash('info', 'Password changed successfully, please log in again');
        redirect('/login');
    }

    $error = flash('error');
    $info = flash('info');
    $content = '<div class="card"><h2>Account Settings</h2>';
    if ($error) {
        $content .= '<div class="flash">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="flash">' . htmlspecialchars($info) . '</div>';
    }
    if ((int)$user['must_change_password'] === 1) {
        $content .= '<div class="notice">You are using the default password, please change it soon.</div>';
    }
    $content .= '<form method="post" style="margin-top:12px">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Current Password</label><input class="input" type="password" name="current_password" required></div>';
    $content .= '<div><label>New Password</label><input class="input" type="password" name="new_password" required></div>';
    $content .= '<div><label>Confirm New Password</label><input class="input" type="password" name="confirm_password" required></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">UpdatedPassword</button></div>';
    $content .= '</form></div>';

    $currentEmail = trim((string)($user['email'] ?? ''));
    $currentEmailLabel = $currentEmail !== '' ? htmlspecialchars($currentEmail) : 'Not bound';
    $pendingEmail = (string)($_SESSION['account_email_target'] ?? '');
    $content .= '<div class="card"><h2>Change Email</h2>';
    $content .= '<p class="muted">Current Email: ' . $currentEmailLabel . '</p>';
    $content .= '<form method="post" action="' . base_path() . '/account/email-change" style="margin-top:12px">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>New Email</label><input class="input" type="email" name="new_email" value="' . htmlspecialchars($pendingEmail) . '" required></div>';
    $content .= '<div><label>Email Verification Code</label><input class="input" name="email_code" placeholder="Enter captcha" required></div>';
    $content .= '</div>';
    $content .= '<div class="form-actions" style="margin-top:12px">';
    $content .= '<button class="button ghost" type="submit" formaction="' . base_path() . '/account/email-code" formnovalidate>Send Email Verification Code</button>';
    $content .= '<button class="button primary" type="submit">Change Email</button>';
    $content .= '</div>';
    $content .= '</form></div>';
    $titleHtml = build_topbar_title('Account Settings', $user);
    render_page('Account Settings', $content, $user, '', ['title_html' => $titleHtml]);
}

if ($path === '/dashboard') {
    $user = require_login();
    $pdo = db();
    $usedBytes = recalculate_user_storage((int)$user['id']);
    $user['storage_used_bytes'] = $usedBytes;
    $limitBytes = get_user_limit_bytes($user);
    $limitLabel = $limitBytes > 0 ? format_bytes($limitBytes) : 'Unlimited';
    $limitSource = ((int)$user['storage_limit_bytes'] > 0) ? 'Custom' : 'Default';
    $storageFull = $limitBytes > 0 && $usedBytes >= $limitBytes;
    $shareSearch = trim((string)($_GET['share_search'] ?? ''));
    $sharePage = max(1, (int)($_GET['share_page'] ?? 1));
    $shareSize = normalize_page_size($_GET['share_size'] ?? 10);
    $filterStatus = (string)($_GET['status'] ?? 'active');
    if (!in_array($filterStatus, ['active', 'deleted', 'all'], true)) {
        $filterStatus = 'active';
    }
    $where = ['user_id = :uid'];
    $params = [':uid' => $user['id']];
    if ($shareSearch !== '') {
        $where[] = '(title LIKE :share_search OR slug LIKE :share_search)';
        $params[':share_search'] = '%' . $shareSearch . '%';
    }
    if ($filterStatus === 'active') {
        $where[] = 'deleted_at IS NULL';
    } elseif ($filterStatus === 'deleted') {
        $where[] = 'deleted_at IS NOT NULL';
    }
    $shareSql = 'SELECT * FROM shares';
    $shareCountSql = 'SELECT COUNT(*) FROM shares';
    if (!empty($where)) {
        $shareSql .= ' WHERE ' . implode(' AND ', $where);
        $shareCountSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $shareCountStmt = $pdo->prepare($shareCountSql);
    $shareCountStmt->execute($params);
    $totalShares = (int)$shareCountStmt->fetchColumn();
    [$sharePage, $shareSize, $sharePages, $shareOffset] = paginate($totalShares, $sharePage, $shareSize);
    $shareSql .= ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($shareSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $shareSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $shareOffset, PDO::PARAM_INT);
    $stmt->execute();
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $shareQuery = $_GET;
    unset($shareQuery['share_page'], $shareQuery['share_size'], $shareQuery['share_search'], $shareQuery['status']);
    $accessShare = trim((string)($_GET['access_share'] ?? 'all'));
    $accessPage = max(1, (int)($_GET['access_page'] ?? 1));
    $accessSize = normalize_page_size($_GET['access_size'] ?? 10);
    $accessSourcePage = max(1, (int)($_GET['access_source_page'] ?? 1));
    $accessSourceSize = normalize_page_size($_GET['access_source_size'] ?? 10);
    $accessShareId = 0;
    if ($accessShare !== '' && $accessShare !== 'all') {
        $accessShareId = (int)$accessShare;
    }
    if ($accessShareId > 0) {
        $checkShare = $pdo->prepare('SELECT id FROM shares WHERE id = :id AND user_id = :uid LIMIT 1');
        $checkShare->execute([':id' => $accessShareId, ':uid' => $user['id']]);
        if (!$checkShare->fetchColumn()) {
            $accessShareId = 0;
        }
    }
    $accessEnabled = access_stats_enabled((int)$user['id']);
    $accessRetention = access_stats_retention_days((int)$user['id']);
    $accessShareOptionsStmt = $pdo->prepare('SELECT id, title, slug FROM shares WHERE user_id = :uid AND deleted_at IS NULL ORDER BY updated_at DESC');
    $accessShareOptionsStmt->execute([':uid' => $user['id']]);
    $accessShareOptions = $accessShareOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessWhere = ['share_access_logs.user_id = :uid'];
    $accessParams = [':uid' => $user['id']];
    if ($accessShareId > 0) {
        $accessWhere[] = 'share_access_logs.share_id = :sid';
        $accessParams[':sid'] = $accessShareId;
    }
    $accessWhereSql = implode(' AND ', $accessWhere);
    $todayStart = date('Y-m-d H:i:s', strtotime('today'));
    $tomorrowStart = date('Y-m-d H:i:s', strtotime('tomorrow'));
    $yesterdayStart = date('Y-m-d H:i:s', strtotime('yesterday'));
    $summarySql = 'SELECT
        SUM(CASE WHEN created_at >= :today_start AND created_at < :tomorrow_start THEN 1 ELSE 0 END) AS pv_today,
        SUM(CASE WHEN created_at >= :yesterday_start AND created_at < :today_start THEN 1 ELSE 0 END) AS pv_yesterday,
        COUNT(*) AS pv_total,
        COUNT(DISTINCT CASE WHEN created_at >= :today_start AND created_at < :tomorrow_start THEN visitor_id END) AS uv_today,
        COUNT(DISTINCT CASE WHEN created_at >= :yesterday_start AND created_at < :today_start THEN visitor_id END) AS uv_yesterday,
        COUNT(DISTINCT visitor_id || ":" || substr(created_at, 1, 10)) AS uv_total,
        COUNT(DISTINCT CASE WHEN created_at >= :today_start AND created_at < :tomorrow_start THEN ip END) AS ip_today,
        COUNT(DISTINCT CASE WHEN created_at >= :yesterday_start AND created_at < :today_start THEN ip END) AS ip_yesterday,
        COUNT(DISTINCT ip) AS ip_total
        FROM share_access_logs WHERE ' . $accessWhereSql;
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryParams = array_merge($accessParams, [
        ':today_start' => $todayStart,
        ':tomorrow_start' => $tomorrowStart,
        ':yesterday_start' => $yesterdayStart,
    ]);
    $summaryStmt->execute($summaryParams);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $accessSummary = [
        'pv_today' => (int)($summaryRow['pv_today'] ?? 0),
        'pv_yesterday' => (int)($summaryRow['pv_yesterday'] ?? 0),
        'pv_total' => (int)($summaryRow['pv_total'] ?? 0),
        'uv_today' => (int)($summaryRow['uv_today'] ?? 0),
        'uv_yesterday' => (int)($summaryRow['uv_yesterday'] ?? 0),
        'uv_total' => (int)($summaryRow['uv_total'] ?? 0),
        'ip_today' => (int)($summaryRow['ip_today'] ?? 0),
        'ip_yesterday' => (int)($summaryRow['ip_yesterday'] ?? 0),
        'ip_total' => (int)($summaryRow['ip_total'] ?? 0),
    ];
    $sourceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT referer FROM share_access_logs WHERE ' . $accessWhereSql . ' AND referer != "" GROUP BY referer) AS t');
    $sourceCountStmt->execute($accessParams);
    $accessSourceTotal = (int)$sourceCountStmt->fetchColumn();
    [$accessSourcePage, $accessSourceSize, $accessSourcePages, $accessSourceOffset] = paginate(
        $accessSourceTotal,
        $accessSourcePage,
        $accessSourceSize
    );
    $sourceSql = 'SELECT referer, COUNT(*) AS total FROM share_access_logs WHERE ' . $accessWhereSql . ' AND referer != "" GROUP BY referer ORDER BY total DESC LIMIT :limit OFFSET :offset';
    $sourceStmt = $pdo->prepare($sourceSql);
    foreach ($accessParams as $key => $value) {
        $sourceStmt->bindValue($key, $value);
    }
    $sourceStmt->bindValue(':limit', $accessSourceSize, PDO::PARAM_INT);
    $sourceStmt->bindValue(':offset', $accessSourceOffset, PDO::PARAM_INT);
    $sourceStmt->execute();
    $accessSources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
    $regionCnStmt = $pdo->prepare('SELECT ip_region AS label, COUNT(*) AS total FROM share_access_logs WHERE ' . $accessWhereSql . ' AND ip_country_code = "CN" AND ip_region != "" GROUP BY ip_region ORDER BY total DESC LIMIT 12');
    $regionCnStmt->execute($accessParams);
    $accessRegionsCn = $regionCnStmt->fetchAll(PDO::FETCH_ASSOC);
    $regionIntlStmt = $pdo->prepare('SELECT ip_country AS label, COUNT(*) AS total FROM share_access_logs WHERE ' . $accessWhereSql . ' AND (ip_country_code IS NULL OR ip_country_code != "CN") AND ip_country != "" GROUP BY ip_country ORDER BY total DESC LIMIT 12');
    $regionIntlStmt->execute($accessParams);
    $accessRegionsIntl = $regionIntlStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessCnMax = 0;
    foreach ($accessRegionsCn as $row) {
        $accessCnMax = max($accessCnMax, (int)($row['total'] ?? 0));
    }
    $accessIntlMax = 0;
    foreach ($accessRegionsIntl as $row) {
        $accessIntlMax = max($accessIntlMax, (int)($row['total'] ?? 0));
    }
    $accessCountStmt = $pdo->prepare('SELECT COUNT(*) FROM share_access_logs WHERE ' . $accessWhereSql);
    $accessCountStmt->execute($accessParams);
    $accessTotal = (int)$accessCountStmt->fetchColumn();
    $accessSizeStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid');
    $accessSizeStmt->execute([':uid' => $user['id']]);
    $accessLogTotalBytes = (int)$accessSizeStmt->fetchColumn();
    $accessLogTotalLabel = format_bytes($accessLogTotalBytes);
    [$accessPage, $accessSize, $accessPages, $accessOffset] = paginate($accessTotal, $accessPage, $accessSize);
    $accessSql = 'SELECT share_access_logs.*, shares.slug, shares.title AS share_title
        FROM share_access_logs
        JOIN shares ON share_access_logs.share_id = shares.id
        WHERE ' . $accessWhereSql . '
        ORDER BY share_access_logs.created_at DESC
        LIMIT :limit OFFSET :offset';
    $accessStmt = $pdo->prepare($accessSql);
    foreach ($accessParams as $key => $value) {
        $accessStmt->bindValue($key, $value);
    }
    $accessStmt->bindValue(':limit', $accessSize, PDO::PARAM_INT);
    $accessStmt->bindValue(':offset', $accessOffset, PDO::PARAM_INT);
    $accessStmt->execute();
    $accessLogs = $accessStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessQuery = $_GET;
    unset($accessQuery['access_page'], $accessQuery['access_size']);
    $accessFilterQuery = $accessQuery;
    unset($accessFilterQuery['access_share']);
    $accessSourceQuery = $_GET;
    unset($accessSourceQuery['access_source_page'], $accessSourceQuery['access_source_size']);
    $apiKey = flash('api_key');
    $info = flash('info');
    $error = flash('error');
    $content = '';
    if ($error) {
        $content .= '<div class="flash">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="flash">' . htmlspecialchars($info) . '</div>';
    }
    if ((int)$user['must_change_password'] === 1) {
        $content .= '<div class="notice">Default password detected, please change it in Account Settings.</div>';
    }
    $content .= '<div class="card"><h2>Storage space</h2>';
    $content .= '<p>Used: ' . format_bytes($usedBytes) . ' / ' . $limitLabel . '(' . $limitSource . ')</p>';
    $content .= '</div>';
    $content .= '<div class="card"><h2>API Key</h2>';
    if ($apiKey) {
        $content .= '<div class="notice">New API Key: <code>' . htmlspecialchars($apiKey) . '</code>(shown once only, please save it)</div>';
    }
    if (!empty($user['api_key_last4'])) {
        $content .= '<p class="muted">Current Key suffix: ' . htmlspecialchars($user['api_key_last4'] ?? '') . '</p>';
    } else {
        $content .= '<p class="muted">No API Key generated yet.</p>';
    }
    $buttonLabel = !empty($user['api_key_last4']) ? 'Regenerate' : 'Generate API Key';
    $content .= '<form method="post" action="' . base_path() . '/api-key/rotate">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<button class="button" type="submit">' . $buttonLabel . '</button>';
    $content .= '</form></div>';

    $content .= '<div class="card"><h2>Account Settings</h2>';
    $content .= '<p class="muted">Change login password, view account status.</p>';
    $content .= '<a class="button" href="' . base_path() . '/account">Go to Account Settings</a>';
    $content .= '</div>';

    $content .= '<div class="card" id="shares"><h2>Share List</h2>';
    $content .= '<form method="get" action="' . base_path() . '/dashboard#shares" class="filter-form">';
    $content .= render_hidden_inputs($shareQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>Keyword</label><input class="input" name="share_search" placeholder="Title / Slug" value="' . htmlspecialchars($shareSearch) . '"></div>';
    $content .= '<div><label>Filter by Status</label><select class="input" name="status">';
    $content .= '<option value="active"' . ($filterStatus === 'active' ? ' selected' : '') . '>Active</option>';
    $content .= '<option value="deleted"' . ($filterStatus === 'deleted' ? ' selected' : '') . '>Deleted</option>';
    $content .= '<option value="all"' . ($filterStatus === 'all' ? ' selected' : '') . '>All</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">Filter</button></div>';
    $content .= '</form>';
    if (empty($shares)) {
        $content .= '<p class="muted" style="margin-top:12px">No shares found.</p>';
    } else {
        $content .= '<table class="table" style="margin-top:12px"><thead><tr><th>Title</th><th>Type</th><th>Link</th><th>Password</th><th>Expires</th><th>Visitor Limit</th><th>Status</th><th>Comment Email Notify</th><th>Size</th><th>UpdatedTime</th></tr></thead><tbody>';
        foreach ($shares as $share) {
            $title = htmlspecialchars($share['title']);
            $type = $share['type'] === 'notebook' ? 'Notebook' : 'Document';
            $url = share_url($share['slug']);
            $updated = htmlspecialchars($share['updated_at']);
            $size = format_bytes((int)($share['size_bytes'] ?? 0));
            $hasPassword = !empty($share['password_hash']) ? 'Set' : 'None';
            $expiresAt = !empty($share['expires_at']) ? date('Y-m-d H:i', (int)$share['expires_at']) : 'Never';
            $visitorLimit = (int)($share['visitor_limit'] ?? 0);
            if ($visitorLimit > 0) {
                $visitorCount = share_visitor_count((int)$share['id']);
                $visitorLabel = $visitorCount . '/' . $visitorLimit;
            } else {
                $visitorLabel = 'Unlimited';
            }
            $status = $share['deleted_at'] ? 'Deleted' : 'Active';
            $notifyEnabled = (int)($share['comment_notify'] ?? 0) === 1;
            if (!empty($share['deleted_at'])) {
                $notifyHtml = '<span class="muted">Deleted</span>';
            } else {
                $notifyHtml = '<div class="comment-notify">';
                $notifyHtml .= '<span class="comment-notify-status">' . ($notifyEnabled ? 'On' : 'Off') . '</span>';
                if ($notifyEnabled || smtp_enabled()) {
                    $notifyHtml .= '<form method="post" action="' . base_path() . '/dashboard/comment-notify" class="inline-form">';
                    $notifyHtml .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                    $notifyHtml .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
                    $notifyHtml .= '<input type="hidden" name="action" value="' . ($notifyEnabled ? 'disable' : 'enable') . '" data-toggle-action>';
                    $notifyHtml .= '<label class="switch">';
                    $notifyHtml .= '<input type="checkbox" ' . ($notifyEnabled ? 'checked ' : '') . 'data-toggle-input>';
                    $notifyHtml .= '<span class="switch-slider"></span>';
                    $notifyHtml .= '</label>';
                    $notifyHtml .= '</form>';
                } else {
                    $notifyHtml .= '<span class="muted">SMTP required</span>';
                    $notifyHtml .= '<label class="switch is-disabled">';
                    $notifyHtml .= '<input type="checkbox" disabled>';
                    $notifyHtml .= '<span class="switch-slider"></span>';
                    $notifyHtml .= '</label>';
                }
                $notifyHtml .= '</div>';
            }
            $content .= "<tr><td>{$title}</td><td>{$type}</td><td><a href=\"{$url}\" target=\"_blank\">{$url}</a></td><td>{$hasPassword}</td><td>{$expiresAt}</td><td>{$visitorLabel}</td><td>{$status}</td><td>{$notifyHtml}</td><td>{$size}</td><td>{$updated}</td></tr>";
        }
        $content .= '</tbody></table>';
    }
    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_dashboard_query_url(['share_page' => max(1, $sharePage - 1)]) . '">Previous</a>';
    $content .= '<div class="pagination-info">Page ' . $sharePage . ' / ' . $sharePages . ' of ' . $totalShares . ' recordsShare</div>';
    $content .= '<a class="button ghost" href="' . build_dashboard_query_url(['share_page' => min($sharePages, $sharePage + 1)]) . '">Next</a>';
    $content .= '<form method="get" action="' . base_path() . '/dashboard#shares" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($shareQuery, [
        'share_search' => $shareSearch,
        'status' => $filterStatus,
    ]));
    $content .= '<label>Per page</label><select class="input" name="share_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $shareSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>Page Number</label><input class="input small" type="number" name="share_page" min="1" max="' . $sharePages . '" value="' . $sharePage . '">';
    $content .= '<button class="button" type="submit">Go</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="access-stats"><h2>Access Overview</h2>';
    $content .= '<form method="post" action="' . base_path() . '/dashboard/access-stats/update" class="stats-settings">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Access Statistics</label><label class="checkbox stats-toggle"><input type="checkbox" name="access_enabled" value="1"' . ($accessEnabled ? ' checked' : '') . '> On</label></div>';
    $content .= '<div><label>Retention Days</label><input class="input" type="number" name="access_retention_days" min="1" max="365" value="' . (int)$accessRetention . '"></div>';
    $content .= '</div>';
    $content .= '<div class="muted stats-note">Visitors (UV) deduplicated by browser cookie (daily), access records count toward account storage space, default retention Last 7 days(current: ' . (int)$accessRetention . ' days), adjustable here; insufficient storage will automatically disable and clear statistics.</div>';
    if ($storageFull) {
        $content .= '<div class="notice" style="margin-top:8px">Storage is full, cannot enable Access Statistics, please free up space first.</div>';
    }
    if (!$accessEnabled) {
        $content .= '<div class="notice" style="margin-top:8px">Access statistics are off, new visits will not be recorded.</div>';
    }
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">Save Settings</button></div>';
    $content .= '</form>';

    $content .= '<form method="get" action="' . base_path() . '/dashboard#access-stats" class="filter-form">';
    $content .= render_hidden_inputs($accessFilterQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>Note Filter</label><select class="input" name="access_share">';
    $content .= '<option value="all"' . ($accessShareId <= 0 ? ' selected' : '') . '>All</option>';
    foreach ($accessShareOptions as $option) {
        $optionId = (int)($option['id'] ?? 0);
        $optionTitle = (string)($option['title'] ?? '');
        $optionSlug = (string)($option['slug'] ?? '');
        $label = $optionTitle !== '' ? $optionTitle : $optionSlug;
        if ($optionSlug !== '') {
            $label .= ' /s/' . $optionSlug;
        }
        $selected = $accessShareId === $optionId ? ' selected' : '';
        $content .= '<option value="' . $optionId . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">Filter</button></div>';
    $content .= '</form>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">Access Overview</div>';
    $content .= '<table class="table stats-table"><thead><tr><th></th><th>Views (PV)</th><th>Visitors (UV)</th><th>IP Count</th></tr></thead><tbody>';
    $content .= '<tr><td>Today</td><td>' . $accessSummary['pv_today'] . '</td><td>' . $accessSummary['uv_today'] . '</td><td>' . $accessSummary['ip_today'] . '</td></tr>';
    $content .= '<tr><td>Yesterday</td><td>' . $accessSummary['pv_yesterday'] . '</td><td>' . $accessSummary['uv_yesterday'] . '</td><td>' . $accessSummary['ip_yesterday'] . '</td></tr>';
    $content .= '<tr><td>Total</td><td>' . $accessSummary['pv_total'] . '</td><td>' . $accessSummary['uv_total'] . '</td><td>' . $accessSummary['ip_total'] . '</td></tr>';
    $content .= '</tbody></table>';
    $content .= '</div>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">Referrers</div>';
    if (empty($accessSources)) {
        $content .= '<p class="muted">No referrer data.</p>';
    } else {
        $content .= '<table class="table stats-table"><thead><tr><th>Rank</th><th>Count</th><th>Referrer</th></tr></thead><tbody>';
        $rank = 1;
        foreach ($accessSources as $row) {
            $referer = (string)($row['referer'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $content .= '<tr><td>' . $rank . '</td><td>' . $total . '</td><td class="stats-source">' . htmlspecialchars($referer) . '</td></tr>';
            $rank++;
        }
        $content .= '</tbody></table>';
        $content .= '<div class="pagination">';
        $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_source_page' => max(1, $accessSourcePage - 1)]) . '">Previous</a>';
        $content .= '<div class="pagination-info">Page ' . $accessSourcePage . ' / ' . $accessSourcePages . ' of ' . $accessSourceTotal . ' referrers</div>';
        $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_source_page' => min($accessSourcePages, $accessSourcePage + 1)]) . '">Next</a>';
        $content .= '<form method="get" action="' . base_path() . '/dashboard#access-stats" class="pagination-form">';
        $content .= render_hidden_inputs(array_merge($accessSourceQuery, [
            'access_share' => $accessShareId > 0 ? $accessShareId : 'all',
        ]));
        $content .= '<label>Per page</label><select class="input" name="access_source_size">';
        foreach ([10, 50, 200, 1000] as $size) {
            $selected = $accessSourceSize === $size ? ' selected' : '';
            $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }
        $content .= '</select>';
        $content .= '<label>Page Number</label><input class="input small" type="number" name="access_source_page" min="1" max="' . $accessSourcePages . '" value="' . $accessSourcePage . '">';
        $content .= '<button class="button" type="submit">Go</button>';
        $content .= '</form>';
        $content .= '</div>';
    }
    $content .= '</div>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">Visitor Geography</div>';
    $content .= '<div class="stats-charts">';
    $content .= '<div class="stats-chart">';
    $content .= '<div class="stats-subtitle">Domestic</div>';
    if (empty($accessRegionsCn)) {
        $content .= '<p class="muted">No data.</p>';
    } else {
        $content .= '<div class="stats-chart-list">';
        foreach ($accessRegionsCn as $row) {
            $label = (string)($row['label'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $percent = $accessCnMax > 0 ? round(($total / $accessCnMax) * 100, 1) : 0;
            $content .= '<div class="stats-chart-row"><div class="stats-label">' . htmlspecialchars($label) . '</div><div class="stats-bar-track"><div class="stats-bar" style="width:' . $percent . '%"></div></div><div class="stats-value">' . $total . '</div></div>';
        }
        $content .= '</div>';
    }
    $content .= '</div>';
    $content .= '<div class="stats-chart">';
    $content .= '<div class="stats-subtitle">International</div>';
    if (empty($accessRegionsIntl)) {
        $content .= '<p class="muted">No data.</p>';
    } else {
        $content .= '<div class="stats-chart-list">';
        foreach ($accessRegionsIntl as $row) {
            $label = (string)($row['label'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $percent = $accessIntlMax > 0 ? round(($total / $accessIntlMax) * 100, 1) : 0;
            $content .= '<div class="stats-chart-row"><div class="stats-label">' . htmlspecialchars($label) . '</div><div class="stats-bar-track"><div class="stats-bar" style="width:' . $percent . '%"></div></div><div class="stats-value">' . $total . '</div></div>';
        }
        $content .= '</div>';
    }
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">Access Records</div>';
    $content .= '<div class="table-actions stats-actions">';
    $content .= '<form id="access-batch-form" method="post" action="' . base_path() . '/dashboard/access-stats/delete" class="inline-form" data-batch-form="access">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="access"> Select All</label>';
    $content .= '<button class="button danger" type="submit">Batch Delete</button>';
    $content .= '</form>';
    $content .= '<form method="post" action="' . base_path() . '/dashboard/access-stats/delete-all" class="inline-form" data-confirm-message="Confirm Deleteall access records? This action cannot be undone.">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<button class="button danger" type="submit">Delete All (occupying ' . $accessLogTotalLabel . ')</button>';
    $content .= '</form>';
    $content .= '</div>';
    if (empty($accessLogs)) {
        $content .= '<p class="muted">No access records.</p>';
    } else {
        $content .= '<table class="table stats-table"><thead><tr><th><input type="checkbox" data-check-all="access" form="access-batch-form"></th><th>Title</th><th>IP</th><th>IP Location</th><th>Access Date</th></tr></thead><tbody>';
        foreach ($accessLogs as $log) {
            $logTitle = trim((string)($log['doc_title'] ?? ''));
            if ($logTitle === '') {
                $logTitle = (string)($log['share_title'] ?? '');
            }
            if ($logTitle === '') {
                $logTitle = 'Untitled';
            }
            $slug = (string)($log['slug'] ?? '');
            $docId = trim((string)($log['doc_id'] ?? ''));
            if ($slug !== '') {
                $shareLink = $docId !== '' ? base_url() . build_share_redirect_path($slug, $docId, '') : share_url($slug);
                $suffix = $docId !== '' ? '/s/' . $slug . '/' . $docId : '/s/' . $slug;
            } else {
                $shareLink = '#';
                $suffix = '';
            }
            $ip = trim((string)($log['ip'] ?? ''));
            if ($ip === '') {
                $ip = '-';
            }
            $location = format_ip_location([
                'country' => $log['ip_country'] ?? '',
                'country_code' => $log['ip_country_code'] ?? '',
                'region' => $log['ip_region'] ?? '',
                'city' => $log['ip_city'] ?? '',
            ]);
            if ($location === '') {
                $location = '-';
            }
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="access_ids[]" value="' . (int)$log['id'] . '" data-check-item="access" form="access-batch-form"></td>';
            $content .= '<td><a href="' . htmlspecialchars($shareLink) . '" target="_blank">' . htmlspecialchars($logTitle) . '</a>';
            if ($suffix !== '') {
                $content .= '<div class="muted">' . htmlspecialchars($suffix) . '</div>';
            }
            $content .= '</td>';
            $content .= '<td>' . htmlspecialchars($ip) . '</td>';
            $content .= '<td>' . htmlspecialchars($location) . '</td>';
            $content .= '<td>' . htmlspecialchars((string)($log['created_at'] ?? '')) . '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_page' => max(1, $accessPage - 1)]) . '">Previous</a>';
    $content .= '<div class="pagination-info">Page ' . $accessPage . ' / ' . $accessPages . ' of ' . $accessTotal . ' visits</div>';
    $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_page' => min($accessPages, $accessPage + 1)]) . '">Next</a>';
    $content .= '<form method="get" action="' . base_path() . '/dashboard#access-stats" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($accessQuery, [
        'access_share' => $accessShareId > 0 ? $accessShareId : 'all',
    ]));
    $content .= '<label>Per page</label><select class="input" name="access_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $accessSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>Page Number</label><input class="input small" type="number" name="access_page" min="1" max="' . $accessPages . '" value="' . $accessPage . '">';
    $content .= '<button class="button" type="submit">Go</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '</div>';

    if ($user['role'] === 'admin') {
        $content .= '<div class="card"><h2>Admin Portal</h2>';
        $content .= '<a class="button" href="' . base_path() . '/admin-home">Enter Admin</a>';
        $content .= '</div>';
    }

    $titleHtml = build_topbar_title('Dashboard', $user);
    render_page('Dashboard', $content, $user, '', ['layout' => 'app', 'nav' => 'dashboard', 'title_html' => $titleHtml]);
}

if ($path === '/admin-home') {
    $admin = require_admin();
    $pdo = db();
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $disabledUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE disabled = 1')->fetchColumn();
    $totalSharesAll = (int)$pdo->query('SELECT COUNT(*) FROM shares')->fetchColumn();
    $totalSharesActive = (int)$pdo->query('SELECT COUNT(*) FROM shares WHERE deleted_at IS NULL')->fetchColumn();
    $deletedShares = max(0, $totalSharesAll - $totalSharesActive);
    $totalAccess = (int)$pdo->query('SELECT COUNT(*) FROM share_access_logs')->fetchColumn();
    $totalUv = (int)$pdo->query('SELECT COUNT(DISTINCT visitor_id) FROM share_access_logs WHERE visitor_id IS NOT NULL AND visitor_id != ""')->fetchColumn();
    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $todayStmt = $pdo->prepare('SELECT COUNT(*) AS pv, COUNT(DISTINCT CASE WHEN visitor_id IS NULL OR visitor_id = "" THEN NULL ELSE visitor_id END) AS uv
        FROM share_access_logs WHERE created_at >= :start AND created_at < :end');
    $todayStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    $todayRow = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $todayPv = (int)($todayRow['pv'] ?? 0);
    $todayUv = (int)($todayRow['uv'] ?? 0);
    $activeStart30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $activeStart7 = date('Y-m-d H:i:s', strtotime('-7 days'));
    $activeStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE last_active_at >= :start');
    $activeStmt->execute([':start' => $activeStart30]);
    $activeUsers30 = (int)$activeStmt->fetchColumn();
    $activeStmt->execute([':start' => $activeStart7]);
    $activeUsers7 = (int)$activeStmt->fetchColumn();
    $shareBytes = (int)$pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM shares WHERE deleted_at IS NULL')->fetchColumn();
    $logBytes = (int)$pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM share_access_logs')->fetchColumn();
    $usedBytes = $shareBytes + $logBytes;
    $defaultLimitBytes = default_storage_limit_bytes();
    $limitStmt = $pdo->prepare('SELECT SUM(CASE WHEN storage_limit_bytes > 0 THEN storage_limit_bytes ELSE :default_limit END) AS total FROM users');
    $limitStmt->execute([':default_limit' => $defaultLimitBytes]);
    $totalLimitBytes = (int)($limitStmt->fetchColumn() ?: 0);
    if ($totalUsers === 0) {
        $totalLimitBytes = 0;
    }
    $remainingBytes = $totalLimitBytes > 0 ? max(0, $totalLimitBytes - $usedBytes) : 0;
    $storagePercent = $totalLimitBytes > 0 ? min(100, round($usedBytes / max(1, $totalLimitBytes) * 100, 1)) : 0;
    $commentTotal = (int)$pdo->query('SELECT COUNT(*) FROM share_comments')->fetchColumn();
    $commentStmt = $pdo->prepare('SELECT COUNT(*) FROM share_comments WHERE created_at >= :start');
    $commentStmt->execute([':start' => $activeStart7]);
    $commentNew7 = (int)$commentStmt->fetchColumn();
    $reportTotal = (int)$pdo->query('SELECT COUNT(*) FROM share_reports')->fetchColumn();
    $reportPending = (int)$pdo->query('SELECT COUNT(*) FROM share_reports WHERE handled_at IS NULL OR handled_at = ""')->fetchColumn();

    $range7 = build_date_range(7);
    $range30 = build_date_range(30);
    $range7Start = $range7[0] . ' 00:00:00';
    $range30Start = $range30[0] . ' 00:00:00';

    $accessDailySql = 'SELECT substr(created_at, 1, 10) AS day,
        COUNT(*) AS pv,
        COUNT(DISTINCT CASE WHEN visitor_id IS NULL OR visitor_id = "" THEN NULL ELSE visitor_id END) AS uv
        FROM share_access_logs WHERE created_at >= :start GROUP BY day ORDER BY day';
    $accessDailyStmt = $pdo->prepare($accessDailySql);
    $accessDailyStmt->execute([':start' => $range7Start]);
    $accessRows7 = $accessDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessDailyStmt->execute([':start' => $range30Start]);
    $accessRows30 = $accessDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $pvSeries7 = fill_series($range7, $accessRows7, 'pv');
    $uvSeries7 = fill_series($range7, $accessRows7, 'uv');
    $pvSeries30 = fill_series($range30, $accessRows30, 'pv');
    $uvSeries30 = fill_series($range30, $accessRows30, 'uv');

    $shareDailySql = 'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS total FROM shares WHERE created_at >= :start GROUP BY day ORDER BY day';
    $shareDailyStmt = $pdo->prepare($shareDailySql);
    $shareDailyStmt->execute([':start' => $range7Start]);
    $shareRows7 = $shareDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareDailyStmt->execute([':start' => $range30Start]);
    $shareRows30 = $shareDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareSeries7 = fill_series($range7, $shareRows7, 'total');
    $shareSeries30 = fill_series($range30, $shareRows30, 'total');

    $userDailySql = 'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS total FROM users WHERE created_at >= :start GROUP BY day ORDER BY day';
    $userDailyStmt = $pdo->prepare($userDailySql);
    $userDailyStmt->execute([':start' => $range7Start]);
    $userRows7 = $userDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $userDailyStmt->execute([':start' => $range30Start]);
    $userRows30 = $userDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $userSeries7 = fill_series($range7, $userRows7, 'total');
    $userSeries30 = fill_series($range30, $userRows30, 'total');

    $shareBytesSql = 'SELECT substr(created_at, 1, 10) AS day, COALESCE(SUM(size_bytes), 0) AS total
        FROM shares WHERE created_at >= :start AND deleted_at IS NULL GROUP BY day ORDER BY day';
    $logBytesSql = 'SELECT substr(created_at, 1, 10) AS day, COALESCE(SUM(size_bytes), 0) AS total
        FROM share_access_logs WHERE created_at >= :start GROUP BY day ORDER BY day';
    $shareBytesStmt = $pdo->prepare($shareBytesSql);
    $logBytesStmt = $pdo->prepare($logBytesSql);
    $shareBytesStmt->execute([':start' => $range7Start]);
    $shareBytesRows7 = $shareBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $logBytesStmt->execute([':start' => $range7Start]);
    $logBytesRows7 = $logBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareBytesStmt->execute([':start' => $range30Start]);
    $shareBytesRows30 = $shareBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $logBytesStmt->execute([':start' => $range30Start]);
    $logBytesRows30 = $logBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareBytesSeries7 = fill_series($range7, $shareBytesRows7, 'total');
    $logBytesSeries7 = fill_series($range7, $logBytesRows7, 'total');
    $shareBytesSeries30 = fill_series($range30, $shareBytesRows30, 'total');
    $logBytesSeries30 = fill_series($range30, $logBytesRows30, 'total');
    $storageBytesSeries7 = [];
    $storageBytesSeries30 = [];
    foreach ($shareBytesSeries7 as $index => $value) {
        $storageBytesSeries7[] = $value + ($logBytesSeries7[$index] ?? 0);
    }
    foreach ($shareBytesSeries30 as $index => $value) {
        $storageBytesSeries30[] = $value + ($logBytesSeries30[$index] ?? 0);
    }
    $storageSeries7 = array_map(fn($value) => round($value / 1024 / 1024, 2), $storageBytesSeries7);
    $storageSeries30 = array_map(fn($value) => round($value / 1024 / 1024, 2), $storageBytesSeries30);
    $pvTotal7 = array_sum($pvSeries7);
    $uvTotal7 = array_sum($uvSeries7);
    $pvTotal30 = array_sum($pvSeries30);
    $uvTotal30 = array_sum($uvSeries30);
    $shareTotal7 = array_sum($shareSeries7);
    $shareTotal30 = array_sum($shareSeries30);
    $userTotal7 = array_sum($userSeries7);
    $userTotal30 = array_sum($userSeries30);
    $storageTotal7 = array_sum($storageBytesSeries7);
    $storageTotal30 = array_sum($storageBytesSeries30);

    $pvUvSeries7Chart = [
        ['key' => 'pv', 'values' => $pvSeries7, 'lineClass' => 'chart-line chart-line--primary', 'areaClass' => 'chart-area chart-area--primary'],
        ['key' => 'uv', 'values' => $uvSeries7, 'lineClass' => 'chart-line chart-line--accent'],
    ];
    $pvUvSeries30Chart = [
        ['key' => 'pv', 'values' => $pvSeries30, 'lineClass' => 'chart-line chart-line--primary', 'areaClass' => 'chart-area chart-area--primary'],
        ['key' => 'uv', 'values' => $uvSeries30, 'lineClass' => 'chart-line chart-line--accent'],
    ];
    $shareSeries7Chart = [
        ['key' => 'share', 'values' => $shareSeries7, 'lineClass' => 'chart-line chart-line--secondary', 'areaClass' => 'chart-area chart-area--secondary'],
    ];
    $shareSeries30Chart = [
        ['key' => 'share', 'values' => $shareSeries30, 'lineClass' => 'chart-line chart-line--secondary', 'areaClass' => 'chart-area chart-area--secondary'],
    ];
    $userSeries7Chart = [
        ['key' => 'user', 'values' => $userSeries7, 'lineClass' => 'chart-line chart-line--info', 'areaClass' => 'chart-area chart-area--info'],
    ];
    $userSeries30Chart = [
        ['key' => 'user', 'values' => $userSeries30, 'lineClass' => 'chart-line chart-line--info', 'areaClass' => 'chart-area chart-area--info'],
    ];
    $storageSeries7Chart = [
        [
            'key' => 'storage',
            'values' => $storageSeries7,
            'lineClass' => 'chart-line chart-line--storage',
            'areaClass' => 'chart-area chart-area--storage',
            'sumValues' => $storageBytesSeries7,
            'sumFormat' => 'bytes',
        ],
    ];
    $storageSeries30Chart = [
        [
            'key' => 'storage',
            'values' => $storageSeries30,
            'lineClass' => 'chart-line chart-line--storage',
            'areaClass' => 'chart-area chart-area--storage',
            'sumValues' => $storageBytesSeries30,
            'sumFormat' => 'bytes',
        ],
    ];

    $pvUvChart7 = render_chart_svg([
        ['values' => $pvSeries7, 'line_class' => 'chart-line chart-line--primary', 'area_class' => 'chart-area chart-area--primary'],
        ['values' => $uvSeries7, 'line_class' => 'chart-line chart-line--accent'],
    ]);
    $pvUvChart30 = render_chart_svg([
        ['values' => $pvSeries30, 'line_class' => 'chart-line chart-line--primary', 'area_class' => 'chart-area chart-area--primary'],
        ['values' => $uvSeries30, 'line_class' => 'chart-line chart-line--accent'],
    ]);
    $shareChart7 = render_chart_svg([
        ['values' => $shareSeries7, 'line_class' => 'chart-line chart-line--secondary', 'area_class' => 'chart-area chart-area--secondary'],
    ]);
    $shareChart30 = render_chart_svg([
        ['values' => $shareSeries30, 'line_class' => 'chart-line chart-line--secondary', 'area_class' => 'chart-area chart-area--secondary'],
    ]);
    $userChart7 = render_chart_svg([
        ['values' => $userSeries7, 'line_class' => 'chart-line chart-line--info', 'area_class' => 'chart-area chart-area--info'],
    ]);
    $userChart30 = render_chart_svg([
        ['values' => $userSeries30, 'line_class' => 'chart-line chart-line--info', 'area_class' => 'chart-area chart-area--info'],
    ]);
    $storageChart7 = render_chart_svg([
        ['values' => $storageSeries7, 'line_class' => 'chart-line chart-line--storage', 'area_class' => 'chart-area chart-area--storage'],
    ]);
    $storageChart30 = render_chart_svg([
        ['values' => $storageSeries30, 'line_class' => 'chart-line chart-line--storage', 'area_class' => 'chart-area chart-area--storage'],
    ]);

    $pvUvHolder7 = render_admin_chart_holder($range7, $pvUvSeries7Chart, 'count', $pvUvChart7);
    $pvUvHolder30 = render_admin_chart_holder($range30, $pvUvSeries30Chart, 'count', $pvUvChart30);
    $shareHolder7 = render_admin_chart_holder($range7, $shareSeries7Chart, 'count', $shareChart7);
    $shareHolder30 = render_admin_chart_holder($range30, $shareSeries30Chart, 'count', $shareChart30);
    $userHolder7 = render_admin_chart_holder($range7, $userSeries7Chart, 'count', $userChart7);
    $userHolder30 = render_admin_chart_holder($range30, $userSeries30Chart, 'count', $userChart30);
    $storageHolder7 = render_admin_chart_holder($range7, $storageSeries7Chart, 'MB', $storageChart7);
    $storageHolder30 = render_admin_chart_holder($range30, $storageSeries30Chart, 'MB', $storageChart30);

    $instanceStats = fetch_central_instance_stats();
    $instanceTotal = $instanceStats ? (int)($instanceStats['total'] ?? 0) : null;
    $instanceActive30 = $instanceStats ? (int)($instanceStats['active_30'] ?? 0) : null;
    $instanceUpdatedAt = $instanceStats ? (string)($instanceStats['updated_at'] ?? '') : '';

    $greetingLabel = 'Hello';
    $hour = (int)date('G');
    if ($hour < 6) {
        $greetingLabel = 'Good early morning';
    } elseif ($hour < 12) {
        $greetingLabel = 'Good morning';
    } elseif ($hour < 18) {
        $greetingLabel = 'Good afternoon';
    } else {
        $greetingLabel = 'Good evening';
    }
    $adminName = htmlspecialchars((string)($admin['username'] ?? ''));
    $greeting = $greetingLabel . ', ' . $adminName;

    $content = '<section class="admin-home">';
    $content .= '<div class="admin-hero card">';
    $content .= '<div class="admin-hero__main">';
    $content .= '<div class="admin-hero__eyebrow">Statistics</div>';
    $content .= '<div class="admin-hero__title">' . $greeting . '</div>';
    $content .= '<div class="admin-hero__meta">Today Views (PV) ' . number_format($todayPv) . ' / Visitors (UV) ' . number_format($todayUv) . '</div>';
    $content .= '</div>';
    $content .= '<div class="admin-hero__aside">';
    $content .= '<div class="admin-hero__panel">';
    $content .= '<div class="admin-hero__panel-label">Active Instances</div>';
    if ($instanceTotal === null) {
        $content .= '<div class="admin-hero__panel-value">—</div>';
        $content .= '<div class="admin-hero__panel-meta muted">Statistics unavailable</div>';
    } else {
        $content .= '<div class="admin-hero__panel-value">' . number_format($instanceTotal) . '</div>';
        $content .= '<div class="admin-hero__panel-meta">30-day active ' . number_format($instanceActive30 ?? 0) . '</div>';
        if ($instanceUpdatedAt !== '') {
            $content .= '<div class="admin-hero__panel-sub muted">Updated ' . htmlspecialchars($instanceUpdatedAt) . '</div>';
        }
    }
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $iconUser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14z"/></svg>';
    $iconActive = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 12h4l2-5 4 10 2-5h4v2h-3l-3 7-4-10-2 5H3z"/></svg>';
    $iconShare = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18 8a3 3 0 1 0-2.83-4H15a3 3 0 0 0 0 4 2.96 2.96 0 0 0 1 .19zM6 14a3 3 0 1 0 2.83 4H9a3 3 0 0 0 0-4 2.96 2.96 0 0 0-1-.19zM18 20a3 3 0 1 0-2.83-4H15a3 3 0 0 0 0 4 2.96 2.96 0 0 0 1 .19zM8.41 12.59l7.18 3.59.9-1.79-7.18-3.59-.9 1.79zM15.59 9.59l-7.18 3.59.9 1.79 7.18-3.59-.9-1.79z"/></svg>';
    $iconAccess = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 14.5 2.5 12 6.5 5 12 5zm0 3a4 4 0 1 0 4 4 4 4 0 0 0-4-4z"/></svg>';
    $iconStorage = '<svg class="kpi-icon kpi-icon--storage" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 6c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2zm0 4c0 1.1 3.6 2 8 2s8-.9 8-2V8c-1.7 1.2-5.1 2-8 2s-6.3-.8-8-2zm0 4c0 1.1 3.6 2 8 2s8-.9 8-2v-2c-1.7 1.2-5.1 2-8 2s-6.3-.8-8-2zm0 4c0 1.1 3.6 2 8 2s8-.9 8-2v-2c-1.7 1.2-5.1 2-8 2s-6.3-.8-8-2z"/></svg>';

    $storageValue = format_bytes($usedBytes) . ($totalLimitBytes > 0 ? ' / ' . format_bytes($totalLimitBytes) : '');
    $storageMeta = $totalLimitBytes > 0 ? 'Remaining: ' . format_bytes($remainingBytes) : 'Total Unlimited';
    $storageProgress = '<div class="admin-kpi__progress"><span style="width:' . $storagePercent . '%"></span></div>';

    $content .= '<div class="admin-kpi-grid">';
    $content .= render_kpi_card('Total Users', number_format($totalUsers), 'incl. disabled ' . number_format($disabledUsers), $iconUser);
    $content .= render_kpi_card('Active Users', number_format($activeUsers30), 'Last 7 days ' . number_format($activeUsers7), $iconActive);
    $content .= render_kpi_card('Total Shares', number_format($totalSharesActive), 'soft deleted ' . number_format($deletedShares), $iconShare);
    $content .= render_kpi_card('Total Visits', number_format($totalAccess), 'UV total ' . number_format($totalUv), $iconAccess);
    $content .= render_kpi_card('Storage Used/Free', htmlspecialchars($storageValue), htmlspecialchars($storageMeta), $iconStorage, $storageProgress);
    $content .= '</div>';

    $pvUvRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $pvUvSeries30Chart,
        'unit' => 'count',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $shareRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $shareSeries30Chart,
        'unit' => 'count',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $userRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $userSeries30Chart,
        'unit' => 'count',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $storageRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $storageSeries30Chart,
        'unit' => 'MB',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);

    $content .= '<div class="admin-chart-grid">';
    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $pvUvRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">PV/UV Trend</div><div class="admin-chart-card__meta" data-range-label>Last 7 days</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">Custom</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary"><div class="admin-legend"><span class="legend-dot is-primary"></span>Views (PV) ' . number_format($pvTotal7) . '</div>';
    $content .= '<div class="admin-legend"><span class="legend-dot is-accent"></span>Visitors (UV) ' . number_format($uvTotal7) . '</div></div>';
    $content .= $pvUvHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary"><div class="admin-legend"><span class="legend-dot is-primary"></span>Views (PV) ' . number_format($pvTotal30) . '</div>';
    $content .= '<div class="admin-legend"><span class="legend-dot is-accent"></span>Visitors (UV) ' . number_format($uvTotal30) . '</div></div>';
    $content .= $pvUvHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary"><div class="admin-legend"><span class="legend-dot is-primary"></span>Views (PV) <span data-range-metric="pv">0</span></div>';
    $content .= '<div class="admin-legend"><span class="legend-dot is-accent"></span>Visitors (UV) <span data-range-metric="uv">0</span></div></div>';
    $content .= $pvUvHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">Last <span data-range-days>7</span> days</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $shareRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">New Shares</div><div class="admin-chart-card__meta" data-range-label>Last 7 days</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">Custom</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary muted">New in period: ' . number_format($shareTotal7) . ' records</div>';
    $content .= $shareHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary muted">New in period: ' . number_format($shareTotal30) . ' records</div>';
    $content .= $shareHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary muted">New in period: <span data-range-total>0</span> records</div>';
    $content .= $shareHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">Last <span data-range-days>7</span> days</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $userRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">New Users</div><div class="admin-chart-card__meta" data-range-label>Last 7 days</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">Custom</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary muted">New in period: ' . number_format($userTotal7) . '</div>';
    $content .= $userHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary muted">New in period: ' . number_format($userTotal30) . '</div>';
    $content .= $userHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary muted">New in period: <span data-range-total>0</span></div>';
    $content .= $userHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">Last <span data-range-days>7</span> days</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $storageRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">Storage Growth</div><div class="admin-chart-card__meta" data-range-label>Last 7 days</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30 days</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">Custom</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary muted">Growth in period: ' . htmlspecialchars(format_bytes($storageTotal7)) . '</div>';
    $content .= $storageHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary muted">Growth in period: ' . htmlspecialchars(format_bytes($storageTotal30)) . '</div>';
    $content .= $storageHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary muted">Growth in period: <span data-range-total>0</span></div>';
    $content .= $storageHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">Last <span data-range-days>7</span> days</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-governance-grid">';
    $content .= '<div class="admin-governance card">';
    $content .= '<div class="admin-governance__label">Total Comments</div>';
    $content .= '<div class="admin-governance__value">' . number_format($commentTotal) . '</div>';
    $content .= '<div class="admin-governance__meta">New last 7 days: ' . number_format($commentNew7) . '</div>';
    $content .= '</div>';
    $content .= '<div class="admin-governance card">';
    $content .= '<div class="admin-governance__label">Total Reports</div>';
    $content .= '<div class="admin-governance__value">' . number_format($reportTotal) . '</div>';
    $content .= '<div class="admin-governance__meta">Pending: ' . number_format($reportPending) . '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $titleHtml = build_topbar_title('Statistics', $admin);
    render_page('Statistics', $content, $admin, '', ['layout' => 'app', 'nav' => 'admin-home', 'title_html' => $titleHtml]);
}

if ($path === '/api-key/rotate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    [$rawKey, $hash, $prefix, $last4] = generate_api_key();
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET api_key_hash = :hash, api_key_prefix = :prefix, api_key_last4 = :last4, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':hash' => $hash,
        ':prefix' => $prefix,
        ':last4' => $last4,
        ':updated_at' => now(),
        ':id' => $user['id'],
    ]);
    flash('api_key', $rawKey);
    redirect('/dashboard');
}

if ($path === '/dashboard/comment-notify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($shareId <= 0 || !in_array($action, ['enable', 'disable'], true)) {
        flash('error', 'Invalid request parameters');
        redirect('/dashboard#shares');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM shares WHERE id = :id AND user_id = :uid AND deleted_at IS NULL');
    $stmt->execute([':id' => $shareId, ':uid' => $user['id']]);
    if (!$stmt->fetchColumn()) {
        flash('error', 'Share not found');
        redirect('/dashboard#shares');
    }
    $enable = $action === 'enable';
    if ($enable && !smtp_enabled()) {
        flash('error', 'Please enable SMTP in Admin first, then enable Comment Email Notify');
        redirect('/dashboard#shares');
    }
    $update = $pdo->prepare('UPDATE shares SET comment_notify = :notify WHERE id = :id AND user_id = :uid');
    $update->execute([
        ':notify' => $enable ? 1 : 0,
        ':id' => $shareId,
        ':uid' => $user['id'],
    ]);
    flash('info', $enable ? 'Comment email notifications enabled' : 'Comment email notifications disabled');
    redirect('/dashboard#shares');
}

if ($path === '/dashboard/access-stats/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $userId = (int)$user['id'];
    $enabled = !empty($_POST['access_enabled']);
    $daysRaw = (int)($_POST['access_retention_days'] ?? access_stats_retention_days($userId));
    $days = max(1, min(365, $daysRaw));
    set_user_setting($userId, 'access_stats_retention_days', (string)$days);
    if ($enabled) {
        $used = recalculate_user_storage($userId);
        $limit = get_user_limit_bytes($user);
        if ($limit > 0 && $used >= $limit) {
            set_user_setting($userId, 'access_stats_enabled', '0');
            flash('error', 'Storage is full, cannot enable Access Statistics');
            redirect('/dashboard#access-stats');
        }
        set_user_setting($userId, 'access_stats_enabled', '1');
    } else {
        set_user_setting($userId, 'access_stats_enabled', '0');
    }
    flash('info', 'Access statistics settings updated');
    redirect('/dashboard#access-stats');
}

if ($path === '/dashboard/access-stats/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $userId = (int)$user['id'];
    $ids = $_POST['access_ids'] ?? [];
    $ids = is_array($ids) ? array_values(array_filter($ids)) : [];
    if (empty($ids)) {
        redirect('/dashboard#access-stats');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = ? AND id IN (' . $placeholders . ')');
    $sumStmt->execute(array_merge([$userId], $ids));
    $total = (int)($sumStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $delStmt = $pdo->prepare('DELETE FROM share_access_logs WHERE user_id = ? AND id IN (' . $placeholders . ')');
    $delStmt->execute(array_merge([$userId], $ids));
    adjust_user_storage($userId, -$total);
    flash('info', 'Deleted selected access records');
    redirect('/dashboard#access-stats');
}

if ($path === '/dashboard/access-stats/delete-all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $userId = (int)$user['id'];
    purge_user_access_logs($userId);
    flash('info', 'Cleared all access records');
    redirect('/dashboard#access-stats');
}

if ($path === '/admin') {
    $admin = require_admin();
    $pdo = db();
    $info = flash('info');
    $error = flash('error');
    $createForm = $_SESSION['user_create_form'] ?? [];
    if (!is_array($createForm)) {
        $createForm = [];
    }
    $createOpen = !empty($createForm['open']);
    $createUsername = (string)($createForm['username'] ?? '');
    $createEmail = (string)($createForm['email'] ?? '');
    $createRole = (string)($createForm['role'] ?? 'user');
    if (!in_array($createRole, ['admin', 'user'], true)) {
        $createRole = 'user';
    }
    $createDisabled = (string)($createForm['disabled'] ?? '0');
    $createLimitMb = (string)($createForm['limit_mb'] ?? '0');
    $createPassword = (string)($createForm['password'] ?? '');
    unset($_SESSION['user_create_form']);
    $allowRegistration = allow_registration();
    $captchaEnabled = captcha_enabled();
    $emailVerifyEnabled = email_verification_enabled();
    $defaultLimitBytes = default_storage_limit_bytes();
    $defaultLimitMb = mb_from_bytes($defaultLimitBytes);
    $emailFrom = get_setting('email_from', 'no-reply@example.com');
    $emailFromName = get_setting('email_from_name', 'SiYuan Note Share');
    $emailSubject = get_setting('email_subject', 'Email Verification Code');
    $emailResetSubject = get_setting('email_reset_subject', 'Password Reset Verification Code');
    $smtpEnabled = smtp_enabled();
    $smtpHost = get_setting('smtp_host', '');
    $smtpPort = get_setting('smtp_port', '587');
    $smtpSecure = get_setting('smtp_secure', 'tls');
    $smtpUser = get_setting('smtp_user', '');
    $smtpPass = get_setting('smtp_pass', '');
    $siteIcp = get_setting('site_icp', '');
    $siteContactEmail = get_setting('site_contact_email', '');
    $siteBaseUrl = get_setting('site_base_url', '');
    $siteHeadHtml = get_setting('site_head_html', '');
    $bannedWordsRaw = get_banned_words_raw();
    $scanKeep = ((string)($_GET['scan_keep'] ?? '')) === '1';
    if (!$scanKeep) {
        unset($_SESSION['scan_results'], $_SESSION['scan_logs'], $_SESSION['scan_done'], $_SESSION['scan_at'], $_SESSION['scan_total']);
    }
    $scanResults = $_SESSION['scan_results'] ?? [];
    $scanAt = (int)($_SESSION['scan_at'] ?? 0);
    $scanResults = is_array($scanResults) ? $scanResults : [];
    $scanLogs = $_SESSION['scan_logs'] ?? [];
    $scanLogs = is_array($scanLogs) ? $scanLogs : [];
    $scanDone = (int)($_SESSION['scan_done'] ?? 0) === 1;
    $scanPage = max(1, (int)($_GET['scan_page'] ?? 1));
    $scanSize = normalize_page_size($_GET['scan_size'] ?? 10);
    $scanTotal = count($scanResults);
    $scanKeepParam = (!empty($scanLogs) || $scanTotal > 0) ? '1' : null;
    $scanLogHtml = '';
    foreach ($scanLogs as $log) {
        $scanLogHtml .= '<div>' . $log . '</div>';
    }
    $scanProgressHidden = $scanLogHtml === '' ? ' hidden' : '';
    $scanReady = $scanDone || $scanLogHtml !== '';
    $scanStatusLabel = $scanReady ? ('Scan complete, matched ' . number_format($scanTotal) . ' records') : 'Waiting for scan...';
    $scanBarStyle = $scanReady ? ' style="width:100%"' : '';
    [$scanPage, $scanSize, $scanPages, $scanOffset] = paginate($scanTotal, $scanPage, $scanSize);
    $scanPageResults = array_slice($scanResults, $scanOffset, $scanSize);
    [$chunkTtlSeconds] = chunk_cleanup_settings();
    $staleChunks = list_stale_chunks($chunkTtlSeconds);
    $allUsers = $pdo->query('SELECT id, username FROM users ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);
    $userSearch = trim((string)($_GET['user_search'] ?? ''));
    $userStatus = (string)($_GET['user_status'] ?? 'all');
    $userRole = (string)($_GET['user_role'] ?? 'all');
    $userPage = max(1, (int)($_GET['user_page'] ?? 1));
    $userSize = normalize_page_size($_GET['user_size'] ?? 10);
    $userWhere = [];
    $userParams = [];
    if ($userSearch !== '') {
        $userWhere[] = '(username LIKE :user_search OR email LIKE :user_search)';
        $userParams[':user_search'] = '%' . $userSearch . '%';
    }
    if ($userStatus === 'active') {
        $userWhere[] = 'disabled = 0';
    } elseif ($userStatus === 'disabled') {
        $userWhere[] = 'disabled = 1';
    }
    if ($userRole === 'admin') {
        $userWhere[] = 'role = "admin"';
    } elseif ($userRole === 'user') {
        $userWhere[] = 'role = "user"';
    }
    $userSql = 'SELECT * FROM users';
    $userCountSql = 'SELECT COUNT(*) FROM users';
    if (!empty($userWhere)) {
        $userSql .= ' WHERE ' . implode(' AND ', $userWhere);
        $userCountSql .= ' WHERE ' . implode(' AND ', $userWhere);
    }
    $userCountStmt = $pdo->prepare($userCountSql);
    $userCountStmt->execute($userParams);
    $totalUsers = (int)$userCountStmt->fetchColumn();
    [$userPage, $userSize, $userPages, $userOffset] = paginate($totalUsers, $userPage, $userSize);
    $userSql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
    $userStmt = $pdo->prepare($userSql);
    foreach ($userParams as $key => $value) {
        $userStmt->bindValue($key, $value);
    }
    $userStmt->bindValue(':limit', $userSize, PDO::PARAM_INT);
    $userStmt->bindValue(':offset', $userOffset, PDO::PARAM_INT);
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$u) {
        $u['storage_used_bytes'] = recalculate_user_storage((int)$u['id']);
    }
    unset($u);

    $filterUser = (int)($_GET['user'] ?? 0);
    $filterStatus = (string)($_GET['status'] ?? 'active');
    $shareSearch = trim((string)($_GET['share_search'] ?? ''));
    $sharePage = max(1, (int)($_GET['share_page'] ?? 1));
    $shareSize = normalize_page_size($_GET['share_size'] ?? 10);
    if (!in_array($filterStatus, ['active', 'deleted', 'all'], true)) {
        $filterStatus = 'active';
    }
    $where = [];
    $params = [];
    if ($filterUser > 0) {
        $where[] = 'shares.user_id = :uid';
        $params[':uid'] = $filterUser;
    }
    if ($shareSearch !== '') {
        $where[] = '(shares.title LIKE :share_search OR shares.slug LIKE :share_search)';
        $params[':share_search'] = '%' . $shareSearch . '%';
    }
    if ($filterStatus === 'active') {
        $where[] = 'shares.deleted_at IS NULL';
    } elseif ($filterStatus === 'deleted') {
        $where[] = 'shares.deleted_at IS NOT NULL';
    }
    $shareSql = 'SELECT shares.*, users.username FROM shares JOIN users ON shares.user_id = users.id';
    $shareCountSql = 'SELECT COUNT(*) FROM shares JOIN users ON shares.user_id = users.id';
    if (!empty($where)) {
        $shareSql .= ' WHERE ' . implode(' AND ', $where);
        $shareCountSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $shareCountStmt = $pdo->prepare($shareCountSql);
    $shareCountStmt->execute($params);
    $totalShares = (int)$shareCountStmt->fetchColumn();
    [$sharePage, $shareSize, $sharePages, $shareOffset] = paginate($totalShares, $sharePage, $shareSize);
    $shareSql .= ' ORDER BY shares.updated_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($shareSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $shareSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $shareOffset, PDO::PARAM_INT);
    $stmt->execute();
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reportStatus = (string)($_GET['report_status'] ?? 'pending');
    if (!in_array($reportStatus, ['pending', 'handled', 'all'], true)) {
        $reportStatus = 'pending';
    }
    $reportPage = max(1, (int)($_GET['report_page'] ?? 1));
    $reportSize = normalize_page_size($_GET['report_size'] ?? 10);
    $reportWhere = [];
    if ($reportStatus === 'pending') {
        $reportWhere[] = 'share_reports.handled_at IS NULL';
    } elseif ($reportStatus === 'handled') {
        $reportWhere[] = 'share_reports.handled_at IS NOT NULL';
    }
    $reportSql = 'SELECT share_reports.*, users.username AS share_username, reporters.username AS reporter_username
        FROM share_reports
        LEFT JOIN users ON share_reports.share_user_id = users.id
        LEFT JOIN users reporters ON share_reports.reporter_user_id = reporters.id';
    $reportCountSql = 'SELECT COUNT(*) FROM share_reports';
    if (!empty($reportWhere)) {
        $reportSql .= ' WHERE ' . implode(' AND ', $reportWhere);
        $reportCountSql .= ' WHERE ' . implode(' AND ', $reportWhere);
    }
    $reportCountStmt = $pdo->prepare($reportCountSql);
    $reportCountStmt->execute();
    $reportTotal = (int)$reportCountStmt->fetchColumn();
    [$reportPage, $reportSize, $reportPages, $reportOffset] = paginate($reportTotal, $reportPage, $reportSize);
    $reportSql .= ' ORDER BY share_reports.created_at DESC LIMIT :limit OFFSET :offset';
    $reportStmt = $pdo->prepare($reportSql);
    $reportStmt->bindValue(':limit', $reportSize, PDO::PARAM_INT);
    $reportStmt->bindValue(':offset', $reportOffset, PDO::PARAM_INT);
    $reportStmt->execute();
    $reports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

    $announcements = $pdo->query('SELECT a.*, u.username AS author FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);
    $userQuery = $_GET;
    unset($userQuery['user_page'], $userQuery['user_size'], $userQuery['user_search'], $userQuery['user_status'], $userQuery['user_role']);
    $shareQuery = $_GET;
    unset($shareQuery['share_page'], $shareQuery['share_size'], $shareQuery['share_search'], $shareQuery['user'], $shareQuery['status']);
    $reportQuery = $_GET;
    unset($reportQuery['report_page'], $reportQuery['report_size'], $reportQuery['report_status']);
    $scanQuery = $_GET;
    unset($scanQuery['scan_page'], $scanQuery['scan_size']);
    if ($scanKeepParam !== null) {
        $scanQuery['scan_keep'] = $scanKeepParam;
    } else {
        unset($scanQuery['scan_keep']);
    }

    $content = '';
    if ($error) {
        $content .= '<div class="flash">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="flash">' . htmlspecialchars($info) . '</div>';
    }

    $content .= '<div class="card" id="settings"><h2>Site Settings</h2>';
    $content .= '<form method="post" action="' . base_path() . '/admin/settings">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Default Storage Limit (MB)</label><input class="input" name="default_storage_limit_mb" type="number" min="0" value="' . (int)$defaultLimitMb . '"></div>';
    $content .= '<div><label>Email Sender</label><input class="input" name="email_from" value="' . htmlspecialchars((string)$emailFrom) . '"></div>';
    $content .= '<div><label>Sender Name</label><input class="input" name="email_from_name" value="' . htmlspecialchars((string)$emailFromName) . '"></div>';
    $content .= '<div><label>Verification Code Subject</label><input class="input" name="email_subject" value="' . htmlspecialchars((string)$emailSubject) . '"></div>';
    $content .= '<div><label>Password Reset Subject</label><input class="input" name="email_reset_subject" value="' . htmlspecialchars((string)$emailResetSubject) . '"></div>';
    $content .= '<div><label>ICP Registration</label><input class="input" name="site_icp" value="' . htmlspecialchars((string)$siteIcp) . '"></div>';
    $content .= '<div><label>Contact Email</label><input class="input" name="site_contact_email" value="' . htmlspecialchars((string)$siteContactEmail) . '"></div>';
    $content .= '<div><label>Site URL (share link prefix)<button class="link-button" type="button" data-report-open data-report-target="site-base-url-help">Info</button></label><input class="input" name="site_base_url" placeholder="https://share.example.com" value="' . htmlspecialchars((string)$siteBaseUrl) . '"></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>Banned Words (separated by |)</label>';
    $content .= '<textarea class="input" name="banned_words" rows="2" placeholder="Example: word1|word2|word3">' . htmlspecialchars($bannedWordsRaw) . '</textarea>';
    $content .= '<div class="muted">User shares and comments matching any banned word will be rejected and flagged in scan results.</div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>HTML Head Insert Content</label>';
    $content .= '<textarea class="input" name="site_head_html" rows="4" placeholder="e.g.: &lt;script src=&quot;https://example.com/xxx.js&quot;&gt;&lt;/script&gt;">' . htmlspecialchars((string)$siteHeadHtml) . '</textarea>';
    $content .= '<div class="muted">Will be inserted into the page &lt;head&gt;, useful for analytics scripts.</div>';
    $content .= '</div>';
    $content .= '<div class="grid" style="margin-top:12px">';
    $content .= '<label><input type="checkbox" name="allow_registration" value="1"' . ($allowRegistration ? ' checked' : '') . '> Allow Registration</label>';
    $content .= '<label><input type="checkbox" name="captcha_enabled" value="1"' . ($captchaEnabled ? ' checked' : '') . '> Enable Captcha</label>';
    $content .= '<label><input type="checkbox" name="email_verification_enabled" value="1"' . ($emailVerifyEnabled ? ' checked' : '') . '> EnableEmail Verification Code</label>';
    $content .= '<label><input type="checkbox" name="smtp_enabled" value="1"' . ($smtpEnabled ? ' checked' : '') . '> Enable SMTP</label>';
    $content .= '</div>';
    $content .= '<div class="grid" style="margin-top:12px">';
    $content .= '<div><label>SMTP Host</label><input class="input" name="smtp_host" value="' . htmlspecialchars((string)$smtpHost) . '"></div>';
    $content .= '<div><label>SMTP Port</label><input class="input" name="smtp_port" type="number" min="0" value="' . htmlspecialchars((string)$smtpPort) . '"></div>';
    $content .= '<div><label>Encryption</label><select class="input" name="smtp_secure">';
    $content .= '<option value="none"' . ($smtpSecure === 'none' ? ' selected' : '') . '>None</option>';
    $content .= '<option value="tls"' . ($smtpSecure === 'tls' ? ' selected' : '') . '>TLS</option>';
    $content .= '<option value="ssl"' . ($smtpSecure === 'ssl' ? ' selected' : '') . '>SSL</option>';
    $content .= '</select></div>';
    $content .= '<div><label>SMTP Username</label><input class="input" name="smtp_user" value="' . htmlspecialchars((string)$smtpUser) . '"></div>';
    $content .= '<div><label>SMTP Password</label><input class="input" type="password" name="smtp_pass" value="' . htmlspecialchars((string)$smtpPass) . '"></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">Save Settings</button></div>';
    $content .= '</form></div>';
    $content .= '<div class="modal" id="site-base-url-help" data-report-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">Site URL Info</div>';
    $content .= '<div class="modal-body">';
    $content .= '<p><strong>Leave blank: </strong>Auto-detect current access URL (protocol/domain/port).</p>';
    $content .= '<p><strong>Fill in: </strong>Share links will use this prefix; useful for reverse proxy/HTTPS termination scenarios.</p>';
    $content .= '<p><strong>Example: </strong><code>https://share.example.com</code> or <code>https://IP:port</code></p>';
    $content .= '<p><strong>Note: </strong>Only affects the share link prefix; does not restrict other access methods.</p>';
    $content .= '</div>';
    $content .= '<div class="modal-actions"><button class="button" type="button" data-modal-close>Close</button></div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card"><h2>SMTP Test</h2>';
    $content .= '<form method="post" action="' . base_path() . '/admin/smtp-test">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Test Email Address</label><input class="input" name="test_email" placeholder="e.g. test@example.com" required></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">Send Test Email</button></div>';
    $content .= '</form></div>';

    $content .= '<div class="card" id="announcements"><h2>Post Announcement</h2>';
    $content .= '<form method="post" action="' . base_path() . '/admin/announcement/create">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Title</label><input class="input" name="title" required></div>';
    $content .= '<div><label>Content</label><textarea class="input" name="content" rows="4" required></textarea></div>';
    $content .= '</div>';
    $content .= '<div class="muted" style="margin-top:6px">Supports HTML; will be rendered directly after saving.</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label><input type="checkbox" name="active" value="1" checked> Publish Now</label>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">Post Announcement</button></div>';
    $content .= '</form></div>';

    $content .= '<div class="card"><h2>Announcement List</h2>';
    if (empty($announcements)) {
        $content .= '<p class="muted">No announcements.</p>';
    } else {
        $content .= '<table class="table"><thead><tr><th>Title</th><th>Status</th><th>Author</th><th>Published At</th><th>Actions</th></tr></thead><tbody>';
        foreach ($announcements as $item) {
            $status = ((int)$item['active'] === 1) ? 'Enabled' : 'Disabled';
            $author = $item['author'] ?? 'System';
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars($item['title']) . '</td>';
            $content .= '<td>' . htmlspecialchars($status) . '</td>';
            $content .= '<td>' . htmlspecialchars($author) . '</td>';
            $content .= '<td>' . htmlspecialchars($item['created_at']) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<form method="post" action="' . base_path() . '/admin/announcement/toggle" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="announcement_id" value="' . (int)$item['id'] . '">';
            $content .= '<button class="button" type="submit">' . (((int)$item['active'] === 1) ? 'Disable' : 'Enable') . '</button>';
            $content .= '</form>';
            $content .= '<form method="post" action="' . base_path() . '/admin/announcement/delete" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="announcement_id" value="' . (int)$item['id'] . '">';
            $content .= '<button class="button danger" type="submit">Delete</button>';
            $content .= '</form>';
            $content .= '<details class="announcement-edit">';
            $content .= '<summary class="button">Edit</summary>';
            $content .= '<form method="post" action="' . base_path() . '/admin/announcement/update" class="announcement-edit-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="announcement_id" value="' . (int)$item['id'] . '">';
            $content .= '<div class="grid">';
            $content .= '<div><label>Title</label><input class="input" name="title" value="' . htmlspecialchars($item['title']) . '" required></div>';
            $content .= '<div><label>Content</label><textarea class="input" name="content" rows="4" required>' . htmlspecialchars((string)$item['content']) . '</textarea></div>';
            $content .= '</div>';
            $content .= '<div class="muted" style="margin-top:6px">Supports HTML; will be rendered directly after saving.</div>';
            $content .= '<label style="margin-top:8px"><input type="checkbox" name="active" value="1"' . (((int)$item['active'] === 1) ? ' checked' : '') . '> Enable</label>';
            $content .= '<div style="margin-top:8px"><button class="button primary" type="submit">Save Changes</button></div>';
            $content .= '</form>';
            $content .= '</details>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '</div>';

    $content .= '<div class="card" id="reports"><h2>Report Management</h2>';
    $content .= '<form method="get" action="' . base_path() . '/admin#reports" class="filter-form">';
    $content .= render_hidden_inputs($reportQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>Filter by Status</label><select class="input" name="report_status">';
    $content .= '<option value="pending"' . ($reportStatus === 'pending' ? ' selected' : '') . '>Unhandled</option>';
    $content .= '<option value="handled"' . ($reportStatus === 'handled' ? ' selected' : '') . '>Handled</option>';
    $content .= '<option value="all"' . ($reportStatus === 'all' ? ' selected' : '') . '>All</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">Filter</button></div>';
    $content .= '</form>';
    $content .= '<form id="report-batch-form" method="post" action="' . base_path() . '/admin/report-batch" data-confirm-message="Are you sure you want to delete the selected report records?">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="table-actions">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="reports" form="report-batch-form"> Select All</label>';
    $content .= '<select class="input" name="action">';
    $content .= '<option value="delete">Batch Delete</option>';
    $content .= '</select>';
    $content .= '<button class="button" type="submit">Apply</button>';
    $content .= '</div>';
    $content .= '</form>';
    if (empty($reports)) {
        $content .= '<p class="muted" style="margin-top:12px">No report records.</p>';
    } else {
        $content .= '<table class="table" style="margin-top:12px"><thead><tr><th><input type="checkbox" data-check-all="reports" form="report-batch-form"></th><th>Time</th><th>Share</th><th>User</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($reports as $report) {
            $reportId = (int)($report['id'] ?? 0);
            $shareTitle = htmlspecialchars((string)($report['share_title'] ?? ''));
            $shareSlug = (string)($report['share_slug'] ?? '');
            $shareUrl = $shareSlug !== '' ? share_url($shareSlug) : '';
            $shareUserId = (int)($report['share_user_id'] ?? 0);
            $shareUser = htmlspecialchars((string)($report['share_username'] ?? ''));
            $reporter = (string)($report['reporter_username'] ?? '');
            $reporterLabel = $reporter !== '' ? htmlspecialchars($reporter) : 'Visitor';
            $reportEmailRaw = (string)($report['report_email'] ?? '');
            $reportEmailLabel = $reportEmailRaw !== '' ? $reportEmailRaw : '-';
            $reason = report_reason_label((string)($report['reason_type'] ?? ''));
            $detailRaw = (string)($report['reason_detail'] ?? '');
            $created = htmlspecialchars((string)($report['created_at'] ?? ''));
            $handledAt = (string)($report['handled_at'] ?? '');
            $statusLabel = $handledAt !== '' ? 'Handled' : 'Unhandled';
            $modalId = 'report-view-' . $reportId;
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="report_ids[]" value="' . $reportId . '" data-check-item="reports" form="report-batch-form"></td>';
            $content .= '<td>' . $created . '</td>';
            $content .= '<td>';
            if ($shareUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($shareUrl) . '" target="_blank">' . $shareTitle . '</a>';
                $content .= '<div class="muted">/s/' . htmlspecialchars($shareSlug) . '</div>';
            } else {
                $content .= $shareTitle !== '' ? $shareTitle : 'Deleted';
            }
            $content .= '</td>';
            $content .= '<td>';
            if ($shareUserId > 0) {
                $content .= '<a href="' . base_path() . '/admin?user=' . $shareUserId . '&status=all#shares">' . ($shareUser !== '' ? $shareUser : ('ID:' . $shareUserId)) . '</a>';
            } else {
                $content .= $shareUser !== '' ? $shareUser : '-';
            }
            $content .= '</td>';
            $content .= '<td>' . htmlspecialchars($statusLabel) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button ghost" type="button" data-report-open data-report-target="' . htmlspecialchars($modalId) . '">View Report</button>';
            if ($handledAt === '') {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-handle" class="inline-form">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button" type="submit">Mark Handled</button>';
                $content .= '</form>';
            }
            if ($handledAt !== '') {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-delete" class="inline-form" data-confirm-message="Are you sure you want to delete this report record?">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button ghost" type="submit">Delete Record</button>';
                $content .= '</form>';
            }
            if ($shareSlug !== '') {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-share-delete" class="inline-form" data-confirm-message="Are you sure you want to permanently delete this share? This action is irreversible.">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button danger" type="submit">Permanently Delete Share</button>';
                $content .= '</form>';
            }
            if ($shareUserId > 0) {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-user-disable" class="inline-form" data-confirm-message="Are you sure you want to disable this account?">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button" type="submit">Disable Account</button>';
                $content .= '</form>';
            }
            $content .= '<div class="modal report-modal" id="' . htmlspecialchars($modalId) . '" data-report-modal hidden>';
            $content .= '<div class="modal-backdrop" data-modal-close></div>';
            $content .= '<div class="modal-card">';
            $content .= '<div class="modal-header"><h3>Report Content</h3></div>';
            $content .= '<div class="modal-body">';
            $content .= '<div class="report-grid">';
            $content .= '<div><label>Report Type</label><input class="input" value="' . htmlspecialchars($reason) . '" readonly></div>';
            $content .= '<div><label>Reporter Email</label><input class="input" value="' . htmlspecialchars($reportEmailLabel) . '" readonly></div>';
            $content .= '<div><label>Reporter</label><input class="input" value="' . htmlspecialchars($reporterLabel) . '" readonly></div>';
            $content .= '<div class="report-wide"><label>Additional Notes</label><textarea class="input" rows="4" readonly>' . htmlspecialchars($detailRaw) . '</textarea></div>';
            $content .= '</div>';
            $content .= '<div class="modal-actions"><button class="button" type="button" data-modal-close>Close</button></div>';
            $content .= '</div>';
            $content .= '</div>';
            $content .= '</div>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('reports', ['report_page' => max(1, $reportPage - 1)]) . '">Previous</a>';
    $content .= '<div class="pagination-info">Page ' . $reportPage . ' / ' . $reportPages . ' of ' . $reportTotal . ' reports</div>';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('reports', ['report_page' => min($reportPages, $reportPage + 1)]) . '">Next</a>';
    $content .= '<form method="get" action="' . base_path() . '/admin#reports" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($reportQuery, [
        'report_status' => $reportStatus,
    ]));
    $content .= '<label>Per page</label><select class="input" name="report_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $reportSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>Page Number</label><input class="input small" type="number" name="report_page" min="1" max="' . $reportPages . '" value="' . $reportPage . '">';
    $content .= '<button class="button" type="submit">Go</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="users"><h2>User Management</h2>';
    $content .= '<form method="get" action="' . base_path() . '/admin#users" class="filter-form">';
    $content .= render_hidden_inputs($userQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>Keyword</label><input class="input" name="user_search" placeholder="Username / Email" value="' . htmlspecialchars($userSearch) . '"></div>';
    $content .= '<div><label>Filter by Status</label><select class="input" name="user_status">';
    $content .= '<option value="all"' . ($userStatus === 'all' ? ' selected' : '') . '>All</option>';
    $content .= '<option value="active"' . ($userStatus === 'active' ? ' selected' : '') . '>Active</option>';
    $content .= '<option value="disabled"' . ($userStatus === 'disabled' ? ' selected' : '') . '>Disabled</option>';
    $content .= '</select></div>';
    $content .= '<div><label>Filter by Role</label><select class="input" name="user_role">';
    $content .= '<option value="all"' . ($userRole === 'all' ? ' selected' : '') . '>All</option>';
    $content .= '<option value="admin"' . ($userRole === 'admin' ? ' selected' : '') . '>Admin</option>';
    $content .= '<option value="user"' . ($userRole === 'user' ? ' selected' : '') . '>User</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div class="table-actions">';
    $content .= '<button class="button" type="submit">Filter</button>';
    $content .= '<button class="button" type="button" data-user-create-open>Add Account</button>';
    $content .= '</div>';
    $content .= '</form>';

    $content .= '<form id="user-batch-form" method="post" action="' . base_path() . '/admin/user-batch" data-batch-form="user">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="table-actions">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="users" form="user-batch-form"> Select All</label>';
    $content .= '<select class="input" name="action">';
    $content .= '<option value="disable">Batch Disable</option>';
    $content .= '<option value="enable">Batch Enable</option>';
    $content .= '<option value="delete">Batch Delete</option>';
    $content .= '</select>';
    $content .= '<button class="button" type="submit">Apply</button>';
    $content .= '</div>';
    $content .= '</form>';
    if (empty($users)) {
        $content .= '<p class="muted" style="margin-top:12px">No users found.</p>';
    } else {
        $content .= '<table class="table"><thead><tr><th><input type="checkbox" data-check-all="users" form="user-batch-form"></th><th>Username</th><th>Role</th><th>Status</th><th>Email</th><th>Storage</th><th>Actions</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $status = (int)$u['disabled'] === 1 ? 'Disabled' : 'Active';
            $roleLabel = $u['role'] === 'admin' ? 'Admin' : 'User';
            $limitMb = mb_from_bytes((int)$u['storage_limit_bytes']);
            $limitLabel = (int)$u['storage_limit_bytes'] > 0
                ? format_bytes((int)$u['storage_limit_bytes'])
                : ($defaultLimitBytes > 0 ? 'Default (' . format_bytes($defaultLimitBytes) . ')' : 'Unlimited');
            $usedLabel = format_bytes((int)$u['storage_used_bytes']);
            $disabledAttr = $u['role'] === 'admin' ? ' disabled' : '';
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="user_ids[]" value="' . (int)$u['id'] . '" data-check-item="users" form="user-batch-form"' . $disabledAttr . '></td>';
            $content .= '<td>' . htmlspecialchars($u['username']) . '</td>';
            $content .= '<td>' . htmlspecialchars($roleLabel) . '</td>';
            $content .= '<td>' . htmlspecialchars($status) . '</td>';
            $content .= '<td>' . htmlspecialchars((string)$u['email']) . '</td>';
            $content .= '<td>' . htmlspecialchars($usedLabel) . ' / ' . htmlspecialchars($limitLabel) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button" type="button" data-user-edit data-user-id="' . (int)$u['id'] . '" data-user-name="' . htmlspecialchars($u['username']) . '" data-user-email="' . htmlspecialchars((string)$u['email']) . '" data-user-role="' . htmlspecialchars((string)$u['role']) . '" data-user-disabled="' . (int)$u['disabled'] . '" data-user-limit="' . (int)$limitMb . '">Edit</button>';
            $shareUrl = build_admin_query_url('shares', ['user' => (int)$u['id'], 'status' => 'all']);
            $content .= '<a class="button" href="' . htmlspecialchars($shareUrl) . '">View Shares</a>';
            if ($u['role'] !== 'admin' && (int)$u['id'] !== (int)$admin['id']) {
                $content .= '<form method="post" action="' . base_path() . '/admin/user-delete" class="inline-form" data-confirm-message="Are you sure you want to delete this user and all their shares? This action is irreversible.">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="user_id" value="' . (int)$u['id'] . '">';
                $content .= '<button class="button danger" type="submit">Delete</button>';
                $content .= '</form>';
            }
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '';

    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('users', ['user_page' => max(1, $userPage - 1)]) . '">Previous</a>';
    $content .= '<div class="pagination-info">Page ' . $userPage . ' / ' . $userPages . ' of ' . $totalUsers . ' users</div>';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('users', ['user_page' => min($userPages, $userPage + 1)]) . '">Next</a>';
    $content .= '<form method="get" action="' . base_path() . '/admin#users" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($userQuery, [
        'user_search' => $userSearch,
        'user_status' => $userStatus,
        'user_role' => $userRole,
    ]));
    $content .= '<label>Per page</label><select class="input" name="user_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $userSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>Page Number</label><input class="input small" type="number" name="user_page" min="1" max="' . $userPages . '" value="' . $userPage . '">';
    $content .= '<button class="button" type="submit">Go</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="shares"><h2>Share Management</h2>';
    $content .= '<form method="get" action="' . base_path() . '/admin#shares" class="filter-form">';
    $content .= render_hidden_inputs($shareQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>Keyword</label><input class="input" name="share_search" placeholder="Title / Slug" value="' . htmlspecialchars($shareSearch) . '"></div>';
    $content .= '<div><label>Filter by User</label><select class="input" name="user">';
    $content .= '<option value="0">All Users</option>';
    foreach ($allUsers as $u) {
        $selected = ($filterUser === (int)$u['id']) ? ' selected' : '';
        $content .= '<option value="' . (int)$u['id'] . '"' . $selected . '>' . htmlspecialchars($u['username']) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div><label>Filter by Status</label><select class="input" name="status">';
    $content .= '<option value="all"' . ($filterStatus === 'all' ? ' selected' : '') . '>All</option>';
    $content .= '<option value="active"' . ($filterStatus === 'active' ? ' selected' : '') . '>Active</option>';
    $content .= '<option value="deleted"' . ($filterStatus === 'deleted' ? ' selected' : '') . '>Deleted</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">Filter</button></div>';
    $content .= '</form>';

    $content .= '<form id="share-batch-form" method="post" action="' . base_path() . '/admin/share-batch" data-batch-form="share">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="table-actions">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="shares" form="share-batch-form"> Select All</label>';
    $content .= '<select class="input" name="action">';
    $content .= '<option value="soft_delete">Batch Soft Delete</option>';
    $content .= '<option value="restore">Batch Restore</option>';
    $content .= '<option value="hard_delete">Batch Hard Delete</option>';
    $content .= '</select>';
    $content .= '<button class="button" type="submit" data-confirm="hard_delete">Apply</button>';
    $content .= '</div>';
    $content .= '</form>';
    if (empty($shares)) {
        $content .= '<p class="muted" style="margin-top:12px">No shares found.</p>';
    } else {
        $content .= '<table class="table" style="margin-top:12px"><thead><tr><th><input type="checkbox" data-check-all="shares" form="share-batch-form"></th><th>Title</th><th>Link</th><th>Type</th><th>User</th><th>Password</th><th>Expires</th><th>Visitor Limit</th><th>Status</th><th>Comment Email Notify</th><th>Size</th><th>UpdatedTime</th><th>Actions</th></tr></thead><tbody>';
        foreach ($shares as $share) {
            $type = $share['type'] === 'notebook' ? 'Notebook' : 'Document';
            $status = $share['deleted_at'] ? 'Deleted' : 'Active';
            $size = format_bytes((int)($share['size_bytes'] ?? 0));
            $url = share_url((string)$share['slug']);
            $hasPassword = !empty($share['password_hash']) ? 'Password set' : 'No password';
            $expiresAt = !empty($share['expires_at']) ? date('Y-m-d H:i', (int)$share['expires_at']) : 'Never';
            $visitorLimit = (int)($share['visitor_limit'] ?? 0);
            if ($visitorLimit > 0) {
                $visitorCount = share_visitor_count((int)$share['id']);
                $visitorLabel = $visitorCount . '/' . $visitorLimit;
            } else {
                $visitorLabel = 'Unlimited';
            }
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="share_ids[]" value="' . (int)$share['id'] . '" data-check-item="shares" form="share-batch-form"></td>';
            $content .= '<td>' . htmlspecialchars($share['title']) . '</td>';
            $content .= '<td><a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($url) . '</a></td>';
            $content .= '<td>' . htmlspecialchars($type) . '</td>';
            $content .= '<td>' . htmlspecialchars($share['username'] ?? '') . '</td>';
            $content .= '<td>' . htmlspecialchars($hasPassword) . '</td>';
            $content .= '<td>' . htmlspecialchars($expiresAt) . '</td>';
            $content .= '<td>' . htmlspecialchars($visitorLabel) . '</td>';
            $content .= '<td>' . htmlspecialchars($status) . '</td>';
            $content .= '<td>' . (((int)($share['comment_notify'] ?? 0) === 1) ? 'On' : 'Close') . '</td>';
            $content .= '<td>' . htmlspecialchars($size) . '</td>';
            $content .= '<td>' . htmlspecialchars($share['updated_at']) . '</td>';
            $content .= '<td class="actions">';
            if ($share['deleted_at']) {
                $content .= '<form method="post" action="' . base_path() . '/admin/share-restore" class="inline-form">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
                $content .= '<button class="button" type="submit">Restore</button>';
                $content .= '</form>';
            } else {
                $content .= '<form method="post" action="' . base_path() . '/admin/share-delete" class="inline-form">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
                $content .= '<button class="button" type="submit">Soft Delete</button>';
                $content .= '</form>';
            }
            $content .= '<form method="post" action="' . base_path() . '/admin/share-hard-delete" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
            $content .= '<button class="button danger" type="submit">Hard Delete</button>';
            $content .= '</form>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '';

    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('shares', ['share_page' => max(1, $sharePage - 1)]) . '">Previous</a>';
    $content .= '<div class="pagination-info">Page ' . $sharePage . ' / ' . $sharePages . ' of ' . $totalShares . ' recordsShare</div>';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('shares', ['share_page' => min($sharePages, $sharePage + 1)]) . '">Next</a>';
    $content .= '<form method="get" action="' . base_path() . '/admin#shares" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($shareQuery, [
        'share_search' => $shareSearch,
        'user' => $filterUser,
        'status' => $filterStatus,
    ]));
    $content .= '<label>Per page</label><select class="input" name="share_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $shareSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>Page Number</label><input class="input small" type="number" name="share_page" min="1" max="' . $sharePages . '" value="' . $sharePage . '">';
    $content .= '<button class="button" type="submit">Go</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $chunkTtlHours = $chunkTtlSeconds > 0 ? ($chunkTtlSeconds / 3600) : 2;
    $content .= '<div class="card" id="chunks"><h2>Chunk Cleanup</h2>';
    $content .= '<div class="muted">Showing chunks older than ' . htmlspecialchars(number_format($chunkTtlHours, 1)) . ' hours without update.</div>';
    if (empty($staleChunks)) {
        $content .= '<p class="muted" style="margin-top:12px">No expired chunks.</p>';
    } else {
        $content .= '<form method="post" action="' . base_path() . '/admin/chunk-clean" class="inline-form" style="margin-top:12px">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button danger" type="submit">Clean All Expired Chunks</button>';
        $content .= '</form>';
        $content .= '<table class="table" style="margin-top:12px"><thead><tr><th>Directory</th><th>Last Updated</th><th>Expired</th><th>Actions</th></tr></thead><tbody>';
        foreach ($staleChunks as $chunk) {
            $chunkId = (string)$chunk['id'];
            $mtime = (int)$chunk['mtime'];
            $ageHours = max(0, $chunk['age'] / 3600);
            $content .= '<tr>';
            $content .= '<td><span class="muted">' . htmlspecialchars($chunkId) . '</span></td>';
            $content .= '<td>' . htmlspecialchars(date('Y-m-d H:i', $mtime)) . '</td>';
            $content .= '<td>' . htmlspecialchars(number_format($ageHours, 1)) . ' hours</td>';
            $content .= '<td class="actions">';
            $content .= '<form method="post" action="' . base_path() . '/admin/chunk-delete" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="chunk_id" value="' . htmlspecialchars($chunkId) . '">';
            $content .= '<button class="button danger" type="submit">Delete</button>';
            $content .= '</form>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '</div>';

    $content .= '<div class="card" id="scan"><h2>Banned Word Scan</h2>';
    if ($bannedWordsRaw === '') {
        $content .= '<div class="notice">Please configure banned words in Site Settings first.</div>';
    }
    $content .= '<form method="post" action="' . base_path() . '/admin/scan" data-scan-form="1">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<button class="button" type="submit">Start Scan</button>';
    $content .= '</form>';
    $content .= '<div class="scan-progress" data-scan-progress' . $scanProgressHidden . '>';
    $content .= '<div class="scan-progress__bar"><span data-scan-bar' . $scanBarStyle . '></span></div>';
    $content .= '<div class="scan-progress__status" data-scan-status>' . htmlspecialchars($scanStatusLabel) . '</div>';
    $content .= '<div class="scan-log" data-scan-log>' . $scanLogHtml . '</div>';
    $content .= '</div>';
    if (!empty($scanResults)) {
        $shareIds = [];
        $userIds = [];
        foreach ($scanResults as $hit) {
            if (!isset($hit['share_id'], $hit['user_id'])) {
                continue;
            }
            $shareIds[(int)$hit['share_id']] = true;
            $userIds[(int)$hit['user_id']] = true;
        }
        $scanTimeLabel = $scanAt ? date('Y-m-d H:i', $scanAt) : 'Unknown';
        $content .= '<div class="muted" style="margin-top:10px">Last scan: ' . htmlspecialchars($scanTimeLabel) . ', matched ' . count($scanResults) . ' records, involving ' . count($shareIds) . ' shares / ' . count($userIds) . ' accounts.</div>';
        $content .= '<div class="scan-actions" style="margin-top:10px">';
        $content .= '<form method="post" action="' . base_path() . '/admin/scan/delete" class="inline-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button danger" type="submit">Delete All Violating Shares</button>';
        $content .= '</form>';
        $content .= '<form method="post" action="' . base_path() . '/admin/scan/disable" class="inline-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button" type="submit">Disable All Violating Accounts</button>';
        $content .= '</form>';
        $content .= '</div>';

        $content .= '<form id="scan-batch-form" method="post" action="' . base_path() . '/admin/scan/batch">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<div class="table-actions">';
        $content .= '<label class="checkbox"><input type="checkbox" data-check-all="scan" form="scan-batch-form"> Select All</label>';
        $content .= '<select class="input" name="action">';
        $content .= '<option value="delete">Batch DeleteShare</option>';
        $content .= '<option value="disable">Batch Disable Accounts</option>';
        $content .= '</select>';
        $content .= '<button class="button" type="submit">Apply</button>';
        $content .= '</div>';
        $content .= '</form>';
        $content .= '<table class="table scan-table" style="margin-top:12px"><thead><tr><th><input type="checkbox" data-check-all="scan" form="scan-batch-form"></th><th>Share</th><th>Document/Comment</th><th>User</th><th>Banned Word</th><th>Preview</th><th>Link</th></tr></thead><tbody>';
        foreach ($scanPageResults as $hit) {
            $itemType = (string)($hit['item_type'] ?? 'doc');
            $shareTitleRaw = (string)($hit['share_title'] ?? '');
            $shareTitle = htmlspecialchars($shareTitleRaw);
            $docTitleRaw = (string)($hit['doc_title'] ?? $hit['doc_id'] ?? '');
            $docTitle = htmlspecialchars($docTitleRaw);
            $hPath = trim((string)($hit['hpath'] ?? ''), '/');
            $userName = htmlspecialchars((string)($hit['username'] ?? ''));
            $word = htmlspecialchars((string)($hit['word'] ?? ''));
            $snippet = htmlspecialchars((string)($hit['snippet'] ?? ''));
            $shareUrl = $hit['slug'] ? share_url((string)$hit['slug']) : '';
            $commentId = (int)($hit['comment_id'] ?? 0);
            $commentEmail = trim((string)($hit['comment_email'] ?? ''));
            $commentCreatedAt = (string)($hit['comment_created_at'] ?? '');
            $commentContent = (string)($hit['comment_content'] ?? '');
            $value = (int)($hit['share_id'] ?? 0) . '|' . (int)($hit['user_id'] ?? 0);
            $slug = (string)($hit['slug'] ?? '');
            $detailTitleHtml = htmlspecialchars($docTitleRaw);
            $detailMeta = $hPath;
            $docId = (string)($hit['doc_id'] ?? '');
            if ($itemType === 'doc') {
                $docLabel = trim($docTitleRaw) !== '' ? $docTitleRaw : $docId;
                $docLabel = $docLabel !== '' ? 'Document: ' . $docLabel : 'Document';
                $detailTitleHtml = htmlspecialchars($docLabel);
                $detailMeta = $hPath !== '' ? 'Path: ' . $hPath : '';
                $docUrl = $shareUrl;
                if ($slug !== '' && $docId !== '') {
                    $docUrl = base_url() . build_share_redirect_path($slug, $docId, '');
                }
                if ($docUrl !== '') {
                    $detailTitleHtml = '<a class="scan-comment-link" href="' . htmlspecialchars($docUrl) . '" target="_blank">' . htmlspecialchars($docLabel) . '</a>';
                    $shareUrl = $docUrl;
                }
            }
            if ($itemType === 'comment') {
                $metaParts = [];
                if ($commentEmail !== '') {
                    $metaParts[] = 'Email: ' . $commentEmail;
                }
                if ($commentCreatedAt !== '') {
                    $metaParts[] = 'Time: ' . format_share_datetime($commentCreatedAt);
                }
                $detailMeta = implode(' / ', $metaParts);
                if ($shareUrl !== '' && $commentId > 0) {
                    $shareUrl .= '#comment-' . $commentId;
                }
                if ($commentId > 0) {
                    $detailTitleHtml = '<button type="button" class="scan-comment-link" data-admin-comment-edit="1"'
                        . ' data-admin-comment-id="' . $commentId . '"'
                        . ' data-admin-comment-email="' . htmlspecialchars($commentEmail, ENT_QUOTES) . '"'
                        . ' data-admin-comment-created="' . htmlspecialchars(format_share_datetime($commentCreatedAt), ENT_QUOTES) . '"'
                        . ' data-admin-comment-share="' . htmlspecialchars($shareTitleRaw, ENT_QUOTES) . '"'
                        . ' data-admin-comment-content="' . htmlspecialchars($commentContent, ENT_QUOTES) . '"'
                        . '>Comment #' . $commentId . '</button>';
                } else {
                    $detailTitleHtml = 'Comment';
                }
            }
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="scan_ids[]" value="' . htmlspecialchars($value) . '" data-check-item="scan" form="scan-batch-form"></td>';
            $content .= '<td>' . $shareTitle . '</td>';
            $content .= '<td>' . $detailTitleHtml;
            if ($detailMeta !== '') {
                $content .= '<div class="muted">' . htmlspecialchars($detailMeta) . '</div>';
            }
            $content .= '</td>';
            $content .= '<td>' . $userName . '</td>';
            $content .= '<td>' . $word . '</td>';
            $content .= '<td><div class="scan-snippet">' . $snippet . '</div></td>';
            $content .= '<td>';
            if ($shareUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($shareUrl) . '" target="_blank">Open</a>';
            } else {
                $content .= '-';
            }
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';

        $content .= '<div class="pagination">';
        $content .= '<a class="button ghost" href="' . build_admin_query_url('scan', ['scan_page' => max(1, $scanPage - 1), 'scan_keep' => $scanKeepParam]) . '">Previous</a>';
        $content .= '<div class="pagination-info">Page ' . $scanPage . ' / ' . $scanPages . ' of ' . $scanTotal . ' records</div>';
        $content .= '<a class="button ghost" href="' . build_admin_query_url('scan', ['scan_page' => min($scanPages, $scanPage + 1), 'scan_keep' => $scanKeepParam]) . '">Next</a>';
        $content .= '<form method="get" action="' . base_path() . '/admin#scan" class="pagination-form">';
        $content .= render_hidden_inputs($scanQuery);
        $content .= '<label>Per page</label><select class="input" name="scan_size">';
        foreach ([10, 50, 200, 1000] as $size) {
            $selected = $scanSize === $size ? ' selected' : '';
            $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }
        $content .= '</select>';
        $content .= '<label>Page Number</label><input class="input small" type="number" name="scan_page" min="1" max="' . $scanPages . '" value="' . $scanPage . '">';
        $content .= '<button class="button" type="submit">Go</button>';
        $content .= '</form>';
        $content .= '</div>';
    } else {
        $content .= '<p class="muted" style="margin-top:12px">No scan results.</p>';
    }
    $content .= '</div>';

    $content .= '<div class="card danger-zone"><h2>Danger Zone</h2>';
    $content .= '<p class="muted">Deleting all data will clear users, shares, and announcements, keeping only the initial admin.</p>';
    $content .= '<form method="post" action="' . base_path() . '/admin/reset-data">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Enter "confirm delete" to proceed</label><input class="input" name="confirm_phrase" placeholder="confirm delete" required></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button danger" type="submit">Delete All Data</button></div>';
    $content .= '</form></div>';
$content .= '<div class="modal admin-comment-modal" data-admin-comment-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">Edit Comment</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/comment/edit" data-admin-comment-form>';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<input type="hidden" name="comment_id" value="" data-admin-comment-id>';
    $content .= '<div class="modal-body">';
    $content .= '<div class="muted" data-admin-comment-note hidden></div>';
    $content .= '<textarea class="input" name="content" rows="6" placeholder="Enter comment content" data-admin-comment-content required></textarea>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button" type="button" data-modal-close>Cancel</button>';
    $content .= '<button class="button primary" type="submit">Save Changes</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="modal" data-user-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">Edit User</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/user-update" class="modal-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<input type="hidden" name="user_id" value="" data-user-field="id">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Username</label><input class="input" name="username" data-user-field="username" required></div>';
    $content .= '<div><label>Email</label><input class="input" name="email" data-user-field="email"></div>';
    $content .= '<div><label>Role</label><select class="input" name="role" data-user-field="role"><option value="user">User</option><option value="admin">Admin</option></select></div>';
    $content .= '<div><label>Status</label><select class="input" name="disabled" data-user-field="disabled"><option value="0">Active</option><option value="1">Disabled</option></select></div>';
    $content .= '<div><label>Storage Limit (MB)</label><input class="input" name="limit_mb" type="number" min="0" data-user-field="limit"></div>';
    $content .= '<div><label>New Password (leave blank to keep)</label><input class="input" name="password" type="password" placeholder="********"></div>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button ghost" type="button" data-modal-close>Cancel</button>';
    $content .= '<button class="button primary" type="submit">Save</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';
    $createModalHidden = $createOpen ? '' : ' hidden';
    $roleAdminSelected = $createRole === 'admin' ? ' selected' : '';
    $roleUserSelected = $createRole !== 'admin' ? ' selected' : '';
    $disabledSelected = $createDisabled === '1' ? ' selected' : '';
    $activeSelected = $createDisabled === '1' ? '' : ' selected';
    $content .= '<div class="modal" data-user-create-modal' . $createModalHidden . '>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">Add Account</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/user-create" class="modal-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>Username</label><input class="input" name="username" value="' . htmlspecialchars($createUsername) . '" required></div>';
    $content .= '<div><label>Email</label><input class="input" name="email" value="' . htmlspecialchars($createEmail) . '"></div>';
    $content .= '<div><label>Role</label><select class="input" name="role"><option value="user"' . $roleUserSelected . '>User</option><option value="admin"' . $roleAdminSelected . '>Admin</option></select></div>';
    $content .= '<div><label>Status</label><select class="input" name="disabled"><option value="0"' . $activeSelected . '>Active</option><option value="1"' . $disabledSelected . '>Disabled</option></select></div>';
    $content .= '<div><label>Storage Limit (MB)</label><input class="input" name="limit_mb" type="number" min="0" value="' . htmlspecialchars($createLimitMb) . '"></div>';
    $content .= '<div><label>Password</label><input class="input" name="password" type="password" value="' . htmlspecialchars($createPassword) . '" required></div>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button ghost" type="button" data-modal-close>Cancel</button>';
    $content .= '<button class="button primary" type="submit">Add</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';
    $titleHtml = build_topbar_title('Admin', $admin);
    render_page('Admin', $content, $admin, '', ['layout' => 'app', 'nav' => 'admin-settings', 'title_html' => $titleHtml]);
}

if ($path === '/admin/settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
    $captchaEnabled = isset($_POST['captcha_enabled']) ? '1' : '0';
    $emailVerifyEnabled = isset($_POST['email_verification_enabled']) ? '1' : '0';
    $smtpEnabled = isset($_POST['smtp_enabled']) ? '1' : '0';
    $defaultLimitMb = max(0, (int)($_POST['default_storage_limit_mb'] ?? 0));
    $emailFrom = trim((string)($_POST['email_from'] ?? ''));
    $emailFromName = trim((string)($_POST['email_from_name'] ?? ''));
    $emailSubject = trim((string)($_POST['email_subject'] ?? ''));
    $emailResetSubject = trim((string)($_POST['email_reset_subject'] ?? ''));
    $siteIcp = trim((string)($_POST['site_icp'] ?? ''));
    $siteContactEmail = trim((string)($_POST['site_contact_email'] ?? ''));
    $siteBaseUrl = trim((string)($_POST['site_base_url'] ?? ''));
    $siteHeadHtml = trim((string)($_POST['site_head_html'] ?? ''));
    $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
    $smtpPort = trim((string)($_POST['smtp_port'] ?? ''));
    $smtpSecure = trim((string)($_POST['smtp_secure'] ?? ''));
    $smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
    $smtpPass = trim((string)($_POST['smtp_pass'] ?? ''));
    $bannedWords = trim((string)($_POST['banned_words'] ?? ''));
    if ($bannedWords !== '') {
        $bannedWords = str_replace(["\r\n", "\n", "\r"], '|', $bannedWords);
    }
    set_setting('allow_registration', $allowRegistration);
    set_setting('captcha_enabled', $captchaEnabled);
    set_setting('email_verification_enabled', $emailVerifyEnabled);
    set_setting('smtp_enabled', $smtpEnabled);
    set_setting('default_storage_limit_bytes', (string)bytes_from_mb($defaultLimitMb));
    set_setting('email_from', $emailFrom);
    set_setting('email_from_name', $emailFromName);
    set_setting('email_subject', $emailSubject);
    set_setting('email_reset_subject', $emailResetSubject);
    set_setting('site_icp', $siteIcp);
    set_setting('site_contact_email', $siteContactEmail);
    set_setting('site_base_url', $siteBaseUrl);
    set_setting('site_head_html', $siteHeadHtml);
    set_setting('smtp_host', $smtpHost);
    set_setting('smtp_port', $smtpPort !== '' ? $smtpPort : '587');
    set_setting('smtp_secure', $smtpSecure !== '' ? $smtpSecure : 'tls');
    set_setting('smtp_user', $smtpUser);
    set_setting('smtp_pass', $smtpPass);
    set_setting('banned_words', $bannedWords);
    flash('info', 'Site settings updated');
    redirect('/admin#settings');
}

if ($path === '/admin/announcement/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $title = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;
    if ($title === '' || $content === '') {
        flash('error', 'Announcement title and content cannot be empty');
        redirect('/admin#announcements');
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO announcements (title, content, active, created_by, created_at)
        VALUES (:title, :content, :active, :created_by, :created_at)');
    $stmt->execute([
        ':title' => $title,
        ':content' => $content,
        ':active' => $active,
        ':created_by' => $admin['id'],
        ':created_at' => now(),
    ]);
    flash('info', 'Announcement published');
    redirect('/admin#announcements');
}

if ($path === '/admin/announcement/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;
    if ($id <= 0 || $title === '' || $content === '') {
        flash('error', 'Announcement title and content cannot be empty');
        redirect('/admin#announcements');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE announcements SET title = :title, content = :content, active = :active WHERE id = :id');
    $stmt->execute([
        ':title' => $title,
        ':content' => $content,
        ':active' => $active,
        ':id' => $id,
    ]);
    flash('info', 'Announcement updated');
    redirect('/admin#announcements');
}

if ($path === '/admin/announcement/toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE announcements SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash('info', 'Announcement status updated');
    redirect('/admin#announcements');
}

if ($path === '/admin/announcement/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash('info', 'AnnouncementsDeleted');
    redirect('/admin#announcements');
}

if ($path === '/admin/smtp-test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $email = trim((string)($_POST['test_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid test email address');
        redirect('/admin#settings');
    }
    if (!smtp_enabled()) {
        flash('error', 'Please enable SMTP first');
        redirect('/admin#settings');
    }
    $sent = send_mail($email, 'SMTP Test Email', 'This is an SMTP configuration test email.');
    if ($sent) {
        flash('info', 'Test email sent successfully');
    } else {
        $detail = trim((string)($GLOBALS['smtp_last_error'] ?? ''));
        $message = $detail !== '' ? 'Test email failed: ' . $detail : 'Test email failed';
        flash('error', $message);
    }
    redirect('/admin#settings');
}

if ($path === '/admin/user-batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $ids = $_POST['user_ids'] ?? [];
    $action = (string)($_POST['action'] ?? '');
    $ids = array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (empty($ids)) {
        flash('error', 'Please select a user first');
        redirect('/admin#users');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET disabled = :disabled WHERE id = :id AND role != "admin"');
    $updated = 0;
    $deleted = 0;
    foreach ($ids as $userId) {
        if ($userId === (int)$admin['id']) {
            continue;
        }
        if ($action === 'delete') {
            if (delete_user_account($userId)) {
                $deleted++;
            }
            continue;
        }
        if ($action === 'disable') {
            $stmt->execute([':disabled' => 1, ':id' => $userId]);
            $updated++;
        } elseif ($action === 'enable') {
            $stmt->execute([':disabled' => 0, ':id' => $userId]);
            $updated++;
        }
    }
    if ($action === 'delete') {
        flash('info', 'Deleted ' . $deleted . ' users');
    } else {
        flash('info', 'Handled ' . $updated . ' users');
    }
    redirect('/admin#users');
}

if ($path === '/admin/user-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0 || $userId === (int)$admin['id']) {
        flash('error', 'Cannot delete this user');
        redirect('/admin#users');
    }
    if (!delete_user_account($userId)) {
        flash('error', 'Failed to delete user');
        redirect('/admin#users');
    }
    flash('info', 'UserDeleted');
    redirect('/admin#users');
}

if ($path === '/admin/user-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'user');
    $disabled = (int)($_POST['disabled'] ?? 0);
    $limitMb = max(0, (int)($_POST['limit_mb'] ?? 0));
    $password = (string)($_POST['password'] ?? '');
    if (!in_array($role, ['admin', 'user'], true)) {
        $role = 'user';
    }
    $createForm = [
        'open' => 1,
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'disabled' => (string)($disabled ? 1 : 0),
        'limit_mb' => (string)$limitMb,
        'password' => $password,
    ];
    if ($username === '' || $password === '') {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', 'Please enter username and password');
        redirect('/admin#users');
    }
    if (strlen($password) < 6) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', 'Password must be at least 6 characters');
        redirect('/admin#users');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', 'Invalid email format');
        redirect('/admin#users');
    }
    $pdo = db();
    if ($email !== '') {
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $email]);
        if ($checkEmail->fetch()) {
            $_SESSION['user_create_form'] = $createForm;
            flash('error', 'Email already in use by another account');
            redirect('/admin#users');
        }
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $check->execute([':username' => $username]);
    if ($check->fetch()) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', 'Username already exists');
        redirect('/admin#users');
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $emailVerified = $email !== '' ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
        VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
    try {
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':api_key_hash' => null,
            ':api_key_prefix' => null,
            ':api_key_last4' => null,
            ':disabled' => $disabled ? 1 : 0,
            ':storage_limit_bytes' => bytes_from_mb($limitMb),
            ':storage_used_bytes' => 0,
            ':must_change_password' => 0,
            ':email_verified' => $emailVerified,
            ':created_at' => now(),
            ':updated_at' => now(),
        ]);
    } catch (PDOException $e) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', 'Account creation failed');
        redirect('/admin#users');
    }
    unset($_SESSION['user_create_form']);
    flash('info', 'Account added');
    redirect('/admin#users');
}

if ($path === '/admin/user-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'user');
    $disabled = (int)($_POST['disabled'] ?? 0);
    $limitMb = max(0, (int)($_POST['limit_mb'] ?? 0));
    $password = (string)($_POST['password'] ?? '');
    if ($userId <= 0 || $username === '') {
        flash('error', 'Please enter a valid username');
        redirect('/admin#users');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email format');
        redirect('/admin#users');
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        $role = 'user';
    }
    if ($userId === (int)$admin['id']) {
        $role = 'admin';
        $disabled = 0;
    }
    $pdo = db();
    if ($email !== '') {
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
        $checkEmail->execute([':email' => $email, ':id' => $userId]);
        if ($checkEmail->fetch()) {
            flash('error', 'Email already in use by another account');
            redirect('/admin#users');
        }
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id');
    $check->execute([':username' => $username, ':id' => $userId]);
    if ($check->fetch()) {
        flash('error', 'Username already exists');
        redirect('/admin#users');
    }
    $stmt = $pdo->prepare('UPDATE users SET username = :username, email = :email, role = :role, disabled = :disabled, storage_limit_bytes = :limit, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':role' => $role,
        ':disabled' => $disabled ? 1 : 0,
        ':limit' => bytes_from_mb($limitMb),
        ':updated_at' => now(),
        ':id' => $userId,
    ]);
    if ($password !== '') {
        $pwd = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
        $pwd->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => now(),
            ':id' => $userId,
        ]);
    }
    flash('info', 'User information updated');
    redirect('/admin#users');
}

if ($path === '/admin/share-batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $ids = $_POST['share_ids'] ?? [];
    $action = (string)($_POST['action'] ?? '');
    $ids = array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (empty($ids)) {
        flash('error', 'Please select a share first');
        redirect('/admin#shares');
    }
    $pdo = db();
    $ownerStmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $softStmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id');
    $restoreStmt = $pdo->prepare('UPDATE shares SET deleted_at = NULL WHERE id = :id');
    $affectedOwners = [];
    foreach ($ids as $shareId) {
        $ownerStmt->execute([':id' => $shareId]);
        $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
        if ($action === 'soft_delete') {
            $softStmt->execute([':deleted_at' => now(), ':id' => $shareId]);
            purge_share_access_logs($shareId);
        } elseif ($action === 'restore') {
            $restoreStmt->execute([':id' => $shareId]);
        } elseif ($action === 'hard_delete') {
            hard_delete_share($shareId);
        }
        if ($ownerId) {
            $affectedOwners[$ownerId] = true;
        }
    }
    foreach (array_keys($affectedOwners) as $ownerId) {
        recalculate_user_storage($ownerId);
    }
    flash('info', 'Batch operation completed');
    redirect('/admin#shares');
}

if ($path === '/admin/scan/batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pairs = $_POST['scan_ids'] ?? [];
    $pairs = is_array($pairs) ? $pairs : [$pairs];
    if (empty($pairs)) {
        flash('error', 'Please select a record first');
        redirect('/admin#scan');
    }
    $shareIds = [];
    $userIds = [];
    foreach ($pairs as $value) {
        $parts = explode('|', (string)$value, 2);
        $shareId = (int)($parts[0] ?? 0);
        $userId = (int)($parts[1] ?? 0);
        if ($shareId) {
            $shareIds[$shareId] = true;
        }
        if ($userId) {
            $userIds[$userId] = true;
        }
    }
    $pdo = db();
    if ($action === 'delete' && !empty($shareIds)) {
        $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL');
        foreach (array_keys($shareIds) as $shareId) {
            $stmt->execute([':deleted_at' => now(), ':id' => $shareId]);
            purge_share_access_logs($shareId);
        }
        flash('info', 'Deleted ' . count($shareIds) . ' violating shares');
        redirect('/admin#scan');
    }
    if ($action === 'disable' && !empty($userIds)) {
        $stmt = $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = :id AND role != "admin"');
        foreach (array_keys($userIds) as $userId) {
            $stmt->execute([':id' => $userId]);
        }
        flash('info', 'Disabled ' . count($userIds) . ' violating accounts');
        redirect('/admin#scan');
    }
    flash('error', 'No processable records found');
    redirect('/admin#scan');
}

if ($path === '/admin/user-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === (int)$admin['id']) {
        flash('error', 'Cannot disable yourself');
        redirect('/admin#users');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET disabled = CASE WHEN disabled = 1 THEN 0 ELSE 1 END WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    flash('info', 'User status updated');
    redirect('/admin#users');
}

if ($path === '/admin/user-role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === (int)$admin['id']) {
        flash('error', 'Cannot change your own role');
        redirect('/admin#users');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET role = CASE WHEN role = "admin" THEN "user" ELSE "admin" END WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    flash('info', 'User role updated');
    redirect('/admin#users');
}

if ($path === '/admin/user-limit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $limitMb = max(0, (int)($_POST['limit_mb'] ?? 0));
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET storage_limit_bytes = :limit WHERE id = :id');
    $stmt->execute([
        ':limit' => bytes_from_mb($limitMb),
        ':id' => $userId,
    ]);
    flash('info', 'User storage limit updated');
    redirect('/admin#users');
}

if ($path === '/admin/share-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $pdo = db();
    $ownerStmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $ownerStmt->execute([':id' => $shareId]);
    $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
    $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id');
    $stmt->execute([
        ':deleted_at' => now(),
        ':id' => $shareId,
    ]);
    purge_share_access_logs($shareId);
    if ($ownerId) {
        recalculate_user_storage($ownerId);
    }
    flash('info', 'Share soft deleted');
    redirect('/admin#shares');
}

if ($path === '/admin/share-restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $pdo = db();
    $ownerStmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $ownerStmt->execute([':id' => $shareId]);
    $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
    $stmt = $pdo->prepare('UPDATE shares SET deleted_at = NULL WHERE id = :id');
    $stmt->execute([':id' => $shareId]);
    if ($ownerId) {
        recalculate_user_storage($ownerId);
    }
    flash('info', 'Share restored');
    redirect('/admin#shares');
}

if ($path === '/admin/share-hard-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $deleted = hard_delete_share($shareId);
    if ($deleted === null) {
        flash('error', 'Share not found');
    } else {
        flash('info', 'Share permanently deleted');
    }
    redirect('/admin#shares');
}

if ($path === '/admin/report-handle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        flash('error', 'Report not found');
        redirect('/admin#reports');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE share_reports SET handled_at = :handled_at, handled_by = :handled_by WHERE id = :id');
    $stmt->execute([
        ':handled_at' => now(),
        ':handled_by' => (int)$admin['id'],
        ':id' => $reportId,
    ]);
    flash('info', 'Report handled');
    redirect('/admin#reports');
}

if ($path === '/admin/report-share-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        flash('error', 'Report not found');
        redirect('/admin#reports');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT share_id FROM share_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $shareId = (int)($stmt->fetchColumn() ?: 0);
    if ($shareId <= 0) {
        flash('error', 'Share not found');
        redirect('/admin#reports');
    }
    $deleted = hard_delete_share($shareId);
    if ($deleted === null) {
        flash('error', 'Share not found');
        redirect('/admin#reports');
    }
    flash('info', 'Share permanently deleted');
    redirect('/admin#reports');
}

if ($path === '/admin/report-user-disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    $pdo = db();
    if ($reportId <= 0) {
        flash('error', 'Report not found');
        redirect('/admin#reports');
    }
    $stmt = $pdo->prepare('SELECT share_user_id FROM share_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if ($userId <= 0) {
        flash('error', 'User not found');
        redirect('/admin#reports');
    }
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $roleStmt->execute([':id' => $userId]);
    $role = (string)($roleStmt->fetchColumn() ?? '');
    if ($role === 'admin') {
        flash('error', 'Cannot disable an admin account');
        redirect('/admin#reports');
    }
    $stmt = $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $pdo->prepare('UPDATE share_reports SET handled_at = :handled_at, handled_by = :handled_by WHERE id = :id')
        ->execute([
            ':handled_at' => now(),
            ':handled_by' => (int)$admin['id'],
            ':id' => $reportId,
        ]);
    flash('info', 'Account disabled');
    redirect('/admin#reports');
}

if ($path === '/admin/report-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        flash('error', 'Report not found');
        redirect('/admin#reports');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT handled_at FROM share_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $handledAt = (string)($stmt->fetchColumn() ?? '');
    if ($handledAt === '') {
        flash('error', 'Please handle the report before deleting');
        redirect('/admin#reports');
    }
    $pdo->prepare('DELETE FROM share_reports WHERE id = :id')->execute([':id' => $reportId]);
    flash('info', 'Report record deleted');
    redirect('/admin#reports');
}

if ($path === '/admin/report-batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $ids = $_POST['report_ids'] ?? [];
    $action = (string)($_POST['action'] ?? '');
    $ids = array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (empty($ids) || $action !== 'delete') {
        flash('error', 'Please select a report to delete first');
        redirect('/admin#reports');
    }
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $del = $pdo->prepare('DELETE FROM share_reports WHERE id IN (' . $placeholders . ')');
    $del->execute($ids);
    $deleted = $del->rowCount();
    flash('info', 'Deleted ' . $deleted . ' reports');
    redirect('/admin#reports');
}

if ($path === '/admin/chunk-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    global $config;
    $chunkId = trim((string)($_POST['chunk_id'] ?? ''));
    if ($chunkId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $chunkId)) {
        redirect('/admin#chunks');
    }
    $path = $config['uploads_dir'] . '/chunks/' . $chunkId;
    remove_dir($path);
    $stage = $config['uploads_dir'] . '/staging/' . $chunkId;
    remove_dir($stage);
    redirect('/admin#chunks');
}

if ($path === '/admin/chunk-clean' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    global $config;
    [$ttl] = chunk_cleanup_settings();
    $stale = list_stale_chunks($ttl);
    foreach ($stale as $chunk) {
        $chunkId = (string)($chunk['id'] ?? '');
        if ($chunkId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $chunkId)) {
            continue;
        }
        $path = $config['uploads_dir'] . '/chunks/' . $chunkId;
        remove_dir($path);
        $stage = $config['uploads_dir'] . '/staging/' . $chunkId;
        remove_dir($stage);
    }
    redirect('/admin#chunks');
}

if ($path === '/admin/scan/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $words = get_banned_words();
    if (empty($words)) {
        api_response(400, null, 'Please configure banned words first');
    }
    $total = count_scannable_docs();
    $_SESSION['scan_results'] = [];
    $_SESSION['scan_logs'] = [];
    $_SESSION['scan_done'] = 0;
    $_SESSION['scan_at'] = time();
    $_SESSION['scan_total'] = $total;
    api_response(200, ['total' => $total]);
}

if ($path === '/admin/scan/step' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $words = get_banned_words();
    if (empty($words)) {
        api_response(400, null, 'Please configure banned words first');
    }
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $limit = max(1, min(200, (int)($_POST['limit'] ?? 50)));
    $total = (int)($_SESSION['scan_total'] ?? count_scannable_docs());
    $batch = scan_banned_shares_batch($words, $offset, $limit);
    $existing = $_SESSION['scan_results'] ?? [];
    if (!is_array($existing)) {
        $existing = [];
    }
    $existing = array_merge($existing, $batch['hits']);
    $_SESSION['scan_results'] = $existing;
    $existingLogs = $_SESSION['scan_logs'] ?? [];
    if (!is_array($existingLogs)) {
        $existingLogs = [];
    }
    $existingLogs = array_merge($existingLogs, $batch['logs']);
    $_SESSION['scan_logs'] = $existingLogs;
    $_SESSION['scan_at'] = time();
    $nextOffset = $offset + (int)$batch['count'];
    $done = $nextOffset >= $total || $batch['count'] === 0;
    $_SESSION['scan_done'] = $done ? 1 : 0;
    api_response(200, [
        'nextOffset' => $nextOffset,
        'done' => $done,
        'hitCount' => count($existing),
        'logs' => $batch['logs'],
        'total' => $total,
    ]);
}

if ($path === '/admin/scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $words = get_banned_words();
    if (empty($words)) {
        flash('error', 'Please configure banned words first');
        redirect('/admin#scan');
    }
    $results = scan_banned_shares($words);
    $_SESSION['scan_results'] = $results;
    $_SESSION['scan_logs'] = [];
    $_SESSION['scan_done'] = 1;
    $_SESSION['scan_at'] = time();
    flash('info', 'Scan complete, matched ' . count($results) . ' records');
    redirect('/admin#scan');
}

if ($path === '/admin/scan/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $results = $_SESSION['scan_results'] ?? [];
    if (empty($results) || !is_array($results)) {
        flash('error', 'No scan results');
        redirect('/admin#scan');
    }
    $shareIds = [];
    foreach ($results as $hit) {
        if (isset($hit['share_id'])) {
            $shareIds[(int)$hit['share_id']] = true;
        }
    }
    if (empty($shareIds)) {
        flash('error', 'No shares to delete');
        redirect('/admin#scan');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL');
    foreach (array_keys($shareIds) as $shareId) {
        $stmt->execute([':deleted_at' => now(), ':id' => $shareId]);
    }
    flash('info', 'Deleted ' . count($shareIds) . ' violating shares');
    redirect('/admin#scan');
}

if ($path === '/admin/scan/disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $results = $_SESSION['scan_results'] ?? [];
    if (empty($results) || !is_array($results)) {
        flash('error', 'No scan results');
        redirect('/admin#scan');
    }
    $userIds = [];
    foreach ($results as $hit) {
        if (isset($hit['user_id'])) {
            $userIds[(int)$hit['user_id']] = true;
        }
    }
    if (empty($userIds)) {
        flash('error', 'No accounts to disable');
        redirect('/admin#scan');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = :id AND role != "admin"');
    foreach (array_keys($userIds) as $userId) {
        $stmt->execute([':id' => $userId]);
    }
    flash('info', 'Disabled ' . count($userIds) . ' violating accounts');
    redirect('/admin#scan');
}

if ($path === '/admin/comment/edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $commentId = max(0, (int)($_POST['comment_id'] ?? 0));
    $content = trim((string)($_POST['content'] ?? ''));
    if ($commentId <= 0) {
        flash('error', 'Missing comment ID');
        redirect('/admin#scan');
    }
    if ($content === '') {
        flash('error', 'Comment content cannot be empty');
        redirect('/admin#scan');
    }
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($contentLength > 2000) {
        flash('error', 'Comment content too long');
        redirect('/admin#scan');
    }
    $bannedWords = get_banned_words();
    if (!empty($bannedWords)) {
        $hit = find_banned_word($content, $bannedWords);
        if ($hit) {
            flash('error', 'Triggered banned word: ' . $hit['word']);
            redirect('/admin#scan');
        }
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT share_comments.*, shares.user_id AS share_user_id FROM share_comments
        JOIN shares ON share_comments.share_id = shares.id
        WHERE share_comments.id = :id');
    $stmt->execute([':id' => $commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comment) {
        flash('error', 'Comment not found');
        redirect('/admin#scan');
    }
    $shareId = (int)($comment['share_id'] ?? 0);
    $shareUserId = (int)($comment['share_user_id'] ?? 0);
    if ($shareId <= 0 || $shareUserId <= 0) {
        flash('error', 'Associated share for comment not found');
        redirect('/admin#scan');
    }
    $commentEmail = (string)($comment['email'] ?? '');
    $newSize = calculate_comment_size($commentEmail, $content);
    $oldSize = (int)($comment['size_bytes'] ?? 0);
    $oldAssets = extract_comment_asset_paths((string)($comment['content'] ?? ''), $shareId);
    $newAssets = extract_comment_asset_paths($content, $shareId);
    $removeAssets = array_values(array_diff($oldAssets, $newAssets));
    if (!empty($removeAssets)) {
        $removeAssets = filter_unused_comment_assets($shareId, $removeAssets, [$commentId]);
    }
    $removeAssetSize = sum_share_asset_sizes($shareId, $removeAssets);
    $delta = $newSize - $oldSize;
    $netDelta = $delta - $removeAssetSize;
    if ($netDelta > 0) {
        $owner = get_user_by_id($shareUserId);
        if (!$owner) {
            flash('error', 'Share owner not found');
            redirect('/admin#scan');
        }
        $used = recalculate_user_storage((int)$owner['id']);
        $limit = get_user_limit_bytes($owner);
        if ($limit > 0 && ($used + $netDelta) > $limit) {
            flash('error', 'Insufficient storage space to save changes');
            redirect('/admin#scan');
        }
    }
    $update = $pdo->prepare('UPDATE share_comments SET content = :content, size_bytes = :size_bytes WHERE id = :id');
    $update->execute([
        ':content' => $content,
        ':size_bytes' => $newSize,
        ':id' => $commentId,
    ]);
    $deletedAssetSize = delete_comment_assets($shareId, $removeAssets);
    $totalDelta = $delta - $deletedAssetSize;
    if ($totalDelta !== 0) {
        adjust_share_size($shareId, $totalDelta);
        adjust_user_storage($shareUserId, $totalDelta);
    }
    flash('info', 'Comment updated');
    redirect('/admin#scan');
}

if ($path === '/admin/reset-data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $phrase = trim((string)($_POST['confirm_phrase'] ?? ''));
    if ($phrase !== 'confirm delete') {
        flash('error', 'Incorrect confirmation passphrase');
        redirect('/admin');
    }
    reset_database();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    flash('info', 'Data has been reset; please log in with the default admin credentials');
    redirect('/login');
}

if ($path === '/') {
    $user = current_user();
    if ($user) {
        redirect('/dashboard');
    }
    $allowRegistration = allow_registration();
    $siteIcp = get_setting('site_icp', '');
    $siteContactEmail = get_setting('site_contact_email', '');
    $appName = htmlspecialchars($config['app_name']);
    $versionText = site_version();
    $versionHtml = '';
    if ($versionText !== '') {
        $versionLabel = $versionText;
        if (stripos($versionLabel, 'v') !== 0) {
            $versionLabel = 'v' . $versionLabel;
        }
        $versionHtml = '<span class="home-version">' . htmlspecialchars($versionLabel) . '</span>';
    }
    $loginUrl = base_path() . '/login';
    $registerUrl = base_path() . '/register';

    $content = '<section class="home-hero">';
    $content .= '<div class="home-hero__main">';
    $content .= '<div class="home-badge">Official Markdown Export</div>';
    $content .= '<h1 class="home-title">' . $appName . $versionHtml . '</h1>';
    $content .= '<p class="home-lead">Share documents and notebooks via controlled external links: auto-sync, password & expiry controls, unified link management.</p>';
    $content .= '<div class="home-actions">';
    $content .= '<a class="button primary" href="' . $loginUrl . '">Login</a>';
    if ($allowRegistration) {
        $content .= '<a class="button" href="' . $registerUrl . '">Register</a>';
    }
    $content .= '</div>';
    $content .= '<div class="home-hero__meta">Documents / Notebooks · Access Control · Multi-user Collaboration</div>';
    $content .= '</div>';
    $content .= '<div class="home-hero__visual">';
    $content .= '<div class="hero-card hero-card--preview">';
    $content .= '<div class="hero-card__title">Share Preview</div>';
    $content .= '<div class="hero-card__lines"><span></span><span></span><span></span><span></span></div>';
    $content .= '<div class="hero-card__chips"><span>Views 128</span><span>Expires 2026-01-10</span><span>Password Protected</span></div>';
    $content .= '</div>';
    $content .= '<div class="hero-card hero-card--flow">';
    $content .= '<div class="hero-card__title">Sync Workflow</div>';
    $content .= '<ol class="hero-flow"><li>Generate API Key</li><li>One-click Verify & Sync</li><li>Copy Link to Share</li></ol>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="home-metrics">';
    $content .= '<div class="home-metric"><strong>Documents & Notebooks</strong><span>Preserves hierarchy and directory structure</span></div>';
    $content .= '<div class="home-metric"><strong>Access & Expiry</strong><span>Password, expiry, and disabled state all supported</span></div>';
    $content .= '<div class="home-metric"><strong>Centralized Management</strong><span>Unified share list for search and tracking</span></div>';
    $content .= '</section>';

    $content .= '<section class="home-section">';
    $content .= '<h2>Key Features</h2>';
    $content .= '<div class="home-grid">';
    $content .= '<div class="home-card"><h3>Full Markdown Support</h3><p>Math, tables, task lists, Mermaid, and code highlighting all supported.</p></div>';
    $content .= '<div class="home-card"><h3>Unified Link Management</h3><p>Soft delete / restore / hard delete, share status at a glance.</p></div>';
    $content .= '<div class="home-card"><h3>Multi-user & Moderation</h3><p>Account management, announcements, and banned word scanning in one place.</p></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="home-section">';
    $content .= '<h2>Getting Started</h2>';
    $content .= '<div class="home-steps">';
    $content .= '<div class="home-step"><span>1</span> LoginAdminGenerate API Key</div>';
    $content .= '<div class="home-step"><span>2</span> Enter the URL and Key in the plugin</div>';
    $content .= '<div class="home-step"><span>3</span> One-click verify and sync</div>';
    $content .= '<div class="home-step"><span>4</span> Copy link to share externally</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="home-section">';
    $content .= '<h2>Use Cases</h2>';
    $content .= '<div class="home-grid">';
    $content .= '<div class="home-card"><h3>Product Docs</h3><p>Publish manuals externally, auto-sync updates.</p></div>';
    $content .= '<div class="home-card"><h3>Team Knowledge Base</h3><p>Internal sharing, access control, expiry management.</p></div>';
    $content .= '<div class="home-card"><h3>Courses & Tutorials</h3><p>Long-form content with clear, navigable directory.</p></div>';
    $content .= '</div>';
    $content .= '</section>';

    $footerItems = [];
    if (trim((string)$siteIcp) !== '') {
        $footerItems[] = 'ICP: ' . htmlspecialchars((string)$siteIcp);
    }
    if (trim((string)$siteContactEmail) !== '') {
        $footerItems[] = 'Contact: ' . htmlspecialchars((string)$siteContactEmail);
    }
    if (!empty($footerItems)) {
        $content .= '<footer class="home-footer">' . implode(' · ', $footerItems) . '</footer>';
    }
    render_page('Home', $content, null);
}

render_404_page();

