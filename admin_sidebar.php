<?php
// Minimal Admin Sidebar to ensure mobile toggle works across admin pages
?>

<!-- Admin Sidebar -->
<aside id="sidebar" class="fixed inset-y-0 right-0 z-50 w-64 bg-white border-l border-gray-200 transform translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out lg:static lg:inset-0">
  <div class="flex items-center justify-between p-4 border-b border-gray-200">
    <div class="flex items-center gap-2 font-bold text-gray-800">
      <i class="fa-solid fa-shield-halved text-blue-600"></i>
      <span>لوحة الإدارة</span>
    </div>
  </div>
  <nav class="p-4 space-y-2">
    <a href="admin.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 text-gray-700">
      <i class="fa-solid fa-chart-line text-blue-600"></i>
      <span>الإحصائيات</span>
    </a>
    <a href="manage.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 text-gray-700">
      <i class="fa-solid fa-users-gear text-emerald-600"></i>
      <span>إدارة المسوقين</span>
    </a>
    <a href="editorders.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 text-gray-700">
      <i class="fa-solid fa-truck-fast text-amber-600"></i>
      <span>إدارة الطلبات</span>
    </a>
    <a href="addnew.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 text-gray-700">
      <i class="fa-solid fa-plus text-emerald-600"></i>
      <span>إضافة منتج</span>
    </a>
    <a href="withdraworders.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 text-gray-700">
      <i class="fa-solid fa-wallet text-purple-600"></i>
      <span>سحوبات المسوقين</span>
    </a>
  </nav>
</aside>

<!-- Sidebar Overlay for Mobile -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden" onclick="(window.toggleSidebar?toggleSidebar:openSidebar)()"></div>