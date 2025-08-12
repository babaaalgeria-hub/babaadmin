<?php
// sidebar.php - Component للشريط الجانبي

// تحديد الصفحة الحالية لتفعيل التنقل
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// قائمة عناصر التنقل مع بياناتها
$menuItems = [
    [
        'id' => 'dashboard',
        'title' => 'لوحة الإدارة',
        'icon' => 'fa-home',
        'url' => 'admin.php',
        'description' => 'إحصائيات النظام والملخص',
        'color' => 'indigo'
    ],
    [
        'id' => 'manage_marketers',
        'title' => 'إدارة المسوقين',
        'icon' => 'fa-users',
        'url' => 'manage.php',
        'description' => 'إدارة بيانات المسوقين',
        'color' => 'purple'
    ],
    [
        'id' => 'orders',
        'title' => 'إدارة الطلبيات',
        'icon' => 'fa-shopping-cart',
        'url' => 'editorders.php',
        'description' => 'عرض وتحديث حالات الطلبيات',
        'color' => 'blue'
    ],
    [
        'id' => 'withdrawals',
        'title' => 'سحوبات المسوقين',
        'icon' => 'fa-wallet',
        'url' => 'withdraworders.php',
        'description' => 'إدارة طلبات السحب',
        'color' => 'yellow'
    ],
    [
        'id' => 'add_marketer',
        'title' => 'إضافة مسوق',
        'icon' => 'fa-user-plus',
        'url' => 'addnew.php',
        'description' => 'إضافة مسوق جديد للنظام',
        'color' => 'emerald'
    ]
];

// ألوان مخصصة لكل عنصر
$colorClasses = [
    'indigo' => [
        'bg' => 'bg-indigo-100',
        'text' => 'text-indigo-600',
        'border' => 'border-indigo-200',
        'active_bg' => 'bg-indigo-600',
        'active_text' => 'text-white',
        'hover' => 'hover:bg-indigo-50'
    ],
    'emerald' => [
        'bg' => 'bg-emerald-100',
        'text' => 'text-emerald-600',
        'border' => 'border-emerald-200',
        'active_bg' => 'bg-emerald-600',
        'active_text' => 'text-white',
        'hover' => 'hover:bg-emerald-50'
    ],
    'blue' => [
        'bg' => 'bg-blue-100',
        'text' => 'text-blue-600',
        'border' => 'border-blue-200',
        'active_bg' => 'bg-blue-600',
        'active_text' => 'text-white',
        'hover' => 'hover:bg-blue-50'
    ],
    'purple' => [
        'bg' => 'bg-purple-100',
        'text' => 'text-purple-600',
        'border' => 'border-purple-200',
        'active_bg' => 'bg-purple-600',
        'active_text' => 'text-white',
        'hover' => 'hover:bg-purple-50'
    ],
    'yellow' => [
        'bg' => 'bg-yellow-100',
        'text' => 'text-yellow-600',
        'border' => 'border-yellow-200',
        'active_bg' => 'bg-yellow-600',
        'active_text' => 'text-white',
        'hover' => 'hover:bg-yellow-50'
    ],
    'gray' => [
        'bg' => 'bg-gray-100',
        'text' => 'text-gray-600',
        'border' => 'border-gray-200',
        'active_bg' => 'bg-gray-600',
        'active_text' => 'text-white',
        'hover' => 'hover:bg-gray-50'
    ]
];
?>

<?php
// Compute sidebar quick stats safely
$sidebarOrdersToday = 0;
$sidebarCustomersCount = 0;
$__availableBalanceFallback = 0.0;

try {
  // Ensure DB and user id
  if (!isset($userId)) {
    $userId = (int)($_SESSION['user_id'] ?? 0);
  }
  if (!isset($conn)) {
    require_once __DIR__ . '/db_config/db.php';
  }
  if ($userId > 0) {
    // Orders today
    $q = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND DATE(created_at) = CURDATE()");
    $q->execute([$userId]);
    $sidebarOrdersToday = (int)$q->fetchColumn();

    // Total customers (distinct)
    $q = $conn->prepare("\n      SELECT COUNT(DISTINCT CONCAT(customer_first_name, ' ', customer_last_name, customer_phone))\n      FROM orders WHERE user_id = ?\n    ");
    $q->execute([$userId]);
    $sidebarCustomersCount = (int)$q->fetchColumn();

    // Available balance fallback if not defined by the page
    if (!isset($availableBalance)) {
      $deliveredCommission = 0.0;
      $withdrawn = 0.0;
      $pending = 0.0;
      // Delivered commissions
      $q = $conn->prepare("\n        SELECT COALESCE(SUM(o.sale_price * COALESCE(p.commission_rate, 10) / 100), 0)\n        FROM orders o\n        LEFT JOIN products p ON o.product_id = p.id\n        WHERE o.user_id = ? AND o.status = 'delivered'\n      ");
      $q->execute([$userId]);
      $deliveredCommission = (float)$q->fetchColumn();
      // Withdrawn completed
      $q = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id = ? AND status = 'completed'");
      $q->execute([$userId]);
      $withdrawn = (float)$q->fetchColumn();
      // Pending withdrawal
      $q = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id = ? AND status = 'pending'");
      $q->execute([$userId]);
      $pending = (float)$q->fetchColumn();
      $__availableBalanceFallback = max(0.0, $deliveredCommission - $withdrawn - $pending);
      $availableBalance = $__availableBalanceFallback;
    }
  }
} catch (Throwable $e) {
  // ignore sidebar stats failures
}
?>

<!-- Sidebar -->
<aside id="sidebar" class="fixed inset-y-0 right-0 z-50 w-64 bg-white border-l border-gray-200 transform translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out lg:static lg:inset-0">
  <!-- Sidebar Header -->
  <div class="flex items-center justify-between p-4 border-b border-gray-200">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600">
        <i class="fa-solid fa-store text-lg"></i>
      </div>
      <div>
        <h2 class="font-bold text-gray-900">baba algeria</h2>
        <p class="text-xs text-gray-600">لوحة المسوق</p>
      </div>
    </div>
    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition-colors">
      <i class="fa-solid fa-times text-gray-500"></i>
    </button>
  </div>

  <!-- Navigation Menu -->
  <nav class="p-4 space-y-2">
    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">التنقل الرئيسي</h3>
    
    <?php foreach ($menuItems as $item): ?>
      <?php 
        $isActive = $currentPage === basename($item['url'], '.php');
        $colors = $colorClasses[$item['color']];
        
        if ($isActive) {
          $itemClasses = $colors['active_bg'] . ' ' . $colors['active_text'] . ' border-transparent shadow-md';
        } else {
          $itemClasses = 'text-gray-700 border-gray-200 ' . $colors['hover'];
        }
      ?>
      
      <a href="<?php echo $item['url']; ?>" 
         class="flex items-center gap-3 p-3 rounded-lg border transition-all duration-200 <?php echo $itemClasses; ?> group">
        <div class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-lg <?php echo $isActive ? 'bg-white/20' : $colors['bg']; ?>">
          <i class="fa-solid <?php echo $item['icon']; ?> <?php echo $isActive ? 'text-white' : $colors['text']; ?>"></i>
        </div>
        
        <div class="flex-1 min-w-0">
          <div class="font-medium <?php echo $isActive ? 'text-white' : 'text-gray-900'; ?> group-hover:text-gray-900">
            <?php echo $item['title']; ?>
          </div>
          <div class="text-xs <?php echo $isActive ? 'text-white/80' : 'text-gray-500'; ?> group-hover:text-gray-600">
            <?php echo $item['description']; ?>
          </div>
        </div>
        
        <?php if ($isActive): ?>
          <div class="flex-shrink-0">
            <i class="fa-solid fa-chevron-left text-white/80 text-sm"></i>
          </div>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- Quick Stats Section -->
  <div class="p-4 border-t border-gray-200 mt-auto">
    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">إحصائيات سريعة</h3>
    
    <div class="space-y-2">
      <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
        <div class="flex items-center gap-2">
          <i class="fa-solid fa-shopping-cart text-emerald-600 text-sm"></i>
          <span class="text-sm text-gray-600">طلبات اليوم</span>
        </div>
        <span class="text-sm font-semibold text-emerald-600"><?php echo (int)$sidebarOrdersToday; ?></span>
      </div>
      
      <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
        <div class="flex items-center gap-2">
          <i class="fa-solid fa-coins text-yellow-600 text-sm"></i>
          <span class="text-sm text-gray-600">الأرباح المتاحة</span>
        </div>
        <span class="text-sm font-semibold text-yellow-600"><?php echo number_format((float)($availableBalance ?? $__availableBalanceFallback ?? 0), 0); ?> دج</span>
      </div>
      
      <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
        <div class="flex items-center gap-2">
          <i class="fa-solid fa-users text-blue-600 text-sm"></i>
          <span class="text-sm text-gray-600">العملاء</span>
        </div>
        <span class="text-sm font-semibold text-blue-600"><?php echo (int)$sidebarCustomersCount; ?></span>
      </div>
    </div>
  </div>

  <!-- User Profile Section -->
  <div class="p-4 border-t border-gray-200">
    <div class="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
      <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700">
        <i class="fa-solid fa-user"></i>
      </div>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 truncate">
          <?php echo htmlspecialchars($displayName ?? 'مستخدم', ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="text-xs text-gray-600">مسوق نشط</div>
      </div>
      <div class="flex-shrink-0">
        <button class="p-1 text-gray-400 hover:text-gray-600 transition-colors" title="الإعدادات">
          <i class="fa-solid fa-cog text-sm"></i>
        </button>
      </div>
    </div>
  </div>
</aside>

<!-- Sidebar Overlay for Mobile -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden" onclick="closeSidebar()"></div>

<style>
/* Custom styles for sidebar */
.sidebar-transition {
  transition: transform 0.3s ease-in-out;
}

@media (max-width: 1023px) {
  #sidebar.open {
    transform: translateX(0);
  }
}

/* Active menu item animation */
.menu-item-active {
  animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
  from {
    transform: translateX(10px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

/* Hover effects */
.sidebar-item:hover {
  transform: translateX(-2px);
}

/* Responsive adjustments */
@media (max-width: 768px) {
  #sidebar {
    width: 280px;
  }
}
</style>

<script>
// Sidebar JavaScript functions
function openSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  
  sidebar.classList.add('open');
  sidebar.style.transform = 'translateX(0)';
  overlay.classList.remove('hidden');
  
  document.body.style.overflow = 'hidden';
}

function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  
  sidebar.classList.remove('open');
  sidebar.style.transform = 'translateX(100%)';
  overlay.classList.add('hidden');
  
  document.body.style.overflow = '';
}

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  
  if (sidebar.classList.contains('open')) {
    closeSidebar();
  } else {
    openSidebar();
  }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(e) {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!sidebar) return;
  const clickedInsideSidebar = sidebar.contains(e.target);
  const clickedOverlay = overlay && overlay.contains(e.target);
  if (window.innerWidth < 1024 && sidebar.classList.contains('open') && !clickedInsideSidebar && clickedOverlay) {
    closeSidebar();
  }
});

// Handle window resize
window.addEventListener('resize', function() {
  if (window.innerWidth >= 1024) {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('sidebarOverlay')?.classList.add('hidden');
    document.body.style.overflow = '';
  }
});

// Active menu item animation
document.addEventListener('DOMContentLoaded', function() {
  const activeItem = document.querySelector('nav a[class*="bg-"]');
  if (activeItem) {
    activeItem.classList.add('menu-item-active');
  }
});

// Add smooth scroll to sidebar navigation
document.querySelectorAll('#sidebar a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    e.preventDefault();
    const target = document.querySelector(this.getAttribute('href'));
    if (target) {
      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });
});
</script>