<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
?>
<header>
  <div class="container">
    <a href="index.php" class="logo">เต้นคุณเจมส์</a>
    <nav>
      <ul>
        <li><a href="index.php">หน้าหลัก</a></li>
        <li><a href="index.php#cars">รถ</a></li>
        <li><a href="index.php#contact">ติดต่อ</a></li>

        <?php if(isset($_SESSION['full_name'])): ?>
          <li><a class="user-name" href="#"><?php echo htmlspecialchars($_SESSION['full_name']); ?></a></li>
          <li><a href="logout.php">ออกจากระบบ</a></li>
        <?php else: ?>
          <li><a href="login.php">เข้าสู่ระบบ</a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
</header>
