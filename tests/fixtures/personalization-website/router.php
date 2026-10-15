<?php
declare(strict_types=1);

// Local acceptance website only. No database credentials or CRM identities enter its forms.
if (getenv('NEXA_PERSONALIZATION_DEMO') !== '1') { http_response_code(404); exit; }
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie, Sec-GPC');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: same-origin');
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') { http_response_code(400); exit('HTTPS required.'); }
if (!is_file('/run/nexa/config.json')) { http_response_code(503); exit('The local acceptance fixture is not configured.'); }
require '/adapter.php';
session_name('nexa_demo_session');
session_start(['use_strict_mode'=>true, 'cookie_secure'=>true, 'cookie_httponly'=>true, 'cookie_samesite'=>'Strict']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$config = json_decode(file_get_contents('/run/nexa/config.json'), true, 32, JSON_THROW_ON_ERROR);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('#^/([ab])(?:/(login|logout|consent))?/?$#D', $path ?? '', $match)) { http_response_code(404); exit('Not found.'); }
$site = $match[1]; $action = $match[2] ?? ''; $settings = $config[$site];
$uuid = static function (): string {
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
};
if (!isset($_SESSION[$site])) {
    $_SESSION[$site] = ['visitor'=>$uuid()];
}
$session = &$_SESSION[$site];
$gpc = ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
$adapter = new NexaPersonalizationBackendExample('https://crm.nexa.test/nexa/api/v1', $settings['apiKey']);
$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://localhost:8444' || !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403); exit('Invalid form origin or token.');
        }
        $allowed = match ($action) { 'login'=>['csrf','email','password'], 'consent'=>['csrf','choice'], 'logout'=>['csrf'], default=>[] };
        if (!$allowed || array_diff(array_keys($_POST), $allowed)) { http_response_code(400); exit('Unsupported form fields.'); }
        if ($action === 'login') {
            if (!hash_equals($settings['email'], strtolower(trim((string) ($_POST['email'] ?? '')))) ||
                !password_verify((string) ($_POST['password'] ?? ''), $settings['passwordHash'])) {
                http_response_code(401); $error = 'Email or password is incorrect.';
            } else {
                $adapter->revoke($session['visitor']);
                session_regenerate_id(true);
                $session['customer'] = $settings['email'];
                $adapter->identify($session['visitor'], $settings['contactId'], hash('sha256', session_id()), $gpc);
            }
        } elseif ($action === 'logout') {
            $adapter->revoke($session['visitor']);
            unset($session['customer']); session_regenerate_id(true);
        } else {
            if (!in_array($_POST['choice'] ?? '', ['allow','deny'], true)) { http_response_code(400); exit('Invalid consent choice.'); }
            $allow = $_POST['choice'] === 'allow';
            $curl = curl_init('https://crm.nexa.test/nexa/api/v1/Nexa/public/cookies/receipts');
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode([
                    'publicKey'=>$settings['publicKey'], 'visitorId'=>$session['visitor'],
                    'receiptKey'=>$uuid(), 'choice'=>'custom',
                    'categories'=>['preferences'=>$allow,'advertising'=>$allow], 'globalPrivacyControl'=>$gpc,
                ], JSON_THROW_ON_ERROR)]);
            try {
                if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) { throw new RuntimeException('Consent could not be saved.'); }
            } finally { curl_close($curl); }
            if (!$allow || $gpc) { $adapter->revoke($session['visitor']); }
            elseif (isset($session['customer'])) { $adapter->identify($session['visitor'], $settings['contactId'], hash('sha256', session_id()), $gpc); }
        }
        if (!$error) { header('Location: /'.$site.'/', true, 303); exit; }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
    $content = $adapter->resolve($settings['pageId'], $session['visitor'], $gpc);
} catch (Throwable $exception) {
    error_log('Local personalization upstream status: '.$exception->getCode());
    // Never reflect upstream errors, API keys, CRM IDs or session evidence.
    http_response_code(502); $error = 'The customer connection is temporarily unavailable.'; $content = ['blocks'=>[]];
}
$token = $escape($_SESSION['csrf']);
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customer Demo <?=strtoupper($site)?></title>
<style>body{font:16px Arial,sans-serif;color:#182c27;background:#f4f7f6;margin:0}main{max-width:760px;margin:48px auto;padding:24px}header{border-bottom:1px solid #b8cbc4;padding-bottom:20px}section{padding:24px 0;border-bottom:1px solid #b8cbc4}input,button{font:inherit;padding:10px;margin:6px 8px 6px 0}input{display:block;max-width:95%;width:320px;border:1px solid #71877f}button{cursor:pointer;border:1px solid #15664d;background:#15664d;color:white}p[role=alert]{color:#ad2020}label{display:block;margin-top:12px}</style>
<main><header><h1>Customer Demo <?=strtoupper($site)?></h1><p><?=isset($session['customer'])?'Signed in':'Not signed in'?></p></header>
<?php if ($error): ?><p role="alert"><?=$escape($error)?></p><?php endif ?>
<section data-content><?php foreach ($content['blocks'] as $block): ?><h2><?=$escape($block['heading'])?></h2><p><?=$escape($block['text'])?></p><?php endforeach ?></section>
<section><h2>Privacy preferences</h2><form method="post" action="/<?=$site?>/consent"><input type="hidden" name="csrf" value="<?=$token?>"><button name="choice" value="allow">Allow personalization</button><button name="choice" value="deny">Withdraw consent</button></form></section>
<section><?php if (isset($session['customer'])): ?><form method="post" action="/<?=$site?>/logout"><input type="hidden" name="csrf" value="<?=$token?>"><button>Sign out</button></form>
<?php else: ?><h2>Customer sign in</h2><form method="post" action="/<?=$site?>/login"><input type="hidden" name="csrf" value="<?=$token?>"><label>Email<input name="email" type="email" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button>Sign in</button></form><?php endif ?></section></main></html>
