<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Vary: Authorization');
header('X-Content-Type-Options: nosniff');

function configRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadEnvFile(): array
{
    $configuredPath = getenv('LIFE_HUB_ENV_FILE');
    $path = is_string($configuredPath) && trim($configuredPath) !== ''
        ? trim($configuredPath)
        : __DIR__ . DIRECTORY_SEPARATOR . '.env';
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        configRespond(503, ['ok' => false, 'error' => '服务器尚未配置 .env']);
    }

    $values = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }
        $key = trim(substr($line, 0, $separator));
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = stripcslashes($value);
            }
        }
        $values[$key] = $value;
    }
    return $values;
}

function envValue(array $fileValues, string $key, string $default = ''): string
{
    $runtime = getenv($key);
    if (is_string($runtime) && $runtime !== '') {
        return trim($runtime);
    }
    return trim((string) ($fileValues[$key] ?? $default));
}

function requiredEnv(array $values, string $key): string
{
    $value = envValue($values, $key);
    if ($value === '') {
        configRespond(503, ['ok' => false, 'error' => "服务器配置缺少 {$key}"]);
    }
    return $value;
}

function requestIsSameOrigin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true;
    }
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $originHost = parse_url($origin, PHP_URL_HOST);
    return is_string($originHost) && $host !== '' && strcasecmp($originHost, $host) === 0;
}

function bearerToken(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($header), $match) === 1 ? trim($match[1]) : '';
}

function fetchUserInfo(string $url, string $token): array
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
        configRespond(503, ['ok' => false, 'error' => 'LIFE_HUB_OIDC_USERINFO_URL 必须是 HTTPS 地址']);
    }
    if ($token === '' || strlen($token) > 8192) {
        configRespond(401, ['ok' => false, 'error' => '缺少有效的登录令牌']);
    }

    $status = 0;
    $body = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    if ($status === 401 || $status === 403) {
        configRespond(401, ['ok' => false, 'error' => 'Authentik 登录令牌无效或已过期']);
    }
    if ($status < 200 || $status >= 300 || !is_string($body)) {
        configRespond(502, ['ok' => false, 'error' => "Authentik UserInfo 校验失败（HTTP {$status}）"]);
    }
    $claims = json_decode($body, true);
    if (!is_array($claims) || empty($claims['sub'])) {
        configRespond(502, ['ok' => false, 'error' => 'Authentik UserInfo 响应无效']);
    }
    return $claims;
}

function allowList(array $values, string $key): array
{
    $raw = envValue($values, $key);
    return array_values(array_filter(array_map(
        static fn(string $item): string => strtolower(trim($item)),
        preg_split('/[,;\r\n]+/', $raw) ?: []
    )));
}

function isAdministrator(array $claims, array $values): bool
{
    $allowedGroups = allowList($values, 'LIFE_HUB_ADMIN_GROUPS');
    $allowedUsers = allowList($values, 'LIFE_HUB_ADMIN_USERS');
    $allowedEmails = allowList($values, 'LIFE_HUB_ADMIN_EMAILS');
    $groups = normalizedClaimGroups($claims);
    $username = strtolower(trim((string) ($claims['preferred_username'] ?? '')));
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    return array_intersect($allowedGroups, $groups) !== []
        || ($username !== '' && in_array($username, $allowedUsers, true))
        || ($email !== '' && in_array($email, $allowedEmails, true));
}

function normalizedClaimGroups(array $claims): array
{
    $raw = $claims['groups'] ?? $claims['ak_groups'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : (preg_split('/[,;\r\n]+/', $raw) ?: []);
    }
    if (!is_array($raw)) {
        return [];
    }
    $groups = array_map(static function (mixed $group): string {
        if (is_array($group)) {
            $group = $group['name'] ?? $group['group_name'] ?? '';
        }
        return strtolower(trim((string) $group));
    }, $raw);
    return array_values(array_unique(array_filter($groups)));
}

function authorizationDetails(array $claims, array $values): array
{
    return [
        'administrator' => isAdministrator($claims, $values),
        'groups' => normalizedClaimGroups($claims),
        'allowedGroups' => allowList($values, 'LIFE_HUB_ADMIN_GROUPS'),
    ];
}


function workspaceCachePath(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'life-dashboard-workspaces-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
}

function validateWorkspacePayload(array $payload): array
{
    if (($payload['ok'] ?? false) !== true || !is_array($payload['workspaces'] ?? null) || !is_array($payload['summary'] ?? null)) {
        configRespond(400, ['ok' => false, 'error' => '工作区推送数据结构无效']);
    }
    if (count($payload['workspaces']) > 500) configRespond(400, ['ok' => false, 'error' => '工作区数量超出限制']);
    $workspaces = [];
    foreach ($payload['workspaces'] as $item) {
        if (!is_array($item) || preg_match('/^[a-f0-9]{16}$/', (string) ($item['key'] ?? '')) !== 1) {
            configRespond(400, ['ok' => false, 'error' => '工作区标识无效']);
        }
        $session = is_array($item['latestSession'] ?? null) ? $item['latestSession'] : [];
        $workspaces[] = [
            'key' => (string) $item['key'],
            'name' => function_exists('mb_substr') ? mb_substr(trim((string) ($item['name'] ?? '未命名工作区')), 0, 100) : substr(trim((string) ($item['name'] ?? 'unnamed')), 0, 100),
            'lastAt' => max(0, (int) ($item['lastAt'] ?? 0)),
            'sessions' => max(0, (int) ($item['sessions'] ?? 0)),
            'activeEvents' => max(0, (int) ($item['activeEvents'] ?? 0)),
            'todayEvents' => max(0, (int) ($item['todayEvents'] ?? 0)),
            'todayTokens' => max(0, (int) ($item['todayTokens'] ?? 0)),
            'state' => in_array($item['state'] ?? '', ['working', 'active', 'recent', 'idle', 'none'], true) ? $item['state'] : 'none',
            'ageMs' => isset($item['ageMs']) ? max(0, (int) $item['ageMs']) : null,
            'latestSession' => $session === [] ? null : [
                'id' => preg_match('/^[a-z0-9-]{1,16}$/i', (string) ($session['id'] ?? '')) === 1 ? (string) $session['id'] : '',
                'turns' => max(0, (int) ($session['turns'] ?? 0)),
                'lastAt' => max(0, (int) ($session['lastAt'] ?? 0)),
            ],
        ];
    }
    $summary = [];
    foreach (['total', 'working', 'active', 'recent', 'idle', 'none'] as $key) $summary[$key] = max(0, (int) ($payload['summary'][$key] ?? 0));
    return [
        'ok' => true,
        'generatedAt' => max(0, (int) ($payload['generatedAt'] ?? 0)),
        'tz' => function_exists('mb_substr') ? mb_substr((string) ($payload['tz'] ?? ''), 0, 100) : substr((string) ($payload['tz'] ?? ''), 0, 100),
        'thresholdsMs' => is_array($payload['thresholdsMs'] ?? null) ? $payload['thresholdsMs'] : [],
        'summary' => $summary,
        'workspaces' => $workspaces,
    ];
}

function receiveWorkspacePush(array $values): never
{
    $secret = requiredEnv($values, 'LIFE_HUB_DSH_PUSH_SECRET');
    if (strlen($secret) < 32) configRespond(503, ['ok' => false, 'error' => 'LIFE_HUB_DSH_PUSH_SECRET 至少需要 32 个字符']);
    $timestamp = (string) ($_SERVER['HTTP_X_DSH_PUSH_TIMESTAMP'] ?? '');
    $signature = strtolower((string) ($_SERVER['HTTP_X_DSH_PUSH_SIGNATURE'] ?? ''));
    if (preg_match('/^\d{10}$/', $timestamp) !== 1 || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
        configRespond(401, ['ok' => false, 'error' => '推送签名缺失或无效']);
    }
    $now = time();
    if (abs($now - (int) $timestamp) > 120) configRespond(401, ['ok' => false, 'error' => '推送时间戳已过期']);
    $body = (string) file_get_contents('php://input');
    if ($body === '' || strlen($body) > 512 * 1024) configRespond(413, ['ok' => false, 'error' => '推送请求体无效']);
    $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    if (!hash_equals($expected, $signature)) configRespond(401, ['ok' => false, 'error' => '推送签名校验失败']);
    $payload = json_decode($body, true);
    if (!is_array($payload)) configRespond(400, ['ok' => false, 'error' => '推送请求体必须是 JSON']);
    $payload = validateWorkspacePayload($payload);
    $path = workspaceCachePath();
    $previous = json_decode((string) @file_get_contents($path), true);
    if (is_array($previous) && (int) ($previous['pushTimestamp'] ?? 0) >= (int) $timestamp) {
        configRespond(409, ['ok' => false, 'error' => '重复或过期的推送请求']);
    }
    $record = ['receivedAt' => (int) round(microtime(true) * 1000), 'pushTimestamp' => (int) $timestamp, 'payload' => $payload];
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($temporary, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        configRespond(500, ['ok' => false, 'error' => '服务器无法保存工作区快照']);
    }
    @chmod($path, 0600);
    configRespond(202, ['ok' => true, 'receivedAt' => $record['receivedAt']]);
}

function cachedDshWorkspaces(array $values): array
{
    $record = json_decode((string) @file_get_contents(workspaceCachePath()), true);
    if (!is_array($record) || !is_array($record['payload'] ?? null)) {
        return ['ok' => true, 'generatedAt' => 0, 'summary' => ['total' => 0, 'working' => 0, 'active' => 0, 'recent' => 0, 'idle' => 0, 'none' => 0], 'workspaces' => [], 'source' => ['online' => false, 'receivedAt' => 0, 'ageMs' => null]];
    }
    $receivedAt = (int) ($record['receivedAt'] ?? 0);
    $ageMs = max(0, (int) round(microtime(true) * 1000) - $receivedAt);
    $offlineAfter = max(30, min(3600, (int) envValue($values, 'LIFE_HUB_DSH_OFFLINE_AFTER_SECONDS', '45'))) * 1000;
    $payload = validateWorkspacePayload($record['payload']);
    foreach ($payload['workspaces'] as &$item) if ($item['ageMs'] !== null) $item['ageMs'] += $ageMs;
    unset($item);
    return $payload + ['source' => ['online' => $ageMs <= $offlineAfter, 'receivedAt' => $receivedAt, 'ageMs' => $ageMs]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strtolower(trim((string) ($_GET['action'] ?? ''))) === 'workspace-push') {
    $values = loadEnvFile();
    receiveWorkspacePush($values);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    configRespond(405, ['ok' => false, 'error' => '配置接口只接受 GET 请求']);
}
if (!requestIsSameOrigin()) {
    configRespond(403, ['ok' => false, 'error' => '跨站请求被拒绝']);
}

$values = loadEnvFile();
$action = strtolower(trim((string) ($_GET['action'] ?? 'public')));
if ($action === 'public') {
    $payload = [
        'ok' => true,
        'oidc' => [
            'clientId' => requiredEnv($values, 'LIFE_HUB_OIDC_CLIENT_ID'),
            'authorize' => requiredEnv($values, 'LIFE_HUB_OIDC_AUTHORIZE_URL'),
            'token' => requiredEnv($values, 'LIFE_HUB_OIDC_TOKEN_URL'),
            'rememberDays' => max(1, min(90, (int) envValue($values, 'LIFE_HUB_OIDC_REMEMBER_DAYS', '30'))),
        ],
    ];
    $localUsername = envValue($values, 'LIFE_HUB_LOCAL_AUTH_USERNAME');
    $localSalt = envValue($values, 'LIFE_HUB_LOCAL_AUTH_SALT');
    $localHash = envValue($values, 'LIFE_HUB_LOCAL_AUTH_HASH');
    if ($localUsername !== '' && $localSalt !== '' && $localHash !== '') {
        $payload['localAuth'] = [
            'username' => $localUsername,
            'pbkdf2' => [
                'salt' => $localSalt,
                'hash' => $localHash,
                'iterations' => max(100000, (int) envValue($values, 'LIFE_HUB_LOCAL_AUTH_ITERATIONS', '310000')),
            ],
        ];
    }
    configRespond(200, $payload);
}

if (!in_array($action, ['private', 'identity', 'workspaces'], true)) {
    configRespond(400, ['ok' => false, 'error' => 'action 必须为 public、identity、private 或 workspaces']);
}

$claims = fetchUserInfo(requiredEnv($values, 'LIFE_HUB_OIDC_USERINFO_URL'), bearerToken());
$identity = [
    'username' => (string) ($claims['preferred_username'] ?? ''),
    'email' => (string) ($claims['email'] ?? ''),
];
$authorization = authorizationDetails($claims, $values);
if ($action === 'identity') {
    configRespond(200, ['ok' => true, 'identity' => $identity, 'authorization' => $authorization]);
}
if (!$authorization['administrator']) {
    $error = $authorization['allowedGroups'] === []
        ? '服务器未配置 LIFE_HUB_ADMIN_GROUPS'
        : '当前 Authentik 账号不在允许的管理员组中';
    configRespond(403, ['ok' => false, 'error' => $error, 'identity' => $identity, 'authorization' => $authorization]);
}

if ($action === 'workspaces') {
    configRespond(200, cachedDshWorkspaces($values));
}

configRespond(200, [
    'ok' => true,
    'administrator' => true,
    'identity' => $identity,
    'authorization' => $authorization,
    'homeAssistant' => [
        'url' => requiredEnv($values, 'LIFE_HUB_HA_URL'),
        'token' => requiredEnv($values, 'LIFE_HUB_HA_TOKEN'),
    ],
]);