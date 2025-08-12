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

    // Detect available columns in users table to build dynamic queries safely
    $userColumns = [];
    try {
        $colsStmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $colsStmt->execute();
        $userColumns = array_flip(array_map(function($r){ return $r['COLUMN_NAME']; }, $colsStmt->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $ie) {
        $userColumns = [];
    }
 
    // Handle AJAX requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        switch ($_POST['action']) {
            case 'delete_marketer':
                if (isset($_POST['marketer_id'])) {
                    $marketerId = (int)$_POST['marketer_id'];
                    
                    // Check if marketer has orders
                    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
                    $checkStmt->execute([$marketerId]);
                    $orderCount = $checkStmt->fetchColumn();
                    
                    if ($orderCount > 0) {
                        echo json_encode(['success' => false, 'message' => 'لا يمكن حذف المسوق لأنه يحتوي على طلبات']);
                        exit;
                    }
                    
                    $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
                    if ($deleteStmt->execute([$marketerId])) {
                        echo json_encode(['success' => true, 'message' => 'تم حذف المسوق بنجاح']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'فشل في حذف المسوق']);
                    }
                }
                exit;
                
            case 'update_marketer':
                if (isset($_POST['marketer_id'], $_POST['username'], $_POST['first_name'], $_POST['last_name'], $_POST['phone'], $_POST['email'])) {
                    $marketerId = (int)$_POST['marketer_id'];
                    $username = trim($_POST['username']);
                    $firstName = trim($_POST['first_name']);
                    $lastName = trim($_POST['last_name']);
                    $phone = trim($_POST['phone']);
                    $email = trim($_POST['email']);
                    
                    // Validation
                    if (empty($username) || empty($phone)) {
                        echo json_encode(['success' => false, 'message' => 'اسم المتجر ورقم الهاتف مطلوبان']);
                        exit;
                    }
                    
                    // Unique checks based on existing columns
                    // email uniqueness
                    if (isset($userColumns['email']) && $email !== '') {
                        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                        $checkStmt->execute([$email, $marketerId]);
                        if ($checkStmt->fetchColumn() > 0) {
                            echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني مستخدم بالفعل']);
                            exit;
                        }
                    }
                    // phone uniqueness
                    if (isset($userColumns['phone']) && $phone !== '') {
                        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE phone = ? AND id != ?");
                        $checkStmt->execute([$phone, $marketerId]);
                        if ($checkStmt->fetchColumn() > 0) {
                            echo json_encode(['success' => false, 'message' => 'رقم الهاتف مستخدم بالفعل']);
                            exit;
                        }
                    }
                    // username/store_name uniqueness if needed
                    if (isset($userColumns['username']) && $username !== '') {
                        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                        $checkStmt->execute([$username, $marketerId]);
                        if ($checkStmt->fetchColumn() > 0) {
                            echo json_encode(['success' => false, 'message' => 'اسم المتجر مستخدم بالفعل']);
                            exit;
                        }
                    }
                    
                    // Build dynamic update set according to existing columns
                    $setParts = [];
                    $values = [];
                    if (isset($userColumns['username'])) { $setParts[] = 'username = ?'; $values[] = $username; }
                    if (isset($userColumns['first_name'])) { $setParts[] = 'first_name = ?'; $values[] = $firstName; }
                    if (isset($userColumns['last_name'])) { $setParts[] = 'last_name = ?'; $values[] = $lastName; }
                    if (isset($userColumns['phone'])) { $setParts[] = 'phone = ?'; $values[] = $phone; }
                    if (isset($userColumns['email'])) { $setParts[] = 'email = ?'; $values[] = $email; }
                    
                    if (empty($setParts)) {
                        echo json_encode(['success' => false, 'message' => 'لا توجد أعمدة قابلة للتحديث']);
                        exit;
                    }
                    $values[] = $marketerId;
                    $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE id = ? AND role = 'user'";
                    $updateStmt = $conn->prepare($sql);
                    if ($updateStmt->execute($values)) {
                        echo json_encode(['success' => true, 'message' => 'تم تحديث بيانات المسوق بنجاح']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'فشل في تحديث البيانات']);
                    }
                }
                exit;
                
            case 'export_csv':
                // Build dynamic select
                $selectCols = ['id', 'username'];
                if (isset($userColumns['first_name'])) { $selectCols[] = 'first_name'; }
                if (isset($userColumns['last_name'])) { $selectCols[] = 'last_name'; }
                if (isset($userColumns['phone'])) { $selectCols[] = 'phone'; }
                if (isset($userColumns['email'])) { $selectCols[] = 'email'; }
                $selectCols[] = 'created_at';
                $sql = "SELECT " . implode(', ', $selectCols) . " FROM users WHERE role = 'user' ORDER BY created_at DESC";
                $stmt = $conn->prepare($sql);
                $stmt->execute();
                $marketers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Generate CSV
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="marketers_' . date('Y-m-d') . '.csv"');
                header('Cache-Control: no-cache, must-revalidate');
                
                echo "\xEF\xBB\xBF"; // UTF-8 BOM
                
                $output = fopen('php://output', 'w');
                
                // CSV Headers
                $headers = ['الرقم التعريفي', 'اسم المتجر'];
                if (isset($userColumns['first_name'])) { $headers[] = 'الاسم الأول'; }
                if (isset($userColumns['last_name'])) { $headers[] = 'اللقب'; }
                if (isset($userColumns['phone'])) { $headers[] = 'رقم الهاتف'; }
                if (isset($userColumns['email'])) { $headers[] = 'البريد الإلكتروني'; }
                $headers[] = 'تاريخ التسجيل';
                fputcsv($output, $headers);
                
                // CSV Data
                foreach ($marketers as $marketer) {
                    $row = [$marketer['id'], $marketer['username']];
                    if (isset($userColumns['first_name'])) { $row[] = $marketer['first_name'] ?? ''; }
                    if (isset($userColumns['last_name'])) { $row[] = $marketer['last_name'] ?? ''; }
                    if (isset($userColumns['phone'])) { $row[] = $marketer['phone'] ?? ''; }
                    if (isset($userColumns['email'])) { $row[] = $marketer['email'] ?? ''; }
                    $row[] = date('Y-m-d H:i:s', strtotime($marketer['created_at']));
                    fputcsv($output, $row);
                }
                
                fclose($output);
                exit;
        }
    }

    // Marketers Statistics
    $totalMarketers = 0;
    $newMarketersThisMonth = 0;
    $activeMarketersThisMonth = 0;

    // إجمالي عدد المسوقين
    $q = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'user'");
    $q->execute();
    $totalMarketers = (int)$q->fetchColumn();

    // المسوقين الجدد هذا الشهر
    $q = $conn->prepare("
        SELECT COUNT(*) 
        FROM users 
        WHERE role = 'user' 
        AND YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
    ");
    $q->execute();
    $newMarketersThisMonth = (int)$q->fetchColumn();

    // المسوقين النشطين هذا الشهر (الذين لديهم طلبات)
    $q = $conn->prepare("
        SELECT COUNT(DISTINCT u.id) 
        FROM users u 
        JOIN orders o ON u.id = o.user_id 
        WHERE u.role = 'user' 
        AND YEAR(o.created_at) = YEAR(CURDATE()) 
        AND MONTH(o.created_at) = MONTH(CURDATE())
    ");
    $q->execute();
    $activeMarketersThisMonth = (int)$q->fetchColumn();

    // Get all marketers with pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $whereClause = "WHERE role = 'user'";
    $params = [];
    
    if (!empty($search)) {
        // Limit search to columns that exist to avoid SQL errors
        $searchable = [];
        $searchable[] = 'username LIKE ?';
        if (isset($userColumns['first_name'])) { $searchable[] = 'first_name LIKE ?'; }
        if (isset($userColumns['last_name'])) { $searchable[] = 'last_name LIKE ?'; }
        if (isset($userColumns['phone'])) { $searchable[] = 'phone LIKE ?'; }
        if (isset($userColumns['email'])) { $searchable[] = 'email LIKE ?'; }
        $whereClause .= ' AND (' . implode(' OR ', $searchable) . ')';
        $searchTerm = "%$search%";
        $params = array_fill(0, count($searchable), $searchTerm);
    }

    // Total marketers for pagination
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM users $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $perPage);

    // Get marketers
    // Build dynamic select with fallbacks for first/last name if columns missing
    $selectParts = ['id', 'username'];
    if (isset($userColumns['first_name'])) {
        $selectParts[] = 'first_name';
    } else {
        $selectParts[] = "SUBSTRING_INDEX(username, ' ', 1) AS first_name";
    }
    if (isset($userColumns['last_name'])) {
        $selectParts[] = 'last_name';
    } else {
        $selectParts[] = "SUBSTRING_INDEX(username, ' ', -1) AS last_name";
    }
    if (isset($userColumns['phone'])) { $selectParts[] = 'phone'; }
    if (isset($userColumns['email'])) { $selectParts[] = 'email'; }
    $selectParts[] = 'created_at';
    $sql = "SELECT " . implode(', ', $selectParts) . " FROM users $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $marketers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>لوحة الإدارة - إدارة المسوقين</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Cairo', sans-serif; background-color: #f7f7f7; }
    @media (min-width: 1024px) { .main-content { margin-right: 256px; } }
    .page-transition { opacity: 0; transform: translateY(20px); animation: pageLoad 0.5s ease-out forwards; }
    @keyframes pageLoad { to { opacity: 1; transform: translateY(0); } }
    .card-animation { transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    .card-animation:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.12); }
    @media (max-width: 768px) { .mobile-optimized { padding: 1rem 0.75rem; } }
    .stat-card { transition: all 0.3s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    .modal { 
      display: none; 
      position: fixed; 
      z-index: 1000; 
      left: 0; 
      top: 0; 
      width: 100%; 
      height: 100%; 
      background-color: rgba(0,0,0,0.5);
      backdrop-filter: blur(8px);
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    .modal.show { 
      display: block; 
      opacity: 1;
    }
    .modal-content { 
      position: relative; 
      margin: 5% auto; 
      padding: 30px; 
      width: 95%; 
      max-width: 600px; 
      background-color: white; 
      border-radius: 20px;
      transform: translateY(-50px);
      transition: transform 0.3s ease;
      box-shadow: 0 25px 50px rgba(0,0,0,0.2);
    }
    .modal.show .modal-content {
      transform: translateY(0);
    }
    .loader {
      display: none;
      position: fixed;
      z-index: 2000;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      background: white;
      padding: 30px;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }
    .loader.show { display: block; }
    .spinner {
      border: 4px solid #f3f3f3;
      border-top: 4px solid #3498db;
      border-radius: 50%;
      width: 50px;
      height: 50px;
      animation: spin 1s linear infinite;
      margin: 0 auto 15px;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .alert {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 3000;
      min-width: 300px;
      padding: 15px 20px;
      border-radius: 10px;
      color: white;
      font-weight: 600;
      box-shadow: 0 10px 20px rgba(0,0,0,0.2);
      opacity: 0;
      transform: translateX(100%);
      transition: all 0.3s ease;
    }
    .alert.show {
      opacity: 1;
      transform: translateX(0);
    }
    .alert.success { background: linear-gradient(135deg, #4CAF50, #45a049); }
    .alert.error { background: linear-gradient(135deg, #f44336, #da190b); }
    .btn-hover { transition: all 0.2s ease; }
    .btn-hover:hover { transform: translateY(-2px); }
    .table-row-hover { transition: all 0.2s ease; }
    .table-row-hover:hover { background-color: #f8fafc; transform: scale(1.01); }
    .search-highlight { background-color: #fef3c7; padding: 2px 4px; border-radius: 4px; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .fade-in { animation: fadeIn 0.5s ease-in; }
    .gradient-bg {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
  </style>
</head>
<body class="bg-gray-50">
  <!-- Loader -->
  <div id="loader" class="loader">
    <div class="spinner"></div>
    <p class="text-center text-gray-600">جاري المعالجة...</p>
  </div>

  <!-- Alert Messages -->
  <div id="alertContainer"></div>

  <div class="flex h-screen bg-gray-50">
    <?php include 'sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
      <!-- Header -->
      <header class="bg-white border-b border-gray-200 sticky top-0 z-30">
        <div class="px-4 py-3 flex items-center justify-between">
          <div class="flex items-center gap-4">
            <button id="mobileMenuBtn" onclick="openSidebar()" class="lg:hidden p-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors btn-hover">
              <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div class="flex items-center gap-2 font-bold text-gray-800">
              <i class="fa-solid fa-users text-indigo-600"></i>
              <span>إدارة المسوقين</span>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <button onclick="exportCSV()" class="gradient-bg hover:opacity-90 text-white px-4 py-2 rounded-lg text-sm font-semibold flex items-center gap-2 btn-hover">
              <i class="fa-solid fa-download"></i>
              تصدير CSV
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
          
          <!-- Statistics Section -->
          <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="stat-card card-animation bg-white rounded-xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-3 rounded-lg bg-gradient-to-r from-blue-500 to-blue-600 text-white">
                  <i class="fa-solid fa-users text-xl"></i>
                </div>
                <div class="text-sm text-blue-600 font-semibold">إجمالي</div>
              </div>
              <div class="text-3xl font-bold mt-4 text-blue-600"><?php echo number_format($totalMarketers); ?></div>
              <div class="text-sm text-gray-600 mt-1">إجمالي عدد المسوقين</div>
              <div class="mt-3 pt-3 border-t border-gray-100">
                <div class="flex items-center text-xs text-gray-500">
                  <i class="fa-solid fa-chart-bar ml-1"></i>
                  <span>المجموع الكلي</span>
                </div>
              </div>
            </div>

            <div class="stat-card card-animation bg-white rounded-xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-3 rounded-lg bg-gradient-to-r from-green-500 to-green-600 text-white">
                  <i class="fa-solid fa-user-plus text-xl"></i>
                </div>
                <div class="text-sm text-green-600 font-semibold">جديد</div>
              </div>
              <div class="text-3xl font-bold mt-4 text-green-600"><?php echo number_format($newMarketersThisMonth); ?></div>
              <div class="text-sm text-gray-600 mt-1">المسوقين الجدد هذا الشهر</div>
              <div class="mt-3 pt-3 border-t border-gray-100">
                <div class="flex items-center text-xs text-gray-500">
                  <i class="fa-solid fa-calendar-plus ml-1"></i>
                  <span><?php echo date('F Y'); ?></span>
                </div>
              </div>
            </div>

            <div class="stat-card card-animation bg-white rounded-xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-3 rounded-lg bg-gradient-to-r from-purple-500 to-purple-600 text-white">
                  <i class="fa-solid fa-chart-line text-xl"></i>
                </div>
                <div class="text-sm text-purple-600 font-semibold">نشط</div>
              </div>
              <div class="text-3xl font-bold mt-4 text-purple-600"><?php echo number_format($activeMarketersThisMonth); ?></div>
              <div class="text-sm text-gray-600 mt-1">المسوقين النشطين هذا الشهر</div>
              <div class="mt-3 pt-3 border-t border-gray-100">
                <div class="flex items-center text-xs text-gray-500">
                  <i class="fa-solid fa-activity ml-1"></i>
                  <span>لديهم طلبات</span>
                </div>
              </div>
            </div>
          </section>

          <!-- Search and Filter Section -->
          <section class="card-animation bg-white rounded-xl p-6 shadow-sm border border-gray-100">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
              <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-users-gear text-blue-600"></i>
                إدارة المسوقين
                <?php if (!empty($search)): ?>
                  <span class="text-sm font-normal text-gray-500">- نتائج البحث عن: "<?php echo htmlspecialchars($search); ?>"</span>
                <?php endif; ?>
              </h2>
              <div class="flex items-center gap-3">
                <form method="GET" class="flex items-center gap-2">
                  <div class="relative">
                    <input 
                      type="text" 
                      name="search" 
                      value="<?php echo htmlspecialchars($search); ?>"
                      placeholder="البحث في المسوقين..." 
                      class="pr-10 pl-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-64"
                      autocomplete="off"
                    >
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                      <i class="fa-solid fa-search text-gray-400"></i>
                    </div>
                  </div>
                  <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold btn-hover">
                    <i class="fa-solid fa-search ml-1"></i>
                    بحث
                  </button>
                  <?php if (!empty($search)): ?>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-semibold btn-hover">
                      <i class="fa-solid fa-times ml-1"></i>
                      إلغاء
                    </a>
                  <?php endif; ?>
                </form>
              </div>
            </div>
            
            <!-- Results count -->
            <?php if (!empty($marketers)): ?>
              <div class="mt-4 pt-4 border-t border-gray-100">
                <div class="flex items-center justify-between text-sm text-gray-600">
                  <span>عدد النتائج: <strong><?php echo $totalRecords; ?></strong> مسوق</span>
                  <span>الصفحة <?php echo $page; ?> من <?php echo $totalPages; ?></span>
                </div>
              </div>
            <?php endif; ?>
          </section>

          <!-- Marketers Table -->
          <section class="card-animation bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="gradient-bg text-white">
                  <tr>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-hashtag ml-1"></i>
                      الرقم التعريفي
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-store ml-1"></i>
                      اسم المتجر
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-user ml-1"></i>
                      الاسم الأول
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-user-tag ml-1"></i>
                      اللقب
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-phone ml-1"></i>
                      رقم الهاتف
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-envelope ml-1"></i>
                      البريد الإلكتروني
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-calendar ml-1"></i>
                      تاريخ التسجيل
                    </th>
                    <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider">
                      <i class="fa-solid fa-cogs ml-1"></i>
                      الإجراءات
                    </th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <?php if (empty($marketers)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                        <div class="flex flex-col items-center">
                          <i class="fa-solid fa-inbox text-6xl mb-4 text-gray-300"></i>
                          <p class="text-xl font-semibold mb-2">لا توجد نتائج</p>
                          <?php if (!empty($search)): ?>
                            <p class="text-sm">جرب البحث بكلمات مختلفة أو <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="text-blue-600 hover:underline">عرض جميع المسوقين</a></p>
                          <?php else: ?>
                            <p class="text-sm">لم يتم تسجيل أي مسوقين بعد</p>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($marketers as $marketer): ?>
                      <tr class="table-row-hover" id="marketer-<?php echo $marketer['id']; ?>">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-blue-600">
                          <div class="flex items-center">
                            <div class="w-2 h-2 bg-blue-500 rounded-full ml-2"></div>
                            #<?php echo $marketer['id']; ?>
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                          <div class="flex items-center">
                            <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white text-xs font-bold ml-3">
                              <?php echo strtoupper(substr($marketer['username'], 0, 1)); ?>
                            </div>
                            <?php echo htmlspecialchars($marketer['username']); ?>
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($marketer['first_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($marketer['last_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          <div class="flex items-center">
                            <i class="fa-solid fa-phone text-green-500 ml-2"></i>
                            <?php echo htmlspecialchars($marketer['phone']); ?>
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          <div class="flex items-center">
                            <i class="fa-solid fa-envelope text-gray-400 ml-2"></i>
                            <?php echo htmlspecialchars($marketer['email']); ?>
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          <div class="flex flex-col">
                            <span><?php echo date('Y/m/d', strtotime($marketer['created_at'])); ?></span>
                            <span class="text-xs text-gray-400"><?php echo date('H:i', strtotime($marketer['created_at'])); ?></span>
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                          <div class="flex items-center gap-2">
                            <button 
                              onclick="editMarketer(<?php echo htmlspecialchars(json_encode($marketer)); ?>)" 
                              class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1 btn-hover"
                              title="تعديل بيانات المسوق"
                            >
                              <i class="fa-solid fa-edit"></i>
                              تعديل
                            </button>
                            <button 
                              onclick="confirmDelete(<?php echo $marketer['id']; ?>, '<?php echo htmlspecialchars($marketer['username']); ?>')" 
                              class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1 btn-hover"
                              title="حذف المسوق"
                            >
                              <i class="fa-solid fa-trash"></i>
                              حذف
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
              <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                  <div class="text-sm text-gray-700">
                    عرض <?php echo (($page - 1) * $perPage) + 1; ?> إلى <?php echo min($page * $perPage, $totalRecords); ?> من أصل <?php echo $totalRecords; ?> نتيجة
                  </div>
                  <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                      <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                         class="bg-white hover:bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm border border-gray-300 btn-hover flex items-center gap-1">
                        <i class="fa-solid fa-chevron-right"></i>
                        السابق
                      </a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                      <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                         class="bg-white hover:bg-gray-100 text-gray-700 px-3 py-2 rounded-lg text-sm border border-gray-300 btn-hover">1</a>
                      <?php if ($start > 2): ?>
                        <span class="text-gray-500">...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                      <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                         class="<?php echo $i == $page ? 'gradient-bg text-white' : 'bg-white hover:bg-gray-100 text-gray-700 border border-gray-300'; ?> px-3 py-2 rounded-lg text-sm font-semibold btn-hover">
                        <?php echo $i; ?>
                      </a>
                    <?php endfor; ?>
                    
                    <?php if ($end < $totalPages): ?>
                      <?php if ($end < $totalPages - 1): ?>
                        <span class="text-gray-500">...</span>
                      <?php endif; ?>
                      <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                         class="bg-white hover:bg-gray-100 text-gray-700 px-3 py-2 rounded-lg text-sm border border-gray-300 btn-hover"><?php echo $totalPages; ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                      <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                         class="bg-white hover:bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm border border-gray-300 btn-hover flex items-center gap-1">
                        التالي
                        <i class="fa-solid fa-chevron-left"></i>
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </section>
        </div>
      </main>
    </div>
  </div>

  <!-- Edit Modal -->
  <div id="editModal" class="modal">
    <div class="modal-content">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
          <i class="fa-solid fa-user-edit text-blue-600"></i>
          تعديل بيانات المسوق
        </h3>
        <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-700 p-2 rounded-lg hover:bg-gray-100 btn-hover">
          <i class="fa-solid fa-times text-xl"></i>
        </button>
      </div>
      <form id="editForm">
        <input type="hidden" id="editMarketerId" name="marketer_id">
        <div class="space-y-5">
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2 flex items-center gap-2">
              <i class="fa-solid fa-store text-blue-600"></i>
              اسم المتجر
            </label>
            <input type="text" id="editUsername" name="username" required 
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fa-solid fa-user text-green-600"></i>
                الاسم الأول
              </label>
              <input type="text" id="editFirstName" name="first_name" required 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
            </div>
            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fa-solid fa-user-tag text-purple-600"></i>
                اللقب
              </label>
              <input type="text" id="editLastName" name="last_name" required 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
            </div>
          </div>
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2 flex items-center gap-2">
              <i class="fa-solid fa-phone text-green-600"></i>
              رقم الهاتف
            </label>
            <input type="text" id="editPhone" name="phone" required 
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
          </div>
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2 flex items-center gap-2">
              <i class="fa-solid fa-envelope text-orange-600"></i>
              البريد الإلكتروني
            </label>
            <input type="email" id="editEmail" name="email" 
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
          </div>
        </div>
        <div class="flex items-center justify-end gap-3 mt-8 pt-6 border-t border-gray-200">
          <button type="button" onclick="closeEditModal()" 
                  class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-semibold btn-hover flex items-center gap-2">
            <i class="fa-solid fa-times"></i>
            إلغاء
          </button>
          <button type="submit" 
                  class="gradient-bg hover:opacity-90 text-white px-6 py-3 rounded-lg font-semibold btn-hover flex items-center gap-2">
            <i class="fa-solid fa-save"></i>
            حفظ التعديلات
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div id="deleteModal" class="modal">
    <div class="modal-content max-w-md">
      <div class="text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
          <i class="fa-solid fa-exclamation-triangle text-red-600 text-xl"></i>
        </div>
        <h3 class="text-lg font-bold text-gray-900 mb-2">تأكيد الحذف</h3>
        <p class="text-sm text-gray-600 mb-6">
          هل أنت متأكد من حذف المسوق "<span id="deleteMarketerName" class="font-semibold text-red-600"></span>"؟
          <br><span class="text-red-500 font-semibold">هذا الإجراء لا يمكن التراجع عنه!</span>
        </p>
        <div class="flex items-center justify-center gap-3">
          <button type="button" onclick="closeDeleteModal()" 
                  class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-semibold btn-hover">
            إلغاء
          </button>
          <button type="button" onclick="deleteMarketer()" 
                  class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg font-semibold btn-hover flex items-center gap-2">
            <i class="fa-solid fa-trash"></i>
            حذف نهائياً
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Global variables
    let currentMarketerId = null;

    // Show alert function
    function showAlert(message, type = 'success') {
      const alertContainer = document.getElementById('alertContainer');
      const alert = document.createElement('div');
      alert.className = `alert ${type}`;
      alert.innerHTML = `
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <i class="fa-solid fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
          </div>
          <button onclick="this.parentElement.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fa-solid fa-times"></i>
          </button>
        </div>
      `;
      
      alertContainer.appendChild(alert);
      
      // Show alert
      setTimeout(() => alert.classList.add('show'), 100);
      
      // Auto remove after 5 seconds
      setTimeout(() => {
        alert.classList.remove('show');
        setTimeout(() => alert.remove(), 300);
      }, 5000);
    }

    // Show/Hide loader
    function showLoader() {
      document.getElementById('loader').classList.add('show');
    }

    function hideLoader() {
      document.getElementById('loader').classList.remove('show');
    }

    // Edit marketer function
    function editMarketer(marketer) {
      document.getElementById('editMarketerId').value = marketer.id;
      document.getElementById('editUsername').value = marketer.username;
      document.getElementById('editFirstName').value = marketer.first_name;
      document.getElementById('editLastName').value = marketer.last_name;
      document.getElementById('editPhone').value = marketer.phone;
      document.getElementById('editEmail').value = marketer.email;
      
      const modal = document.getElementById('editModal');
      modal.classList.add('show');
      
      // Focus on first input
      setTimeout(() => {
        document.getElementById('editUsername').focus();
      }, 300);
    }

    // Close edit modal
    function closeEditModal() {
      document.getElementById('editModal').classList.remove('show');
    }

    // Confirm delete function
    function confirmDelete(marketerId, marketerName) {
      currentMarketerId = marketerId;
      document.getElementById('deleteMarketerName').textContent = marketerName;
      document.getElementById('deleteModal').classList.add('show');
    }

    // Close delete modal
    function closeDeleteModal() {
      document.getElementById('deleteModal').classList.remove('show');
      currentMarketerId = null;
    }

    // Delete marketer function
    function deleteMarketer() {
      if (!currentMarketerId) return;
      
      showLoader();
      closeDeleteModal();
      
      const formData = new FormData();
      formData.append('action', 'delete_marketer');
      formData.append('marketer_id', currentMarketerId);
      
      fetch(window.location.href, {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        hideLoader();
        
        if (data.success) {
          showAlert(data.message, 'success');
          // Remove row from table with animation
          const row = document.getElementById(`marketer-${currentMarketerId}`);
          if (row) {
            row.style.opacity = '0';
            row.style.transform = 'translateX(-100%)';
            setTimeout(() => {
              row.remove();
              // Reload page if no more rows
              const tbody = document.querySelector('tbody');
              const rows = tbody.querySelectorAll('tr:not([colspan])');
              if (rows.length === 0) {
                setTimeout(() => location.reload(), 1000);
              }
            }, 300);
          }
        } else {
          showAlert(data.message, 'error');
        }
      })
      .catch(error => {
        hideLoader();
        showAlert('حدث خطأ أثناء الحذف', 'error');
        console.error('Error:', error);
      });
    }

    // Export CSV function
    function exportCSV() {
      showLoader();
      
      const form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';
      
      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'export_csv';
      
      form.appendChild(actionInput);
      document.body.appendChild(form);
      
      form.submit();
      
      // Hide loader after a short delay
      setTimeout(() => {
        hideLoader();
        showAlert('تم تصدير البيانات بنجاح', 'success');
      }, 2000);
      
      document.body.removeChild(form);
    }

    // Handle edit form submission
    document.getElementById('editForm').addEventListener('submit', function(e) {
      e.preventDefault();
      
      showLoader();
      
      const formData = new FormData(this);
      formData.append('action', 'update_marketer');
      
      fetch(window.location.href, {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        hideLoader();
        
        if (data.success) {
          showAlert(data.message, 'success');
          closeEditModal();
          // Reload page to show updated data
          setTimeout(() => location.reload(), 1000);
        } else {
          showAlert(data.message, 'error');
        }
      })
      .catch(error => {
        hideLoader();
        showAlert('حدث خطأ أثناء التحديث', 'error');
        console.error('Error:', error);
      });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      // ESC key to close modals
      if (e.key === 'Escape') {
        closeEditModal();
        closeDeleteModal();
      }
      
      // Ctrl+E to focus search
      if (e.ctrlKey && e.key === 'e') {
        e.preventDefault();
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
          searchInput.focus();
          searchInput.select();
        }
      }
      
      // Ctrl+D to download CSV
      if (e.ctrlKey && e.key === 'd') {
        e.preventDefault();
        exportCSV();
      }
    });

    // Search input enhancements
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
      // Auto-submit search on Enter
      searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          this.form.submit();
        }
      });
      
      // Clear search on Ctrl+Backspace
      searchInput.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Backspace') {
          this.value = '';
          this.form.submit();
        }
      });
    }

    // Auto-hide alerts on click outside
    document.addEventListener('click', function(e) {
      if (e.target.closest('.modal') && !e.target.closest('.modal-content')) {
        closeEditModal();
        closeDeleteModal();
      }
    });

    // Page load animation
    document.addEventListener('DOMContentLoaded', function() {
      // Animate table rows
      const rows = document.querySelectorAll('tbody tr');
      rows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        setTimeout(() => {
          row.style.transition = 'all 0.3s ease';
          row.style.opacity = '1';
          row.style.transform = 'translateY(0)';
        }, index * 50);
      });
      
      // Show keyboard shortcuts info
      console.log(`
🎯 اختصارات لوحة المفاتيح:
- ESC: إغلاق النوافذ المنبثقة
- Ctrl+E: التركيز على مربع البحث
- Ctrl+D: تصدير CSV
- Ctrl+Backspace: مسح البحث
- Enter في البحث: تنفيذ البحث
      `);
    });

    // Add smooth scrolling to pagination links
    document.querySelectorAll('a[href*="page="]').forEach(link => {
      link.addEventListener('click', function(e) {
        const targetPage = new URL(this.href).searchParams.get('page');
        const currentPage = new URLSearchParams(window.location.search).get('page') || '1';
        
        if (targetPage !== currentPage) {
          // Add loading state to clicked link
          this.style.opacity = '0.5';
          this.style.pointerEvents = 'none';
        }
      });
    });

    // Sidebar controls are provided by sidebar.php

    // Performance monitoring
    window.addEventListener('load', function() {
      const loadTime = window.performance.timing.domContentLoadedEventEnd - window.performance.timing.navigationStart;
      if (loadTime > 3000) {
        console.warn('⚠️ الصفحة تحتاج وقت طويل للتحميل:', loadTime + 'ms');
      }
    });

    // Auto-refresh data every 5 minutes (optional)
    // setInterval(() => {
    //   if (document.visibilityState === 'visible') {
    //     location.reload();
    //   }
    // }, 300000);
  </script>
</body>
</html>