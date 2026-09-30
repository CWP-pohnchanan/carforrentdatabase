<?php include 'header.php'; ?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>สมัครสมาชิก</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>

  <main class="auth-page">
    <form class="auth-card" action="processlogin.php" method="POST">
      <h2>สมัครสมาชิก</h2>

      <input type="text" name="full_name" placeholder="ชื่อเต็ม" required>
      <input type="email" name="email" placeholder="อีเมล" required>
      <input type="password" name="password" placeholder="รหัสผ่าน" required>
      <input type="date" name="birthdate" required>
      <input type="tel" name="phone" placeholder="เบอร์โทรศัพท์" required>

      <button type="submit">ลงทะเบียน</button>
      <p class="switch-auth">มีบัญชีอยู่แล้ว? <a href="login.php">เข้าสู่ระบบ</a></p>
    </form>
  </main>

</body>
</html>
