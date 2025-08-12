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

    // Handle withdrawal status update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'get_withdrawal_details') {
            header('Content-Type: application/json; charset=utf-8');
            $withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
            if ($withdrawalId <= 0) {
                echo json_encode(['success' => false, 'message' => 'معرّف غير صالح']);
                exit;
            }
            try {
                $stmt = $conn->prepare("SELECT w.*, u.username, u.store_name, u.phone AS user_phone, u.balance FROM withdrawals w LEFT JOIN users u ON w.user_id = u.id WHERE w.id = ? LIMIT 1");
                $stmt->execute([$withdrawalId]);
                $rec = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rec) {
                    echo json_encode(['success' => false, 'message' => 'السجل غير موجود']);
                    exit;
                }
                $details = [];
                if (!empty($rec['payment_details'])) {
                    $tmp = @json_decode((string)$rec['payment_details'], true);
                    if (is_array($tmp)) { $details = $tmp; }
                }
                echo json_encode(['success' => true, 'data' => [
                    'id' => (int)$rec['id'],
                    'username' => (string)($rec['username'] ?? ''),
                    'store_name' => (string)($rec['store_name'] ?? ''),
                    'user_phone' => (string)($rec['user_phone'] ?? ''),
                    'amount' => (float)$rec['amount'],
                    'method' => (string)$rec['method'],
                    'payment_details' => $details,
                    'status' => (string)$rec['status'],
                    'created_at' => (string)$rec['created_at'],
                    'processed_at' => (string)($rec['processed_at'] ?? ''),
                    'proof_image' => (string)($rec['proof_image'] ?? ''),
                    'balance' => (float)($rec['balance'] ?? 0),
                ]]);
            } catch (Throwable $je) {
                echo json_encode(['success' => false, 'message' => 'فشل الجلب']);
            }
            exit;
        }
        if ($_POST['action'] === 'update_withdrawal_status') {
            $withdrawalId = (int)$_POST['withdrawal_id'];
            $newStatus = $_POST['new_status'];
            $cancelReason = $_POST['cancel_reason'] ?? '';
            $proofImage = '';
            
            // Handle proof image upload
            if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/withdrawal_proofs/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileExtension = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
                $fileName = 'withdrawal_' . $withdrawalId . '_' . time() . '.' . $fileExtension;
                $targetPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $targetPath)) {
                    $proofImage = $targetPath;
                }
            }
            
            $validStatuses = ['pending', 'completed', 'cancelled'];
            if (in_array($newStatus, $validStatuses)) {
                // Load and merge payment_details JSON to add cancel_reason if provided
                $existingDetails = [];
                try {
                    $detStmt = $conn->prepare("SELECT payment_details FROM withdrawals WHERE id = ?");
                    $detStmt->execute([$withdrawalId]);
                    $detRaw = $detStmt->fetchColumn();
                    $decoded = @json_decode((string)$detRaw, true);
                    if (is_array($decoded)) { $existingDetails = $decoded; }
                } catch (Throwable $ie) { /* ignore */ }
                if ($cancelReason !== '') {
                    $existingDetails['cancel_reason'] = $cancelReason;
                }
                $detailsJson = !empty($existingDetails) ? json_encode($existingDetails, JSON_UNESCAPED_UNICODE) : (isset($detRaw) ? (string)$detRaw : null);
                
                // Build dynamic update
                $updateFields = ['status' => $newStatus];
                $params = [];
                
                if ($detailsJson !== null) {
                    $updateFields['payment_details'] = $detailsJson;
                }
                if ($proofImage) {
                    $updateFields['proof_image'] = $proofImage;
                }
                if (in_array($newStatus, ['completed', 'cancelled'])) {
                    $updateFields['processed_at'] = date('Y-m-d H:i:s');
                }
                
                $setParts = [];
                foreach ($updateFields as $col => $val) {
                    $setParts[] = "$col = ?";
                    $params[] = $val;
                }
                $params[] = $withdrawalId;
                
                $updateQuery = "UPDATE withdrawals SET " . implode(', ', $setParts) . " WHERE id = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->execute($params);
                
                // If withdrawal is completed, update user balance
                if ($newStatus === 'completed') {
                    $stmt = $conn->prepare("SELECT user_id, amount FROM withdrawals WHERE id = ?");
                    $stmt->execute([$withdrawalId]);
                    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($withdrawal) {
                        $stmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                        $stmt->execute([$withdrawal['amount'], $withdrawal['user_id']]);
                    }
                }
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit;
            }
        }
    }

    // Filter parameters
    $statusFilter = $_GET['status'] ?? 'all';
    $searchTerm = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    // Build WHERE clause for withdrawals
    $whereConditions = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $whereConditions[] = "w.status = ?";
        $params[] = $statusFilter;
    }

    if (!empty($searchTerm)) {
        $whereConditions[] = "(u.username LIKE ? OR u.store_name LIKE ? OR w.id LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get withdrawal requests with pagination
    $withdrawalsQuery = "
        SELECT w.*, u.username, u.store_name AS user_store_name, u.balance,
               (SELECT COUNT(*) FROM withdrawals WHERE user_id = u.id) as total_withdrawals,
               (SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id = u.id AND status = 'completed') as total_withdrawn
        FROM withdrawals w
        LEFT JOIN users u ON w.user_id = u.id
        $whereClause
        ORDER BY w.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    
    $withdrawalsStmt = $conn->prepare($withdrawalsQuery);
    $withdrawalsStmt->execute($params);
    $withdrawals = $withdrawalsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*)
        FROM withdrawals w
        LEFT JOIN users u ON w.user_id = u.id
        $whereClause
    ";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->execute($params);
    $totalWithdrawals = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalWithdrawals / $perPage);

    // Get withdrawal statistics
    $stats = [];
    
    // Pending withdrawal requests count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'");
    $stmt->execute();
    $stats['pending_count'] = (int)$stmt->fetchColumn();

    // Pending withdrawal amount
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status = 'pending'");
    $stmt->execute();
    $stats['pending_amount'] = (float)$stmt->fetchColumn();

    // Total available balance in marketers accounts
    $stmt = $conn->prepare("SELECT COALESCE(SUM(balance), 0) FROM users WHERE role = 'user'");
    $stmt->execute();
    $stats['total_available_balance'] = (float)$stmt->fetchColumn();

    // Completed withdrawals amount
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status = 'completed'");
    $stmt->execute();
    $stats['completed_amount'] = (float)$stmt->fetchColumn();

    // Completed withdrawals count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = 'completed'");
    $stmt->execute();
    $stats['completed_count'] = (int)$stmt->fetchColumn();

    // Total withdrawals
    $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals");
    $stmt->execute();
    $stats['total'] = (int)$stmt->fetchColumn();

    // Cancelled withdrawals
    $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = 'cancelled'");
    $stmt->execute();
    $stats['cancelled'] = (int)$stmt->fetchColumn();

} catch (Throwable $e) {
    error_log($e->getMessage());
    $withdrawals = [];
    $stats = [
        'pending_count' => 0, 'pending_amount' => 0, 'total_available_balance' => 0,
        'completed_amount' => 0, 'completed_count' => 0, 'total' => 0, 'cancelled' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>إدارة سحوبات المسوقين - لوحة الإدارة</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Cairo', sans-serif; background-color: #f7f7f7; }
    @media (min-width: 1024px) { .main-content { margin-right: 256px; } }
    .page-transition { opacity: 0; transform: translateY(20px); animation: pageLoad 0.5s ease-out forwards; }
    @keyframes pageLoad { to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 768px) { .mobile-optimized { padding: 1rem 0.75rem; } }
    .stat-card { transition: all 0.3s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    .withdrawal-row { transition: all 0.2s ease; }
    .withdrawal-row:hover { background-color: #f8fafc; }
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
    .modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 12px; width: 90%; max-width: 600px; position: relative; max-height: 90vh; overflow-y: auto; }
    .modal.show { display: block; }
    .payment-method-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 600; }
    .payment-method-mobili { background-color: #fef3c7; color: #92400e; }
    .payment-method-ccp { background-color: #dbeafe; color: #1e40af; }
    .payment-method-flexi { background-color: #d1fae5; color: #065f46; }
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
              <i class="fa-solid fa-money-bill-transfer text-indigo-600"></i>
              <span>إدارة سحوبات المسوقين</span>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <button class="relative text-gray-600 hover:text-gray-800" title="إشعارات">
              <i class="fa-regular fa-bell text-lg"></i>
              <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] rounded-full px-1"><?php echo $stats['pending_count']; ?></span>
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
        <div class="max-w-7xl mx-auto px-4 py-6 space-y-6 page-transition main-content">
          
          <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg" role="alert">
              <div class="flex items-center gap-2">
                <i class="fa-solid fa-check-circle"></i>
                <span>تم تحديث حالة طلب السحب بنجاح!</span>
              </div>
            </div>
          <?php endif; ?>

          <!-- Main Statistics Section -->
          <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Pending Withdrawals -->
            <div class="stat-card bg-gradient-to-l from-yellow-500 to-yellow-700 text-white rounded-xl p-4 shadow-sm">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-white bg-opacity-20">
                  <i class="fa-solid fa-clock"></i>
                </div>
                <div class="text-sm font-semibold">قيد الانتظار</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['pending_count']); ?></div>
              <div class="text-sm opacity-90">طلب سحب</div>
              <div class="text-lg font-bold mt-1"><?php echo number_format($stats['pending_amount'], 2); ?> دج</div>
            </div>

            <!-- Available Balance -->
            <div class="stat-card bg-gradient-to-l from-blue-600 to-blue-800 text-white rounded-xl p-4 shadow-sm">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-white bg-opacity-20">
                  <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="text-sm font-semibold">الأموال المتاحة</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['total_available_balance'], 2); ?></div>
              <div class="text-sm opacity-90">دج في حسابات المسوقين</div>
            </div>

            <!-- Completed Withdrawals -->
            <div class="stat-card bg-gradient-to-l from-green-600 to-green-800 text-white rounded-xl p-4 shadow-sm">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-white bg-opacity-20">
                  <i class="fa-solid fa-check-circle"></i>
                </div>
                <div class="text-sm font-semibold">السحوبات المكتملة</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['completed_count']); ?></div>
              <div class="text-sm opacity-90">طلب مكتمل</div>
              <div class="text-lg font-bold mt-1"><?php echo number_format($stats['completed_amount'], 2); ?> دج</div>
            </div>

            <!-- Total Withdrawals -->
            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-purple-500 text-white">
                  <i class="fa-solid fa-list-ul"></i>
                </div>
                <div class="text-sm text-purple-600 font-semibold">الإجمالي</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['total']); ?></div>
              <div class="text-sm text-gray-600">إجمالي الطلبات</div>
            </div>
          </section>

          <!-- Filters and Search -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
            <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between mb-4">
              <div class="flex flex-wrap gap-2">
                <a href="?status=all&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  جميع الطلبات
                </a>
                <a href="?status=pending&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  قيد الانتظار
                </a>
                <a href="?status=completed&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'completed' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  مكتملة
                </a>
                <a href="?status=cancelled&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'cancelled' ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  ملغية
                </a>
              </div>
              <div class="flex gap-2 w-full lg:w-auto">
                <form method="GET" class="flex gap-2 flex-1 lg:flex-initial">
                  <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                  <input type="text" name="search" placeholder="البحث في طلبات السحب..." 
                         value="<?php echo htmlspecialchars($searchTerm); ?>"
                         class="flex-1 lg:w-64 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                  <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fa-solid fa-search"></i>
                  </button>
                </form>
              </div>
            </div>
            
            <!-- Additional Tools -->
            <div class="flex flex-wrap gap-2 items-center justify-between pt-4 border-t border-gray-200">
              <div class="flex gap-2 items-center">
                <button onclick="exportWithdrawals('excel')" class="px-3 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors text-sm">
                  <i class="fa-solid fa-file-excel mr-1"></i>
                  تصدير Excel
                </button>
                <button onclick="exportWithdrawals('pdf')" class="px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors text-sm">
                  <i class="fa-solid fa-file-pdf mr-1"></i>
                  تصدير PDF
                </button>
                <button onclick="printWithdrawalsTable()" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm">
                  <i class="fa-solid fa-print mr-1"></i>
                  طباعة
                </button>
              </div>
              <div class="flex items-center gap-2 text-sm text-gray-600">
                <i class="fa-solid fa-clock text-gray-400"></i>
                <span>آخر تحديث: <?php echo date('Y-m-d H:i:s'); ?></span>
                <button onclick="location.reload()" class="text-blue-600 hover:text-blue-800 font-semibold">
                  <i class="fa-solid fa-refresh"></i>
                  تحديث
                </button>
              </div>
            </div>
          </section>

          <!-- Withdrawals Table -->
          <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-money-bill-transfer text-green-600"></i>
                طلبات سحب المسوقين
                <span class="bg-gray-100 text-gray-800 text-sm px-2 py-1 rounded-full mr-2">
                  <?php echo number_format($totalWithdrawals); ?> طلب
                </span>
              </h3>
            </div>
            
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-gray-50">
                  <tr class="text-right">
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">رقم الطلب</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">المسوق (المتجر)</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">الرصيد المتاح</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">مبلغ السحب</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">عدد الطلبات</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">إجمالي المسحوب</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">طريقة الدفع</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">الحالة</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">التاريخ</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">إجراءات</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <?php if (empty($withdrawals)): ?>
                    <tr>
                      <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                        <i class="fa-solid fa-inbox text-4xl mb-4 block text-gray-300"></i>
                        <p class="text-lg font-semibold mb-2">لا توجد طلبات سحب</p>
                        <p class="text-sm">لم يتم العثور على أي طلبات سحب تطابق معايير البحث المحددة</p>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($withdrawals as $withdrawal): ?>
                      <?php
                        // Prepare status visuals
                        $statusColors = [
                            'pending' => 'bg-yellow-100 text-yellow-800',
                            'completed' => 'bg-green-100 text-green-800',
                            'cancelled' => 'bg-red-100 text-red-800'
                        ];
                        $statusText = [
                            'pending' => 'قيد الانتظار',
                            'completed' => 'مكتمل',
                            'cancelled' => 'ملغي'
                        ];
                        $statusIcons = [
                            'pending' => 'fa-clock',
                            'completed' => 'fa-check-circle',
                            'cancelled' => 'fa-times-circle'
                        ];
                        $statusClass = $statusColors[$withdrawal['status']] ?? 'bg-gray-100 text-gray-800';
                        $statusLabel = $statusText[$withdrawal['status']] ?? $withdrawal['status'];
                        $statusIcon = $statusIcons[$withdrawal['status']] ?? 'fa-question';

                        // Payment method mapping
                        $paymentMethodKey = $withdrawal['method'] ?? 'flexy';
                        $paymentMethods = [
                            'flexy' => ['text' => 'فليكسي', 'class' => 'payment-method-flexi', 'icon' => 'fa-mobile'],
                            'baridi_mob' => ['text' => 'بريدي موب', 'class' => 'payment-method-ccp', 'icon' => 'fa-building-columns'],
                            'ccp' => ['text' => 'بريد الجزائر', 'class' => 'payment-method-ccp', 'icon' => 'fa-building-columns'],
                            'mobili' => ['text' => 'موبيليس', 'class' => 'payment-method-mobili', 'icon' => 'fa-mobile']
                        ];
                        $methodMeta = $paymentMethods[$paymentMethodKey] ?? $paymentMethods['flexy'];

                        // Payment details JSON
                        $detailsArr = [];
                        if (!empty($withdrawal['payment_details'])) {
                            $tmp = @json_decode((string)$withdrawal['payment_details'], true);
                            if (is_array($tmp)) { $detailsArr = $tmp; }
                        }
                        $methodNote = '';
                        if ($paymentMethodKey === 'flexy' && !empty($detailsArr['flexy_phone'])) {
                            $methodNote = 'الهاتف: ' . htmlspecialchars($detailsArr['flexy_phone']);
                        } elseif ($paymentMethodKey === 'baridi_mob' && !empty($detailsArr['baridi_mob'])) {
                            $methodNote = 'رقم الحساب: ' . htmlspecialchars($detailsArr['baridi_mob']);
                        } elseif ($paymentMethodKey === 'ccp' && !empty($detailsArr['ccp'])) {
                            $methodNote = 'CCP: ' . htmlspecialchars($detailsArr['ccp']);
                        }

                        $displayStore = $withdrawal['store_name'] ?? ($withdrawal['user_store_name'] ?? 'غير محدد');
                        $displayUsername = $withdrawal['username'] ?? 'غير معروف';
                        $availableBalance = isset($withdrawal['balance']) ? (float)$withdrawal['balance'] : 0.0;
                      ?>
                      <tr class="withdrawal-row hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3">
                          <span class="text-sm font-semibold text-blue-600">#<?php echo (int)$withdrawal['id']; ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600">
                              <i class="fa-solid fa-user text-xs"></i>
                            </div>
                            <div>
                              <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($displayUsername); ?></span>
                              <span class="text-xs text-gray-500 block">(<?php echo htmlspecialchars($displayStore); ?>)</span>
                            </div>
                          </div>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm font-bold text-green-600"><?php echo number_format($availableBalance, 2); ?> دج</span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm font-bold text-red-600"><?php echo number_format((float)$withdrawal['amount'], 2); ?> دج</span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-900"><?php echo number_format((int)$withdrawal['total_withdrawals']); ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm font-bold text-blue-600"><?php echo number_format((float)$withdrawal['total_withdrawn'], 2); ?> دج</span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="payment-method-badge <?php echo $methodMeta['class']; ?>">
                            <i class="fa-solid <?php echo $methodMeta['icon']; ?>"></i>
                            <?php echo $methodMeta['text']; ?>
                          </span>
                          <?php if ($methodNote): ?>
                            <div class="text-[11px] text-gray-500 mt-1"><?php echo $methodNote; ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                          <span class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold rounded-full <?php echo $statusClass; ?>">
                            <i class="fa-solid <?php echo $statusIcon; ?>"></i>
                            <?php echo $statusLabel; ?>
                          </span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($withdrawal['created_at'])); ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <div class="flex gap-1">
                            <button onclick="viewWithdrawalDetails(<?php echo (int)$withdrawal['id']; ?>)" 
                                    class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold px-2 py-1 rounded-lg hover:bg-indigo-50 transition-all" 
                                    title="عرض التفاصيل">
                              <i class="fa-solid fa-eye"></i>
                            </button>
                            <button onclick="openEditWithdrawalModal(<?php echo (int)$withdrawal['id']; ?>, '<?php echo htmlspecialchars($withdrawal['status'], ENT_QUOTES, 'UTF-8'); ?>')" 
                                    class="text-blue-600 hover:text-blue-800 text-sm font-semibold px-2 py-1 rounded-lg hover:bg-blue-50 transition-all"
                                    title="تحديث الحالة">
                              <i class="fa-solid fa-edit"></i>
                            </button>
                            <?php if ($withdrawal['status'] === 'pending'): ?>
                            <button onclick="quickApproveWithdrawal(<?php echo (int)$withdrawal['id']; ?>)" 
                                    class="text-green-600 hover:text-green-800 text-sm font-semibold px-2 py-1 rounded-lg hover:bg-green-50 transition-all"
                                    title="موافقة سريعة">
                              <i class="fa-solid fa-check"></i>
                            </button>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
            <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                  عرض <?php echo number_format(($page - 1) * $perPage + 1); ?> إلى 
                  <?php echo number_format(min($page * $perPage, $totalWithdrawals)); ?> من 
                  <?php echo number_format($totalWithdrawals); ?> طلب
                </div>
                <div class="flex gap-2">
                  <?php if ($page > 1): ?>
                    <a href="?status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchTerm); ?>&page=<?php echo $page - 1; ?>" 
                       class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                      <i class="fa-solid fa-chevron-right"></i>
                      السابق
                    </a>
                  <?php endif; ?>
                  
                  <div class="flex gap-1">
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    // Show first page if not in range
                    if ($startPage > 1) {
                      echo '<a href="?status=' . urlencode($statusFilter) . '&search=' . urlencode($searchTerm) . '&page=1" class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="px-2 py-2 text-sm text-gray-400">...</span>';
                      }
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                      <a href="?status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchTerm); ?>&page=<?php echo $i; ?>" 
                         class="px-3 py-2 text-sm rounded-lg transition-colors <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                        <?php echo $i; ?>
                      </a>
                    <?php endfor; 
                    
                    // Show last page if not in range
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="px-2 py-2 text-sm text-gray-400">...</span>';
                      }
                      echo '<a href="?status=' . urlencode($statusFilter) . '&search=' . urlencode($searchTerm) . '&page=' . $totalPages . '" class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">' . $totalPages . '</a>';
                    }
                    ?>
                  </div>
                  
                  <?php if ($page < $totalPages): ?>
                    <a href="?status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchTerm); ?>&page=<?php echo $page + 1; ?>" 
                       class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                      التالي
                      <i class="fa-solid fa-chevron-left"></i>
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </section>
          <?php endif; ?>
        </div>
      </main>
    </div>
  </div>

  <!-- Edit Withdrawal Status Modal -->
  <div id="editWithdrawalModal" class="modal">
    <div class="modal-content">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">تحديث حالة طلب السحب</h3>
        <button onclick="closeEditWithdrawalModal()" class="text-gray-400 hover:text-gray-600">
          <i class="fa-solid fa-times text-xl"></i>
        </button>
      </div>
      
      <form method="POST" id="editWithdrawalForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_withdrawal_status">
        <input type="hidden" name="withdrawal_id" id="editWithdrawalId">
        
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">الحالة الجديدة:</label>
            <select name="new_status" id="editWithdrawalStatus" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
              <option value="pending">قيد الانتظار</option>
              <option value="completed">مكتمل</option>
              <option value="cancelled">ملغي</option>
            </select>
          </div>

          <div id="cancelReasonDiv" style="display: none;">
            <label class="block text-sm font-semibold text-gray-700 mb-2">سبب الإلغاء:</label>
            <textarea name="cancel_reason" id="cancelReason" rows="3" 
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
                      placeholder="اكتب سبب إلغاء طلب السحب..."></textarea>
          </div>

          <div id="proofImageDiv" style="display: none;">
            <label class="block text-sm font-semibold text-gray-700 mb-2">إثبات الدفع:</label>
            <div class="flex items-center justify-center w-full">
              <label for="proofImage" class="flex flex-col items-center justify-center w-full h-32 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100">
                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                  <i class="fa-solid fa-cloud-upload-alt text-2xl text-gray-400 mb-2"></i>
                  <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">انقر لرفع الصورة</span> أو اسحب وأفلت</p>
                  <p class="text-xs text-gray-500">PNG, JPG أو JPEG (حد أقصى 5MB)</p>
                </div>
                <input id="proofImage" name="proof_image" type="file" class="hidden" accept="image/*" />
              </label>
            </div>
            <div id="imagePreview" class="mt-2 hidden">
              <img id="previewImg" src="" alt="معاينة الصورة" class="max-w-full h-32 object-cover rounded-lg">
            </div>
          </div>
        </div>
        
        <div class="flex gap-3 justify-end mt-6">
          <button type="button" onclick="closeEditWithdrawalModal()" 
                  class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            إلغاء
          </button>
          <button type="submit" 
                  class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2">
            <i class="fa-solid fa-save"></i>
            حفظ التغييرات
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Withdrawal Details Modal -->
  <div id="withdrawalDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-xl font-bold text-gray-900">تفاصيل طلب السحب</h3>
        <button onclick="closeWithdrawalDetailsModal()" class="text-gray-400 hover:text-gray-600">
          <i class="fa-solid fa-times text-xl"></i>
        </button>
      </div>
      
      <div id="withdrawalDetailsContent" class="space-y-4">
        <!-- Content will be populated by JavaScript -->
        <div class="text-center py-8">
          <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
          <p class="text-gray-500 mt-2">جاري تحميل التفاصيل...</p>
        </div>
      </div>
      
      <div class="flex justify-end mt-6 pt-4 border-t border-gray-200">
        <button onclick="closeWithdrawalDetailsModal()" 
                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
          إغلاق
        </button>
      </div>
    </div>
  </div>

  <!-- Quick Approval Confirmation Modal -->
  <div id="quickApprovalModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
      <div class="text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
          <i class="fa-solid fa-check text-green-600"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">تأكيد موافقة السحب</h3>
        <p class="text-sm text-gray-500 mb-6">هل أنت متأكد من الموافقة على طلب السحب هذا؟ سيتم خصم المبلغ من رصيد المسوق.</p>
        
        <div class="flex gap-3 justify-center">
          <button onclick="closeQuickApprovalModal()" 
                  class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            إلغاء
          </button>
          <button onclick="confirmQuickApproval()" 
                  class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
            تأكيد الموافقة
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let currentWithdrawalId = null;

    // Sidebar controls are provided by sidebar.php

    function openEditWithdrawalModal(withdrawalId, currentStatus) {
      currentWithdrawalId = withdrawalId;
      document.getElementById('editWithdrawalId').value = withdrawalId;
      document.getElementById('editWithdrawalStatus').value = currentStatus;
      document.getElementById('editWithdrawalModal').classList.add('show');
      document.body.style.overflow = 'hidden';
      
      // Show/hide relevant fields based on status
      toggleStatusFields(currentStatus);
    }

    function closeEditWithdrawalModal() {
      document.getElementById('editWithdrawalModal').classList.remove('show');
      document.body.style.overflow = 'auto';
      currentWithdrawalId = null;
    }

    function toggleStatusFields(status) {
      const cancelDiv = document.getElementById('cancelReasonDiv');
      const proofDiv = document.getElementById('proofImageDiv');
      
      if (status === 'cancelled') {
        cancelDiv.style.display = 'block';
        proofDiv.style.display = 'none';
      } else if (status === 'completed') {
        cancelDiv.style.display = 'none';
        proofDiv.style.display = 'block';
      } else {
        cancelDiv.style.display = 'none';
        proofDiv.style.display = 'none';
      }
    }

    // Status change handler
    document.getElementById('editWithdrawalStatus').addEventListener('change', function() {
      toggleStatusFields(this.value);
    });

    // Image preview functionality
    document.getElementById('proofImage').addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          document.getElementById('previewImg').src = e.target.result;
          document.getElementById('imagePreview').classList.remove('hidden');
        }
        reader.readAsDataURL(file);
      }
    });

    function viewWithdrawalDetails(withdrawalId) {
      document.getElementById('withdrawalDetailsModal').classList.add('show');
      document.body.style.overflow = 'hidden';
      const detailsEl = document.getElementById('withdrawalDetailsContent');
      detailsEl.innerHTML = '<div class="text-center py-8"><div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div><p class="text-gray-500 mt-2">جاري تحميل التفاصيل...</p></div>';
      const formData = new FormData();
      formData.append('action', 'get_withdrawal_details');
      formData.append('withdrawal_id', String(withdrawalId));
      fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
          if (!res || !res.success) { throw new Error('فشل الجلب'); }
          const d = res.data;
          const methodMap = {
            'flexy': { label: 'فليكسي' },
            'baridi_mob': { label: 'بريدي موب' },
            'ccp': { label: 'بريد الجزائر' },
            'mobili': { label: 'موبيليس' }
          };
          const methodLabel = (methodMap[d.method]?.label) || d.method;
          let methodExtra = '';
          if (d.method === 'flexy' && d.payment_details?.flexy_phone) methodExtra = 'الهاتف: ' + d.payment_details.flexy_phone;
          if (d.method === 'baridi_mob' && d.payment_details?.baridi_mob) methodExtra = 'رقم الحساب: ' + d.payment_details.baridi_mob;
          if (d.method === 'ccp' && d.payment_details?.ccp) methodExtra = 'CCP: ' + d.payment_details.ccp;
          const proof = d.proof_image ? `<a href="${d.proof_image}" target="_blank" class="text-blue-600 underline">عرض الإثبات</a>` : '<span class="text-gray-400">لا يوجد</span>';
          const processed = d.processed_at ? d.processed_at : '<span class="text-gray-400">—</span>';
          detailsEl.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 mb-2">معلومات المسوق</h4>
                <div class="space-y-2 text-sm">
                  <p><span class="font-semibold">الاسم:</span> ${d.username || 'غير معروف'}</p>
                  <p><span class="font-semibold">المتجر:</span> ${d.store_name || '—'}</p>
                  <p><span class="font-semibold">الهاتف:</span> ${d.user_phone || '—'}</p>
                  <p><span class="font-semibold">الرصيد الحالي:</span> ${Number(d.balance).toLocaleString()} دج</p>
                </div>
              </div>
              <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 mb-2">تفاصيل السحب</h4>
                <div class="space-y-2 text-sm">
                  <p><span class="font-semibold">رقم الطلب:</span> #${d.id}</p>
                  <p><span class="font-semibold">المبلغ:</span> ${Number(d.amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} دج</p>
                  <p><span class="font-semibold">طريقة الدفع:</span> ${methodLabel}</p>
                  ${methodExtra ? `<p><span class="font-semibold">بيانات الدفع:</span> ${methodExtra}</p>` : ''}
                  <p><span class="font-semibold">الحالة:</span> ${d.status}</p>
                  <p><span class="font-semibold">التاريخ:</span> ${d.created_at}</p>
                  <p><span class="font-semibold">تاريخ المعالجة:</span> ${processed}</p>
                  <p><span class="font-semibold">إثبات الدفع:</span> ${proof}</p>
                </div>
              </div>
            </div>
          `;
        })
        .catch(() => {
          detailsEl.innerHTML = '<div class="text-center text-red-600 py-8">تعذر تحميل التفاصيل</div>';
        });
    }

    function closeWithdrawalDetailsModal() {
      document.getElementById('withdrawalDetailsModal').classList.remove('show');
      document.body.style.overflow = 'auto';
    }

    function quickApproveWithdrawal(withdrawalId) {
      currentWithdrawalId = withdrawalId;
      document.getElementById('quickApprovalModal').classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeQuickApprovalModal() {
      document.getElementById('quickApprovalModal').classList.remove('show');
      document.body.style.overflow = 'auto';
      currentWithdrawalId = null;
    }

    function confirmQuickApproval() {
      if (currentWithdrawalId) {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
          <input type="hidden" name="action" value="update_withdrawal_status">
          <input type="hidden" name="withdrawal_id" value="${currentWithdrawalId}">
          <input type="hidden" name="new_status" value="completed">
        `;
        document.body.appendChild(form);
        form.submit();
      }
    }

    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', function(e) {
        if (e.target === this) {
          this.classList.remove('show');
          document.body.style.overflow = 'auto';
        }
      });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(modal => {
          modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
      }
    });

    // Form validation
    document.getElementById('editWithdrawalForm').addEventListener('submit', function(e) {
      const status = document.getElementById('editWithdrawalStatus').value;
      
      if (status === 'cancelled') {
        const reason = document.getElementById('cancelReason').value.trim();
        if (!reason) {
          e.preventDefault();
          alert('يرجى إدخال سبب الإلغاء');
          return false;
        }
      }
      
      // Confirmation for status changes
      if (status === 'completed') {
        if (!confirm('هل أنت متأكد من تأكيد هذا السحب؟ سيتم خصم المبلغ من رصيد المسوق.')) {
          e.preventDefault();
          return false;
        }
      } else if (status === 'cancelled') {
        if (!confirm('هل أنت متأكد من إلغاء طلب السحب هذا؟')) {
          e.preventDefault();
          return false;
        }
      }
    });

    // Export functionality
    function exportWithdrawals(format) {
      console.log('Exporting withdrawals in format:', format);
      alert('سيتم إضافة وظيفة التصدير قريباً');
    }

    // Print functionality
    function printWithdrawalsTable() {
      window.print();
    }

    // Auto-refresh functionality
    function updateNotifications() {
      console.log('Updating withdrawal notifications...');
    }

    setInterval(updateNotifications, 30000); // Every 30 seconds

    // Enhanced search functionality
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.querySelector('input[name="search"]');
      let searchTimeout;
      
      if (searchInput) {
        searchInput.addEventListener('input', function() {
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            console.log('Search term:', this.value);
          }, 500);
        });
      }

      // Table row animations
      document.querySelectorAll('.withdrawal-row').forEach(row => {
        row.addEventListener('mouseenter', function() {
          this.style.transform = 'translateX(-2px)';
          this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
        });
        
        row.addEventListener('mouseleave', function() {
          this.style.transform = 'translateX(0)';
          this.style.boxShadow = 'none';
        });
      });

      // Stat cards animation
      document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-4px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0) scale(1)';
        });
      });
    });
  </script>

  <style>
    @media print {
      body * { visibility: hidden; }
      .print-section, .print-section * { visibility: visible; }
      .print-section { position: absolute; left: 0; top: 0; }
      .no-print { display: none !important; }
    }
    
    /* Responsive improvements */
    @media (max-width: 768px) {
      .stat-card { margin-bottom: 1rem; }
      .modal-content { width: 95%; margin: 10% auto; }
      table { font-size: 0.875rem; }
      .withdrawal-row td { padding: 0.5rem; }
    }
    
    /* Loading state */
    .loading {
      opacity: 0.6;
      pointer-events: none;
    }
    
    .loading::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 20px;
      height: 20px;
      margin: -10px 0 0 -10px;
      border: 2px solid #f3f3f3;
      border-top: 2px solid #3498db;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* Custom scrollbar */
    .overflow-x-auto::-webkit-scrollbar {
      height: 8px;
    }
    
    .overflow-x-auto::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 4px;
    }
    
    .overflow-x-auto::-webkit-scrollbar-thumb {
      background: #c1c1c1;
      border-radius: 4px;
    }
    
    .overflow-x-auto::-webkit-scrollbar-thumb:hover {
      background: #a8a8a8;
    }
    
    /* Enhanced button styles */
    button:focus, .btn:focus {
      outline: none;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .btn-primary {
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
      transition: all 0.3s ease;
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }
    
    /* File upload styling */
    .file-upload-area {
      border: 2px dashed #d1d5db;
      transition: all 0.3s ease;
    }
    
    .file-upload-area:hover {
      border-color: #3b82f6;
      background-color: #eff6ff;
    }
    
    /* Payment method badges */
    .payment-method-badge {
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 9999px;
    }
    
    /* Status animations */
    .status-badge {
      animation: fadeIn 0.3s ease-in;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: scale(0.8); }
      to { opacity: 1; transform: scale(1); }
    }
    
    /* Modal animations */
    .modal.show .modal-content {
      animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
      from { 
        opacity: 0; 
        transform: translateY(-20px) scale(0.95); 
      }
      to { 
        opacity: 1; 
        transform: translateY(0) scale(1); 
      }
    }
  </style>
</body>
</html>