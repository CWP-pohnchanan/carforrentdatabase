<?php
session_start();
require_once 'connect.php';
mysqli_set_charset($connect, 'utf8');

// ถ้าล็อกอินอยู่แล้ว และมี redirect -> ไปเลย
if (isset($_SESSION['account_id'])) {
  $go = $_GET['redirect'] ?? 'index.php';
  header("Location: " . $go);
  exit;
}

$redirect = $_GET['redirect'] ?? 'index.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>เข้าสู่ระบบ</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>

<?php include 'header.php'; ?>

<main class="auth-page">
  <form class="auth-card" action="processmain.php" method="POST">
    <h2>เข้าสู่ระบบ</h2>

    <!-- ✅ สำคัญ: ส่ง redirect ไป processmain -->
    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">

    <input type="email" name="email_account" placeholder="อีเมล" required>
    <input type="password" name="password_account" placeholder="รหัสผ่าน" required>

    <button type="submit">เข้าสู่ระบบ</button>

    <p class="switch-auth">
      ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a>
    </p>
  </form>
</main>

</body>
</html>
