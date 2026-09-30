<?php
session_start();
require_once 'connect.php';
mysqli_set_charset($connect, 'utf8');

if (!isset($_POST['email_account'], $_POST['password_account'])) {
  header('Location: login.php');
  exit;
}

$email = trim($_POST['email_account']);
$pass  = trim($_POST['password_account']);
$redirect = $_POST['redirect'] ?? 'index.php';

// กัน redirect แปลกๆ (ปลอดภัย)
if ($redirect === '' || strpos($redirect, 'http') === 0) {
  $redirect = 'index.php';
}

$email_esc = mysqli_real_escape_string($connect, $email);

// DB ของคุณใช้ table: customeraccount และคอลัมน์ password_account + salt
$sql = "SELECT account_id, full_name, email, password_account, salt
        FROM customeraccount
        WHERE email = '$email_esc'
        LIMIT 1";

$res = mysqli_query($connect, $sql);

if (!$res || mysqli_num_rows($res) !== 1) {
  echo "<script>alert('อีเมลหรือรหัสผ่านไม่ถูกต้อง');window.location.href='login.php';</script>";
  exit;
}

$row = mysqli_fetch_assoc($res);

// ของคุณ hash เป็น password_hash( password+salt )
$hash = $row['password_account'];
$salt = $row['salt'] ?? '';

$check = $pass . $salt;

if (password_verify($check, $hash)) {
  // ✅ สำคัญมาก
  $_SESSION['account_id'] = (int)$row['account_id'];
  $_SESSION['full_name']  = $row['full_name'];
  $_SESSION['email_account'] = $row['email'];

  echo "<script>
    alert('เข้าสู่ระบบสำเร็จ');
    window.location.href = '".addslashes($redirect)."';
  </script>";
  exit;
} else {
  echo "<script>alert('อีเมลหรือรหัสผ่านไม่ถูกต้อง');window.location.href='login.php';</script>";
  exit;
}

