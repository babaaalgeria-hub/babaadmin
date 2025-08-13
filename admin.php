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

    // Admin Statistics
    $totalProfits = 0.0;
    $pendingWithdrawals = 0.0;
    $todayOrders = 0;
    $weeklyOrders = 0;
    $monthlyOrders = 0;
    $completedOrders = 0;
    $cancelledOrders = 0;
    $shippingOrders = 0;
    $totalUsers = 0;
    $totalProducts = 0;
    $successRate = 0.0;

    // إجمالي الأرباح (من جميع الطلبات المكتملة)
    $q = $conn->prepare("
        SELECT COALESCE(SUM(GREATEST(o.sale_price - COALESCE(p.wholesale_price, 0), 0)), 0)
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.id
        WHERE o.status = 'delivered'
    ");
    $q->execute();
    $totalProfits = (float)$q->fetchColumn();

    // الأرباح في انتظار السحب
    $q = $conn->prepare("
        SELECT COALESCE(SUM(w.amount), 0)
        FROM withdrawals w
        WHERE w.status = 'pending'
    ");
    $q->execute();
    $pendingWithdrawals = (float)$q->fetchColumn();

    // طلبات اليوم قيد الانتظار
    $q = $conn->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE DATE(created_at) = CURDATE() 
        AND status IN ('pending', 'confirmed', 'processing')
    ");
    $q->execute();
    $todayOrders = (int)$q->fetchColumn();

    // طلبات الأسبوع
    $q = $conn->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
    ");
    $q->execute();
    $weeklyOrders = (int)$q->fetchColumn();

    // طلبات الشهر
    $q = $conn->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
    ");
    $q->execute();
    $monthlyOrders = (int)$q->fetchColumn();

    // إحصائيات حالة الطلبات
    $q = $conn->prepare("
        SELECT
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status IN ('shipping', 'confirmed', 'processing') THEN 1 ELSE 0 END) as shipping,
        SUM(CASE WHEN status IN ('cancelled', 'returned') THEN 1 ELSE 0 END) as cancelled
        FROM orders
    ");
    $q->execute();
    $statusCounts = $q->fetch(PDO::FETCH_ASSOC);
    $completedOrders = (int)$statusCounts['completed'];
    $shippingOrders = (int)$statusCounts['shipping'];
    $cancelledOrders = (int)$statusCounts['cancelled'];

    // معدل النجاح
    $totalOrdersForRate = $completedOrders + $cancelledOrders;
    $successRate = $totalOrdersForRate > 0 ? round($completedOrders * 100.0 / $totalOrdersForRate, 1) : 0.0;

    // إجمالي المستخدمين
    $q = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'user'");
    $q->execute();
    $totalUsers = (int)$q->fetchColumn();

    // إجمالي المنتجات
    $q = $conn->prepare("SELECT COUNT(*) FROM products");
    $q->execute();
    $totalProducts = (int)$q->fetchColumn();

    // طلبات السحب الحديثة
    $withdrawalRequests = [];
    $q = $conn->prepare("
        SELECT w.*, u.username, u.phone
        FROM withdrawals w
        JOIN users u ON w.user_id = u.id
        WHERE w.status = 'pending'
        ORDER BY w.created_at DESC
        LIMIT 5
    ");
    $q->execute();
    $withdrawalRequests = $q->fetchAll(PDO::FETCH_ASSOC);

    // الطلبات الحديثة
    $recentOrders = [];
    $q = $conn->prepare("
        SELECT o.*, u.username, p.name as product_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN products p ON o.product_id = p.id
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $q->execute();
    $recentOrders = $q->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>لوحة الإدارة - إحصائيات النظام</title>
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
            <button id="mobileMenuBtn" onclick="openSidebar()" class="lg:hidden p-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors">
              <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div class="flex items-center gap-2 font-bold text-gray-800">
              <i class="fa-solid fa-chart-pie text-blue-600"></i>
              <span>لوحة الإدارة - الإحصائيات</span>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <button class="relative text-gray-600 hover:text-gray-800" title="إشعارات">
              <i class="fa-regular fa-bell text-lg"></i>
              <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] rounded-full px-1"><?php echo count($withdrawalRequests); ?></span>
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
          <!-- Welcome Section -->
          <section class="bg-gradient-to-l from-blue-600 to-blue-800 rounded-xl p-6 text-white">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
              <div>
                <h1 class="text-xl sm:text-2xl font-bold flex items-center gap-2">
                  <i class="fa-solid fa-crown"></i>
                  مرحباً، <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>
                </h1>
                <p class="opacity-90 mt-1">نظرة عامة على أداء النظام والإحصائيات</p>
              </div>
              <div class="text-center">
                <div class="text-3xl font-extrabold"><?php echo number_format($totalProfits, 2); ?> دج</div>
                <div class="text-sm opacity-90">إجمالي الأرباح</div>
              </div>
            </div>
          </section>

          <!-- Main Statistics -->
          <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-green-500 text-white">
                  <i class="fa-solid fa-money-bill-wave"></i>
                </div>
                <div class="text-sm text-green-600 font-semibold">
                  <i class="fa-solid fa-arrow-up"></i> 15%
                </div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($pendingWithdrawals, 2); ?></div>
              <div class="text-sm text-gray-600">في انتظار السحب</div>
              <div class="text-xs text-gray-500 mt-1">دج</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-orange-500 text-white">
                  <i class="fa-solid fa-clock"></i>
                </div>
                <div class="text-sm text-orange-600 font-semibold">اليوم</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($todayOrders); ?></div>
              <div class="text-sm text-gray-600">طلبات قيد الانتظار</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-purple-500 text-white">
                  <i class="fa-solid fa-calendar-week"></i>
                </div>
                <div class="text-sm text-purple-600 font-semibold">
                  <i class="fa-solid fa-arrow-up"></i> 8%
                </div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($weeklyOrders); ?></div>
              <div class="text-sm text-gray-600">طلبات الأسبوع</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-blue-500 text-white">
                  <i class="fa-solid fa-calendar-month"></i>
                </div>
                <div class="text-sm text-blue-600 font-semibold">
                  <i class="fa-solid fa-arrow-up"></i> 22%
                </div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($monthlyOrders); ?></div>
              <div class="text-sm text-gray-600">طلبات الشهر</div>
            </div>
          </section>

          <!-- Additional Statistics -->
          <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-emerald-500 text-white">
                  <i class="fa-solid fa-users"></i>
                </div>
                <div class="text-sm text-emerald-600 font-semibold">نشط</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($totalUsers); ?></div>
              <div class="text-sm text-gray-600">إجمالي المسوقين</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-indigo-500 text-white">
                  <i class="fa-solid fa-box"></i>
                </div>
                <div class="text-sm text-indigo-600 font-semibold">متاح</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($totalProducts); ?></div>
              <div class="text-sm text-gray-600">إجمالي المنتجات</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-rose-500 text-white">
                  <i class="fa-solid fa-percentage"></i>
                </div>
                <div class="text-sm text-rose-600 font-semibold">معدل</div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo $successRate; ?>%</div>
              <div class="text-sm text-gray-600">معدل النجاح</div>
            </div>

            <div class="stat-card bg-white rounded-xl p-4 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between">
                <div class="p-2 rounded-lg bg-teal-500 text-white">
                  <i class="fa-solid fa-chart-line"></i>
                </div>
                <div class="text-sm text-teal-600 font-semibold">
                  <i class="fa-solid fa-arrow-up"></i> 18%
                </div>
              </div>
              <div class="text-2xl font-bold mt-2"><?php echo number_format($totalProfits / max($totalUsers, 1), 2); ?></div>
              <div class="text-sm text-gray-600">متوسط ربح المسوق</div>
            </div>
          </section>

          <!-- Orders Status & Notifications -->
          <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Orders Status -->
            <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100 lg:col-span-2">
              <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                <i class="fa-solid fa-chart-bar text-blue-600"></i>
                حالة جميع طلبات المسوقين
              </h3>
              <div class="grid grid-cols-3 gap-6">
                <div class="text-center">
                  <div class="bg-green-100 rounded-full p-4 w-20 h-20 mx-auto mb-3 flex items-center justify-center text-green-600">
                    <i class="fa-solid fa-circle-check text-3xl"></i>
                  </div>
                  <p class="text-3xl font-bold text-green-600"><?php echo number_format($completedOrders); ?></p>
                  <p class="text-sm text-gray-600 mt-1">طلبات مكتملة</p>
                  <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                    <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $totalOrdersForRate > 0 ? ($completedOrders * 100 / $totalOrdersForRate) : 0; ?>%"></div>
                  </div>
                </div>
                <div class="text-center">
                  <div class="bg-blue-100 rounded-full p-4 w-20 h-20 mx-auto mb-3 flex items-center justify-center text-blue-600">
                    <i class="fa-solid fa-truck text-3xl"></i>
                  </div>
                  <p class="text-3xl font-bold text-blue-600"><?php echo number_format($shippingOrders); ?></p>
                  <p class="text-sm text-gray-600 mt-1">قيد التوصيل</p>
                  <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                    <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo ($completedOrders + $shippingOrders + $cancelledOrders) > 0 ? ($shippingOrders * 100 / ($completedOrders + $shippingOrders + $cancelledOrders)) : 0; ?>%"></div>
                  </div>
                </div>
                <div class="text-center">
                  <div class="bg-red-100 rounded-full p-4 w-20 h-20 mx-auto mb-3 flex items-center justify-center text-red-600">
                    <i class="fa-solid fa-circle-xmark text-3xl"></i>
                  </div>
                  <p class="text-3xl font-bold text-red-600"><?php echo number_format($cancelledOrders); ?></p>
                  <p class="text-sm text-gray-600 mt-1">ملغية</p>
                  <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                    <div class="bg-red-600 h-2 rounded-full" style="width: <?php echo $totalOrdersForRate > 0 ? ($cancelledOrders * 100 / $totalOrdersForRate) : 0; ?>%"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Notifications -->
            <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                  <i class="fa-solid fa-bell text-yellow-600"></i>
                  طلبات السحب
                </h3>
                <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full"><?php echo count($withdrawalRequests); ?></span>
              </div>
              <div class="space-y-3">
                <?php if (empty($withdrawalRequests)): ?>
                  <div class="text-center py-4 text-gray-500">
                    <i class="fa-solid fa-inbox text-2xl mb-2 block"></i>
                    <p class="text-sm">لا توجد طلبات سحب جديدة</p>
                  </div>
                <?php else: ?>
                  <?php foreach ($withdrawalRequests as $request): ?>
                    <div class="flex items-start gap-3 p-3 hover:bg-gray-50 rounded-lg border border-gray-100">
                      <div class="p-2 rounded-full bg-yellow-100 text-yellow-600">
                        <i class="fa-solid fa-money-bill-wave"></i>
                      </div>
                      <div class="flex-1">
                        <p class="text-sm text-gray-900 font-semibold"><?php echo htmlspecialchars($request['username']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo number_format($request['amount'], 2); ?> دج</p>
                        <p class="text-xs text-gray-500 mt-1"><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></p>
                      </div>
                      <button class="text-green-600 hover:text-green-800 text-xs font-semibold">
                        موافقة
                      </button>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <!-- Recent Orders -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-list-ul text-emerald-600"></i>
                طلبيات حديثة
              </h3>
              <a href="orders.php" class="text-blue-600 hover:text-blue-800 text-sm font-semibold">
                عرض الكل <i class="fa-solid fa-arrow-left mr-1"></i>
              </a>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-gray-50">
                  <tr class="text-right">
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">رقم الطلب</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">المسوق</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">المنتج</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">المبلغ</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">الحالة</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">التاريخ</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <?php if (empty($recentOrders)): ?>
                    <tr>
                      <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                        <i class="fa-solid fa-inbox text-3xl mb-2 block"></i>
                        لا توجد طلبات حديثة
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($recentOrders as $order): ?>
                      <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-semibold text-blue-600">#<?php echo $order['id']; ?></td>
                        <td class="px-4 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($order['username']); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($order['product_name'] ?? 'غير محدد'); ?></td>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-900"><?php echo number_format($order['sale_price'], 2); ?> دج</td>
                        <td class="px-4 py-3">
                          <?php
                          $statusColors = [
                              'pending' => 'bg-yellow-100 text-yellow-800',
                              'confirmed' => 'bg-blue-100 text-blue-800',
                              'shipping' => 'bg-purple-100 text-purple-800',
                              'delivered' => 'bg-green-100 text-green-800',
                              'cancelled' => 'bg-red-100 text-red-800',
                              'returned' => 'bg-orange-100 text-orange-800'
                          ];
                          $statusText = [
                              'pending' => 'في الانتظار',
                              'confirmed' => 'مؤكد',
                              'shipping' => 'قيد الشحن',
                              'delivered' => 'تم التسليم',
                              'cancelled' => 'ملغي',
                              'returned' => 'مرتجع'
                          ];
                          $statusClass = $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-800';
                          $statusLabel = $statusText[$order['status']] ?? $order['status'];
                          ?>
                          <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusClass; ?>">
                            <?php echo $statusLabel; ?>
                          </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>
  </div>

  <script>
    function openSidebar() {
      // إضافة وظيفة فتح الشريط الجانبي للهاتف المحمول
      const sidebar = document.querySelector('.sidebar');
      if (sidebar) {
        sidebar.classList.toggle('open');
      }
    }

    // تحديث الوقت الفعلي
    function updateTime() {
      const now = new Date();
      const timeString = now.toLocaleTimeString('ar-DZ');
      const dateString = now.toLocaleDateString('ar-DZ');
      
      // يمكن إضافة عنصر لعرض الوقت إذا أردت
      console.log(`${dateString} - ${timeString}`);
    }

    setInterval(updateTime, 1000);

    // تأثيرات hover للبطاقات
    document.querySelectorAll('.stat-card').forEach(card => {
      card.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-4px)';
      });
      
      card.addEventListener('mouseleave', function() {
        this.style.transform = 'translateY(0)';
      });
    });
  </script>
</body>
</html>