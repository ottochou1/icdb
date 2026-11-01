<?php
/**
 * 华政选课 · PHP 版通用库
 * 数据库访问、响应输出、会话、业务对象序列化
 * 兼容 PHP 7.4+
 */

define('COOKIE_NAME', 'hzxc_session');

class ApiException extends Exception
{
    private $status;
    private $errorCode;

    public function __construct($message, $status = 400, $errorCode = '')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    public function getStatus()
    {
        return $this->status;
    }

    /** 机器可读的错误码，前端据此做引导（如 NEED_VERIFY） */
    public function getErrorCode()
    {
        return $this->errorCode;
    }
}

/* ------------------------- 配置与数据库 ------------------------- */

function app_config()
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new ApiException('站点尚未安装，请先访问 install.php 完成安装', 500);
        }
        $loaded = require $file;
        $cfg = is_array($loaded) ? $loaded : [];
        $cfg = array_merge([
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => '',
            'db_user' => 'root',
            'db_pass' => '',
            'db_charset' => 'utf8mb4',
            'table_prefix' => 'hz_',
            'session_days' => 30,
        ], $cfg);
    }
    return $cfg;
}

/** 带前缀的表名 */
function t($name)
{
    $cfg = app_config();
    return '`' . $cfg['table_prefix'] . $name . '`';
}

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = app_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['db_host'],
        $cfg['db_port'],
        $cfg['db_name'],
        $cfg['db_charset']
    );
    try {
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['db_charset']} COLLATE {$cfg['db_charset']}_unicode_ci",
        ]);
    } catch (PDOException $e) {
        throw new ApiException('数据库连接失败：' . $e->getMessage(), 500);
    }
    return $pdo;
}

function q($sql, $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_one($sql, $params = [])
{
    $row = q($sql, $params)->fetch();
    return $row ?: null;
}

function q_val($sql, $params = [], $default = 0)
{
    $row = q($sql, $params)->fetchColumn();
    return $row === false ? $default : $row;
}

function q_all($sql, $params = [])
{
    return q($sql, $params)->fetchAll();
}

/* ------------------------- 响应 ------------------------- */

function json_out($payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok_response($data = null)
{
    json_out(['ok' => true, 'data' => $data]);
}

function fail_response($message, $status = 400, $errorCode = '')
{
    $payload = ['ok' => false, 'error' => $message];
    if ($errorCode !== '') {
        $payload['code'] = $errorCode;
    }
    json_out($payload, $status);
}

/* ------------------------- 输入 ------------------------- */

function input_body()
{
    static $cache = null;
    if ($cache === null) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        $cache = is_array($decoded) ? $decoded : [];
    }
    return $cache;
}

function param_raw($key, $default = null)
{
    $body = input_body();
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    // multipart 表单（文件上传）时，额外字段会落在 $_POST
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $default;
}

function param_str($key, $default = '', $max = 200)
{
    $value = param_raw($key);
    if ($value === null || is_array($value)) {
        return $default;
    }
    $value = trim((string) $value);
    if ($max > 0 && function_exists('mb_substr') && mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    } elseif ($max > 0 && strlen($value) > $max * 4) {
        $value = substr($value, 0, $max * 4);
    }
    return $value === '' ? $default : $value;
}

function param_int($key, $default = 0, $min = PHP_INT_MIN, $max = PHP_INT_MAX)
{
    $value = param_raw($key);
    if ($value === null || $value === '') {
        return $default;
    }
    $n = (int) $value;
    return max($min, min($max, $n));
}

function param_bool($key)
{
    $value = param_raw($key);
    return $value === true || $value === '1' || $value === 1 || $value === 'true' || $value === 'on';
}

function param_tags($key, $max = 5)
{
    $value = param_raw($key, []);
    if (!is_array($value)) {
        $value = array_filter(array_map('trim', explode(',', (string) $value)));
    }
    $out = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) {
            continue;
        }
        $item = trim((string) $item);
        if ($item !== '') {
            $out[] = mb_substr($item, 0, 12);
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/* ------------------------- 工具 ------------------------- */

function now_ms()
{
    return (int) round(microtime(true) * 1000);
}

function new_id($prefix = '')
{
    return $prefix . date('ymdHis') . bin2hex(random_bytes(5));
}

function is_email_addr($value)
{
    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

function like_escape($keyword)
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
}

function csv_parse($text)
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $rows = [];
    $row = [];
    $field = '';
    $inQuotes = false;
    $len = strlen($text);

    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];
        if ($inQuotes) {
            if ($ch === '"') {
                if ($i + 1 < $len && $text[$i + 1] === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $inQuotes = false;
                }
            } else {
                $field .= $ch;
            }
            continue;
        }
        if ($ch === '"') {
            $inQuotes = true;
        } elseif ($ch === ',') {
            $row[] = $field;
            $field = '';
        } elseif ($ch === "\n") {
            $row[] = $field;
            $field = '';
            if (count(array_filter($row, function ($c) { return trim((string) $c) !== ''; }))) {
                $rows[] = $row;
            }
            $row = [];
        } else {
            $field .= $ch;
        }
    }
    $row[] = $field;
    if (count(array_filter($row, function ($c) { return trim((string) $c) !== ''; }))) {
        $rows[] = $row;
    }
    return $rows;
}

/*
 * CSV 与 Excel 共用的「表格行 → 课程数组」映射。
 * $keepInvalid = true 时保留课程名为空的行，交由调用方（如后台导入）统计并提示，
 * 避免这类行被静默丢弃、管理员却以为都导进去了。
 */
function rows_to_courses($rows, $keepInvalid = false)
{
    if (!$rows) {
        return [];
    }
    $aliases = [
        '课程名称' => 'name', '课程名' => 'name', 'name' => 'name',
        '老师' => 'teacher', '教师' => 'teacher', '任课教师' => 'teacher', '老师名字' => 'teacher', 'teacher' => 'teacher',
        '课程代码' => 'code', '课程编号' => 'code', '课号' => 'code', 'code' => 'code',
        '学分' => 'credit', 'credit' => 'credit',
        '开课学院' => 'college', '学院' => 'college', 'college' => 'college',
        '课程类别' => 'category', '类别' => 'category', 'category' => 'category',
        '简介' => 'intro', '课程简介' => 'intro', 'intro' => 'intro',
    ];
    $header = array_map('trim', $rows[0]);
    $hasHeader = false;
    foreach ($header as $h) {
        if (isset($aliases[$h])) {
            $hasHeader = true;
            break;
        }
    }
    $map = $hasHeader
        ? array_map(function ($h) use ($aliases) { return isset($aliases[$h]) ? $aliases[$h] : null; }, $header)
        : ['name', 'teacher', 'code', 'credit', 'college', 'category', 'intro'];
    $body = $hasHeader ? array_slice($rows, 1) : $rows;

    $out = [];
    foreach ($body as $cells) {
        $obj = [];
        foreach ($map as $i => $key) {
            if (!$key) {
                continue;
            }
            $obj[$key] = trim((string) ($cells[$i] ?? ''));
        }
        if (!empty($obj['name']) || $keepInvalid) {
            $out[] = $obj;
        }
    }
    return $out;
}

function csv_to_courses($text, $keepInvalid = false)
{
    return rows_to_courses(csv_parse($text), $keepInvalid);
}

function courses_to_csv($courses)
{
    $header = ['课程名称', '老师', '课程代码', '学分', '开课学院', '课程类别', '课程简介'];
    $lines = [implode(',', $header)];
    $esc = function ($v) { return '"' . str_replace('"', '""', (string) ($v ?? '')) . '"'; };
    foreach ($courses as $c) {
        $lines[] = implode(',', array_map($esc, [
            $c['name'], $c['teacher'], $c['code'], $c['credit'], $c['college'], $c['category'], $c['intro'],
        ]));
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines);
}

/* ------------------------- Excel (xlsx)：不依赖 zip 扩展 ------------------------- */

/*
 * 很多虚拟主机没装 php-zip，这里用 zlib 手写一个最小 ZIP 读取器。
 * xlsx 本质就是一个 zip 包，只需支持 stored(0) 与 deflate(8) 两种压缩方式。
 */
function zip_entries($data)
{
    $len = strlen($data);
    if ($len < 22) {
        return [];
    }
    // 从末尾往前找 End Of Central Directory 签名 PK\x05\x06
    $eocd = -1;
    $floor = max(0, $len - 22 - 65535);
    for ($i = $len - 22; $i >= $floor; $i--) {
        if (substr($data, $i, 4) === "PK\x05\x06") {
            $eocd = $i;
            break;
        }
    }
    if ($eocd < 0) {
        return [];
    }
    $count = unpack('v', substr($data, $eocd + 10, 2))[1];
    $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];

    $entries = [];
    $p = $cdOffset;
    for ($n = 0; $n < $count; $n++) {
        if ($p + 46 > $len || substr($data, $p, 4) !== "PK\x01\x02") {
            break;
        }
        $method = unpack('v', substr($data, $p + 10, 2))[1];
        $compSize = unpack('V', substr($data, $p + 20, 4))[1];
        $nameLen = unpack('v', substr($data, $p + 28, 2))[1];
        $extraLen = unpack('v', substr($data, $p + 30, 2))[1];
        $commentLen = unpack('v', substr($data, $p + 32, 2))[1];
        $localOffset = unpack('V', substr($data, $p + 42, 4))[1];
        $name = substr($data, $p + 46, $nameLen);
        $p += 46 + $nameLen + $extraLen + $commentLen;

        if ($localOffset + 30 > $len || substr($data, $localOffset, 4) !== "PK\x03\x04") {
            continue;
        }
        $lNameLen = unpack('v', substr($data, $localOffset + 26, 2))[1];
        $lExtraLen = unpack('v', substr($data, $localOffset + 28, 2))[1];
        $start = $localOffset + 30 + $lNameLen + $lExtraLen;
        if ($compSize <= 0 || $start + $compSize > $len) {
            continue;
        }

        $raw = substr($data, $start, $compSize);
        if ($method === 0) {
            $content = $raw;
        } elseif ($method === 8) {
            $content = @gzinflate($raw);
            if ($content === false) {
                $content = @gzuncompress($raw);
            }
        } else {
            continue;
        }
        if ($content !== false && $content !== null) {
            $entries[$name] = $content;
        }
    }
    return $entries;
}

/* 单元格引用 "B3" → 列索引 1 */
function xlsx_col_index($ref)
{
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $ref));
    if ($letters === '') {
        return 0;
    }
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

function xlsx_to_rows($binary)
{
    if (!function_exists('gzinflate') || !function_exists('simplexml_load_string')) {
        return [];
    }
    $entries = zip_entries($binary);
    if (!$entries) {
        return [];
    }

    // 共享字符串表
    $shared = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        $xml = @simplexml_load_string($entries['xl/sharedStrings.xml']);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } elseif (isset($si->r)) {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string) $r->t;
                    }
                    $shared[] = $text;
                } else {
                    $shared[] = '';
                }
            }
        }
    }

    $sheetKey = null;
    foreach (array_keys($entries) as $k) {
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $k)) {
            $sheetKey = $k;
            break;
        }
    }
    if ($sheetKey === null) {
        return [];
    }
    $sheet = @simplexml_load_string($entries[$sheetKey]);
    if (!$sheet || !isset($sheet->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $idx = xlsx_col_index((string) $c['r']);
            $type = (string) $c['t'];
            if ($type === 's') {
                $i = isset($c->v) ? (int) $c->v : -1;
                $value = isset($shared[$i]) ? $shared[$i] : '';
            } elseif ($type === 'inlineStr') {
                $value = isset($c->is->t) ? (string) $c->is->t : '';
            } else {
                $value = isset($c->v) ? (string) $c->v : '';
            }
            $cells[$idx] = trim($value);
        }
        if (!$cells) {
            continue;
        }
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = isset($cells[$i]) ? $cells[$i] : '';
        }
        while (count($line) && end($line) === '') {
            array_pop($line);
        }
        if (count(array_filter($line, function ($x) { return $x !== ''; }))) {
            $rows[] = $line;
        }
    }
    return $rows;
}

function xlsx_to_courses($binary, $keepInvalid = false)
{
    return rows_to_courses(xlsx_to_rows($binary), $keepInvalid);
}

/* ------------------------- 用户与会话 ------------------------- */

function set_session_cookie($token)
{
    $days = (int) app_config()['session_days'];
    $expire = time() + $days * 86400;
    if (PHP_VERSION_ID >= 70300) {
        setcookie(COOKIE_NAME, $token, [
            'expires' => $expire,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    } else {
        setcookie(COOKIE_NAME, $token, $expire, '/; SameSite=Lax', '', !empty($_SERVER['HTTPS']), true);
    }
}

function clear_session_cookie()
{
    if (PHP_VERSION_ID >= 70300) {
        setcookie(COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(COOKIE_NAME, '', time() - 3600, '/; SameSite=Lax', '', false, true);
    }
}

function current_user()
{
    static $cached = null;
    static $called = false;
    if ($called) {
        return $cached;
    }
    $called = true;
    $token = isset($_COOKIE[COOKIE_NAME]) ? (string) $_COOKIE[COOKIE_NAME] : '';
    if ($token === '') {
        return $cached = null;
    }
    $row = q_one(
        'SELECT u.* FROM ' . t('sessions') . ' s INNER JOIN ' . t('users') . ' u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > ? LIMIT 1',
        [$token, now_ms()]
    );
    return $cached = ($row ?: null);
}

function require_login()
{
    $user = current_user();
    if (!$user) {
        throw new ApiException('请先登录后再操作', 401);
    }
    return $user;
}

/** 是否具备参与权限（发帖 / 评分 / 评论 / 点赞 / 私信）。管理员豁免 */
function can_participate($user)
{
    if (!$user) {
        return false;
    }
    if (!empty($user['banned'])) {
        return false;
    }
    return !empty($user['is_admin']) || !empty($user['verified']);
}

/** 需要登录且已通过学生认证，否则抛 NEED_VERIFY 让前端引导去认证 */
function require_verified()
{
    $user = require_login();
    if (!empty($user['banned'])) {
        throw new ApiException('账号已被限制发言，如有疑问请联系管理员', 403, 'BANNED');
    }
    if (!can_participate($user)) {
        throw new ApiException('完成学生认证后才能发帖、评分和评论', 403, 'NEED_VERIFY');
    }
    return $user;
}

function require_admin()
{
    $user = require_login();
    if (empty($user['is_admin'])) {
        throw new ApiException('需要管理员权限', 403, 'NOT_ADMIN');
    }
    return $user;
}

/** 需要管理员明确授予课程上传权限；管理员始终拥有该权限。 */
function require_course_uploader()
{
    $user = require_verified();
    if (empty($user['is_admin']) && empty($user['can_upload_courses'])) {
        throw new ApiException('课程上传权限需要管理员单独授权', 403, 'NO_COURSE_UPLOAD_PERMISSION');
    }
    return $user;
}

/** 读取站点设置。升级前 settings 表不存在时安全回落到默认值。 */
function setting_get($key, $default = '')
{
    try {
        return (string) q_val(
            'SELECT setting_value FROM ' . t('settings') . ' WHERE setting_key = ? LIMIT 1',
            [(string) $key],
            $default
        );
    } catch (Throwable $e) {
        return (string) $default;
    }
}

function setting_set($key, $value)
{
    q(
        'INSERT INTO ' . t('settings') . ' (setting_key, setting_value, updated_at) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
        [(string) $key, (string) $value, now_ms()]
    );
}

function maintenance_mode()
{
    return setting_get('maintenance_mode', '0') === '1';
}

function get_user($id)
{
    static $cache = [];
    if ($id === '' || $id === null) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = q_one('SELECT * FROM ' . t('users') . ' WHERE id = ? LIMIT 1', [$id]) ?: null;
    }
    return $cache[$id];
}

function public_user($row, $self = false)
{
    if (!$row) {
        return null;
    }
    $out = [
        'id' => $row['id'],
        'nickname' => $row['nickname'],
        'bio' => (string) $row['bio'],
        'avatarColor' => $row['avatar_color'] ?: '#2f7d51',
        'grade' => (string) $row['grade'],
        'joinedAt' => (int) $row['joined_at'],
        'isAdmin' => (bool) $row['is_admin'],
        'verified' => !empty($row['verified']) || !empty($row['is_admin']),
        'verifiedAt' => (int) ($row['verified_at'] ?? 0),
        'banned' => !empty($row['banned']),
    ];
    if ($self) {
        $out['email'] = $row['email'];
        $out['studentId'] = (string) $row['student_id'];
        $out['banReason'] = (string) ($row['ban_reason'] ?? '');
        $out['canPost'] = can_participate($row);
        $out['canUploadCourses'] = !empty($row['is_admin']) || !empty($row['can_upload_courses']);
    }
    return $out;
}

$AVATAR_COLORS = ['#2f7d51', '#3d6ea8', '#b5651d', '#8a5cb8', '#c04851', '#2e8b8b', '#a06a3c', '#5a6b8c'];

function avatar_color_for($seed)
{
    global $AVATAR_COLORS;
    $sum = 0;
    $chars = preg_split('//u', (string) $seed, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chars as $ch) {
        $code = mb_ord($ch);
        $sum += $code === false ? 0 : $code;
    }
    return $AVATAR_COLORS[$sum % count($AVATAR_COLORS)];
}

if (!function_exists('mb_ord')) {
    function mb_ord($char)
    {
        $code = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8'));
        return $code ? $code[1] : 0;
    }
}

/* ------------------------- 点赞 ------------------------- */

function like_count($type, $id)
{
    return (int) q_val('SELECT COUNT(*) FROM ' . t('likes') . ' WHERE target_type = ? AND target_id = ?', [$type, $id]);
}

function has_liked($type, $id, $userId)
{
    if (!$userId) {
        return false;
    }
    return (bool) q_val(
        'SELECT COUNT(*) FROM ' . t('likes') . ' WHERE target_type = ? AND target_id = ? AND user_id = ?',
        [$type, $id, $userId]
    );
}

function toggle_like($type, $id)
{
    $user = require_verified();
    $existing = q_one(
        'SELECT id FROM ' . t('likes') . ' WHERE target_type = ? AND target_id = ? AND user_id = ? LIMIT 1',
        [$type, $id, $user['id']]
    );
    if ($existing) {
        q('DELETE FROM ' . t('likes') . ' WHERE id = ?', [$existing['id']]);
        return ['liked' => false, 'likeCount' => like_count($type, $id)];
    }
    q('INSERT INTO ' . t('likes') . ' (id, target_type, target_id, user_id, created_at) VALUES (?,?,?,?,?)',
        [new_id('l_'), $type, $id, $user['id'], now_ms()]);
    return ['liked' => true, 'likeCount' => like_count($type, $id)];
}

/* ------------------------- 业务对象序列化 ------------------------- */

function course_stats($courseId)
{
    $row = q_one(
        'SELECT COUNT(*) AS c, COALESCE(AVG(rating), 0) AS avg FROM ' . t('reviews') . ' WHERE course_id = ?',
        [$courseId]
    );
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach (q_all('SELECT rating, COUNT(*) AS c FROM ' . t('reviews') . ' WHERE course_id = ? GROUP BY rating', [$courseId]) as $r) {
        $dist[(int) $r['rating']] = (int) $r['c'];
    }
    $postCount = (int) q_val('SELECT COUNT(*) FROM ' . t('posts') . ' WHERE course_id = ?', [$courseId]);
    return [
        'reviewCount' => (int) $row['c'],
        'postCount' => $postCount,
        'avgRating' => round((float) $row['avg'], 2),
        'dist' => $dist,
    ];
}

/* ------------------------- 课程代码（一对多） ------------------------- */

/** 取一门课的所有课程代码；新表为空时回退到 courses.code */
function course_codes_of($courseId, $fallbackCode = '')
{
    $rows = q_all('SELECT code FROM ' . t('course_codes') . ' WHERE course_id = ? ORDER BY code', [$courseId]);
    $codes = [];
    foreach ($rows as $r) {
        if ($r['code'] !== '') {
            $codes[] = $r['code'];
        }
    }
    if (!$codes && $fallbackCode !== '') {
        $codes[] = (string) $fallbackCode;
    }
    return $codes;
}

/** 覆盖式写入一门课的多个课程代码（自动去重、去空） */
function set_course_codes($courseId, $codes)
{
    q('DELETE FROM ' . t('course_codes') . ' WHERE course_id = ?', [$courseId]);
    $seen = [];
    foreach ((array) $codes as $c) {
        $c = trim((string) $c);
        if ($c === '' || isset($seen[$c])) {
            continue;
        }
        $seen[$c] = true;
        q('INSERT IGNORE INTO ' . t('course_codes') . ' (course_id, code) VALUES (?, ?)', [$courseId, $c]);
    }
}

/* ------------------------- 教务表格解析 ------------------------- */

/**
 * 清理授课教师：去掉「（主讲）」这类括号标记，只留姓名。
 * 单元格里可能写了多位老师（张三（主讲）、李四），逐个清理后用顿号连接。
 */
function clean_teacher_name($raw)
{
    $s = trim((string) $raw);
    if ($s === '') {
        return '';
    }
    $parts = preg_split('/[、，,;；\/｜|\x{FF0F}]+/u', $s) ?: [$s];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        // 去掉括号及其中内容：「张三（主讲）」→「张三」
        $p = trim(preg_replace('/[（(\[【][^）)\]】]*[）)\]】]/u', '', $p) ?? $p);
        // 兜底：去掉残留的主讲 / 辅讲 / 助教等角色标记
        $p = trim(preg_replace('/(主讲|辅讲|助教|任课教师|教师)$/u', '', $p) ?? $p);
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return implode('、', $out);
}

// 教务处导出表格的表头别名（只取需要的 8 列，其余列忽略）
function edu_column_aliases()
{
    return [
        '课程代码' => 'code', '课程编号' => 'code', '课号' => 'code', 'code' => 'code',
        '课程名称' => 'name', '课程名' => 'name', 'name' => 'name',
        '学分' => 'credit', 'credit' => 'credit',
        '课程类别' => 'category', '课程性质' => 'category', '类别' => 'category', 'category' => 'category',
        '开课部门' => 'college', '开课院系' => 'college', '开课学院' => 'college', '学院' => 'college', 'college' => 'college',
        '授课教师' => 'teacher', '授课老师' => 'teacher', '任课教师' => 'teacher', '教师' => 'teacher', 'teacher' => 'teacher',
        '考核方式' => 'examMode', '考试方式' => 'examMode', '考核形式' => 'examMode', 'exam_mode' => 'examMode',
        '授课语言' => 'language', '语言' => 'language', 'language' => 'language',
    ];
}

/**
 * 识别教务表格表头。先精确匹配，再按「最长别名包含」兜底。
 * 返回 ['map' => [列下标 => 字段], 'fields' => [['header'=>原表头, 'field'=>字段或null]]]
 */
function edu_header_map($rows)
{
    if (!$rows) {
        return ['map' => [], 'fields' => []];
    }
    $aliases = edu_column_aliases();
    $header = array_map('trim', $rows[0]);
    $map = [];
    $fields = [];
    foreach ($header as $i => $h) {
        if (isset($aliases[$h])) {
            $map[$i] = $aliases[$h];
            $fields[] = ['header' => $h, 'field' => $aliases[$h]];
            continue;
        }
        $bestKey = '';
        $bestField = null;
        foreach ($aliases as $alias => $field) {
            if ($h !== '' && strpos($h, $alias) !== false && strlen($alias) > strlen($bestKey)) {
                $bestKey = $alias;
                $bestField = $field;
            }
        }
        if ($bestField) {
            $map[$i] = $bestField;
        }
        $fields[] = ['header' => $h, 'field' => $bestField];
    }
    return ['map' => $map, 'fields' => $fields];
}

/**
 * 教务表格 → 课程列表。
 * 同一「课程名称 + 授课教师」合并为一条，课程代码保留多个（merged 记录合并了几行）。
 * 返回每项：name / teacher / code（主代码）/ codes（全部）/ credit / category / college / examMode / language / merged
 */
function edu_rows_to_courses($rows)
{
    if (!$rows) {
        return [];
    }
    $map = edu_header_map($rows)['map'];
    if (!in_array('name', $map, true)) {
        return [];
    }

    $fillable = ['credit', 'category', 'college', 'examMode', 'language'];
    $groups = [];

    foreach (array_slice($rows, 1) as $cells) {
        $obj = ['name' => '', 'teacher' => '', 'code' => '', 'credit' => '', 'category' => '', 'college' => '', 'examMode' => '', 'language' => ''];
        foreach ($map as $i => $field) {
            $obj[$field] = trim((string) ($cells[$i] ?? ''));
        }
        if ($obj['name'] === '') {
            continue;
        }
        $obj['teacher'] = clean_teacher_name($obj['teacher']);
        $key = $obj['name'] . "\x01" . $obj['teacher'];

        if (!isset($groups[$key])) {
            $groups[$key] = ['item' => $obj, 'codes' => [], 'merged' => 0];
            unset($groups[$key]['item']['code']);
        }
        $g = &$groups[$key];
        $g['merged']++;
        if ($obj['code'] !== '' && !in_array($obj['code'], $g['codes'], true)) {
            $g['codes'][] = $obj['code'];
        }
        foreach ($fillable as $f) {
            if ($g['item'][$f] === '' && $obj[$f] !== '') {
                $g['item'][$f] = $obj[$f];
            }
        }
        unset($g);
    }

    $out = [];
    foreach ($groups as $g) {
        $item = $g['item'];
        $item['codes'] = $g['codes'];
        $item['code'] = $g['codes'] ? $g['codes'][0] : '';
        $item['merged'] = $g['merged'];
        $out[] = $item;
    }
    return $out;
}

function csv_to_edu_courses($text)
{
    return edu_rows_to_courses(csv_parse($text));
}

function xlsx_to_edu_courses($binary)
{
    return edu_rows_to_courses(xlsx_to_rows($binary));
}

function course_dto($row, $viewer)
{
    $myRating = 0;
    if ($viewer) {
        $myRating = (int) q_val(
            'SELECT rating FROM ' . t('reviews') . ' WHERE course_id = ? AND user_id = ? LIMIT 1',
            [$row['id'], $viewer['id']], 0
        );
    }
    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'teacher' => $row['teacher'],
        'code' => (string) $row['code'],
        'credit' => (string) $row['credit'],
        'category' => (string) $row['category'],
        'examMode' => (string) ($row['exam_mode'] ?? ''),
        'language' => (string) ($row['language'] ?? ''),
        'college' => (string) $row['college'],
        'intro' => (string) $row['intro'],
        'codes' => course_codes_of($row['id'], (string) $row['code']),
        'createdBy' => $row['created_by'],
        'createdAt' => (int) $row['created_at'],
        'updatedAt' => (int) $row['updated_at'],
        'stats' => course_stats($row['id']),
        'author' => public_user(get_user($row['created_by'])),
        'myRating' => $myRating,
    ];
}

function review_dto($row, $viewer)
{
    $tags = $row['tags'] ? array_values(array_filter((array) json_decode($row['tags'], true))) : [];
    $course = q_one('SELECT id, name, code, teacher FROM ' . t('courses') . ' WHERE id = ? LIMIT 1', [$row['course_id']]);
    $author = $row['anonymous']
        ? ['id' => '', 'nickname' => '匿名同学', 'avatarColor' => '#9aa0a6', 'anonymous' => true]
        : public_user(get_user($row['user_id']));
    $viewerId = $viewer ? $viewer['id'] : null;
    return [
        'id' => $row['id'],
        'courseId' => $row['course_id'],
        'userId' => $row['user_id'],
        'rating' => (int) $row['rating'],
        'title' => (string) $row['title'],
        'content' => (string) $row['content'],
        'tags' => $tags,
        'takeAgain' => (bool) $row['take_again'],
        'anonymous' => (bool) $row['anonymous'],
        'semester' => (string) $row['semester'],
        'createdAt' => (int) $row['created_at'],
        'updatedAt' => (int) $row['updated_at'],
        'author' => $author,
        'course' => $course ?: null,
        'likeCount' => like_count('review', $row['id']),
        'liked' => has_liked('review', $row['id'], $viewerId),
        'canEdit' => $viewer && ($viewer['id'] === $row['user_id'] || !empty($viewer['is_admin'])),
    ];
}

function post_dto($row, $viewer)
{
    $tags = $row['tags'] ? array_values(array_filter((array) json_decode($row['tags'], true))) : [];
    $course = null;
    if (!empty($row['course_id'])) {
        $course = q_one('SELECT id, name, code, teacher FROM ' . t('courses') . ' WHERE id = ? LIMIT 1', [$row['course_id']]) ?: null;
    }
    $viewerId = $viewer ? $viewer['id'] : null;
    return [
        'id' => $row['id'],
        'type' => $row['type'],
        'courseId' => (string) $row['course_id'],
        'userId' => $row['user_id'],
        'title' => $row['title'],
        'content' => (string) $row['content'],
        'tags' => $tags,
        'pinned' => (bool) $row['pinned'],
        'createdAt' => (int) $row['created_at'],
        'updatedAt' => (int) $row['updated_at'],
        'author' => public_user(get_user($row['user_id'])),
        'course' => $course,
        'commentCount' => (int) q_val('SELECT COUNT(*) FROM ' . t('comments') . ' WHERE post_id = ?', [$row['id']]),
        'likeCount' => like_count('post', $row['id']),
        'liked' => has_liked('post', $row['id'], $viewerId),
        'canEdit' => $viewer && ($viewer['id'] === $row['user_id'] || !empty($viewer['is_admin'])),
    ];
}

function comment_dto($row, $viewer)
{
    $viewerId = $viewer ? $viewer['id'] : null;
    return [
        'id' => $row['id'],
        'postId' => $row['post_id'],
        'userId' => $row['user_id'],
        'content' => (string) $row['content'],
        'createdAt' => (int) $row['created_at'],
        'author' => public_user(get_user($row['user_id'])),
        'likeCount' => like_count('comment', $row['id']),
        'liked' => has_liked('comment', $row['id'], $viewerId),
        'canEdit' => $viewer && ($viewer['id'] === $row['user_id'] || !empty($viewer['is_admin'])),
    ];
}

function find_or_fail($table, $id, $message)
{
    $row = q_one('SELECT * FROM ' . $table . ' WHERE id = ? LIMIT 1', [$id]);
    if (!$row) {
        throw new ApiException($message, 404);
    }
    return $row;
}

/* ------------------------- 站内信与通知 ------------------------- */

function notify_user($userId, $title, $content, $link = '', $kind = 'system')
{
    if (!$userId) {
        return null;
    }
    $id = new_id('n_');
    q(
        'INSERT INTO ' . t('notifications') . ' (id, user_id, kind, title, content, link, is_read, created_at)
         VALUES (?,?,?,?,?,?,0,?)',
        [$id, $userId, $kind, mb_substr($title, 0, 120), mb_substr($content, 0, 2000), $link, now_ms()]
    );
    return $id;
}

function unread_counts($userId)
{
    if (!$userId) {
        return ['notifications' => 0, 'messages' => 0, 'total' => 0];
    }
    $notes = (int) q_val(
        'SELECT COUNT(*) FROM ' . t('notifications') . ' WHERE user_id = ? AND is_read = 0',
        [$userId]
    );
    $msgs = (int) q_val(
        'SELECT COUNT(*) FROM ' . t('messages') . ' m
         INNER JOIN ' . t('conversations') . ' c ON c.id = m.conversation_id
         WHERE m.is_read = 0 AND m.sender_id <> ? AND (c.user1_id = ? OR c.user2_id = ?)',
        [$userId, $userId, $userId]
    );
    return ['notifications' => $notes, 'messages' => $msgs, 'total' => $notes + $msgs];
}

/** 取（或创建）两人会话，user1_id 固定为字典序较小者 */
function get_or_create_conversation($aId, $bId)
{
    if ($aId === $bId) {
        throw new ApiException('不能给自己发私信');
    }
    $u1 = strcmp($aId, $bId) < 0 ? $aId : $bId;
    $u2 = strcmp($aId, $bId) < 0 ? $bId : $aId;
    $row = q_one(
        'SELECT * FROM ' . t('conversations') . ' WHERE user1_id = ? AND user2_id = ? LIMIT 1',
        [$u1, $u2]
    );
    if ($row) {
        return $row;
    }
    $id = new_id('cv_');
    try {
        q(
            'INSERT INTO ' . t('conversations') . ' (id, user1_id, user2_id, last_message, last_sender_id, last_at) VALUES (?,?,?,?,?,0)',
            [$id, $u1, $u2, '', '']
        );
    } catch (Throwable $e) {
        // 并发下可能已创建
        $row = q_one(
            'SELECT * FROM ' . t('conversations') . ' WHERE user1_id = ? AND user2_id = ? LIMIT 1',
            [$u1, $u2]
        );
        return $row ?: find_or_fail(t('conversations'), $id, '会话不存在');
    }
    return find_or_fail(t('conversations'), $id, '会话不存在');
}

function conversation_dto($row, $viewerId)
{
    $otherId = $row['user1_id'] === $viewerId ? $row['user2_id'] : $row['user1_id'];
    $unread = (int) q_val(
        'SELECT COUNT(*) FROM ' . t('messages') . ' WHERE conversation_id = ? AND is_read = 0 AND sender_id <> ?',
        [$row['id'], $viewerId]
    );
    return [
        'id' => $row['id'],
        'lastMessage' => (string) $row['last_message'],
        'lastAt' => (int) $row['last_at'],
        'lastSenderId' => (string) $row['last_sender_id'],
        'unread' => $unread,
        'other' => public_user(get_user($otherId)),
    ];
}
