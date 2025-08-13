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

    // Handle order status update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $orderId = (int)$_POST['order_id'];
        $newStatus = $_POST['new_status'];
        
        $validStatuses = ['pending', 'confirmed', 'processing', 'shipping', 'delivered', 'cancelled', 'returned'];
        if (in_array($newStatus, $validStatuses)) {
            $updateStmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $orderId]);
            
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit;
        }
    }

    // Filter parameters
    $statusFilter = $_GET['status'] ?? 'all';
    $searchTerm = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    // Build WHERE clause
    $whereConditions = [];
    $params = [];

    if ($statusFilter !== 'all') {
        if ($statusFilter === 'pending_group') {
            $whereConditions[] = "o.status IN ('pending', 'confirmed', 'processing')";
        } elseif ($statusFilter === 'cancelled_returned') {
            $whereConditions[] = "o.status IN ('cancelled', 'returned')";
        } else {
            $whereConditions[] = "o.status = ?";
            $params[] = $statusFilter;
        }
    }

    if (!empty($searchTerm)) {
        $whereConditions[] = "(u.username LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR p.name LIKE ? OR o.id LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get orders with pagination
    $ordersQuery = "
        SELECT o.*, u.username, p.name as product_name, p.wholesale_price
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN products p ON o.product_id = p.id
        $whereClause
        ORDER BY o.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    
    $ordersStmt = $conn->prepare($ordersQuery);
    $ordersStmt->execute($params);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*)
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN products p ON o.product_id = p.id
        $whereClause
    ";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->execute($params);
    $totalOrders = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalOrders / $perPage);

    // Get statistics
    $stats = [];
    
    // All orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders");
    $stmt->execute();
    $stats['total'] = (int)$stmt->fetchColumn();

    // Pending orders (pending, confirmed, processing)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'confirmed', 'processing')");
    $stmt->execute();
    $stats['pending_group'] = (int)$stmt->fetchColumn();

    // Cancelled/Returned orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('cancelled', 'returned')");
    $stmt->execute();
    $stats['cancelled_returned'] = (int)$stmt->fetchColumn();

    // Delivered orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status = 'delivered'");
    $stmt->execute();
    $stats['delivered'] = (int)$stmt->fetchColumn();

    // Shipping orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status = 'shipping'");
    $stmt->execute();
    $stats['shipping'] = (int)$stmt->fetchColumn();

} catch (Throwable $e) {
    error_log($e->getMessage());
    $orders = [];
    $stats = ['total' => 0, 'pending_group' => 0, 'cancelled_returned' => 0, 'delivered' => 0, 'shipping' => 0];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>إدارة الطلبيات - لوحة الإدارة</title>
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
    .order-row { transition: all 0.2s ease; }
    .order-row:hover { background-color: #f8fafc; }
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
    .modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 12px; width: 90%; max-width: 500px; position: relative; }
    .modal.show { display: block; }
  </style>
</head>
<body class="bg-gray-50">
  <div class="flex h-screen bg-gray-50">
    <?php include 'admin_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
      <!-- Header -->
      <header class="bg-white border-b border-gray-200 sticky top-0 z-30">
        <div class="px-4 py-3 flex items-center justify-between">
          <div class="flex items-center gap-4">
            <button id="mobileMenuBtn" onclick="(window.toggleSidebar?toggleSidebar:openSidebar)()" class="lg:hidden p-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors">
              <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div class="flex items-center gap-2 font-bold text-gray-800">
              <i class="fa-solid fa-shopping-cart text-blue-600"></i>
              <span>إدارة الطلبيات</span>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <button class="relative text-gray-600 hover:text-gray-800" title="إشعارات">
              <i class="fa-regular fa-bell text-lg"></i>
              <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] rounded-full px-1"><?php echo $stats['pending_group']; ?></span>
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
                <span>تم تحديث حالة الطلب بنجاح!</span>
              </div>
            </div>
          <?php endif; ?>

          <!-- Statistics Section -->
          <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="stat-card bg-gradient-to-l from-blue-600 to-blue-800 text-white rounded-xl p-4 shadow-sm">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-white bg-opacity-20">
                  <i class="fa-solid fa-list-ul"></i>
                </div>
                <div class="text-sm font-semibold">الكل</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['total']); ?></div>
              <div class="text-sm opacity-90">الطلبيات الإجمالية</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-yellow-500 text-white">
                  <i class="fa-solid fa-clock"></i>
                </div>
                <div class="text-sm text-yellow-600 font-semibold">قيد المعالجة</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['pending_group']); ?></div>
              <div class="text-sm text-gray-600">طلبيات قيد الانتظار</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-red-500 text-white">
                  <i class="fa-solid fa-times-circle"></i>
                </div>
                <div class="text-sm text-red-600 font-semibold">ملغية/مرجعة</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['cancelled_returned']); ?></div>
              <div class="text-sm text-gray-600">طلبيات مرجعة أو ملغية</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-purple-500 text-white">
                  <i class="fa-solid fa-truck"></i>
                </div>
                <div class="text-sm text-purple-600 font-semibold">الشحن</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['shipping']); ?></div>
              <div class="text-sm text-gray-600">قيد الشحن</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-green-500 text-white">
                  <i class="fa-solid fa-check-circle"></i>
                </div>
                <div class="text-sm text-green-600 font-semibold">مكتملة</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($stats['delivered']); ?></div>
              <div class="text-sm text-gray-600">طلبيات مكتملة</div>
            </div>
          </section>

          <!-- Filters and Search -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
            <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between mb-4">
              <div class="flex flex-wrap gap-2">
                <a href="?status=all&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  جميع الطلبيات
                </a>
                <a href="?status=pending_group&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'pending_group' ? 'bg-yellow-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  قيد الانتظار
                </a>
                <a href="?status=cancelled_returned&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'cancelled_returned' ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  مرجعة/ملغية
                </a>
                <a href="?status=shipping&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'shipping' ? 'bg-purple-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  قيد الشحن
                </a>
                <a href="?status=delivered&search=<?php echo urlencode($searchTerm); ?>" 
                   class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors <?php echo $statusFilter === 'delivered' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                  مكتملة
                </a>
              </div>
              <div class="flex gap-2 w-full lg:w-auto">
                <form method="GET" class="flex gap-2 flex-1 lg:flex-initial">
                  <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                  <input type="text" name="search" placeholder="البحث في الطلبيات..." 
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
                <button onclick="exportOrders('excel')" class="px-3 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors text-sm">
                  <i class="fa-solid fa-file-excel mr-1"></i>
                  تصدير Excel
                </button>
                <button onclick="exportOrders('pdf')" class="px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors text-sm">
                  <i class="fa-solid fa-file-pdf mr-1"></i>
                  تصدير PDF
                </button>
                <button onclick="printOrdersTable()" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm">
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

          <!-- Orders Table -->
          <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-table text-emerald-600"></i>
                طلبيات المسوقين
                <span class="bg-gray-100 text-gray-800 text-sm px-2 py-1 rounded-full mr-2">
                  <?php echo number_format($totalOrders); ?> طلب
                </span>
              </h3>
            </div>
            
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-gray-50">
                  <tr class="text-right">
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">رقم الطلب</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">اسم متجر المسوق</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">اسم العميل</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">رقم الهاتف</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">العنوان</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">الولاية</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">المنتج</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">سعر البيع</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">حالة الطلبية</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">التاريخ</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase whitespace-nowrap">إجراءات</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <?php if (empty($orders)): ?>
                    <tr>
                      <td colspan="11" class="px-4 py-12 text-center text-gray-500">
                        <i class="fa-solid fa-inbox text-4xl mb-4 block text-gray-300"></i>
                        <p class="text-lg font-semibold mb-2">لا توجد طلبيات</p>
                        <p class="text-sm">لم يتم العثور على أي طلبيات تطابق معايير البحث المحددة</p>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                      <tr class="order-row hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3">
                          <span class="text-sm font-semibold text-blue-600">#<?php echo $order['id']; ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600">
                              <i class="fa-solid fa-store text-xs"></i>
                            </div>
                            <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($order['username'] ?? 'غير محدد'); ?></span>
                          </div>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-900"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-900 font-mono"><?php echo htmlspecialchars($order['customer_phone'] ?? 'غير محدد'); ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-600 max-w-xs truncate block" title="<?php echo htmlspecialchars($order['customer_address'] ?? ''); ?>">
                            <?php echo htmlspecialchars($order['customer_address'] ?? 'غير محدد'); ?>
                          </span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-900"><?php echo htmlspecialchars($order['customer_state'] ?? 'غير محدد'); ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-green-100 flex items-center justify-center text-green-600">
                              <i class="fa-solid fa-box text-xs"></i>
                            </div>
                            <div>
                              <span class="text-sm font-semibold text-gray-900 block"><?php echo htmlspecialchars($order['product_name'] ?? 'غير محدد'); ?></span>
                              <span class="text-xs text-gray-500">رقم: <?php echo $order['product_id']; ?></span>
                            </div>
                          </div>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm font-bold text-green-600"><?php echo number_format($order['sale_price'], 2); ?> دج</span>
                        </td>
                        <td class="px-4 py-3">
                          <?php
                          $statusColors = [
                              'pending' => 'bg-yellow-100 text-yellow-800',
                              'confirmed' => 'bg-blue-100 text-blue-800',
                              'processing' => 'bg-indigo-100 text-indigo-800',
                              'shipping' => 'bg-purple-100 text-purple-800',
                              'delivered' => 'bg-green-100 text-green-800',
                              'cancelled' => 'bg-red-100 text-red-800',
                              'returned' => 'bg-orange-100 text-orange-800'
                          ];
                          $statusText = [
                              'pending' => 'في الانتظار',
                              'confirmed' => 'مؤكد',
                              'processing' => 'قيد المعالجة',
                              'shipping' => 'قيد الشحن',
                              'delivered' => 'تم التسليم',
                              'cancelled' => 'ملغي',
                              'returned' => 'مرتجع'
                          ];
                          $statusIcons = [
                              'pending' => 'fa-clock',
                              'confirmed' => 'fa-check',
                              'processing' => 'fa-gear',
                              'shipping' => 'fa-truck',
                              'delivered' => 'fa-check-circle',
                              'cancelled' => 'fa-times-circle',
                              'returned' => 'fa-undo'
                          ];
                          $statusClass = $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-800';
                          $statusLabel = $statusText[$order['status']] ?? $order['status'];
                          $statusIcon = $statusIcons[$order['status']] ?? 'fa-question';
                          ?>
                          <span class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold rounded-full <?php echo $statusClass; ?>">
                            <i class="fa-solid <?php echo $statusIcon; ?>"></i>
                            <?php echo $statusLabel; ?>
                          </span>
                        </td>
                        <td class="px-4 py-3">
                          <span class="text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></span>
                        </td>
                        <td class="px-4 py-3">
                          <div class="flex gap-1">
                            <button onclick="viewOrderDetails(<?php echo $order['id']; ?>)" 
                                    class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold px-2 py-1 rounded-lg hover:bg-indigo-50 transition-all" 
                                    title="عرض التفاصيل">
                              <i class="fa-solid fa-eye"></i>
                            </button>
                            <button onclick="openEditModal(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')" 
                                    class="text-blue-600 hover:text-blue-800 text-sm font-semibold px-2 py-1 rounded-lg hover:bg-blue-50 transition-all"
                                    title="تعديل الحالة">
                              <i class="fa-solid fa-edit"></i>
                            </button>
                            <?php if ($order['status'] !== 'delivered' && $order['status'] !== 'cancelled'): ?>
                            <button onclick="quickStatusChange(<?php echo $order['id']; ?>, 'delivered')" 
                                    class="text-green-600 hover:text-green-800 text-sm font-semibold px-2 py-1 rounded-lg hover:bg-green-50 transition-all"
                                    title="تسليم سريع">
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
                  <?php echo number_format(min($page * $perPage, $totalOrders)); ?> من 
                  <?php echo number_format($totalOrders); ?> طلب
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

  <!-- Edit Status Modal -->
  <div id="editModal" class="modal">
    <div class="modal-content">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">تعديل حالة الطلب</h3>
        <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
          <i class="fa-solid fa-times text-xl"></i>
        </button>
      </div>
      
      <form method="POST" id="editForm">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="order_id" id="editOrderId">
        
        <div class="mb-6">
          <label class="block text-sm font-semibold text-gray-700 mb-2">الحالة الجديدة:</label>
          <select name="new_status" id="editStatus" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
            <option value="pending">في الانتظار</option>
            <option value="confirmed">مؤكد</option>
            <option value="processing">قيد المعالجة</option>
            <option value="shipping">قيد الشحن</option>
            <option value="delivered">تم التسليم</option>
            <option value="cancelled">ملغي</option>
            <option value="returned">مرتجع</option>
          </select>
        </div>
        
        <div class="flex gap-3 justify-end">
          <button type="button" onclick="closeEditModal()" 
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

  <!-- Order Details Modal -->
  <div id="detailsModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-xl font-bold text-gray-900">تفاصيل الطلب</h3>
        <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
          <i class="fa-solid fa-times text-xl"></i>
        </button>
      </div>
      
      <div id="orderDetailsContent" class="space-y-4">
        <!-- Content will be populated by JavaScript -->
        <div class="text-center py-8">
          <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
          <p class="text-gray-500 mt-2">جاري تحميل التفاصيل...</p>
        </div>
      </div>
      
      <div class="flex justify-end mt-6 pt-4 border-t border-gray-200">
        <button onclick="closeDetailsModal()" 
                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
          إغلاق
        </button>
      </div>
    </div>
  </div>

  <!-- Quick Status Change Confirmation Modal -->
  <div id="quickStatusModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
      <div class="text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
          <i class="fa-solid fa-check text-green-600"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">تأكيد تسليم الطلب</h3>
        <p class="text-sm text-gray-500 mb-6">هل أنت متأكد من تغيير حالة هذا الطلب إلى "تم التسليم"؟</p>
        
        <div class="flex gap-3 justify-center">
          <button onclick="closeQuickStatusModal()" 
                  class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            إلغاء
          </button>
          <button onclick="confirmQuickStatus()" 
                  class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
            تأكيد التسليم
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    function openSidebar() {
      if (typeof window.toggleSidebar === 'function') { toggleSidebar(); return; }
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) {
        sidebar.classList.toggle('open');
        sidebar.style.transform = sidebar.classList.contains('open') ? 'translateX(0)' : 'translateX(100%)';
      }
      if (overlay) { overlay.classList.toggle('hidden', !(sidebar && sidebar.classList.contains('open'))); }
      document.body.style.overflow = (sidebar && sidebar.classList.contains('open')) ? 'hidden' : '';
    }

    function openEditModal(orderId, currentStatus) {
      document.getElementById('editOrderId').value = orderId;
      document.getElementById('editStatus').value = currentStatus;
      document.getElementById('editModal').classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeEditModal() {
      document.getElementById('editModal').classList.remove('show');
      document.body.style.overflow = 'auto';
    }

    // Close modal when clicking outside
    document.getElementById('editModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeEditModal();
      }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeEditModal();
      }
    });

    // Auto-refresh notifications
    function updateNotifications() {
      // يمكن إضافة AJAX call هنا لتحديث الإشعارات
      console.log('Updating notifications...');
    }

    setInterval(updateNotifications, 30000); // كل 30 ثانية

    // Enhanced search functionality
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.querySelector('input[name="search"]');
      let searchTimeout;
      
      if (searchInput) {
        searchInput.addEventListener('input', function() {
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            // يمكن إضافة البحث المباشر هنا
            console.log('Search term:', this.value);
          }, 500);
        });
      }

      // Table row hover effects
      document.querySelectorAll('.order-row').forEach(row => {
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

    // Form validation
    document.getElementById('editForm').addEventListener('submit', function(e) {
      const status = document.getElementById('editStatus').value;
      if (!status) {
        e.preventDefault();
        alert('يرجى اختيار حالة الطلب');
        return false;
      }
      
      // Confirmation for critical status changes
      if (status === 'cancelled' || status === 'returned') {
        if (!confirm('هل أنت متأكد من تغيير حالة الطلب إلى "' + 
                     (status === 'cancelled' ? 'ملغي' : 'مرتجع') + '"؟')) {
          e.preventDefault();
          return false;
        }
      }
    });

    // Real-time status updates (يمكن تطويرها مع WebSocket)
    function checkForUpdates() {
      // يمكن إضافة AJAX call هنا للتحقق من التحديثات
      console.log('Checking for real-time updates...');
    }

    // setInterval(checkForUpdates, 60000); // كل دقيقة

    // Export functionality placeholder
    function exportOrders(format) {
      console.log('Exporting orders in format:', format);
      // يمكن تنفيذ وظيفة التصدير هنا
      alert('سيتم إضافة وظيفة التصدير قريباً');
    }

    // Bulk actions placeholder
    function handleBulkAction(action) {
      const checkboxes = document.querySelectorAll('input[name="selected_orders[]"]:checked');
      if (checkboxes.length === 0) {
        alert('يرجى اختيار طلب واحد على الأقل');
        return;
      }
      
      console.log('Bulk action:', action, 'for', checkboxes.length, 'orders');
      // يمكن تنفيذ الإجراءات المجمعة هنا
    }

    // Print functionality
    function printOrdersTable() {
      window.print();
    }

    // Advanced filtering
    function applyAdvancedFilter() {
      console.log('Applying advanced filters...');
      // يمكن إضافة فلاتر متقدمة هنا
    }
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
      .order-row td { padding: 0.5rem; }
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
    
    /* Status badge animations */
    .status-badge {
      animation: fadeIn 0.3s ease-in;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: scale(0.8); }
      to { opacity: 1; transform: scale(1); }
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
  </style>
</body>
</html>