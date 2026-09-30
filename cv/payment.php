<?php
session_start();
require_once 'connect.php';
mysqli_set_charset($connect, 'utf8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fetch_one($conn, $sql, $types="", $params=[]){
  $stmt = mysqli_prepare($conn, $sql);
  if(!$stmt){ die("Prepare failed: ".mysqli_error($conn)); }
  if($types !== "") mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  return $res ? mysqli_fetch_assoc($res) : null;
}
function fetch_all($conn, $sql){
  $res = mysqli_query($conn, $sql);
  $data = [];
  if($res){ while($row=mysqli_fetch_assoc($res)) $data[]=$row; }
  return $data;
}
function table_columns($conn, $table){
  $cols = [];
  $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
  if($res){
    while($r = mysqli_fetch_assoc($res)){
      $cols[] = $r['Field'];
    }
  }
  return $cols;
}
function has_col($cols, $name){ return in_array($name, $cols, true); }

/* auth */
if(!isset($_SESSION['account_id'])){
  header("Location: login.php?redirect=" . urlencode("payment.php"));
  exit;
}
$account_id = (int)$_SESSION['account_id'];

/* booking id */
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if($booking_id<=0){ die("ไม่พบ booking_id"); }

/* โหลด booking + รถ + ร้าน */
$bk = fetch_one($connect, "
  SELECT
    b.booking_id, b.vehicle_id, b.account_id, b.pickup_location_id, b.dropoff_location_id,
    b.period_type_id, b.start_datetime, b.end_datetime, b.booking_status, b.total_price, b.created_at, b.service_type_id,

    v.vahicle_id, v.brard AS brand, v.model, v.color, v.seat_count, v.price_per_day, v.price_hour, v.image, v.status AS vehicle_status,
    s.shop_name, s.address, s.province_id,
    p.province_name

  FROM booking b
  LEFT JOIN vahicle v ON v.vahicle_id = b.vehicle_id
  LEFT JOIN rentalshop s ON s.shop_id = v.shop_id
  LEFT JOIN province p ON p.province_id = s.province_id
  WHERE b.booking_id = ?
  LIMIT 1
", "i", [$booking_id]);

if(!$bk){ die("ไม่พบรายการจองนี้"); }
if((int)$bk['account_id'] !== $account_id){ die("คุณไม่มีสิทธิ์ดูรายการนี้"); }

$banks = fetch_all($connect, "SELECT bank_id, bank_name FROM bank ORDER BY bank_id");

/* ตรวจคอลัมน์จริงของตาราง payment / booking */
$payment_cols = table_columns($connect, "payment");
$booking_cols = table_columns($connect, "booking");
$card_cols    = table_columns($connect, "cardpayment");

$errors = [];
$success = "";

/* กันจ่ายซ้ำ: ถ้ามี payment ของ booking นี้แล้ว */
$existingPay = fetch_one($connect, "SELECT payment_id, payment_status FROM payment WHERE booking_id=? LIMIT 1", "i", [$booking_id]);

if($_SERVER['REQUEST_METHOD']==='POST'){
  if($existingPay){
    $errors[] = "รายการนี้มีการสร้าง Payment แล้ว (payment_id: ".$existingPay['payment_id'].")";
  } else {

    $method = trim($_POST['payment_method'] ?? ''); // bank | card
    if($method !== 'bank' && $method !== 'card') $errors[]="กรุณาเลือกวิธีชำระเงิน";

    $amount = (float)($bk['total_price'] ?? 0);
    if($amount <= 0) $errors[]="ยอดชำระไม่ถูกต้อง";

    // เก็บค่าเพิ่มตามวิธีจ่าย
    $bank_id = (int)($_POST['bank_id'] ?? 0);

    $card_holder = trim($_POST['card_holder_name'] ?? '');
    $card_number = preg_replace('/\D+/', '', ($_POST['card_number'] ?? ''));
    $expiry_month = (int)($_POST['expiry_month'] ?? 0);
    $expiry_year  = (int)($_POST['expiry_year'] ?? 0);
    $cvv = preg_replace('/\D+/', '', ($_POST['cvv'] ?? ''));

    if($method==='bank'){
      if($bank_id<=0) $errors[]="กรุณาเลือกธนาคาร";
    }

    if($method==='card'){
      if($card_holder==='') $errors[]="กรุณากรอกชื่อบนบัตร";
      if(strlen($card_number) < 12) $errors[]="เลขบัตรไม่ถูกต้อง";
      if($expiry_month<1 || $expiry_month>12) $errors[]="เดือนหมดอายุไม่ถูกต้อง";
      if($expiry_year<2000) $errors[]="ปีหมดอายุไม่ถูกต้อง";
      if(strlen($cvv) < 3) $errors[]="CVV ไม่ถูกต้อง";
    }

    if(empty($errors)){
      // map เป็นค่าที่ลง payment
      $payment_method_db = ($method==='bank') ? 'bank_transfer' : 'card';
      $payment_status_db = 'paid'; // เดโม: ถือว่าจ่ายสำเร็จทันที

      // ถ้ามี bank_id จริงใน table payment -> ใส่
      $pay_fields = [];
      $pay_types  = "";
      $pay_params = [];

      // required fields
      $pay_fields[] = "booking_id";      $pay_types.="i";  $pay_params[]=$booking_id;
      $pay_fields[] = "amount";          $pay_types.="d";  $pay_params[]=$amount;

      if(has_col($payment_cols, "payment_method")){
        $pay_fields[]="payment_method";  $pay_types.="s";  $pay_params[]=$payment_method_db;
      }
      if(has_col($payment_cols, "payment_status")){
        $pay_fields[]="payment_status";  $pay_types.="s";  $pay_params[]=$payment_status_db;
      }
      if(has_col($payment_cols, "created_at")){
        // ถ้า created_at เป็น auto ก็ไม่ต้องใส่ แต่ใส่ NOW() แบบ field ไม่ได้ง่าย
        // จะใช้ใน SQL โดยตรงด้านล่างถ้ามีคอลัมน์นี้
      }

      // optional: payment_type
      if(has_col($payment_cols, "payment_type")){
        $pay_fields[]="payment_type"; $pay_types.="s"; $pay_params[] = ($method==='bank' ? 'transfer' : 'card');
      }

      // optional: bank_id
      if($method==='bank' && has_col($payment_cols, "bank_id")){
        $pay_fields[]="bank_id"; $pay_types.="i"; $pay_params[]=$bank_id;
      }

      // build insert
      $fieldSql = implode(",", array_map(fn($f)=>"`$f`", $pay_fields));
      $qMarks   = implode(",", array_fill(0, count($pay_fields), "?"));

      // created_at ถ้ามี: ใส่ NOW()
      if(has_col($payment_cols, "created_at")){
        $fieldSql .= ",`created_at`";
        $qMarks   .= ",NOW()";
      }

      $sql = "INSERT INTO payment ($fieldSql) VALUES ($qMarks)";
      $stmt = mysqli_prepare($connect, $sql);
      if(!$stmt){
        $errors[]="Prepare Payment ไม่ผ่าน: ".mysqli_error($connect);
      }else{
        mysqli_stmt_bind_param($stmt, $pay_types, ...$pay_params);
        if(mysqli_stmt_execute($stmt)){
          $payment_id = mysqli_insert_id($connect);

          // ถ้าจ่ายด้วยบัตร -> insert cardpayment (last4, expiry_month, expiry_year)
          if($method==='card'){
            $last4 = substr($card_number, -4);

            // บาง DB ชื่อ column อาจต่างกัน แต่จากรูปคือ:
            // card_payment_id, payment_id(FK), card_holder_name, last4, expiry_month, expiry_year
            $card_fields = [];
            $card_types  = "";
            $card_params = [];

            if(has_col($card_cols, "payment_id")){
              $card_fields[]="payment_id"; $card_types.="i"; $card_params[]=$payment_id;
            }elseif(has_col($card_cols, "payment_id (FK)")){
              // เผื่อชื่อแปลก (แทบไม่เจอ)
            }

            if(has_col($card_cols, "card_holder_name")){
              $card_fields[]="card_holder_name"; $card_types.="s"; $card_params[]=$card_holder;
            }
            if(has_col($card_cols, "last4")){
              $card_fields[]="last4"; $card_types.="s"; $card_params[]=$last4;
            }
            if(has_col($card_cols, "expiry_month")){
              $card_fields[]="expiry_month"; $card_types.="i"; $card_params[]=$expiry_month;
            }
            if(has_col($card_cols, "expiry_year")){
              $card_fields[]="expiry_year"; $card_types.="i"; $card_params[]=$expiry_year;
            }

            if(count($card_fields)>0){
              $f2 = implode(",", array_map(fn($f)=>"`$f`", $card_fields));
              $m2 = implode(",", array_fill(0, count($card_fields), "?"));
              $stmt2 = mysqli_prepare($connect, "INSERT INTO cardpayment ($f2) VALUES ($m2)");
              if($stmt2){
                mysqli_stmt_bind_param($stmt2, $card_types, ...$card_params);
                mysqli_stmt_execute($stmt2);
              }
            }
          }

          // อัปเดตสถานะ booking ถ้ามี column booking_status
          if(has_col($booking_cols, "booking_status")){
            $newStatus = 'confirmed';
            $st = mysqli_prepare($connect, "UPDATE booking SET booking_status=? WHERE booking_id=?");
            if($st){
              mysqli_stmt_bind_param($st, "si", $newStatus, $booking_id);
              mysqli_stmt_execute($st);
            }
          }

          $success = "ชำระเงินสำเร็จ! (payment_id: $payment_id)";
          // reload payment
          $existingPay = fetch_one($connect, "SELECT payment_id, payment_status FROM payment WHERE booking_id=? LIMIT 1", "i", [$booking_id]);

        }else{
          $errors[]="Insert Payment ไม่สำเร็จ: ".mysqli_error($connect);
        }
      }
    }
  }
}

/* UI */
$title = trim(($bk['brand'] ?? '').' '.($bk['model'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ชำระเงิน</title>
  <link rel="stylesheet" href="./styles.css?v=1">
  <style>
    .page{padding:24px 0;}
    .container{max-width:1100px;margin:0 auto;padding:0 16px;}
    .grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px;}
    .card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);}
    .meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
    .badge{display:inline-block;padding:6px 10px;border-radius:999px;background:#f3f4f6;font-size:13px}
    .kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}
    .kv div{background:#f9fafb;border-radius:12px;padding:10px;font-size:14px}
    .kv span{color:#6b7280;font-size:12px}
    .alert{padding:12px;border-radius:12px;margin:10px 0}
    .alert.success{background:#ecfdf5;color:#065f46}
    .alert.error{background:#fef2f2;color:#991b1b}
    .form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
    input,select{padding:10px;border:1px solid #e5e7eb;border-radius:10px}
    .btn{padding:12px 14px;border:none;border-radius:12px;cursor:pointer;font-weight:700}
    .btn.primary{background:#111827;color:#fff}
    .btn.full{width:100%}
    .radio-row{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
    .radio-card{flex:1;min-width:180px;border:1px solid #e5e7eb;border-radius:12px;padding:12px;cursor:pointer}
    .radio-card.active{border-color:#111827}
    .hide{display:none}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="page">
  <div class="container">
    <div class="grid">

      <section class="card">
        <h2>สรุปรายการจอง</h2>

        <div class="meta">
          <span class="badge">Booking #<?=h($bk['booking_id'])?></span>
          <span class="badge"><?=h($bk['province_name'] ?? '-')?></span>
          <span class="badge"><?=h($bk['shop_name'] ?? '-')?></span>
          <span class="badge">สถานะ: <?=h($bk['booking_status'] ?? '-')?></span>
        </div>

        <div class="kv">
          <div><span>รถ</span><br><?=h($title)?></div>
          <div><span>ช่วงเวลา</span><br><?=h($bk['start_datetime'] ?? '-')?> → <?=h($bk['end_datetime'] ?? '-')?></div>
          <div><span>ยอดชำระ</span><br><b><?=h(number_format((float)$bk['total_price'],2))?> บาท</b></div>
          <div><span>ที่อยู่สาขา</span><br><?=h($bk['address'] ?? '-')?></div>
        </div>

        <?php if($existingPay): ?>
          <div class="alert success" style="margin-top:12px;">
            มี Payment แล้ว: payment_id <?=h($existingPay['payment_id'])?> (<?=h($existingPay['payment_status'] ?? '-')?>)
          </div>
        <?php endif; ?>
      </section>

      <section class="card">
        <h2>ชำระเงิน</h2>

        <?php if($success): ?>
          <div class="alert success"><?=h($success)?></div>
        <?php endif; ?>

        <?php if($errors): ?>
          <div class="alert error">
            <ul style="margin:0;padding-left:18px;">
              <?php foreach($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if(!$existingPay): ?>
        <form method="POST" id="payForm">

          <div class="radio-row">
            <div class="radio-card active" data-method="bank">
              <label style="cursor:pointer">
                <input type="radio" name="payment_method" value="bank" checked>
                โอนธนาคาร
              </label>
            </div>
            <div class="radio-card" data-method="card">
              <label style="cursor:pointer">
                <input type="radio" name="payment_method" value="card">
                บัตรเครดิต/เดบิต
              </label>
            </div>
          </div>

          <div id="bankBox">
            <div class="form-group">
              <label>เลือกธนาคาร</label>
              <select name="bank_id" required>
                <option value="">-- เลือกธนาคาร --</option>
                <?php foreach($banks as $b): ?>
                  <option value="<?=h($b['bank_id'])?>"><?=h($b['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="color:#6b7280;font-size:13px">
              *เดโม: ถือว่าโอนสำเร็จทันที (ถ้าจะทำอัปโหลดสลิป เดี๋ยวผมเพิ่มให้ได้)
            </div>
          </div>

          <div id="cardBox" class="hide">
            <div class="form-group">
              <label>ชื่อบนบัตร</label>
              <input type="text" name="card_holder_name" placeholder="Card Holder Name">
            </div>
            <div class="form-group">
              <label>เลขบัตร</label>
              <input type="text" name="card_number" inputmode="numeric" placeholder="xxxx xxxx xxxx xxxx">
            </div>
            <div class="form-group">
              <label>หมดอายุ (เดือน/ปี)</label>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <input type="number" name="expiry_month" min="1" max="12" placeholder="MM">
                <input type="number" name="expiry_year" min="2024" max="2099" placeholder="YYYY">
              </div>
            </div>
            <div class="form-group">
              <label>CVV</label>
              <input type="password" name="cvv" inputmode="numeric" placeholder="***">
            </div>
            <div style="color:#6b7280;font-size:13px">
              *เดโม: ระบบจะเก็บเฉพาะ last4 และวันหมดอายุ (ไม่เก็บเลขเต็ม/ไม่เก็บ cvv)
            </div>
          </div>

          <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb">

          <button class="btn primary full" type="submit">
            ชำระ <?=h(number_format((float)$bk['total_price'],2))?> บาท
          </button>

        </form>
        <?php else: ?>
          <div style="color:#6b7280">รายการนี้ชำระแล้ว หรือมี Payment อยู่แล้ว</div>
        <?php endif; ?>

      </section>

    </div>
  </div>
</main>

<script>
(function(){
  const cards = document.querySelectorAll('.radio-card');
  const bankBox = document.getElementById('bankBox');
  const cardBox = document.getElementById('cardBox');

  function setActive(method){
    cards.forEach(c=>{
      c.classList.toggle('active', c.dataset.method===method);
      const radio = c.querySelector('input[type="radio"]');
      if(radio) radio.checked = (c.dataset.method===method);
    });

    if(method==='bank'){
      bankBox.classList.remove('hide');
      cardBox.classList.add('hide');
      bankBox.querySelector('select')?.setAttribute('required','required');
    }else{
      bankBox.classList.add('hide');
      cardBox.classList.remove('hide');
      bankBox.querySelector('select')?.removeAttribute('required');
    }
  }

  cards.forEach(c=>{
    c.addEventListener('click', ()=> setActive(c.dataset.method));
  });

  // init
  setActive('bank');
})();
</script>

</body>
</html>
 