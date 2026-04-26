<?php
// navbar.php — 公共顶部导航栏
if (!isset($displayName)) {
    $displayName = '用户';
}
?>
<header class="navbar navbar-expand-md navbar-light d-print-none">
  <div class="container-xl">
    <a href="/" class="navbar-brand">
      <span class="navbar-brand-text">云剪切板</span>
    </a>

    <div class="navbar-nav flex-row order-md-last">
      <div class="nav-item dropdown">
        <a href="#" class="nav-link d-flex lh-1 text-reset" data-bs-toggle="dropdown" aria-label="Open user menu">
          <span class="avatar avatar-sm">
            <?php echo htmlspecialchars(mb_substr($displayName, 0, 1), ENT_QUOTES, 'UTF-8'); ?>
          </span>
          <div class="d-none d-xl-block ps-2">
            <div><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="mt-1 small text-muted">已登录</div>
          </div>
        </a>
        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
          <a href="/tokens_manage.php" class="dropdown-item">API Token 管理</a>
          <a href="/devices_manage.php" class="dropdown-item">登录设备管理</a>
          <a href="/profile.php" class="dropdown-item">账号信息 / 修改密码</a>
          <div class="dropdown-divider"></div>
          <a href="/logout.php" class="dropdown-item text-danger">退出登录</a>
        </div>
      </div>
    </div>

    <div class="collapse navbar-collapse" id="navbar-menu">
      <div class="navbar-nav">
        <a class="nav-link <?php echo ($_SERVER['PHP_SELF'] == '/index.php') ? 'active' : ''; ?>" href="/index.php">
          <span class="nav-link-title">首页</span>
        </a>
      </div>
    </div>
  </div>
</header>