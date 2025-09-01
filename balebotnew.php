<?php
// bale_abfacs_bot.php
// اجرا: php bale_abfacs_bot.php

/*
Create the sessions table with this SQL:
CREATE TABLE IF NOT EXISTS sessions (
    user_id BIGINT PRIMARY KEY,
    session_data TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
*/
date_default_timezone_set('Asia/Tehran');

// ====== تنظیمات (اینها را پر کن) ======
$token = "1203345475:qJntdsuSig6U9bUdGzHDOtIU9solCvN38Yyzy1cX"; // توکن ربات
define('BALE_API', 'https://tapi.bale.ai/bot' . $token);
define('ABFACS_ASMX', 'https://main.abfamarkazi.ir:5001/1522Service.asmx');
define('ABFACS_BASE', 'api.abfamarkazi.ir:5002'); // یا api.abfacs.ir
define('ABFACS_USER', '15951362');
define('ABFACS_PASS', 'M@10127o');
define('COMPANYCODE', '9');
define('BALE_CLIENT_ID', 'UINGkICKNSLZowTgnHwNfRcWXbChGQif');
define('BALE_CLIENT_SECRET', 'XSJffvKeUmpjAeImQHznwWGeHeQyAxtV');
define('OTP_EXPIRY', 180); // اعتبار OTP به ثانیه (۳ دقیقه)
// اتصال به دیتابیس
define('sqldsn', 'mysql:host=localhost;dbname=test;charset=utf8');
define('sqluser', 'root');
define('sqlpass', 'm@101270');

// فایل‌ها
$last_update_file = __DIR__ . '/last_update_id.txt';
$session_file = __DIR__ . '/sessions.json';
$token_file = __DIR__ . '/token_data.json';
$bale_log_file = __DIR__ . '/bale_send_log.txt';      // لاگ ارسال/دریافت بله (قبلاً ساخته شده)
$abfacs_log_file = __DIR__ . '/abfacs_log.txt';      // لاگ درخواست/پاسخ به سامانه آب و فاضلاب

$delay_seconds = 2;

// ====== توابع کمکی ======
function safe_file_get_json($path)
{
    if (!file_exists($path))
        return null;
    $txt = file_get_contents($path);
    $data = json_decode($txt, true);
    return $data;
}
function safe_file_put_json($path, $data)
{
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function sanitize_utf8($data)
{
    if (is_array($data)) {
        return array_map('sanitize_utf8', $data);
    }
    return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
}

// لاگ‌نویسی (حفظ پارامتر لاگ-file)
function logData($log_file, $type, $data)
{
    $cleaned_data = sanitize_utf8($data);
    $line = "[" . date('Y-m-d H:i:s') . "] [$type] " . (is_string($cleaned_data) ? $cleaned_data : json_encode($cleaned_data, JSON_UNESCAPED_UNICODE)) . "\n";
    file_put_contents($log_file, $line, FILE_APPEND);
}

// مدیریت session کاربران در دیتابیس
function loadSessions($user_id)
{
    $pdo = new PDO(sqldsn, sqluser, sqlpass);
    if ($user_id !== null) {
        $stmt = $pdo->prepare("SELECT session_data FROM sessions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            //echo "json:".json_encode([$user_id =>$row['session_data']],true) . "\n";
            return json_decode($row['session_data'], true);
        }
    }

    return [];
}

function saveSessions($user_id, $session_data)
{
    $pdo = new PDO(sqldsn, sqluser, sqlpass);
    $stmt = $pdo->prepare("INSERT INTO sessions (user_id, session_data) 
                           VALUES (?, ?) 
                           ON DUPLICATE KEY UPDATE session_data = ?");
    $json_data = json_encode($session_data, JSON_UNESCAPED_UNICODE);
    //echo "userid=$user_id , datatowrite=" . $json_data . "\n";
    $stmt->execute([$user_id, $json_data, $json_data]);
}

// مدیریت توکن مشترک (ذخیره و بارگذاری)
function loadTokenData($token_file)
{
    return safe_file_get_json($token_file);
}
function saveTokenData($token_file, $data)
{
    // انتظار: data['access_token'], data['refresh_token'], data['expires_in'] (ثانیه)
    if (isset($data['expires_in'])) {
        $data['expires_at'] = time() + (int) $data['expires_in'];
    }
    safe_file_put_json($token_file, $data);
}
// // تابع کمکی برای پیدا کردن مقدار بر اساس کد
// function getValueByCode($items, $code)
// {
//     foreach ($items as $item) {
//         if ($item['Code'] == $code) {
//             return $item['Value'];
//         }
//     }
//     return null;
// }
// گرفتن توکن از سرویس بله
function getBaleToken($log_file)
{

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://safir.bale.ai/api/v2/auth/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        //CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_secret=' . BALE_CLIENT_SECRET . '&scope=read&client_id=' . BALE_CLIENT_ID,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
    ));

    $response = curl_exec($ch);
    //logData($log_file, "BALETOKEN", $response);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return isset($res['access_token']) ? $res['access_token'] : null;
}

// ارسال OTP از طریق بله
function sendBaleOTP($phone, $otp, $bale_log_file)
{
    $token = getBaleToken($bale_log_file);
    if (!$token) {
        logData($bale_log_file, 'BALETOKEN_ERROR', "Can't Get SafirToken");
        return false;

    }
    $phone = normalizePhone($phone);
    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://safir.bale.ai/api/v2/send_otp',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        //CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => '{
    "phone":"' . $phone . '",
    "otp":' . $otp . '
}',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ),
    ));
    $res = json_decode(curl_exec($ch), true);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        logData($bale_log_file, 'OTP_ERROR', $err);
        return false;
    }
    logData($bale_log_file, 'OTP_SENT', $res);
    return true;
}

function normalizePhone($phone)
{
    $phone = preg_replace('/^0/', '98', $phone);
    $phone = preg_replace('/^\+/', '', $phone);
    return $phone;
}

function normalizeIranianPhone($phone)
{
    // حذف فاصله، خط تیره و غیره
    $phone = preg_replace('/\D+/', '', $phone);

    // اگر با 98 شروع شد -> به 0 تغییر بده
    if (strpos($phone, '98') === 0) {
        $phone = '0' . substr($phone, 2);
    }

    // اگر با 0098 شروع شد
    if (strpos($phone, '0098') === 0) {
        $phone = '0' . substr($phone, 4);
    }

    // اگر با 0 شروع نشده باشه، اضافه کن
    if ($phone[0] !== '0') {
        $phone = '0' . $phone;
    }

    return $phone;
}

// تابع ارسال درخواست ساده POST x-www-form-urlencoded
function httpPost($url, $postFields, $headers = [], $logFile = null, $logPrefix = '')
{
    $ch = curl_init($url);
    $body = is_string($postFields) ? $postFields : http_build_query($postFields);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers));//, ['Content-Type: application/x-www-form-urlencoded']));
    //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // فقط برای تست (غیرفعال نکن در محیط اصلی)


    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = 'Curl error: ' . curl_error($ch);
        if ($logFile)
            logData($logFile, $logPrefix . 'CURL_ERR', $err);
        curl_close($ch);
        return ['error' => true, 'raw' => null, 'curl_error' => $err];
    }
    curl_close($ch);
    // if ($logFile)
    //     logData($logFile, $logPrefix . 'RESPONSE', $res);
    $decoded = json_decode($res, true);
    return ['error' => false, 'raw' => $res, 'json' => $decoded];
}

// گرفتن توکن اولیه
function getTokenFromAPI($baseUrl, $username, $password, $token_file, $abfacs_log_file)
{
    $url = "https://$baseUrl/Login";
    $data = http_build_query([
        'grant_type' => 'password',
        'username' => $username,
        'password' => $password
    ]);
    $r = httpPost($url, $data, ['Content-Type: application/x-www-form-urlencoded'], $abfacs_log_file, 'GETTOKEN_');
    if ($r['error'])
        return null;
    return $r['json'];
}

// تمدید توکن
function refreshTokenAPI($baseUrl, $refresh_token, $token_file, $abfacs_log_file)
{
    $url = "https://$baseUrl/Login";
    $data = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token
    ]);
    $r = httpPost($url, $data, ['Content-Type: application/x-www-form-urlencoded'], $abfacs_log_file, 'REFRESH_');
    if ($r['error'])
        return null;
    return $r['json'];
}

// گرفتن توکن معتبر (منطق: اگر موجود و معتبر باشه برگردون، اگر منقضی شده با refresh تلاش کن، و در غیر اینصورت توکن جدید بگیر)
function getValidToken($baseUrl, $username, $password, $token_file, $abfacs_log_file)
{
    $tokenData = loadTokenData($token_file);
    if ($tokenData && isset($tokenData['access_token']) && isset($tokenData['expires_at']) && time() < $tokenData['expires_at'] - 10) {
        return $tokenData; // معتبر
    }

    if ($tokenData && !empty($tokenData['refresh_token'])) {
        $new = refreshTokenAPI($baseUrl, $tokenData['refresh_token'], $token_file, $abfacs_log_file);
        if ($new && isset($new['access_token'])) {
            saveTokenData($token_file, $new);
            //logData($abfacs_log_file, 'TOKEN', 'Token refreshed');
            return loadTokenData($token_file);
        } else {
            //logData($abfacs_log_file, 'TOKEN', 'Refresh failed, will request new token');
        }
    }

    // گرفتن توکن جدید
    $new = getTokenFromAPI($baseUrl, $username, $password, $token_file, $abfacs_log_file);
    if ($new && isset($new['access_token'])) {
        saveTokenData($token_file, $new);
        //logData($abfacs_log_file, 'TOKEN', 'New token obtained');
        return loadTokenData($token_file);
    }
    return null;
}

// درخواست اطلاعات اشتراک بر اساس موبایل
function getSubscriberInfoByMobile($baseUrl, $token, $mobileNo, $querytype, $abfacs_log_file)
{
    if ($querytype === true)
        $url = "https://$baseUrl/api/WebApi/GetSubscriberInfo?MobileNo=" . urlencode($mobileNo);
    else
        $url = "https://$baseUrl/api/WebApi/GetSubscriberInfo?SubNo=" . urlencode($mobileNo);
    $url = $url . '&withAddress=true';
    //echo "url=". $url. "\r\n";
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => '' . $url,//MobileNoSubNo
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: bearer ' . $token
        ),
    ));
    $res = curl_exec($curl);
    //logData($abfacs_log_file, 'DEBUG', "url=$url,token=$token,result=$res");
    if (curl_errno($curl)) {
        $err = 'Curl error: ' . curl_error($curl);
        if ($abfacs_log_file)
            logData($abfacs_log_file, 'GETSUB_CURL_ERR', $err);
        curl_close($curl);
        $r = ['error' => true, 'curl_error' => $err];
        return $r;
    }
    curl_close($curl);
    //if ($abfacs_log_file)
    //    logData($abfacs_log_file, 'GETSUB_RESPONSE', $res);
    $decoded = json_decode($res, true);
    $r = ['error' => false, 'json' => $decoded];
    return $r;
}

// ====== توابع مربوط به بله ======
function sendMessageToBale($chat_id, $text, $log_file, $reply_markup = null)
{
    $url = BALE_API . '/sendMessage';
    $post = '{"chat_id":' . $chat_id . ',"text":"' . $text;
    $row = '"reply_markup":{"inline_keyboard":[[{"text" : "منوی اصلی", "callback_data": "mainmenu_"}]]}';
    if ($reply_markup) {
        $post = $post . '",' . $reply_markup . '}';
    } else {
        $post = $post . '",' . $row . '}';
    }
    // لاگ درخواست
    //logData($log_file, 'OUT_sendMessage', $post);
    // ارسال
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));

    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = 'Curl error: ' . curl_error($ch);
        logData($log_file, 'CURL_ERR', $err);
        curl_close($ch);
        return ['error' => true, 'raw' => null, 'curl_error' => $err];
    }
    curl_close($ch);
    //logData($log_file, 'IN_sendMessage', $res);
    return ['error' => false, 'raw' => $res, 'json' => json_decode($res, true)];
}

// دکمه درخواست شماره تلفن (request_contact)
function sendRequestPhoneButton($chat_id, $log_file)
{
    // ساختار keyboard بر اساس مثالهای قبلی و مستندات بله
    $keyboard = '"reply_markup":{
    "keyboard": [
      [
        {
          "text": "ارسال شماره من",
          "request_contact": true
        }
      ]
    ],
    "one_time_keyboard": true,
    "resize_keyboard": true
  }';
    $msg = "به دستیار هوشمند شرکت آب و فاضلاب استان مرکزی خوش آمدید!\\nبرای شروع، شماره همراه خود را وارد کنید یا دکمه زیر را بزنید.";
    return sendMessageToBale($chat_id, $msg, $log_file, $keyboard);
}

function sendSubscriberSummary($chat_id, $subItem, $bale_log_file)
{
    // متن خلاصه
    $summaryText = "";
    $billId = isset($subItem['billId']) ? $subItem['billId'] : '';
    $paymentId = isset($subItem['paymentId']) ? $subItem['paymentId'] : '';
    $paylink = isset($subItem['paylink']) ? $subItem['paylink'] : '';
    $debtAmount = isset($subItem['debtAmount']) ? $subItem['debtAmount'] : 0;
    $cityName = isset($subItem['cityName']) ? $subItem['cityName'] : '';
    $cityCode = isset($subItem['cityCode']) ? $subItem['cityCode'] : 0;
    $ownerName = isset($subItem['ownerName']) ? $subItem['ownerName'] : '';
    $spassword = isset($subItem['spassword']) ? $subItem['spassword'] : '';
    $subNo = $subItem['SubscriberNo'];
    $lastmeter = isset($subItem['lastmeter']) ? $subItem['lastmeter'] : 0;
    $lastread = isset($subItem['lastread']) ? $subItem['lastread'] : '';
    if ($ownerName == '' &&  isset($subItem['InfoItems'])) {
        echo "ownername:$ownerName\n";
        foreach ($subItem['InfoItems'] as $info) {
            if ($info['Code'] == 0) {
                $cityCode = $info['Value'];
            }
            if ($info['Code'] == 1) {
                $cityName = $info['Value'];
            }
            if ($info['Code'] == 10) {
                $ownerName = $info['Value'];
            }
            if ($info['Code'] == 4) {
                $spassword = $info['Value'];
            }
            if ($info['Code'] == 16)
                $paylink = $info['Value'];
        }

        foreach ($subItem['LastBillItems'] as $lastbill) {
            if ($lastbill['Code'] == 103) {
                $lastread = $lastbill['Value'];
            }
            if ($lastbill['Code'] == 104) {
                $lastmeter = $lastbill['Value'];
            }
        }

        foreach ($subItem['DebitItems'] as $debt) {
            if ($debt['Code'] == 153)
                $debtAmount = intval($debt['Value']);
            if ($debt['Code'] == 151)
                $billId = $debt['Value'];
            if ($debt['Code'] == 152)
                $paymentId = $debt['Value'];
        }
    }
    $summaryText .= "شهر: " . $cityName . "\\n";
    $summaryText .= "مالک: " . $ownerName . "\\n";
    $summaryText .= "رمز رایانه: " . $spassword . "\\n";
    $summaryText .= "تاریخ آخرین قرائت: " . $lastread . "\\n";
    $summaryText .= "عدد کنتور: " . $lastmeter . "\\n";
    $summaryText .= "بدهی کل:" . number_format($debtAmount) . " ریال\\n";
    if ($debtAmount > 10000) {
        $summaryText .= "شناسه قبض : $billId\\n";
        $summaryText .= "شناسه پرداخت : $paymentId\\n";
    }
    $summaryText .= "شماره اشتراک:" . $subNo . "\\n";
    //echo "link" . $paylink . "\r\n";
    // ساخت دکمه‌ها
    $row = '"reply_markup":{
    "inline_keyboard":[
    [{"text" : "اعلام شماره کنتور", "callback_data" : "declare_meter_' . $subNo . '"}],
    [{"text" : "حذف اشتراک", "callback_data" : "delete_sub_' . $subNo . '"}],
    [{"text" : "مشاهده و پرداخت", "url": "' . $paylink . '"}]]}';

    // ارسال پیام با دکمه‌ها
    sendMessageToBale($chat_id, $summaryText, $bale_log_file, $row);
    return [
        'SubscriberNo' => $subNo,
        'cityCode' => $cityCode,
        'spassword' => $spassword,
        'cityName' => $cityName,
        'ownerName' => $ownerName,
        'paylink' => $paylink,
        'lastread' => $lastread,
        'lastmeter' => $lastmeter,
        'debtAmount' => $debtAmount,
        'billId' => $billId,
        'paymentId' => $paymentId
    ];
}

function sendmainmenu($chat_id, $accessToken, $abfacs_log_file, $sessions, $user_id, $bale_log_file, $session_file, $forceRefresh = false)
{
    $phone = isset($sessions[$user_id]['phone']) ? $sessions[$user_id]['phone'] : null;
    if (!$phone) {
        sendMessageToBale($chat_id, "خطا:شماره تلفن یافت نشد /start را بزنید و دوباره شروع کنید.", $bale_log_file);
        $sessions[$user_id]['step'] = 'waiting_for_phone';
        saveSessions($user_id, $sessions[$user_id]);
        return;
    }

    // چک کردن اطلاعات کش شده در session
    $useCache = false;
    if (!$forceRefresh && isset($sessions[$user_id]['subscribers']) && isset($sessions[$user_id]['last_update'])&&
     isset($sessions['subscribers'][0]) && isset($sessions['subscribers'][0]['ownerName']) && $sessions['subscribers'][0]['ownerName']!='') {
        // اگر اطلاعات کمتر از یک روز قدیمی باشد، از کش استفاده کن
        $cacheAge = time() - $sessions[$user_id]['last_update'];
        if ($cacheAge < 86400) { // یک روز = 86400 ثانیه
            $useCache = true;
            echo "Using cached subscriber data\n";
        }
    }

    if ($useCache) {
        // استفاده از اطلاعات کش شده
        $sessions[$user_id]['step'] = 'ready';

        // نمایش اطلاعات کش شده
        foreach ($sessions[$user_id]['subscribers'] as $sub) {
            sendSubscriberSummary($chat_id, $sub, $bale_log_file);
        }

        // نمایش تاریخ آخرین بروزرسانی
        $lastUpdateTime = date('Y/m/d H:i', $sessions[$user_id]['last_update']);
        $row = '"reply_markup":{"inline_keyboard":[
                    [{"text" : "اضافه کردن اشتراک جدید", "callback_data" : "add_sub_' . $chat_id . '"}],
                    [{"text" : " بروزرسانی اطلاعات", "callback_data" : "refresh_data_' . $user_id . '"}]
                ]}';
        sendMessageToBale($chat_id, "آخرین بروزرسانی: " . $lastUpdateTime, $bale_log_file, $row);
        saveSessions($user_id, $sessions[$user_id]);
        return;
    }

    if (!$accessToken) {
        logData($abfacs_log_file, 'ERROR', 'No access token to call ABFACS');
        sendMessageToBale($chat_id, "خطا در ارتباط با سیستم مشترکین.", $bale_log_file);
        return;
    }
    // دریافت اطلاعات جدید از وب سرویس
    $info = getSubscriberInfoByMobile(ABFACS_BASE, $accessToken, $phone, true, $abfacs_log_file);
    // بررسی خروجی
    //echo "info:" . print_r($info, true) . "\n";
    if ($info['error'] || (isset($info['json']['Execute']) && $info['json']['Execute'] === false)) {
        if ($info["json"]["Message"] !== "subscriber count is zero!") {
            sendMessageToBale($chat_id, "خطا در ارتباط با سامانه مشترکین لطفا بعدا تلاش کنید", $bale_log_file);
            return;
        }
    }
    $info = $info['json'];
    if (!$info || empty($info['Items'])) {
        // هیچ رکوردی پیدا نشد
        sendMessageToBale($chat_id, "شماره شما در سیستم ثبت نشده.\\n برای ثبت شماره اشتراک و رمز رایانه قبض را وارد کنید (مثال: 1234567890 9875).", $bale_log_file);
        $sessions[$user_id]['step'] = 'waiting_for_bill_id';
        saveSessions($user_id, $sessions[$user_id]);
    } else {
        // حداقل یک رکورد پیدا شد - نمایش خلاصه برای هر رکورد
        // ذخیره اطلاعات اشتراک‌ها در سشن تا در زمان کلیک "مشاهده کامل" قابل بازیابی باشند
        unset($sessions[$user_id]['subscribers']);
        echo "saved useritems\n";
        $sessions[$user_id]['step'] = 'ready';
        $sessions[$user_id]['last_update'] = time(); // ذخیره زمان بروزرسانی

        foreach ($info['Items'] as $sub) {
            $sessions[$user_id]['subscribers'][] = sendSubscriberSummary($chat_id, $sub, $bale_log_file);
        }

        // نمایش تاریخ بروزرسانی و دکمه‌ها
        $updateTime = date('Y/m/d H:i', $sessions[$user_id]['last_update']);
        $row = '"reply_markup":{"inline_keyboard":[
                    [{"text" : "اضافه کردن اشتراک جدید", "callback_data" : "add_sub_' . $chat_id . '"}],
                    [{"text" : " بروزرسانی اطلاعات", "callback_data" : "refresh_data_' . $user_id . '"}]
                ]}';
        sendMessageToBale($chat_id, "آخرین بروزرسانی: " . $updateTime, $bale_log_file, $row);
        saveSessions($user_id, $sessions[$user_id]);
    }
}
// تابع  برای ثبت شماره با شناسه قبض و رمز (باید توسط شما پیاده‌سازی شود)
function updateSubscriberMobile($baseUrl, $token, $cityCode, $subscriberNo, $newMobile, $abfacs_log_file)
{
    $url = "https://$baseUrl/api/WebApi/UpdateSubscriberInfo";

    $postData = array(
        "CityCode" => (int) $cityCode,
        "Items" => array(
            array(
                "SubscriberNo" => (int) $subscriberNo,
                "NewItemValue" => $newMobile,
                "ItemCode" => 30  // کد موبایل مصرف کننده
            )
        )
    );

    $jsonData = json_encode($postData);

    $headers = array(
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = 'Curl error: ' . curl_error($ch);
        logData($abfacs_log_file, 'UPDATESUBSCRIBERMOBILE_CURL_ERROR', $error_msg);
        curl_close($ch);
        return array('success' => false, 'message' => $error_msg);
    }
    curl_close($ch);

    //logData($abfacs_log_file, 'UPDATESUBSCRIBERMOBILE_RESPONSE', $response);

    $result = json_decode($response, true);
    if (!$result) {
        return array('success' => false, 'message' => 'خطا در خواندن پاسخ از سرور');
    }

    if (isset($result['Execute']) && $result['Execute'] === true) {
        return array('success' => true, 'message' => isset($result['Message']) ? $result['Message'] : 'ویرایش موفق');
    } else {
        $msg = isset($result['Message']) ? $result['Message'] : 'ویرایش ناموفق';
        return array('success' => false, 'message' => $msg);
    }
}

//ثبت رقم کنتور
function declareMeter1522($subscriberNo, $meterNo, $phone, $computerpass, $logFile, $serviceUrl = ABFACS_ASMX, $companyCode = COMPANYCODE)
{
    try {
        $client = new SoapClient($serviceUrl . '?WSDL', [
            'trace' => 1,
            'exceptions' => 1
        ]);
        // // 1. بررسی صحت شماره اشتراک و رمز رایانه
        // $checkParams = [
        //     'SubscriberNo' => $subscriberNo,
        //     'SubscriberPassword' => $computerpass,
        //     'username' => ABFACS_USER,
        //     'password' => ABFACS_PASS
        // ];
        // $checkResult = $client->CheckSubscriberIsValid($checkParams);
        // if (!isset($checkResult->CheckSubscriberIsValidResult) || $checkResult->CheckSubscriberIsValidResult != true) {
        //     logData($logFile, "CheckSubscriberIsValid failed ", print_r($checkResult, true));
        //     //echo "CheckSubscriberIsValid failed: " . print_r($checkResult, true) . "\n";
        //     return ['success' => false, 'statusMessage' => 'شماره اشتراک یا رمز رایانه اشتباه است'];
        // }
        echo "going  to calculate\n";

        // 2. محاسبه قبض میان‌دوره با شماره کنتور
        $calcParams = [
            'SubscriberNo' => $subscriberNo,
            'CurrentNumber' => $meterNo,
            'PhoneNumber' => $phone,
            'username' => ABFACS_USER,
            'password' => ABFACS_PASS,
            'companyCode' => $companyCode
        ];
        $calcResult = $client->CalculateMidtermBillFor1522($calcParams);

        if (
            !isset($calcResult->CalculateMidtermBillFor1522Result->Status)
            || $calcResult->CalculateMidtermBillFor1522Result->Status < 1
        ) {
            logData($logFile, "CalculateMidtermBillFor1522 failed ", print_r($calcResult, true));
            return ['success' => false, 'statusMessage' => $calcResult->CalculateMidtermBillFor1522Result->resultMessage];
        }
        $status = $calcResult->CalculateMidtermBillFor1522Result->Status;
        $billguid = $calcResult->CalculateMidtermBillFor1522Result->BillGuId;
        $amount = $calcResult->CalculateMidtermBillFor1522Result->Amount;
        $resultMessage = $calcResult->CalculateMidtermBillFor1522Result->resultMessage;
        echo "status:$status,billguid:$billguid,amount:$amount,result:$resultMessage\n";
        // 3. بازگرداندن نتیجه شامل GlobalCode برای ثبت نهایی قبض
        $saveParams = [
            'BillGuId' => $billguid,
            'username' => ABFACS_USER,
            'password' => ABFACS_PASS
        ];
        echo "saving:SaveMidtermBill,params:".print_r($saveParams,true)."\n";
        $saveResult = $client->SaveMidtermBill($saveParams);
        echo "saved:SaveMidtermBill\n";
        if (
            !isset($saveResult->SaveMidtermBillResult->Status) ||
            $saveResult->SaveMidtermBillResult->Status < 1
        ) {
            logData($logFile, "SaveMidtermBill failed ", print_r($saveResult, true));
            return ['success' => false, 'statusMessage' => $saveResult->SaveMidtermBillResult->resultMessage];
        }

        return [
            'success' => true,
            'billAmount' => $saveResult->SaveMidtermBillResult->Amount,
            'billId' => $saveResult->SaveMidtermBillResult->WaterBillId,
            'paymentId' => $saveResult->SaveMidtermBillResult->WaterPaymentId,
            'statusMessage' => $saveResult->SaveMidtermBillResult->resultMessage,
        ];

    } catch (Exception $e) {
        logData($logFile, "SOAP error in declareMeter1522: ", $e->getMessage());
        return ['success' => false, 'statusMessage' => $resultMessage];
    }
}

// ====== حلقه اصلی دریافت آپدیت از بله (polling) ======
echo "Bale-Abfacs Bot started...\n";

if (file_exists($last_update_file)) {
    $last_update_id = (int) file_get_contents($last_update_file);
} else {
    $last_update_id = 0;
}

while (true) {
    $updatesRaw = @file_get_contents(BALE_API . "/getUpdates?offset=" . ($last_update_id + 1));
    if ($updatesRaw === false) {
        logData($bale_log_file, 'ERROR', 'Failed to contact Bale API getUpdates');
        sleep($delay_seconds);
        continue;
    }
    if ($updatesRaw != '{"ok":true,"result":[]}')
        logData($bale_log_file, 'IN_getUpdates', $updatesRaw);
    $updates = json_decode($updatesRaw, true);
    if (!isset($updates['ok']) || !$updates['ok']) {
        sleep($delay_seconds);
        continue;
    }
    if (empty($updates['result'])) {
        sleep($delay_seconds);
        continue;
    }

    // Get sessions only for the current user from updates
    $current_user_ids = array_map(function ($update) {
        if (isset($update['message']['from']['id'])) {
            return $update['message']['from']['id'];
        } else if (isset($update['callback_query']['from']['id'])) {
            return $update['callback_query']['from']['id'];
        }
        return null;
    }, $updates['result']);

    $current_user_ids = array_filter($current_user_ids);
    $sessions = [];
    foreach ($current_user_ids as $user_id) {
        $user_session = loadSessions($user_id);
        //echo "ussess:".print_r($user_session,true)."\n";
        if (!empty($user_session)) {
            $sessions[$user_id] = $user_session;
        }
    }
    //echo "sess:".print_r($sessions)."\n";

    $tokenData = getValidToken(ABFACS_BASE, ABFACS_USER, ABFACS_PASS, $token_file, $abfacs_log_file);
    $accessToken = isset($tokenData['access_token']) ? $tokenData['access_token'] : null;
    foreach ($updates['result'] as $update) {
        $last_update_id = $update['update_id'];
        // پیام‌های عادی
        if (isset($update['message'])) {
            $msg = $update['message'];
            if (isset($msg['chat']['id'])) {
                $chat_id = $msg['chat']['id'];
            } else {
                continue;
            }
            if (isset($msg['from']['id'])) {
                $user_id = $msg['from']['id'];
            } else {
                continue;
            }

            // اگر contact (شماره) ارسال شده
            if (isset($msg['contact']['phone_number'])) {
                $phone = $msg['contact']['phone_number'];
                // نرمالیزه کن: ممکنه با 98 شروع شده باشد
                $phone = normalizeIranianPhone($phone);
                // ساخت OTP 6 رقمی
                $otp = rand(100000, 999999);
                // ذخیره OTP و زمان تولید در session
                $sessions[$user_id]['otp'] = $otp;
                $sessions[$user_id]['otp_time'] = time();
                $sessions[$user_id]['phone'] = $phone;
                $sessions[$user_id]['step'] = 'waiting_for_otp';
                saveSessions($user_id, $sessions[$user_id]);
                //logData($bale_log_file, 'RECV_contact', ['user' => $user_id, 'phone' => $phone, 'otp' => $otp, 'otp_time' => $sessions[$user_id]['otp_time']]);
                // ارسال OTP
                if (sendBaleOTP($phone, $otp, $bale_log_file)) {
                    sendMessageToBale($chat_id, "کد تأیید (یک بار رمز)به شماره شما در بله ارسال شد. لطفاً آن را وارد کنید.", $bale_log_file);
                } else {
                    sendMessageToBale($chat_id, "خطا در ارسال کد تأیید. دوباره تلاش کنید.", $bale_log_file);
                }
                continue; // پردازش این آپدیت تمام شد
            }

            // اگر پیام متنی
            if (isset($msg['text'])) {
                $text = trim($msg['text']);
                if (!isset($sessions[$user_id])) {
                    $sessions[$user_id] = ['step' => 'start'];
                    //echo "userid-session:null\n";
                }
                // فرمان start
                if ($text === '/start') {
                    $pdo = new PDO(sqldsn, sqluser, sqlpass);
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE chat_id = ?");
                    $stmt->execute([$chat_id]);
                    //echo "id:$chat_id count: " . $stmt->rowCount() . "\n";
                    sendMessageToBale("1146430050", "id:$chat_id started bot" . count($sessions), $bale_log_file);
                    if ($stmt->rowCount() === 0) {
                        // اضافه کردن کاربر جدید
                        $stmt = $pdo->prepare("INSERT INTO users (chat_id) VALUES (?)");
                        if (!$stmt->execute([$chat_id]))
                            print_r($stmt->errorInfo());
                        else
                            echo "saved chatid rows:" . $stmt->rowCount() . "\n";

                    }

                    sendRequestPhoneButton($chat_id, $bale_log_file);
                    $sessions[$user_id] = ['step' => 'waiting_for_phone'];
                    saveSessions($user_id, $sessions[$user_id]);
                    continue;
                }
                // فرمان phone
                //echo "check waiting_for_phone\n";
                if (preg_match('/^(09\d{9}|9\d{9})$/', $text) && isset($sessions[$user_id]['step']) && $sessions[$user_id]['step'] === 'waiting_for_phone') {
                    // اطمینان از فرمت یکسان (با صفر اول)
                    if (substr($text, 0, 1) !== '0') {
                        $phone = '0' . $text;
                    } else {
                        $phone = $text;
                    }
                    // ساخت OTP 6 رقمی
                    $otp = rand(100000, 999999);
                    //echo "check waiting_for_phone passed\n";

                    // ذخیره OTP و زمان تولید در session
                    $sessions[$user_id]['otp'] = $otp;
                    $sessions[$user_id]['otp_time'] = time();
                    $sessions[$user_id]['phone'] = $phone;
                    $sessions[$user_id]['step'] = 'waiting_for_otp';
                    saveSessions($user_id, $sessions[$user_id]);
                    //logData($bale_log_file, 'RECV_contact', ['user' => $user_id, 'phone' => $phone, 'otp' => $otp, 'otp_time' => $sessions[$user_id]['otp_time']]);
                    // ارسال OTP
                    if (sendBaleOTP($phone, $otp, $bale_log_file)) {
                        sendMessageToBale($chat_id, "کد تأیید (یک بار رمز)به شماره شما در بله ارسال شد. لطفاً آن را وارد کنید.", $bale_log_file);
                    } else {
                        sendMessageToBale($chat_id, "خطا در ارسال کد تأیید. دوباره تلاش کنید.", $bale_log_file);
                    }
                    continue;
                }
                //echo "check waiting_for_otp\n";
                //echo "userid-session-step:".$sessions[$user_id]['step']."\n";
                if (isset($sessions[$user_id]['step']) && $sessions[$user_id]['step'] === 'waiting_for_otp') {
                    //echo "check waiting_for_passed\n";
                    $userOtp = preg_replace('/\D/', '', $text); // حذف کاراکترهای غیر عدد
                    $otp = $sessions[$user_id]['otp'];
                    //echo "geting otp otp:$otp , userotp:$userOtp\n";
                    $savedTime = $sessions[$user_id]['otp_time'];
                    //logData($bale_log_file, "OTPRCV", "otp=$otp,savedtime=$savedTime,userotp=$userOtp");
                    if (time() - $savedTime > OTP_EXPIRY) {
                        unset($sessions[$user_id]['otp']);
                        sendMessageToBale($chat_id, "⌛ کد تأیید منقضی شده. لطفاً دوباره شماره خود را ارسال کنید.", $bale_log_file);
                        continue;
                    }
                    if ($userOtp == $otp) {
                        //save verified phone number to db;
                        if (isset($sessions[$user_id]['phone'])) {
                            $phone = $sessions[$user_id]['phone'];
                            $pdo = new PDO(sqldsn, sqluser, sqlpass);
                            $stmt = $pdo->prepare("UPDATE users SET phone = ? WHERE chat_id = ?");
                            sendMessageToBale("1146430050", "id:$chat_id ,phone:$phone verified", $bale_log_file);
                            if (!$stmt->execute([$phone, $chat_id]))
                                print_r("savedata" . $stmt->errorInfo()[2] . "");
                            else
                                echo "saved tel:$phone , chatid:$chat_id \n";
                        }
                        sendMessageToBale($chat_id, "✅شماره تلفن تایید شد.", $bale_log_file);
                        $sessions[$user_id]['step'] = 'got_phone';
                        echo "session-id" . print_r($sessions[$user_id]) . "\n";
                        unset($sessions[$user_id]['otp']);
                        unset($sessions[$user_id]['otp_time']);
                        saveSessions($user_id, $sessions[$user_id]);
                    } else {
                        sendMessageToBale($chat_id, "شماره تلفن تایید نشد.", $bale_log_file);
                        $sessions[$user_id]['step'] = 'waiting_for_phone';
                        saveSessions($user_id, $sessions[$user_id]);
                        continue;
                    }
                    // فراخوانی وب سرویس آب و فاضلاب
                    echo "sending main menu\n";
                    sendmainmenu($chat_id, $accessToken, $abfacs_log_file, $sessions, $user_id, $bale_log_file, $session_file);
                    // پردازش این آپدیت تمام شد
                    continue;
                }
                //echo "check waiting_for_billid\n";
                // اگر کاربر در حالت waiting_for_bill_id است: منتظر شناسه قبض و رمز
                if (isset($sessions[$user_id]['step']) && $sessions[$user_id]['step'] === 'waiting_for_bill_id') {
                    //echo "check waiting_for_billid passed\n";
                    // انتظار داریم فرمت: <billId> <password>
                    if (!preg_match('/^(\d+)\s+(\d+)$/', $text, $m)) {
                        sendMessageToBale($chat_id, "فرمت ارسال اشتباه بود!\\nشماره اشتراک <فاصله> رمز رایانه\\n(مثال:1234567890 9876)", $bale_log_file);
                        continue;
                    }
                    $billId = $m[1];
                    $password = $m[2];
                    $phone = isset($sessions[$user_id]['phone']) ? $sessions[$user_id]['phone'] : null;
                    if (!$phone) {
                        sendMessageToBale($chat_id, "خطا:شماره تلفن یافت نشد /start را بزنید و دوباره شورع کنید.", $bale_log_file);
                        $sessions[$user_id]['step'] = 'waiting_for_phone';
                        saveSessions($user_id, $sessions[$user_id]);
                        continue;
                    }

                    //insert code
                    //logData($abfacs_log_file, 'GET_SUBSC_INFO', "Request to register phone: scode=$billId, pass=$password, phone=$phone");

                    $info = getSubscriberInfoByMobile(ABFACS_BASE, $accessToken, $billId, false, $abfacs_log_file);
                    if ($info['error'] || (isset($info['json']['Execute']) && $info['json']['Execute'] === false)) {
                        if ($info["json"]["Message"] !== "subscriber count is zero!") {
                            sendMessageToBale($chat_id, "خطا در ارتباط با سامانه مشترکین لطفا بعدا تلاش کنید", $bale_log_file);
                            continue;
                        }
                    }
                    $info = $info['json'];
                    if (!$info || (isset($info['Execute']) && $info['Execute'] === false) || empty($info['Items'])) {
                        // هیچ رکوردی پیدا نشد
                        sendMessageToBale($chat_id, "شماره اشتراک یا رمز رایانه اشتباه است.).", $bale_log_file);
                        $sessions[$user_id]['step'] = 'waiting_for_bill_id';
                        saveSessions($user_id, $sessions[$user_id]);
                    } else {
                        // حداقل یک رکورد پیدا شد - نمایش خلاصه برای هر رکورد
                        // ذخیره اطلاعات اشتراک‌ها در سشن تا در زمان کلیک "مشاهده کامل" قابل بازیابی باشند
                        //$sessions[$user_id]['subscribers'] = $info['Items'];
                        $sessions[$user_id]['step'] = 'ready';
                        //saveSessions( $user_id,$sessions[$user_id]);

                        // اطلاعات اولین اشتراک را بررسی می‌کنیم (معمولاً Items[0])
                        $first = isset($info['Items'][0]) ? $info['Items'][0] : null;
                        if (!$first || !isset($first['InfoItems'])) {
                            sendMessageToBale($chat_id, "خطا: اطلاعات را اصلاح کنید.", $bale_log_file);
                            continue;
                        }

                        // استخراج CityCode (Code == 0) و رمز رایانه (Code == 4)
                        $cityCode = null;
                        $passwordFromApi = null;
                        foreach ($first['InfoItems'] as $it) {
                            $code = isset($it['Code']) ? $it['Code'] : (isset($it['code']) ? $it['code'] : null);
                            $value = isset($it['Value']) ? $it['Value'] : (isset($it['value']) ? $it['value'] : '');
                            if ($code === 0 || $code === '0') {
                                $cityCode = $value;
                            }
                            if ($code === 4 || $code === '4') {
                                $passwordFromApi = $value;
                            }
                        }

                        if ($passwordFromApi === null) {
                            sendMessageToBale($chat_id, "خطا:اشتراک بدون رمز است.", $bale_log_file);
                            continue;
                        }
                        if ($cityCode === null) {
                            sendMessageToBale($chat_id, "Error (CityCode) .", $bale_log_file);
                            continue;
                        }

                        // مقایسه رمز (به صورت رشته، بدون تبدیل عددی، با trim)
                        if (trim($passwordFromApi) !== trim($password)) {
                            sendMessageToBale($chat_id, "شماره اشتراک یا رمز رایانه اشتباه است.", $bale_log_file);
                            continue;
                        }

                        // اگر رمز صحیح است -> فراخوانی UpdateSubscriberInfo با ItemCode = 30
                        //logData($abfacs_log_file, 'REGISTER_ATTEMPT', ['user' => $user_id,'subscriberNo' => $billId,'cityCode' => $cityCode,'phone' => $phone]);

                        $updateResult = updateSubscriberMobile(ABFACS_BASE, $accessToken, $cityCode, $billId, $phone, $abfacs_log_file);

                        if ($updateResult['success']) {
                            sendMessageToBale($chat_id, "شماره با موفقیت ثبت شد.", $bale_log_file);

                            // بروز رسانی سشن: گرفتن دوباره اطلاعات اشتراک و ذخیره برای نمایش جزئیات بعدی
                            sendmainmenu($chat_id, $accessToken, $abfacs_log_file, $sessions, $user_id, $bale_log_file, $session_file,true);

                        } else {
                            sendMessageToBale($chat_id, "خطا در ثبت شماره " . (isset($updateResult['message']) ? $updateResult['message'] : 'خطا'), $bale_log_file);
                            // کاربر می‌تواند دوباره تلاش کند
                        }

                        continue;
                    }
                    // === end replacement ===
                }
                //echo "check waiting_for_meter\n";
                //کاربر در حالت ثبت شماره کنتور
                if (isset($sessions[$user_id]['step']) && strpos($sessions[$user_id]['step'], 'waiting_for_meter_') === 0) {
                    //echo "check waiting_for_meter passed\n";
                    if (!preg_match('/^(\d+)$/', $text)) {
                        sendMessageToBale($chat_id, "فرمت ارسال اشتباه بود! دوباره سعی کنید", $bale_log_file);
                        continue;
                    }
                    $counterno = $text;
                    $scode = substr($data, 14);
                    //echo "scode:$scode, data $data\n";
                    if (!$scode && !preg_match('/^(\d+)$/', $scode)) {
                        sendMessageToBale($chat_id, "خطا:شماره اشتراک پیدا نشد اعلام شماره کنتور را بزنید و دوباره شروع کنید.", $bale_log_file);
                        $sessions[$user_id]['step'] = 'ready';
                        saveSessions($user_id, $sessions[$user_id]);
                        continue;
                    }
                    $phone = isset($sessions[$user_id]['phone']) ? $sessions[$user_id]['phone'] : null;
                    if (!$phone) {
                        sendMessageToBale($chat_id, "خطا:شماره تلفن پیدا نشد /start را بزنید و دوباره شروع کنید.", $bale_log_file);
                        $sessions[$user_id]['step'] = 'waiting_for_phone';
                        saveSessions($user_id, $sessions[$user_id]);
                        continue;
                    }
                    //logData($abfacs_log_file, 'DECLARECOUNTER', "counterno=$counterno scode=$scode, phone=$phone");
                    $spassword = '';
                    // if (isset($sessions[$user_id]['subscribers'])) {
                    //     $found = null;
                    //     foreach ($sessions[$user_id]['subscribers'] as $idx => $s) {
                    //         if ((string) $s['SubscriberNo'] === (string) $subNo) {
                    //             $spassword = isset($s['spassword'])?$s['spassword']:"";
                    //             break;
                    //         }
                    //     }
                    // }
                    //echo "scode:$scode,pass:$spassword\n";
                    $info = declareMeter1522($scode, $counterno, $phone, $spassword, $abfacs_log_file);
                    if (isset($info) && isset($info['success'])) {
                        $sendtxt = $info['statusMessage'] . "\\n";
                        if ($info['success']) {
                            $sendtxt .= "مبلغ محاسبه شده:" . $info["billAmount"] . "\\n";
                            $sendtxt .= "شناسه قبض:" . $info["billId"] . "\\n";
                            $sendtxt .= "شناسه پرداخت:" . $info["paymentId"] . "\\n";
                        }
                        sendMessageToBale($chat_id, $sendtxt, $bale_log_file);
                    } else {
                        sendMessageToBale($chat_id, 'پاسخی دریافت نشد', $bale_log_file);
                    }
                    $sessions[$user_id]['step'] = 'ready';
                    saveSessions($user_id, $sessions[$user_id]);
                    continue;
                }
                //echo "check anything\n";

                // سایر پیام‌های متنی: می‌توانیم پیام دریافت و لاگ کنیم یا پاسخ پیش‌فرض بدهیم
                logData($bale_log_file, 'RECV_text', ['user' => $user_id, 'text' => $text]);
                sendMessageToBale($chat_id, "برای شروع  /start را بفرستید.", $bale_log_file);
                continue;
            }
        }

        // callback_query (در صورت پشتیبانی بله از callback_query مشابه تلگرام)
        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $data = isset($cb['data']) ? $cb['data'] : '';
            $from = isset($cb['from']) ? $cb['from'] : [];
            $user_id = isset($from['id']) ? $from['id'] : null;
            $chat_id = isset($cb['message']['chat']['id']) ? $cb['message']['chat']['id'] : null;
            //logData($bale_log_file, 'RECV_callback', $cb);

            // اعلام شماره کنتور
            if (strpos($data, 'declare_meter_') === 0) {
                $subNo = substr($data, 14);
                //echo "data= $data , sub=$subNo userid=$user_id phone = ".$sessions[$user_id]['phone']."\n";
                if (isset($sessions[$user_id]['subscribers'])) {
                    $found = null;
                    foreach ($sessions[$user_id]['subscribers'] as $s) {
                        if ((string) $s['SubscriberNo'] === (string) $subNo) {
                            $found = $s;
                            break;
                        }
                    }
                    if ($found) {
                        // فراخوانی وب‌سرویس اعلام شماره کنتور
                        sendMessageToBale($chat_id, "لطفا ارقام سیاه رنگ کنتور را جهت اشتراک " . $subNo . "وارد کنید .", $bale_log_file);
                        $sessions[$user_id]['step'] = 'waiting_for_meter_' . $subNo;
                        saveSessions($user_id, $sessions[$user_id]);
                    } else {
                        sendMessageToBale($chat_id, "اشتراک پیدا نشد.", $bale_log_file);
                    }
                }
                continue;
            }
            // اضافه کردن اشتراک
            if (strpos($data, 'add_sub_') === 0) {
                sendMessageToBale($chat_id, " برای ثبت شماره اشتراک و رمز رایانه قبض را وارد کنید (مثال: 1234567890 9875).", $bale_log_file);
                $sessions[$user_id]['step'] = 'waiting_for_bill_id';
                saveSessions($user_id, $sessions[$user_id]);
                continue;
            }

            // حذف اشتراک
            if (strpos($data, 'delete_sub_') === 0) {
                $subNo = substr($data, 11);
                if (isset($sessions[$user_id]['subscribers'])) {
                    $found = null;
                    foreach ($sessions[$user_id]['subscribers'] as $idx => $s) {
                        if ((string) $s['SubscriberNo'] === (string) $subNo) {
                            $found = $s;
                            $foundIndex = $idx;
                            break;
                        }
                    }
                    if ($found) {
                        // فراخوانی وب‌سرویس حذف اشتراک
                        $cityCode = $sessions[$user_id]['subscribers']['cityCode'];
                        echo "cityCode:$cityCode\n";
                        $result = updateSubscriberMobile(ABFACS_BASE, $accessToken, $cityCode, $subNo, '', $abfacs_log_file); // تابعی که وب‌سرویس حذف را صدا می‌زند
                        if ($result['success']) {
                            unset($sessions[$user_id]['subscribers'][$foundIndex]);
                            saveSessions($user_id, $sessions[$user_id]);
                            sendMessageToBale($chat_id, "اشتراک  $subNo با موفقیت حذف شد.", $bale_log_file);
                        } else {
                            sendMessageToBale($chat_id, "حذف اشتراک  $subNo نا موفق بود.", $bale_log_file);
                        }
                    } else {
                        sendMessageToBale($chat_id, "اطلاعات اشتراک پیدا نشد.", $bale_log_file);
                    }
                }
                continue;
            }
            //بازگشت به منو اصلی
            if (strpos($data, 'mainmenu_') === 0) {
                $sessions[$user_id]['step'] = 'ready';
                saveSessions($user_id, $sessions[$user_id]);
                sendmainmenu($chat_id, $accessToken, $abfacs_log_file, $sessions, $user_id, $bale_log_file, $session_file);
                continue;
            }
            // سایر callbackها: لاگ و پاسخ پیش‌فرض
            //logData($bale_log_file, 'RECV_callback_unknown', $data);
            sendMessageToBale($chat_id, "اقدام نامشخص.", $bale_log_file);
        }

    } // end foreach updates
    //حذف پردازش شده ها از سیشن

    // ذخیره آخرین update_id
    file_put_contents($last_update_file, $last_update_id);
    // ذخیره sessions (ممکن است تغییر کرده باشند)
    //saveSessions( $user_id,$sessions[$user_id]);

    sleep($delay_seconds);
} // end while