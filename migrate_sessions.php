<?php
// تنظیمات دیتابیس
$pdo = new PDO("mysql:host=localhost;dbname=test;charset=utf8", "root", "m@101270");

// مسیر فایل sessions.json
$session_file = __DIR__ . '/sessions.json';

// ایجاد جدول sessions اگر وجود ندارد
$pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
    user_id BIGINT PRIMARY KEY,
    session_data TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
echo "database created";
// خواندن فایل JSON
if (!file_exists($session_file)) {
    die("فایل sessions.json وجود ندارد!\n");
}

$json_content = file_get_contents($session_file);
$sessions = json_decode($json_content, true);

if ($sessions === null) {
    die("خطا در خواندن فایل JSON!\n");
}

// آماده‌سازی query
$stmt = $pdo->prepare("INSERT INTO sessions (user_id, session_data) VALUES (?, ?) 
                       ON DUPLICATE KEY UPDATE session_data = ?");

// انتقال داده‌ها
$success_count = 0;
$error_count = 0;

foreach ($sessions as $user_id => $session_data) {
    $json_data = json_encode($session_data);
    
    try {
        if ($stmt->execute([$user_id, $json_data, $json_data])) {
            $success_count++;
        } else {
            $error_count++;
            echo "خطا در ذخیره‌سازی داده برای کاربر $user_id\n";
        }
    } catch (PDOException $e) {
        $error_count++;
        echo "خطا در ذخیره‌سازی داده برای کاربر $user_id: " . $e->getMessage() . "\n";
    }
}

echo "عملیات انتقال به پایان رسید.\n";
echo "تعداد رکوردهای موفق: $success_count\n";
echo "تعداد خطاها: $error_count\n";

// ایجاد نسخه پشتیبان از فایل اصلی
if ($success_count > 0) {
    $backup_file = $session_file . '.bak.' . date('Y-m-d-His');
    if (copy($session_file, $backup_file)) {
        echo "نسخه پشتیبان در فایل $backup_file ذخیره شد.\n";
    } else {
        echo "خطا در ایجاد نسخه پشتیبان!\n";
    }
}
