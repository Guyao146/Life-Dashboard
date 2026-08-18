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
    if ($allowedGroups === [] && $allowedUsers === [] && $allowedEmails === []) {
        configRespond(503, ['ok' => false, 'error' => '服务器未配置管理员组、用户名或邮箱白名单']);
    }

    $claimGroups = $claims['groups'] ?? [];
    if (is_string($claimGroups)) {
        $claimGroups = preg_split('/[,;\r\n]+/', $claimGroups) ?: [];
    }
    $groups = array_map('strtolower', array_map('strval', is_array($claimGroups) ? $claimGroups : []));
    $username = strtolower(trim((string) ($claims['preferred_username'] ?? '')));
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    return array_intersect($allowedGroups, $groups) !== []
        || ($username !== '' && in_array($username, $allowedUsers, true))
        || ($email !== '' && in_array($email, $allowedEmails, true));
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

if ($action !== 'private') {
    configRespond(400, ['ok' => false, 'error' => 'action 必须为 public 或 private']);
}

$claims = fetchUserInfo(requiredEnv($values, 'LIFE_HUB_OIDC_USERINFO_URL'), bearerToken());
if (!isAdministrator($claims, $values)) {
    configRespond(403, ['ok' => false, 'error' => '当前 Authentik 账号不是看板管理员']);
}

configRespond(200, [
    'ok' => true,
    'administrator' => true,
    'identity' => [
        'username' => (string) ($claims['preferred_username'] ?? ''),
        'email' => (string) ($claims['email'] ?? ''),
    ],
    'homeAssistant' => [
        'url' => requiredEnv($values, 'LIFE_HUB_HA_URL'),
        'token' => requiredEnv($values, 'LIFE_HUB_HA_TOKEN'),
    ],
]);