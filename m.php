<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// DB connection
$displayName = 'مستخدم';
try {
    require_once __DIR__ . '/db_config/db.php';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0) {
        $stmt = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['username'])) {
                $displayName = $row['username'];
            }
        } elseif (!empty($_SESSION['user_name'])) {
            $displayName = $_SESSION['user_name'];
        }
    } elseif (!empty($_SESSION['user_name'])) {
        $displayName = $_SESSION['user_name'];
    }
} catch (Throwable $e) {
    if (!empty($_SESSION['user_name'])) {
        $displayName = $_SESSION['user_name'];
    }
}

// Get user balance and earnings statistics
$availableBalance = 0;
$paidAmount = 0;
$pendingAmount = 0;
$totalEarnings = 0;

try {
    // Calculate total earned commission from delivered orders
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(o.sale_price * COALESCE(p.commission_rate, 10) / 100), 0) as total_commission
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.id
        WHERE o.user_id = ? AND o.status = 'delivered'
    ");
    $stmt->execute([$userId]);
    $totalCommission = (float)$stmt->fetchColumn();

    // Get total withdrawn amount (completed)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_withdrawn 
        FROM withdrawals 
        WHERE user_id = ? AND status = 'completed'
    ");
    $stmt->execute([$userId]);
    $totalWithdrawn = (float)$stmt->fetchColumn();

    // Get pending withdrawal amount
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as pending_amount 
        FROM withdrawals 
        WHERE user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$userId]);
    $pendingAmount = (float)$stmt->fetchColumn();

    // Derive values
    $paidAmount = $totalWithdrawn;
    $totalEarnings = $totalCommission;
    // Available = delivered commissions - completed withdrawals - pending requests
    $availableBalance = max(0.0, $totalCommission - $totalWithdrawn - $pendingAmount);

} catch (Exception $e) {
    error_log("Error fetching balance: " . $e->getMessage());
}

// Handle withdrawal request
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_withdrawal') {
    try {
        $amount = (float)trim($_POST['amount'] ?? 0);
        $method = trim($_POST['method'] ?? '');
        
        // Validation
        $errors = [];
        
        if ($amount <= 0) {
            $errors[] = 'يجب أن يكون المبلغ أكبر من صفر';
        }
        
        if ($amount > $availableBalance) {
            $errors[] = 'المبلغ المطلوب أكبر من الرصيد المتاح للسحب';
        }
        
        if ($amount < 100) {
            $errors[] = 'الحد الأدنى للسحب هو 100 دج';
        }
        
        if (!in_array($method, ['baridi_mob', 'ccp', 'flexy'])) {
            $errors[] = 'طريقة الدفع غير صحيحة';
        }
        
        // Method-specific validation and data collection
        $paymentDetails = [];
        
        if ($method === 'flexy') {
            $phone = trim($_POST['flexy_phone'] ?? '');
            if (empty($phone) || !preg_match('/^0[0-9]{9}$/', $phone)) {
                $errors[] = 'رقم الهاتف غير صحيح للفليكسي';
            }
            $paymentDetails['flexy_phone'] = $phone;
        }
        
        if ($method === 'baridi_mob') {
            $baridi_mob = trim($_POST['baridi_mob'] ?? '');
            if (empty($baridi_mob)) {
                $errors[] = 'يجب إدخال رقم بريدي موب';
            }
            $paymentDetails['baridi_mob'] = $baridi_mob;
        }
        
        if ($method === 'ccp') {
            $first_name = trim($_POST['ccp_first_name'] ?? '');
            $last_name = trim($_POST['ccp_last_name'] ?? '');
            $ccp_number = trim($_POST['ccp_number'] ?? '');
            $cle = trim($_POST['ccp_cle'] ?? '');
            $wilaya = trim($_POST['ccp_wilaya'] ?? '');
            $baladiya = trim($_POST['ccp_baladiya'] ?? '');
            
            if (empty($first_name) || empty($last_name) || empty($ccp_number) || 
                empty($cle) || empty($wilaya) || empty($baladiya)) {
                $errors[] = 'يجب ملء جميع بيانات CCP';
            }
            
            $paymentDetails = [
                'ccp_first_name' => $first_name,
                'ccp_last_name' => $last_name,
                'ccp_number' => $ccp_number,
                'ccp_cle' => $cle,
                'ccp_wilaya' => $wilaya,
                'ccp_baladiya' => $baladiya
            ];
        }
        
        if (!empty($errors)) {
            throw new Exception(implode('<br>', $errors));
        }
        
        // Fetch optional store name and user phone for record keeping
        $storeName = '';
        $userPhone = '';
        try {
            $u = $conn->prepare('SELECT store_name, phone FROM users WHERE id = ? LIMIT 1');
            $u->execute([$userId]);
            if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
                $storeName = (string)($row['store_name'] ?? '');
                $userPhone = (string)($row['phone'] ?? '');
            }
        } catch (Throwable $e) {}
        
        // Insert withdrawal request with backward-compatible schema
        $result = false;
        try {
            // Preferred schema with store_name and user_phone columns
            $stmt = $conn->prepare("
                INSERT INTO withdrawals (user_id, store_name, user_phone, amount, method, payment_details, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $result = $stmt->execute([
                $userId,
                $storeName,
                $userPhone,
                $amount,
                $method,
                json_encode($paymentDetails, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (Throwable $e) {
            // Legacy schema without extra columns
            $stmt = $conn->prepare("
                INSERT INTO withdrawals (user_id, amount, method, payment_details, status, created_at) 
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $result = $stmt->execute([
                $userId, 
                $amount, 
                $method, 
                json_encode($paymentDetails, JSON_UNESCAPED_UNICODE)
            ]);
        }

        if (!$result) {
            throw new Exception('حدث خطأ أثناء إرسال طلب السحب، يرجى المحاولة مرة أخرى');
        }

        $withdrawalId = $conn->lastInsertId();
        $message = "تم إرسال طلب السحب بنجاح! رقم الطلب: #$withdrawalId";
        $messageType = 'success';
        
        // Update pending amount and available balance in memory
        $pendingAmount += $amount;
        $availableBalance = max(0.0, $totalEarnings - $paidAmount - $pendingAmount);
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    } catch (PDOException $e) {
        $message = 'حدث خطأ في قاعدة البيانات، يرجى المحاولة لاحقاً';
        $messageType = 'error';
        error_log("Database error in withdrawals.php: " . $e->getMessage());
    }
}

// Get withdrawal history
$withdrawalHistory = [];
try {
    $stmt = $conn->prepare("
        SELECT id, amount, method, payment_details, status, created_at, processed_at, proof_image
        FROM withdrawals 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $withdrawalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching withdrawal history: " . $e->getMessage());
}

// Handle proof download
if (isset($_GET['download_proof']) && is_numeric($_GET['download_proof'])) {
    try {
        $withdrawalId = (int)$_GET['download_proof'];
        $stmt = $conn->prepare("SELECT proof_image, amount FROM withdrawals WHERE id = ? AND user_id = ?");
        $stmt->execute([$withdrawalId, $userId]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($withdrawal && !empty($withdrawal['proof_image']) && file_exists($withdrawal['proof_image'])) {
            $filename = "withdrawal_proof_" . $withdrawalId . "_" . $withdrawal['amount'] . "DA.jpg";
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($withdrawal['proof_image']));
            readfile($withdrawal['proof_image']);
            exit;
        }
    } catch (Exception $e) {
        error_log("Error downloading proof: " . $e->getMessage());
    }
}

$monthlyWithdrawCount = 0;
$monthlyWithdrawSum = 0.0;
try {
    $stmt = $conn->prepare("
        SELECT
          COUNT(*) AS cnt,
          COALESCE(SUM(amount), 0) AS sum_amount
        FROM withdrawals
        WHERE user_id = ?
          AND status = 'completed'
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ");
    $stmt->execute([$userId]);
    [$monthlyWithdrawCount, $monthlyWithdrawSum] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $monthlyWithdrawCount = (int)$monthlyWithdrawCount;
    $monthlyWithdrawSum = (float)$monthlyWithdrawSum;
} catch (Throwable $e) {
    // ignore monthly stats errors
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>سحب الأرباح - السوق الجزائري</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Cairo', sans-serif; background-color: #f7f7f7; }
    @media (min-width: 1024px) { .main-content { margin-right: 256px; } }
    .page-transition { opacity: 0; transform: translateY(20px); animation: pageLoad 0.5s ease-out forwards; }
    @keyframes pageLoad { to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 768px) { .mobile-optimized { padding: 1rem 0.75rem; } }
    .input-focus:focus {
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
      border-color: #10b981;
    }
    .success-message {
      animation: slideInDown 0.5s ease-out;
    }
    .error-message {
      animation: shake 0.6s ease-in-out;
    }
    @keyframes slideInDown {
      from { transform: translateY(-20px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }
    .payment-method-card {
      transition: all 0.3s ease;
      cursor: pointer;
    }
    .payment-method-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .payment-method-card.selected {
      border-color: #10b981;
      background-color: #f0fdf4;
    }
    .method-details {
      display: none;
    }
    .method-details.show {
      display: block;
      animation: fadeInDown 0.3s ease-out;
    }
    @keyframes fadeInDown {
      from { 
        opacity: 0;
        transform: translateY(-10px);
      }
      to { 
        opacity: 1;
        transform: translateY(0);
      }
    }
    .pulse-animation {
      animation: pulse 2s infinite;
    }
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.02); }
      100% { transform: scale(1); }
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.25rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    .status-pending {
      background-color: #fef3c7;
      color: #d97706;
    }
    .status-completed {
      background-color: #d1fae5;
      color: #059669;
    }
    .status-rejected {
      background-color: #fee2e2;
      color: #dc2626;
    }
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
          <i class="fa-solid fa-wallet text-emerald-600"></i>
          <span>سحب الأرباح</span>
        </div>
      </div>
      <div class="flex items-center gap-4">
        <button class="relative text-gray-600 hover:text-gray-800 transition-colors" title="إشعارات">
          <i class="fa-regular fa-bell text-lg"></i>
          <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] rounded-full px-1">3</span>
        </button>
        <div class="flex items-center gap-2">
          <div class="w-9 h-9 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700">
            <i class="fa-solid fa-user"></i>
          </div>
          <span class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
    </div>
  </header>

  <!-- Main -->
  <main class="flex-1 overflow-x-hidden overflow-y-auto">
    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6 page-transition main-content">
    <!-- Welcome Section -->
    <section class="bg-gradient-to-r from-emerald-600 to-emerald-700 rounded-xl p-6 text-white">
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div>
          <h1 class="text-xl sm:text-2xl font-bold flex items-center gap-2">
            <i class="fa-solid fa-money-bill-wave"></i>
            سحب الأرباح
          </h1>
          <p class="opacity-90 mt-1">اسحب أرباحك بسهولة وأمان</p>
        </div>
        <div class="text-center">
          <div class="text-3xl font-extrabold"><?php echo number_format($availableBalance, 2); ?></div>
          <div class="text-sm opacity-90">دج متاح للسحب</div>
        </div>
      </div>
    </section>

    <!-- Balance Statistics -->
    <section class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="bg-white rounded-xl p-6 border border-gray-100">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600">المبلغ المتاح</p>
            <p class="text-2xl font-bold text-emerald-600"><?php echo number_format($availableBalance, 2); ?> دج</p>
          </div>
          <div class="p-3 rounded-full bg-emerald-100 text-emerald-600">
            <i class="fa-solid fa-wallet text-xl"></i>
          </div>
        </div>
      </div>
      
      <div class="bg-white rounded-xl p-6 border border-gray-100">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600">قيد الانتظار</p>
            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($pendingAmount, 2); ?> دج</p>
          </div>
          <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
            <i class="fa-solid fa-clock text-xl"></i>
          </div>
        </div>
      </div>
      
      <div class="bg-white rounded-xl p-6 border border-gray-100">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600">تم السحب</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($paidAmount, 2); ?> دج</p>
          </div>
          <div class="p-3 rounded-full bg-blue-100 text-blue-600">
            <i class="fa-solid fa-check-circle text-xl"></i>
          </div>
        </div>
      </div>
      
      <div class="bg-white rounded-xl p-6 border border-gray-100">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600">إجمالي الأرباح</p>
            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($totalEarnings, 2); ?> دج</p>
          </div>
          <div class="p-3 rounded-full bg-purple-100 text-purple-600">
            <i class="fa-solid fa-chart-line text-xl"></i>
          </div>
        </div>
      </div>
    </section>

    <!-- Messages -->
    <?php if (!empty($message)): ?>
      <div class="<?php echo $messageType === 'success' ? 'bg-green-100 border-green-500 text-green-700 success-message' : 'bg-red-100 border-red-500 text-red-700 error-message'; ?> border-l-4 p-4 rounded-lg">
        <div class="flex items-center">
          <div class="flex-shrink-0">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
          </div>
          <div class="mr-3">
            <p class="font-medium"><?php echo $message; ?></p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Main Content -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Withdrawal Form -->
      <div class="lg:col-span-2 bg-white rounded-xl p-6 shadow-sm border border-gray-100">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-3 rounded-lg bg-emerald-100 text-emerald-700">
            <i class="fa-solid fa-money-bill-transfer text-xl"></i>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-gray-900">طلب سحب جديد</h3>
            <p class="text-sm text-gray-600">اختر المبلغ وطريقة الدفع المفضلة</p>
          </div>
        </div>

        <form method="POST" class="space-y-6" id="withdrawalForm">
          <input type="hidden" name="action" value="request_withdrawal">
          
          <!-- Amount -->
          <div>
            <label for="amount" class="block text-sm font-medium text-gray-700 mb-2">
              المبلغ المطلوب <span class="text-red-500">*</span>
            </label>
            <div class="relative">
              <input type="number" id="amount" name="amount" required min="100" max="<?php echo $availableBalance; ?>" step="0.01"
                     class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all pl-12"
                     placeholder="أدخل المبلغ المطلوب">
              <span class="absolute left-3 top-3 text-gray-500">دج</span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
              الحد الأدنى: 100 دج | الحد الأقصى: <?php echo number_format($availableBalance, 2); ?> دج
            </p>
          </div>

          <!-- Payment Methods -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-3">
              طريقة الدفع <span class="text-red-500">*</span>
            </label>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <!-- Flexy -->
              <div class="payment-method-card border-2 border-gray-200 rounded-lg p-4" onclick="selectPaymentMethod('flexy')">
                <input type="radio" name="method" value="flexy" id="flexy" class="hidden">
                <div class="text-center">
                  <div class="w-12 h-12 mx-auto mb-3 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-mobile-alt text-orange-600 text-xl"></i>
                  </div>
                  <h4 class="font-semibold text-gray-900">فليكسي</h4>
                  <p class="text-xs text-gray-600">تحويل فوري</p>
                </div>
              </div>

              <!-- Baridi Mob -->
              <div class="payment-method-card border-2 border-gray-200 rounded-lg p-4" onclick="selectPaymentMethod('baridi_mob')">
                <input type="radio" name="method" value="baridi_mob" id="baridi_mob" class="hidden">
                <div class="text-center">
                  <div class="w-12 h-12 mx-auto mb-3 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-university text-blue-600 text-xl"></i>
                  </div>
                  <h4 class="font-semibold text-gray-900">بريدي موب</h4>
                  <p class="text-xs text-gray-600">محفظة إلكترونية</p>
                </div>
              </div>

              <!-- CCP -->
              <div class="payment-method-card border-2 border-gray-200 rounded-lg p-4" onclick="selectPaymentMethod('ccp')">
                <input type="radio" name="method" value="ccp" id="ccp" class="hidden">
                <div class="text-center">
                  <div class="w-12 h-12 mx-auto mb-3 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-credit-card text-green-600 text-xl"></i>
                  </div>
                  <h4 class="font-semibold text-gray-900">CCP</h4>
                  <p class="text-xs text-gray-600">حساب جاري</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Payment Details - Initially Hidden -->
          <div id="payment-details-section" style="display: none;">
            <div class="bg-gray-50 rounded-lg p-4">
              <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="fa-solid fa-edit text-emerald-600"></i>
                املأ البيانات
              </h4>
              
              <!-- Flexy Details -->
              <div id="flexy-details" class="method-details">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                    <i class="fa-solid fa-mobile-alt text-orange-600"></i>
                  </div>
                  <div>
                    <h5 class="font-semibold text-gray-900">فليكسي</h5>
                    <p class="text-sm text-gray-600">أدخل رقم هاتفك للتحويل</p>
                  </div>
                </div>
                <div>
                  <label for="flexy_phone" class="block text-sm font-medium text-gray-700 mb-2">
                    أعطني رقم الهاتف <span class="text-red-500">*</span>
                  </label>
                  <input type="tel" id="flexy_phone" name="flexy_phone" 
                         class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                         placeholder="مثال: 0555123456" pattern="0[0-9]{9}" maxlength="10">
                  <p class="text-xs text-gray-500 mt-1">
                    <i class="fa-solid fa-info-circle"></i>
                    يجب أن يبدأ الرقم بـ 0 ويتكون من 10 أرقام
                  </p>
                </div>
              </div>

              <!-- Baridi Mob Details -->
              <div id="baridi_mob-details" class="method-details">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fa-solid fa-university text-blue-600"></i>
                  </div>
                  <div>
                    <h5 class="font-semibold text-gray-900">بريدي موب</h5>
                    <p class="text-sm text-gray-600">أدخل رقم محفظتك الإلكترونية</p>
                  </div>
                </div>
                <div>
                  <label for="baridi_mob_number" class="block text-sm font-medium text-gray-700 mb-2">
                    أعطني بريدي موب الخاص بك <span class="text-red-500">*</span>
                  </label>
                  <input type="text" id="baridi_mob_number" name="baridi_mob" 
                         class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                         placeholder="أدخل رقم بريدي موب الخاص بك">
                  <p class="text-xs text-gray-500 mt-1">
                    <i class="fa-solid fa-info-circle"></i>
                    تأكد من صحة رقم بريدي موب لتجنب التأخير
                  </p>
                </div>
              </div>

              <!-- CCP Details -->
              <div id="ccp-details" class="method-details">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                    <i class="fa-solid fa-credit-card text-green-600"></i>
                  </div>
                  <div>
                    <h5 class="font-semibold text-gray-900">CCP</h5>
                    <p class="text-sm text-gray-600">املأ بيانات حسابك الجاري</p>
                  </div>
                </div>
                <div class="space-y-4">
                  <p class="text-sm text-blue-800 bg-blue-50 p-3 rounded-lg">
                    <i class="fa-solid fa-exclamation-circle"></i>
                    أعطني الاسم واللقب ورقم CCP والمفتاح والولاية والبلدية
                  </p>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label for="ccp_first_name" class="block text-sm font-medium text-gray-700 mb-2">
                        الاسم <span class="text-red-500">*</span>
                      </label>
                      <input type="text" id="ccp_first_name" name="ccp_first_name" 
                             class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                             placeholder="الاسم الأول">
                    </div>
                    <div>
                      <label for="ccp_last_name" class="block text-sm font-medium text-gray-700 mb-2">
                        اللقب <span class="text-red-500">*</span>
                      </label>
                      <input type="text" id="ccp_last_name" name="ccp_last_name" 
                             class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                             placeholder="اللقب">
                    </div>
                    <div>
                      <label for="ccp_number" class="block text-sm font-medium text-gray-700 mb-2">
                        رقم CCP <span class="text-red-500">*</span>
                      </label>
                      <input type="text" id="ccp_number" name="ccp_number" 
                             class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                             placeholder="رقم الحساب الجاري">
                    </div>
                    <div>
                      <label for="ccp_cle" class="block text-sm font-medium text-gray-700 mb-2">
                        المفتاح (Clé) <span class="text-red-500">*</span>
                      </label>
                      <input type="text" id="ccp_cle" name="ccp_cle" 
                             class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                             placeholder="مفتاح الحساب">
                    </div>
                    <div>
                      <label for="ccp_wilaya" class="block text-sm font-medium text-gray-700 mb-2">
                        الولاية <span class="text-red-500">*</span>
                      </label>
                      <input type="text" id="ccp_wilaya" name="ccp_wilaya" 
                             class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                             placeholder="اسم الولاية">
                    </div>
                    <div>
                      <label for="ccp_baladiya" class="block text-sm font-medium text-gray-700 mb-2">
                        البلدية <span class="text-red-500">*</span>
                      </label>
                      <input type="text" id="ccp_baladiya" name="ccp_baladiya" 
                             class="w-full px-3 py-3 border border-gray-300 rounded-lg focus:outline-none input-focus transition-all"
                             placeholder="اسم البلدية">
                    </div>
                  </div>
                </div>
              </div>

              <!-- Confirm Button -->
              <div class="mt-6 pt-4 border-t border-gray-200">
                <button type="button" id="confirmDataBtn" onclick="confirmPaymentData()" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center justify-center gap-2">
                  <i class="fa-solid fa-check"></i>
                  تأكيد البيانات
                </button>
              </div>
            </div>
          </div>

          <!-- Submit Button -->
          <div class="flex gap-4">
            <button type="submit" id="submitBtn" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center gap-2 flex-1" disabled>
              <i class="fa-solid fa-paper-plane"></i>
              إرسال طلب السحب
            </button>
          </div>
        </form>
      </div>

      <!-- Sidebar -->
      <div class="space-y-4">
        <!-- Withdrawal Tips -->
        <div class="bg-blue-50 rounded-xl p-4 border border-blue-200">
          <h4 class="font-semibold text-blue-900 mb-3 flex items-center gap-2">
            <i class="fa-solid fa-lightbulb text-blue-600"></i>
            نصائح مهمة
          </h4>
          <ul class="text-sm text-blue-800 space-y-2">
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-check-circle text-green-500 mt-0.5 text-xs"></i>
              <span>الحد الأدنى للسحب 100 دج</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-check-circle text-green-500 mt-0.5 text-xs"></i>
              <span>تأكد من صحة بيانات الدفع</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-check-circle text-green-500 mt-0.5 text-xs"></i>
              <span>معالجة الطلبات خلال 24-48 ساعة</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-check-circle text-green-500 mt-0.5 text-xs"></i>
              <span>يمكن تحميل إثبات الدفع بعد المعالجة</span>
            </li>
          </ul>
        </div>

        <!-- Quick Stats -->
        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
          <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <i class="fa-solid fa-chart-bar text-emerald-600"></i>
            إحصائيات الشهر
          </h4>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-gray-600">طلبات السحب</span>
              <span class="font-semibold text-emerald-600"><?php echo $monthlyWithdrawCount; ?></span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-gray-600">مبلغ مسحوب</span>
              <span class="font-semibold text-emerald-600"><?php echo number_format($monthlyWithdrawSum, 2); ?> دج</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-gray-600">معدل المعالجة</span>
              <span class="font-semibold text-emerald-600">24 ساعة</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Withdrawal History -->
    <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
      <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
          <div class="p-3 rounded-lg bg-purple-100 text-purple-700">
            <i class="fa-solid fa-history text-xl"></i>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-gray-900">سجل السحوبات</h3>
            <p class="text-sm text-gray-600">تاريخ جميع طلبات السحب</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button class="text-gray-500 hover:text-gray-700 transition-colors" onclick="refreshHistory()">
            <i class="fa-solid fa-refresh"></i>
          </button>
        </div>
      </div>

      <?php if (empty($withdrawalHistory)): ?>
        <div class="text-center py-12">
          <i class="fa-solid fa-inbox text-4xl text-gray-300 mb-4"></i>
          <h4 class="text-lg font-semibold text-gray-600 mb-2">لا توجد طلبات سحب</h4>
          <p class="text-gray-500">قم بإنشاء أول طلب سحب لك</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200">
                <th class="text-right py-3 px-4 font-semibold text-gray-900">رقم الطلب</th>
                <th class="text-right py-3 px-4 font-semibold text-gray-900">المبلغ</th>
                <th class="text-right py-3 px-4 font-semibold text-gray-900">طريقة الدفع</th>
                <th class="text-right py-3 px-4 font-semibold text-gray-900">الحالة</th>
                <th class="text-right py-3 px-4 font-semibold text-gray-900">تاريخ الطلب</th>
                <th class="text-right py-3 px-4 font-semibold text-gray-900">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($withdrawalHistory as $withdrawal): ?>
                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors">
                  <td class="py-4 px-4">
                    <span class="font-semibold text-gray-900">#<?php echo $withdrawal['id']; ?></span>
                  </td>
                  <td class="py-4 px-4">
                    <span class="font-semibold text-emerald-600"><?php echo number_format($withdrawal['amount'], 2); ?> دج</span>
                  </td>
                  <td class="py-4 px-4">
                    <?php
                    $methodNames = [
                        'flexy' => 'فليكسي',
                        'baridi_mob' => 'بريدي موب',
                        'ccp' => 'CCP'
                    ];
                    $methodIcons = [
                        'flexy' => 'fa-mobile-alt text-orange-600',
                        'baridi_mob' => 'fa-university text-blue-600',
                        'ccp' => 'fa-credit-card text-green-600'
                    ];
                    ?>
                    <div class="flex items-center gap-2">
                      <i class="fa-solid <?php echo $methodIcons[$withdrawal['method']] ?? 'fa-credit-card text-gray-600'; ?>"></i>
                      <span><?php echo $methodNames[$withdrawal['method']] ?? ucfirst($withdrawal['method']); ?></span>
                    </div>
                  </td>
                  <td class="py-4 px-4">
                    <?php
                    $statusClasses = [
                        'pending' => 'status-pending',
                        'completed' => 'status-completed',
                        'rejected' => 'status-rejected'
                    ];
                    $statusNames = [
                        'pending' => 'قيد المراجعة',
                        'completed' => 'مكتمل',
                        'rejected' => 'مرفوض'
                    ];
                    $statusIcons = [
                        'pending' => 'fa-clock',
                        'completed' => 'fa-check-circle',
                        'rejected' => 'fa-times-circle'
                    ];
                    ?>
                    <span class="status-badge <?php echo $statusClasses[$withdrawal['status']] ?? 'status-pending'; ?>">
                      <i class="fa-solid <?php echo $statusIcons[$withdrawal['status']] ?? 'fa-clock'; ?>"></i>
                      <?php echo $statusNames[$withdrawal['status']] ?? ucfirst($withdrawal['status']); ?>
                    </span>
                  </td>
                  <td class="py-4 px-4">
                    <div class="text-sm">
                      <div class="font-medium text-gray-900">
                        <?php echo date('Y/m/d', strtotime($withdrawal['created_at'])); ?>
                      </div>
                      <div class="text-gray-500">
                        <?php echo date('H:i', strtotime($withdrawal['created_at'])); ?>
                      </div>
                    </div>
                  </td>
                  <td class="py-4 px-4">
                    <div class="flex items-center gap-2">
                      <?php if ($withdrawal['status'] === 'completed' && !empty($withdrawal['proof_image'])): ?>
                        <a href="?download_proof=<?php echo $withdrawal['id']; ?>" 
                           class="text-blue-600 hover:text-blue-800 transition-colors text-sm flex items-center gap-1"
                           title="تحميل إثبات الدفع">
                          <i class="fa-solid fa-download"></i>
                          <span>إثبات الدفع</span>
                        </a>
                      <?php endif; ?>
                      <button onclick="showWithdrawalDetails(<?php echo htmlspecialchars(json_encode($withdrawal), ENT_QUOTES, 'UTF-8'); ?>)" 
                              class="text-gray-600 hover:text-gray-800 transition-colors text-sm flex items-center gap-1"
                              title="عرض التفاصيل">
                        <i class="fa-solid fa-eye"></i>
                        <span>التفاصيل</span>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    </div>
  </main>
    </div>
  </div>

  <!-- Withdrawal Details Modal -->
  <div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-md w-full p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">تفاصيل الطلب</h3>
        <button onclick="closeDetailsModal()" class="text-gray-500 hover:text-gray-700">
          <i class="fa-solid fa-times"></i>
        </button>
      </div>
      <div id="modalContent" class="space-y-4">
        <!-- Content will be populated by JavaScript -->
      </div>
    </div>
  </div>

  <script>
    let selectedMethod = null;

    // Auto-format phone number
    document.getElementById('flexy_phone').addEventListener('input', function(e) {
      let value = e.target.value.replace(/\D/g, '');
      if (value.length > 0) {
        if (!value.startsWith('0')) {
          value = '0' + value;
        }
        if (value.length > 10) {
          value = value.substring(0, 10);
        }
      }
      e.target.value = value;
    });

    // Payment method selection
    function selectPaymentMethod(method) {
      selectedMethod = method;
      
      // Remove previous selections
      document.querySelectorAll('.payment-method-card').forEach(card => {
        card.classList.remove('selected');
      });
      
      // Hide all method details
      document.querySelectorAll('.method-details').forEach(details => {
        details.classList.remove('show');
      });
      
      // Select current method
      document.querySelector(`[onclick="selectPaymentMethod('${method}')"]`).classList.add('selected');
      document.getElementById(method).checked = true;
      
      // Show payment details section
      document.getElementById('payment-details-section').style.display = 'block';
      document.getElementById('payment-details-section').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      
      // Show relevant details
      document.getElementById(`${method}-details`).classList.add('show');
      
      // Focus on first input
      setTimeout(() => {
        const firstInput = document.querySelector(`#${method}-details input`);
        if (firstInput) {
          firstInput.focus();
        }
      }, 300);
      
      // Update submit button
      updateSubmitButton();
    }

    // Confirm payment data
    function confirmPaymentData() {
      if (!selectedMethod) {
        alert('يرجى اختيار طريقة الدفع أولاً');
        return;
      }

      let isValid = true;
      let errorMessage = '';

      // Validate based on selected method
      if (selectedMethod === 'flexy') {
        const phone = document.getElementById('flexy_phone').value;
        if (!phone || !/^0[0-9]{9}$/.test(phone)) {
          isValid = false;
          errorMessage = 'يرجى إدخال رقم هاتف صحيح (10 أرقام تبدأ بـ 0)';
          document.getElementById('flexy_phone').focus();
        }
      } else if (selectedMethod === 'baridi_mob') {
        const baridiMob = document.getElementById('baridi_mob_number').value;
        if (!baridiMob.trim()) {
          isValid = false;
          errorMessage = 'يرجى إدخال رقم بريدي موب';
          document.getElementById('baridi_mob_number').focus();
        }
      } else if (selectedMethod === 'ccp') {
        const requiredFields = [
          { id: 'ccp_first_name', name: 'الاسم' },
          { id: 'ccp_last_name', name: 'اللقب' },
          { id: 'ccp_number', name: 'رقم CCP' },
          { id: 'ccp_cle', name: 'المفتاح' },
          { id: 'ccp_wilaya', name: 'الولاية' },
          { id: 'ccp_baladiya', name: 'البلدية' }
        ];

        for (const field of requiredFields) {
          const element = document.getElementById(field.id);
          if (!element.value.trim()) {
            isValid = false;
            errorMessage = `يرجى إدخال ${field.name}`;
            element.focus();
            break;
          }
        }
      }

      if (!isValid) {
        alert(errorMessage);
        return;
      }

      // Show success message and enable submit
      const confirmBtn = document.getElementById('confirmDataBtn');
      confirmBtn.innerHTML = '<i class="fa-solid fa-check-circle"></i> تم التأكيد بنجاح';
      confirmBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
      confirmBtn.classList.add('bg-green-600', 'hover:bg-green-700');
      confirmBtn.disabled = true;

      // Show success notification
      showNotification('تم تأكيد البيانات بنجاح! يمكنك الآن إرسال طلب السحب.', 'success');

      // Enable submit button
      updateSubmitButton();

      // Scroll to submit button
      document.getElementById('submitBtn').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Show notification function
    function showNotification(message, type = 'info') {
      const notification = document.createElement('div');
      notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm ${
        type === 'success' ? 'bg-green-100 border border-green-200 text-green-800' : 
        type === 'error' ? 'bg-red-100 border border-red-200 text-red-800' :
        'bg-blue-100 border border-blue-200 text-blue-800'
      }`;
      
      notification.innerHTML = `
        <div class="flex items-center gap-3">
          <i class="fa-solid ${
            type === 'success' ? 'fa-check-circle' : 
            type === 'error' ? 'fa-exclamation-circle' : 
            'fa-info-circle'
          }"></i>
          <span class="text-sm font-medium">${message}</span>
          <button onclick="this.parentElement.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
            <i class="fa-solid fa-times"></i>
          </button>
        </div>
      `;
      
      document.body.appendChild(notification);
      
      // Auto remove after 5 seconds
      setTimeout(() => {
        if (notification.parentElement) {
          notification.remove();
        }
      }, 5000);
    }

    // Update submit button state
    function updateSubmitButton() {
      const amount = document.getElementById('amount').value;
      const submitBtn = document.getElementById('submitBtn');
      const confirmBtn = document.getElementById('confirmDataBtn');
      
      // Check if payment data is confirmed
      const isDataConfirmed = confirmBtn && confirmBtn.disabled && confirmBtn.classList.contains('bg-green-600');
      
      if (selectedMethod && amount && parseFloat(amount) >= 100 && isDataConfirmed) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        submitBtn.classList.add('pulse-animation');
      } else {
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        submitBtn.classList.remove('pulse-animation');
      }
    }

    // Amount input validation
    document.getElementById('amount').addEventListener('input', function(e) {
      const value = parseFloat(e.target.value);
      const maxAmount = <?php echo $availableBalance; ?>;
      
      if (value > maxAmount) {
        e.target.value = maxAmount;
      }
      
      updateSubmitButton();
    });

    // Form validation
    document.getElementById('withdrawalForm').addEventListener('submit', function(e) {
      const amount = parseFloat(document.getElementById('amount').value);
      const maxAmount = <?php echo $availableBalance; ?>;
      
      if (!selectedMethod) {
        e.preventDefault();
        alert('يرجى اختيار طريقة الدفع');
        return;
      }
      
      if (amount < 100) {
        e.preventDefault();
        alert('الحد الأدنى للسحب هو 100 دج');
        return;
      }
      
      if (amount > maxAmount) {
        e.preventDefault();
        alert('المبلغ المطلوب أكبر من الرصيد المتاح');
        return;
      }
      
      // Method-specific validation
      if (selectedMethod === 'flexy') {
        const phone = document.getElementById('flexy_phone').value;
        if (!phone || !/^0[0-9]{9}$/.test(phone)) {
          e.preventDefault();
          alert('يرجى إدخال رقم هاتف صحيح');
          document.getElementById('flexy_phone').focus();
          return;
        }
      }
      
      if (selectedMethod === 'baridi_mob') {
        const baridiMob = document.getElementById('baridi_mob_number').value;
        if (!baridiMob.trim()) {
          e.preventDefault();
          alert('يرجى إدخال رقم بريدي موب');
          document.getElementById('baridi_mob_number').focus();
          return;
        }
      }
      
      if (selectedMethod === 'ccp') {
        const requiredFields = ['ccp_first_name', 'ccp_last_name', 'ccp_number', 'ccp_cle', 'ccp_wilaya', 'ccp_baladiya'];
        for (const fieldId of requiredFields) {
          const field = document.getElementById(fieldId);
          if (!field.value.trim()) {
            e.preventDefault();
            alert('يرجى ملء جميع بيانات CCP');
            field.focus();
            return;
          }
        }
      }
      
      // Show loading state
      const submitBtn = document.getElementById('submitBtn');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
    });

    // Show withdrawal details modal
    function showWithdrawalDetails(withdrawal) {
      const modal = document.getElementById('detailsModal');
      const content = document.getElementById('modalContent');
      
      const paymentDetails = JSON.parse(withdrawal.payment_details || '{}');
      
      let detailsHtml = `
        <div class="space-y-3">
          <div class="flex justify-between">
            <span class="text-gray-600">رقم الطلب:</span>
            <span class="font-semibold">#${withdrawal.id}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">المبلغ:</span>
            <span class="font-semibold text-emerald-600">${parseFloat(withdrawal.amount).toFixed(2)} دج</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">طريقة الدفع:</span>
            <span class="font-semibold">${getMethodName(withdrawal.method)}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">الحالة:</span>
            <span class="status-badge ${getStatusClass(withdrawal.status)}">
              <i class="fa-solid ${getStatusIcon(withdrawal.status)}"></i>
              ${getStatusName(withdrawal.status)}
            </span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">تاريخ الطلب:</span>
            <span class="font-semibold">${formatDate(withdrawal.created_at)}</span>
          </div>
      `;
      
      if (withdrawal.processed_at) {
        detailsHtml += `
          <div class="flex justify-between">
            <span class="text-gray-600">تاريخ المعالجة:</span>
            <span class="font-semibold">${formatDate(withdrawal.processed_at)}</span>
          </div>
        `;
      }
      
      // Payment details
      if (Object.keys(paymentDetails).length > 0) {
        detailsHtml += '<hr class="my-4"><div class="space-y-2"><h4 class="font-semibold text-gray-900 mb-2">بيانات الدفع:</h4>';
        
        if (withdrawal.method === 'flexy') {
          detailsHtml += `<div class="text-sm text-gray-600">رقم الهاتف: ${paymentDetails.flexy_phone || 'غير محدد'}</div>`;
        } else if (withdrawal.method === 'baridi_mob') {
          detailsHtml += `<div class="text-sm text-gray-600">بريدي موب: ${paymentDetails.baridi_mob || 'غير محدد'}</div>`;
        } else if (withdrawal.method === 'ccp') {
          detailsHtml += `
            <div class="text-sm text-gray-600">الاسم: ${paymentDetails.ccp_first_name || ''} ${paymentDetails.ccp_last_name || ''}</div>
            <div class="text-sm text-gray-600">رقم CCP: ${paymentDetails.ccp_number || 'غير محدد'}</div>
            <div class="text-sm text-gray-600">المفتاح: ${paymentDetails.ccp_cle || 'غير محدد'}</div>
            <div class="text-sm text-gray-600">الولاية: ${paymentDetails.ccp_wilaya || 'غير محدد'}</div>
            <div class="text-sm text-gray-600">البلدية: ${paymentDetails.ccp_baladiya || 'غير محدد'}</div>
          `;
        }
        
        detailsHtml += '</div>';
      }
      
      detailsHtml += '</div>';
      
      content.innerHTML = detailsHtml;
      modal.classList.remove('hidden');
    }

    function closeDetailsModal() {
      document.getElementById('detailsModal').classList.add('hidden');
    }

    // Utility functions
    function getMethodName(method) {
      const names = {
        'flexy': 'فليكسي',
        'baridi_mob': 'بريدي موب',
        'ccp': 'CCP'
      };
      return names[method] || method;
    }

    function getStatusClass(status) {
      const classes = {
        'pending': 'status-pending',
        'completed': 'status-completed',
        'rejected': 'status-rejected'
      };
      return classes[status] || 'status-pending';
    }

    function getStatusName(status) {
      const names = {
        'pending': 'قيد المراجعة',
        'completed': 'مكتمل',
        'rejected': 'مرفوض'
      };
      return names[status] || status;
    }

    function getStatusIcon(status) {
      const icons = {
        'pending': 'fa-clock',
        'completed': 'fa-check-circle',
        'rejected': 'fa-times-circle'
      };
      return icons[status] || 'fa-clock';
    }

    function formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString('ar-DZ') + ' ' + date.toLocaleTimeString('ar-DZ', {hour: '2-digit', minute: '2-digit'});
    }

    function refreshHistory() {
      location.reload();
    }

    // Close modal when clicking outside
    document.getElementById('detailsModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeDetailsModal();
      }
    });

    // Hide success/error messages after 5 seconds
    setTimeout(function() {
      const message = document.querySelector('.success-message, .error-message');
      if (message) {
        message.style.transition = 'opacity 0.5s ease-out';
        message.style.opacity = '0';
        setTimeout(() => message.remove(), 500);
      }
    }, 5000);

    // Quick amount buttons
    const amountInput = document.getElementById('amount');
    const quickAmounts = [100, 500, 1000, 2000, 5000];
    const availableBalance = <?php echo $availableBalance; ?>;

    // Add quick amount buttons after the amount input
    const quickButtonsHtml = quickAmounts
      .filter(amount => amount <= availableBalance)
      .map(amount => `<button type="button" onclick="setQuickAmount(${amount})" class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-full transition-colors">${amount} دج</button>`)
      .join('');

    if (quickButtonsHtml) {
      const quickButtonsContainer = document.createElement('div');
      quickButtonsContainer.className = 'flex flex-wrap gap-2 mt-2';
      quickButtonsContainer.innerHTML = '<span class="text-xs text-gray-500 w-full">مبالغ سريعة:</span>' + quickButtonsHtml;
      amountInput.parentNode.appendChild(quickButtonsContainer);
    }

    function setQuickAmount(amount) {
      document.getElementById('amount').value = amount;
      updateSubmitButton();
    }

    // Initialize submit button state
    updateSubmitButton();

    // Auto-focus on first input when payment method is selected
    document.addEventListener('click', function(e) {
      if (e.target.closest('.payment-method-card')) {
        setTimeout(() => {
          const activeDetails = document.querySelector('.method-details.show');
          if (activeDetails) {
            const firstInput = activeDetails.querySelector('input');
            if (firstInput) {
              firstInput.focus();
            }
          }
        }, 300);
      }
    });
  </script>
</body>
</html>
