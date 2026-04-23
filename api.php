<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ══════════════════════════════════════════════
// კონფიგურაცია
// ══════════════════════════════════════════════
define('PUBLIC_KEY',  'c97b9d35938648ba');   // ← API Public Key
define('PRIVATE_KEY', 'eyJhbGciOiJBMjU2S1ciLCJlbmMiOiJBMjU2Q0JDLUhTNTEyIiwidHlwIjoiSldUIiwiY3R5IjoiSldUIn0.2jD1USDt2p_xbMlGDPas2oA2EXqDP43-QCAfp5U1zhCPTcorNNnPXPj8I7OVIlrxVjnqh_I8QxltSG9T83PbQNPNOUo8bCS8.xdiuLADjLcdyL28abv-pCQ.O2zetH5R-M1RURaJkJtlFyXsAdvcphGZIAn-ZSElGFFinga4Zhf-wrsfKRrhCOZJBeeJJTSmokYmtDoNvQgT_8FeOwUeLExVXDwQ6q1oJyx1eMswFj1nok_f7sF3YIqRb9bj7oBdWGnAFQhKi1QByd5lvjnRIC223h6mtvHWiW9wG7iSW9TRwHMIfHhCfn6JgNdUj11sNqK103ZGGNGLvr-t49nqih2UPEwMh9hvCsN5DeM7o9Avw3slbAHsiT7HpmzowwDclEP68ZNFXNMczvKCE2LQgMbXkzoDuchLzKIg9r8LOGLF7T0JDKleUD26ITdIfCKsUvjjSjbCq8xigtq54j4BfvsuQ_fps4oOQWahBRY4Jf0Q_8SSg438Brbt8Lz47jmy7iZCRxu5GGFfCzmOk2buiqfqb4aPoYOf4kApRFTXRjH3UR81cRYoEYegzJQghsLumj7ZdvTfiV9RaA.2maizIHMynbcPzfR2jDoin5NsiJYQWyNZqqR7MSg-os');  // ← API Private Key (Bearer token)
define('SMS_SENDER',  'FARMASI');
define('OTP_EXPIRE',  300);
define('DATA_DIR',    __DIR__);
// ══════════════════════════════════════════════

session_start();

$action = $_POST['action'] ?? '';
switch($action) {
    case 'send_otp':   sendOtp();   break;
    case 'verify_otp': verifyOtp(); break;
    case 'logout':     doLogout();  break;
    default: jsonError('უცნობი მოქმედება');
}

function sendOtp() {
    $pid = trim($_POST['pid'] ?? '');
    if (!$pid) jsonError('შეიყვანეთ პირადი ნომერი');
    // ── TEST MODE: FARMASI root (404445249) uses admin phone ──
    if ($pid === '404445249') {
        $mobile = '599772266'; // ← თქვენი ნომერი ტესტისთვის
        $name   = 'FARMASI (test)';
    } else {
        $users = loadJson('users_mobile.json');
        if (!isset($users[$pid])) jsonError('პირადი ნომერი სისტემაში ვერ მოიძებნა');
        $mobile = $users[$pid]['mobile'];
        $name   = $users[$pid]['name'];
    }

    $otp = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp']      = $otp;
    $_SESSION['otp_pid']  = $pid;
    $_SESSION['otp_time'] = time();
    $_SESSION['otp_name'] = $name;

    $text   = "FARMASI: kodi aris " . $otp . ". Vada 5 tsuti.";
    $result = sendSms('995' . $mobile, $text);

    file_put_contents(DATA_DIR.'/sms.log',
        date('Y-m-d H:i:s')." pid:$pid mobile:$mobile result:".json_encode($result)."\n",
        FILE_APPEND);

    if (!$result['ok']) {
        jsonError('SMS ვერ გაიგზავნა: ' . $result['body']);
    }

    $masked = substr($mobile,0,3) . '***' . substr($mobile,-3);
    jsonOk(['masked_phone' => $masked, 'name' => $name]);
}

function verifyOtp() {
    $otp = trim($_POST['otp'] ?? '');
    if (!$otp) jsonError('შეიყვანეთ კოდი');
    if (empty($_SESSION['otp'])) jsonError('სესია ამოიწურა.');
    if (time() - $_SESSION['otp_time'] > OTP_EXPIRE) {
        session_unset(); jsonError('კოდის ვადა ამოიწურა.');
    }
    if ($otp !== $_SESSION['otp']) jsonError('არასწორი კოდი.');
    $pid  = $_SESSION['otp_pid'];
    $name = $_SESSION['otp_name'];
    $_SESSION['auth_pid']  = $pid;
    $_SESSION['auth_name'] = $name;
    unset($_SESSION['otp'], $_SESSION['otp_pid'],
          $_SESSION['otp_time'], $_SESSION['otp_name']);
    jsonOk(['pid' => $pid, 'name' => $name]);
}

function doLogout() { session_destroy(); jsonOk(['ok' => true]); }

// ── bulksms.ge REST API ──────────────────────
function sendSms($phone, $text) {
    $url = 'https://api.bulksms.ge/gateway/api/sms/v1/message/send'
         . '?publicKey=' . urlencode(PUBLIC_KEY);

    $payload = json_encode([
        'Text'    => $text,
        'Purpose' => 'INF',
        'Options' => [
            'Originator' => SMS_SENDER,
            'Encoding'   => 'UNICODE'
        ],
        'Receivers' => [
            ['Receiver' => $phone]
        ]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . PRIVATE_KEY,
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    file_put_contents(DATA_DIR.'/sms.log',
        date('Y-m-d H:i:s')." CODE:$code RESP:$resp ERR:$err\n",
        FILE_APPEND);

    $parsed = json_decode($resp, true);
    $hasError = $parsed && in_array((string)($parsed['Status'] ?? ''), ['400','401','403','500']);

    return [
        'ok'   => $code === 200 && !$hasError,
        'code' => $code,
        'body' => $resp
    ];
}

function loadJson($f) {
    $p = DATA_DIR.'/'.$f;
    if (!file_exists($p)) return [];
    return json_decode(file_get_contents($p), true) ?? [];
}
function jsonOk($d)    { echo json_encode(['status'=>'ok']+$d, JSON_UNESCAPED_UNICODE); exit; }
function jsonError($m) { http_response_code(400); echo json_encode(['status'=>'error','message'=>$m], JSON_UNESCAPED_UNICODE); exit; }
