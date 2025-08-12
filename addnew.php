<?php
session_start();
if (empty($_SESSION['admin_id']) || (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'admin')) {
    header('Location: login.php');
    exit;
}

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// DB connection
$displayName = 'المدير';
$message = '';
$messageType = '';

try {
    $dbCandidates = [
        __DIR__ . '/../../../db_config/db.php',
        dirname(__DIR__, 2) . '/db_config/db.php',
        dirname(__DIR__, 1) . '/db_config/db.php',
        (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : '') . '/db_config/db.php'
    ];
    $dbPath = null;
    foreach ($dbCandidates as $candidate) {
        if ($candidate && is_file($candidate)) { $dbPath = $candidate; break; }
    }
    if (!$dbPath) {
        throw new Exception('ملف إعدادات قاعدة البيانات غير موجود: /db_config/db.php');
    }
    require_once $dbPath;
    if (!isset($conn) || !$conn) {
        if (isset($pdo) && $pdo instanceof PDO) {
            $conn = $pdo;
        } elseif (isset($db) && $db instanceof PDO) {
            $conn = $db;
        } elseif (isset($dbh) && $dbh instanceof PDO) {
            $conn = $dbh;
        } elseif (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
            $conn = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            throw new Exception('تعذر تهيئة الاتصال بقاعدة البيانات ($conn)');
        }
    }

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    
    if ($adminId > 0) {
        $stmt = $conn->prepare('SELECT username FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['username'])) {
                $displayName = $row['username'];
            }
        }
    }

    // Discover available columns in users table to build dynamic INSERT
    $userColumns = [];
    try {
        $colsStmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $colsStmt->execute();
        $userColumns = array_flip(array_map(function($r){ return $r['COLUMN_NAME']; }, $colsStmt->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $e) {
        $userColumns = [];
    }

    // معالجة إضافة مسوق جديد
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_marketer'])) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $storeName = trim($_POST['store_name'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $state = trim($_POST['state'] ?? '');

        // التحقق من صحة البيانات
        $errors = [];
        
        if (empty($firstName)) {
            $errors[] = 'الاسم الأول مطلوب';
        }
        if (empty($lastName)) {
            $errors[] = 'اللقب مطلوب';
        }
        if (empty($email)) {
            $errors[] = 'البريد الإلكتروني مطلوب';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'البريد الإلكتروني غير صحيح';
        }
        if (empty($storeName)) {
            $errors[] = 'اسم المتجر مطلوب';
        }
        if (empty($password)) {
            $errors[] = 'كلمة المرور مطلوبة';
        } elseif (strlen($password) < 6) {
            $errors[] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        }
        if (empty($phone)) {
            $errors[] = 'رقم الهاتف مطلوب';
        }
        if (empty($state)) {
            $errors[] = 'الولاية مطلوبة';
        }

        if (empty($errors)) {
            // التحقق من عدم تكرار البريد الإلكتروني
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'البريد الإلكتروني مستخدم مسبقاً';
            }
            // التحقق من عدم تكرار رقم الهاتف إذا كان العمود موجود
            if (isset($userColumns['phone'])) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
                $stmt->execute([$phone]);
                if ($stmt->fetch()) {
                    $errors[] = 'رقم الهاتف مستخدم مسبقاً';
                }
            }
            // التحقق من عدم تكرار اسم المتجر إذا كان العمود موجود
            if (isset($userColumns['store_name'])) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE store_name = ? LIMIT 1");
                $stmt->execute([$storeName]);
                if ($stmt->fetch()) {
                    $errors[] = 'اسم المتجر مستخدم مسبقاً';
                }
            }
        }

        if (empty($errors)) {
            try {
                // تشفير كلمة المرور
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // تحديد اسم المستخدم
                $username = $firstName . ' ' . $lastName;
                
                // بناء الإدراج ديناميكياً حسب الأعمدة المتاحة
                $data = [
                    'username' => $username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => $hashedPassword,
                    'phone' => $phone,
                    'state' => $state,
                    'store_name' => $storeName,
                    'role' => 'user',
                    'balance' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                // فلترة حسب الأعمدة الموجودة فعلاً
                $insertCols = [];
                $placeholders = [];
                $insertVals = [];
                foreach ($data as $col => $val) {
                    if (isset($userColumns[$col])) {
                        $insertCols[] = $col;
                        $placeholders[] = '?';
                        $insertVals[] = $val;
                    }
                }
                if (empty($insertCols)) {
                    throw new Exception('تعذر تحديد أعمدة الإدراج في جدول users');
                }
                $sql = 'INSERT INTO users (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->execute($insertVals);
                
                $message = 'تم إضافة المسوق بنجاح!';
                $messageType = 'success';
                
                // تفريغ النموذج بعد النجاح
                $firstName = $lastName = $email = $storeName = $password = $phone = $state = '';
                
            } catch (PDOException $e) {
                $errors[] = 'حدث خطأ أثناء إضافة المسوق: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }

} catch (Throwable $e) {
    error_log($e->getMessage());
    $message = 'حدث خطأ في النظام';
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>لوحة الإدارة - إضافة مسوق جديد</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Cairo', sans-serif; background-color: #f7f7f7; }
    @media (min-width: 1024px) { .main-content { margin-right: 256px; } }
    .page-transition { opacity: 0; transform: translateY(20px); animation: pageLoad 0.5s ease-out forwards; }
    @keyframes pageLoad { to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 768px) { .mobile-optimized { padding: 1rem 0.75rem; } }
    .form-card { transition: all 0.3s ease; }
    .form-card:hover { box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    .input-group { position: relative; }
    .input-group i { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #6b7280; }
    .input-group input, .input-group select { padding-right: 40px; }
  </style>
</head>
<body class="bg-gray-50">
  <div class="flex h-screen bg-gray-50">
    <?php include 'sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
      <!-- Header -->
      <header class="bg-white border-b border-gray-200 sticky top-0 z-30">
        <div class="px-4 py-3 flex items-center justify-between">
          <div class="flex items-center gap-4">
            <button id="mobileMenuBtn" onclick="openSidebar()" class="lg:hidden p-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors">
              <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div class="flex items-center gap-2 font-bold text-gray-800">
              <i class="fa-solid fa-user-plus text-indigo-600"></i>
              <span>لوحة الإدارة - إضافة مسوق جديد</span>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <button class="relative text-gray-600 hover:text-gray-800" title="إشعارات">
              <i class="fa-regular fa-bell text-lg"></i>
            </button>
            <div class="flex items-center gap-2">
              <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-blue-700">
                <i class="fa-solid fa-user-shield"></i>
              </div>
              <span class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
          </div>
        </div>
      </header>

      <!-- Main -->
      <main class="flex-1 overflow-x-hidden overflow-y-auto">
        <div class="max-w-4xl mx-auto px-4 py-6 space-y-6 page-transition main-content">
          
          <!-- Welcome Section -->
          <section class="bg-gradient-to-l from-blue-600 to-blue-800 rounded-xl p-6 text-white">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
              <div>
                <h1 class="text-xl sm:text-2xl font-bold flex items-center gap-2">
                  <i class="fa-solid fa-user-plus"></i>
                  إضافة مسوق جديد
                </h1>
                <p class="opacity-90 mt-1">أضف مسوق جديد إلى النظام لبدء العمل</p>
              </div>
              <div class="text-center">
                <div class="text-2xl font-extrabold">نموذج التسجيل</div>
                <div class="text-sm opacity-90">املأ البيانات المطلوبة</div>
              </div>
            </div>
          </section>

          <?php if (!empty($message)): ?>
            <div class="<?php echo $messageType === 'success' ? 'bg-green-100 border-green-500 text-green-800' : 'bg-red-100 border-red-500 text-red-800'; ?> border-r-4 p-4 rounded-lg">
              <div class="flex items-center gap-2">
                <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <div><?php echo $message; ?></div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Form Section -->
          <section class="form-card bg-white rounded-xl p-8 shadow-sm border border-gray-100">
            <form method="POST" action="" class="space-y-6">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- الاسم الأول -->
                <div class="input-group">
                  <label for="first_name" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-user text-blue-600 ml-1"></i>
                    الاسم الأول
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-user"></i>
                    <input
                      type="text"
                      id="first_name"
                      name="first_name"
                      value="<?php echo htmlspecialchars($firstName ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                      placeholder="أدخل الاسم الأول"
                      required
                    >
                  </div>
                </div>

                <!-- اللقب -->
                <div class="input-group">
                  <label for="last_name" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-user text-blue-600 ml-1"></i>
                    اللقب
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-user"></i>
                    <input
                      type="text"
                      id="last_name"
                      name="last_name"
                      value="<?php echo htmlspecialchars($lastName ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                      placeholder="أدخل اللقب"
                      required
                    >
                  </div>
                </div>

                <!-- البريد الإلكتروني -->
                <div class="input-group">
                  <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-envelope text-blue-600 ml-1"></i>
                    البريد الإلكتروني
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-envelope"></i>
                    <input
                      type="email"
                      id="email"
                      name="email"
                      value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                      placeholder="أدخل البريد الإلكتروني"
                      required
                    >
                  </div>
                </div>

                <!-- رقم الهاتف -->
                <div class="input-group">
                  <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-phone text-blue-600 ml-1"></i>
                    رقم الهاتف
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-phone"></i>
                    <input
                      type="tel"
                      id="phone"
                      name="phone"
                      value="<?php echo htmlspecialchars($phone ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                      placeholder="أدخل رقم الهاتف"
                      required
                    >
                  </div>
                </div>

                <!-- الولاية -->
                <div class="input-group">
                  <label for="state" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-map-marker-alt text-blue-600 ml-1"></i>
                    الولاية
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-map-marker-alt"></i>
                    <select
                      id="state"
                      name="state"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors appearance-none"
                      required
                    >
                      <option value="">اختر الولاية</option>
                      <option value="01 - أدرار" <?php echo (isset($wilaya) && $wilaya === '01 - أدرار') ? 'selected' : ''; ?>>01 - أدرار</option>
                      <option value="02 - الشلف" <?php echo (isset($wilaya) && $wilaya === '02 - الشلف') ? 'selected' : ''; ?>>02 - الشلف</option>
                      <option value="03 - الأغواط" <?php echo (isset($wilaya) && $wilaya === '03 - الأغواط') ? 'selected' : ''; ?>>03 - الأغواط</option>
                      <option value="04 - أم البواقي" <?php echo (isset($wilaya) && $wilaya === '04 - أم البواقي') ? 'selected' : ''; ?>>04 - أم البواقي</option>
                      <option value="05 - باتنة" <?php echo (isset($wilaya) && $wilaya === '05 - باتنة') ? 'selected' : ''; ?>>05 - باتنة</option>
                      <option value="06 - بجاية" <?php echo (isset($wilaya) && $wilaya === '06 - بجاية') ? 'selected' : ''; ?>>06 - بجاية</option>
                      <option value="07 - بسكرة" <?php echo (isset($wilaya) && $wilaya === '07 - بسكرة') ? 'selected' : ''; ?>>07 - بسكرة</option>
                      <option value="08 - بشار" <?php echo (isset($wilaya) && $wilaya === '08 - بشار') ? 'selected' : ''; ?>>08 - بشار</option>
                      <option value="09 - البليدة" <?php echo (isset($wilaya) && $wilaya === '09 - البليدة') ? 'selected' : ''; ?>>09 - البليدة</option>
                      <option value="10 - البويرة" <?php echo (isset($wilaya) && $wilaya === '10 - البويرة') ? 'selected' : ''; ?>>10 - البويرة</option>
                      <option value="11 - تمنراست" <?php echo (isset($wilaya) && $wilaya === '11 - تمنراست') ? 'selected' : ''; ?>>11 - تمنراست</option>
                      <option value="12 - تبسة" <?php echo (isset($wilaya) && $wilaya === '12 - تبسة') ? 'selected' : ''; ?>>12 - تبسة</option>
                      <option value="13 - تلمسان" <?php echo (isset($wilaya) && $wilaya === '13 - تلمسان') ? 'selected' : ''; ?>>13 - تلمسان</option>
                      <option value="14 - تيارت" <?php echo (isset($wilaya) && $wilaya === '14 - تيارت') ? 'selected' : ''; ?>>14 - تيارت</option>
                      <option value="15 - تيزي وزو" <?php echo (isset($wilaya) && $wilaya === '15 - تيزي وزو') ? 'selected' : ''; ?>>15 - تيزي وزو</option>
                      <option value="16 - الجزائر" <?php echo (isset($wilaya) && $wilaya === '16 - الجزائر') ? 'selected' : ''; ?>>16 - الجزائر</option>
                      <option value="17 - الجلفة" <?php echo (isset($wilaya) && $wilaya === '17 - الجلفة') ? 'selected' : ''; ?>>17 - الجلفة</option>
                      <option value="18 - جيجل" <?php echo (isset($wilaya) && $wilaya === '18 - جيجل') ? 'selected' : ''; ?>>18 - جيجل</option>
                      <option value="19 - سطيف" <?php echo (isset($wilaya) && $wilaya === '19 - سطيف') ? 'selected' : ''; ?>>19 - سطيف</option>
                      <option value="20 - سعيدة" <?php echo (isset($wilaya) && $wilaya === '20 - سعيدة') ? 'selected' : ''; ?>>20 - سعيدة</option>
                      <option value="21 - سكيكدة" <?php echo (isset($wilaya) && $wilaya === '21 - سكيكدة') ? 'selected' : ''; ?>>21 - سكيكدة</option>
                      <option value="22 - سيدي بلعباس" <?php echo (isset($wilaya) && $wilaya === '22 - سيدي بلعباس') ? 'selected' : ''; ?>>22 - سيدي بلعباس</option>
                      <option value="23 - عنابة" <?php echo (isset($wilaya) && $wilaya === '23 - عنابة') ? 'selected' : ''; ?>>23 - عنابة</option>
                      <option value="24 - قالمة" <?php echo (isset($wilaya) && $wilaya === '24 - قالمة') ? 'selected' : ''; ?>>24 - قالمة</option>
                      <option value="25 - قسنطينة" <?php echo (isset($wilaya) && $wilaya === '25 - قسنطينة') ? 'selected' : ''; ?>>25 - قسنطينة</option>
                      <option value="26 - المدية" <?php echo (isset($wilaya) && $wilaya === '26 - المدية') ? 'selected' : ''; ?>>26 - المدية</option>
                      <option value="27 - مستغانم" <?php echo (isset($wilaya) && $wilaya === '27 - مستغانم') ? 'selected' : ''; ?>>27 - مستغانم</option>
                      <option value="28 - المسيلة" <?php echo (isset($wilaya) && $wilaya === '28 - المسيلة') ? 'selected' : ''; ?>>28 - المسيلة</option>
                      <option value="29 - معسكر" <?php echo (isset($wilaya) && $wilaya === '29 - معسكر') ? 'selected' : ''; ?>>29 - معسكر</option>
                      <option value="30 - ورقلة" <?php echo (isset($wilaya) && $wilaya === '30 - ورقلة') ? 'selected' : ''; ?>>30 - ورقلة</option>
                      <option value="31 - وهران" <?php echo (isset($wilaya) && $wilaya === '31 - وهران') ? 'selected' : ''; ?>>31 - وهران</option>
                      <option value="32 - البيض" <?php echo (isset($wilaya) && $wilaya === '32 - البيض') ? 'selected' : ''; ?>>32 - البيض</option>
                      <option value="33 - اليزي" <?php echo (isset($wilaya) && $wilaya === '33 - اليزي') ? 'selected' : ''; ?>>33 - اليزي</option>
                      <option value="34 - برج بوعريريج" <?php echo (isset($wilaya) && $wilaya === '34 - برج بوعريريج') ? 'selected' : ''; ?>>34 - برج بوعريريج</option>
                      <option value="35 - بومرداس" <?php echo (isset($wilaya) && $wilaya === '35 - بومرداس') ? 'selected' : ''; ?>>35 - بومرداس</option>
                      <option value="36 - الطارف" <?php echo (isset($wilaya) && $wilaya === '36 - الطارف') ? 'selected' : ''; ?>>36 - الطارف</option>
                      <option value="37 - تندوف" <?php echo (isset($wilaya) && $wilaya === '37 - تندوف') ? 'selected' : ''; ?>>37 - تندوف</option>
                      <option value="38 - تيسمسيلت" <?php echo (isset($wilaya) && $wilaya === '38 - تيسمسيلت') ? 'selected' : ''; ?>>38 - تيسمسيلت</option>
                      <option value="39 - الوادي" <?php echo (isset($wilaya) && $wilaya === '39 - الوادي') ? 'selected' : ''; ?>>39 - الوادي</option>
                      <option value="40 - خنشلة" <?php echo (isset($wilaya) && $wilaya === '40 - خنشلة') ? 'selected' : ''; ?>>40 - خنشلة</option>
                      <option value="41 - سوق أهراس" <?php echo (isset($wilaya) && $wilaya === '41 - سوق أهراس') ? 'selected' : ''; ?>>41 - سوق أهراس</option>
                      <option value="42 - تيبازة" <?php echo (isset($wilaya) && $wilaya === '42 - تيبازة') ? 'selected' : ''; ?>>42 - تيبازة</option>
                      <option value="43 - ميلة" <?php echo (isset($wilaya) && $wilaya === '43 - ميلة') ? 'selected' : ''; ?>>43 - ميلة</option>
                      <option value="44 - عين الدفلى" <?php echo (isset($wilaya) && $wilaya === '44 - عين الدفلى') ? 'selected' : ''; ?>>44 - عين الدفلى</option>
                      <option value="45 - النعامة" <?php echo (isset($wilaya) && $wilaya === '45 - النعامة') ? 'selected' : ''; ?>>45 - النعامة</option>
                      <option value="46 - عين تيموشنت" <?php echo (isset($wilaya) && $wilaya === '46 - عين تيموشنت') ? 'selected' : ''; ?>>46 - عين تيموشنت</option>
                      <option value="47 - غرداية" <?php echo (isset($wilaya) && $wilaya === '47 - غرداية') ? 'selected' : ''; ?>>47 - غرداية</option>
                      <option value="48 - غليزان" <?php echo (isset($wilaya) && $wilaya === '48 - غليزان') ? 'selected' : ''; ?>>48 - غليزان</option>
                      <option value="49 - تيميمون" <?php echo (isset($wilaya) && $wilaya === '49 - تيميمون') ? 'selected' : ''; ?>>49 - تيميمون</option>
                      <option value="50 - برج باجي مختار" <?php echo (isset($wilaya) && $wilaya === '50 - برج باجي مختار') ? 'selected' : ''; ?>>50 - برج باجي مختار</option>
                      <option value="51 - أولاد جلال" <?php echo (isset($wilaya) && $wilaya === '51 - أولاد جلال') ? 'selected' : ''; ?>>51 - أولاد جلال</option>
                      <option value="52 - بني عباس" <?php echo (isset($wilaya) && $wilaya === '52 - بني عباس') ? 'selected' : ''; ?>>52 - بني عباس</option>
                      <option value="53 - عين صالح" <?php echo (isset($wilaya) && $wilaya === '53 - عين صالح') ? 'selected' : ''; ?>>53 - عين صالح</option>
                      <option value="54 - عين قزام" <?php echo (isset($wilaya) && $wilaya === '54 - عين قزام') ? 'selected' : ''; ?>>54 - عين قزام</option>
                      <option value="55 - تقرت" <?php echo (isset($wilaya) && $wilaya === '55 - تقرت') ? 'selected' : ''; ?>>55 - تقرت</option>
                      <option value="56 - جانت" <?php echo (isset($wilaya) && $wilaya === '56 - جانت') ? 'selected' : ''; ?>>56 - جانت</option>
                      <option value="57 - المغير" <?php echo (isset($wilaya) && $wilaya === '57 - المغير') ? 'selected' : ''; ?>>57 - المغير</option>
                      <option value="58 - المنيعة" <?php echo (isset($wilaya) && $wilaya === '58 - المنيعة') ? 'selected' : ''; ?>>58 - المنيعة</option>
                    </select>
                  </div>
                </div>

                <!-- اسم المتجر -->
                <div class="input-group md:col-span-2">
                  <label for="store_name" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-store text-blue-600 ml-1"></i>
                    اسم المتجر
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-store"></i>
                    <input
                      type="text"
                      id="store_name"
                      name="store_name"
                      value="<?php echo htmlspecialchars($storeName ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                      placeholder="أدخل اسم المتجر"
                      required
                    >
                  </div>
                </div>

                <!-- كلمة المرور -->
                <div class="input-group md:col-span-2">
                  <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-lock text-blue-600 ml-1"></i>
                    كلمة المرور
                  </label>
                  <div class="relative">
                    <i class="fa-solid fa-lock"></i>
                    <input
                      type="password"
                      id="password"
                      name="password"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                      placeholder="أدخل كلمة المرور (6 أحرف على الأقل)"
                      minlength="6"
                      required
                    >
                  </div>
                  <p class="text-xs text-gray-500 mt-1">يجب أن تكون كلمة المرور 6 أحرف على الأقل</p>
                </div>

              </div>

              <!-- Action Buttons -->
              <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t border-gray-200">
                <button
                  type="submit"
                  name="add_marketer"
                  class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition-colors duration-200 flex items-center justify-center gap-2"
                >
                  <i class="fa-solid fa-save"></i>
                  حفظ المسوق الجديد
                </button>
                <button
                  type="reset"
                  class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-semibold transition-colors duration-200 flex items-center justify-center gap-2"
                >
                  <i class="fa-solid fa-undo"></i>
                  إعادة تعيين
                </button>
                <a
                  href="index.php"
                  class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-semibold transition-colors duration-200 flex items-center justify-center gap-2 text-center"
                >
                  <i class="fa-solid fa-arrow-right"></i>
                  العودة للوحة الرئيسية
                </a>
              </div>
            </form>
          </section>

          <!-- Instructions Section -->
          <section class="bg-blue-50 rounded-xl p-6 border border-blue-200">
            <h3 class="text-lg font-semibold text-blue-900 mb-4 flex items-center gap-2">
              <i class="fa-solid fa-info-circle"></i>
              معلومات مهمة
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div class="flex items-start gap-3">
                <div class="p-2 rounded-full bg-blue-200 text-blue-800">
                  <i class="fa-solid fa-check text-sm"></i>
                </div>
                <div>
                  <p class="font-semibold text-blue-900">تأكد من صحة البيانات</p>
                  <p class="text-blue-700">تحقق من البريد الإلكتروني ورقم الهاتف</p>
                </div>
              </div>
              <div class="flex items-start gap-3">
                <div class="p-2 rounded-full bg-blue-200 text-blue-800">
                  <i class="fa-solid fa-shield-alt text-sm"></i>
                </div>
                <div>
                  <p class="font-semibold text-blue-900">كلمة مرور قوية</p>
                  <p class="text-blue-700">يجب أن تكون 6 أحرف على الأقل</p>
                </div>
              </div>
              <div class="flex items-start gap-3">
                <div class="p-2 rounded-full bg-blue-200 text-blue-800">
                  <i class="fa-solid fa-store text-sm"></i>
                </div>
                <div>
                  <p class="font-semibold text-blue-900">اسم المتجر مميز</p>
                  <p class="text-blue-700">اختر اسماً واضحاً للمتجر</p>
                </div>
              </div>
              <div class="flex items-start gap-3">
                <div class="p-2 rounded-full bg-blue-200 text-blue-800">
                  <i class="fa-solid fa-map-marker-alt text-sm"></i>
                </div>
                <div>
                  <p class="font-semibold text-blue-900">اختر الولاية</p>
                  <p class="text-blue-700">حدد الولاية التي ينتمي إليها المسوق</p>
                </div>
              </div>
            </div>
          </section>

        </div>
      </main>
    </div>
  </div>

  <script>
    // Sidebar controls are provided by sidebar.php

    // تأثيرات النموذج
    document.querySelectorAll('input').forEach(input => {
      input.addEventListener('focus', function() {
        this.parentElement.parentElement.style.transform = 'scale(1.02)';
        this.parentElement.parentElement.style.transition = 'transform 0.2s ease';
      });
      
      input.addEventListener('blur', function() {
        this.parentElement.parentElement.style.transform = 'scale(1)';
      });
    });

    // التحقق من قوة كلمة المرور
    document.getElementById('password').addEventListener('input', function() {
      const password = this.value;
      const strength = calculatePasswordStrength(password);
      
      // يمكن إضافة مؤشر قوة كلمة المرور هنا
    });

    function calculatePasswordStrength(password) {
      let strength = 0;
      if (password.length >= 6) strength++;
      if (password.match(/[a-z]/)) strength++;
      if (password.match(/[A-Z]/)) strength++;
      if (password.match(/[0-9]/)) strength++;
      if (password.match(/[^a-zA-Z0-9]/)) strength++;
      return strength;
    }

    // تنسيق رقم الهاتف
    document.getElementById('phone').addEventListener('input', function() {
      let value = this.value.replace(/\D/g, '');
      if (value.length > 10) {
        value = value.substr(0, 10);
      }
      this.value = value;
    });
  </script>
</body>
</html>