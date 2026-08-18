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


function fetchDshWorkspaces(array $values): array
{
    $baseUrl = requiredEnv($values, 'LIFE_HUB_DSH_URL');
    $token = requiredEnv($values, 'LIFE_HUB_DSH_TOKEN');
    if (strlen($token) < 24) {
        configRespond(503, ['ok' => false, 'error' => 'LIFE_HUB_DSH_TOKEN 至少需要 24 个字符']);
    }
    if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
        configRespond(503, ['ok' => false, 'error' => 'LIFE_HUB_DSH_URL 必须是有效的 http/https 地址']);
    }
    if (parse_url($baseUrl, PHP_URL_USER) !== null || parse_url($baseUrl, PHP_URL_PASS) !== null) {
        configRespond(503, ['ok' => false, 'error' => 'LIFE_HUB_DSH_URL 不允许包含账号或密码']);
    }
    $url = rtrim($baseUrl, '/') . '/dsh-activity/api/workspaces';
    $timeout = max(2, min(15, (int) envValue($values, 'LIFE_HUB_DSH_TIMEOUT_SECONDS', '5')));
    $body = '';
    $status = 0;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-DSH-Dashboard-Token: ' . $token],
        ]);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($result === false) configRespond(502, ['ok' => false, 'error' => '无法连接 DSH：' . $error]);
        $body = (string) $result;
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nX-DSH-Dashboard-Token: {$token}\r\n",
        ]]);
        $result = @file_get_contents($url, false, $context);
        $body = is_string($result) ? $result : '';
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match) === 1) { $status = (int) $match[1]; break; }
        }
    }
    $payload = json_decode($body, true);
    if ($status !== 200 || !is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['workspaces'] ?? null)) {
        $message = is_array($payload) ? (string) ($payload['error'] ?? "HTTP {$status}") : "HTTP {$status}";
        configRespond(502, ['ok' => false, 'error' => 'DSH 工作区接口失败：' . $message]);
    }
    return $payload;
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
    configRespond(200, fetchDshWorkspaces($values));
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