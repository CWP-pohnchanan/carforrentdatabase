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
function norm_img($p){
  $p = trim((string)$p);
  if($p==='') return 'vahicle/car2.jpg';
  return ltrim(str_replace("\\","/",$p),"/");
}

/* id */
$carId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($carId <= 0){
  die("ไม่พบรถที่ต้องการจอง");
}

// ===============================
// AJAX API: คืนช่วงเวลาที่ถูกบล็อกของรถคันนี้
// URL: booking.php?id=XX&ajax=blocked
// ===============================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'blocked') {
  header('Content-Type: application/json; charset=utf-8');

  $vehicle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($vehicle_id <= 0) { echo json_encode([]); exit; }

  $rows = [];

  // 1) vehicleavailabilityblock
  $stmt = mysqli_prepare($connect, "
    SELECT start_datetime, end_datetime
    FROM vehicleavailabilityblock
    WHERE vehicle_id = ?
    ORDER BY start_datetime ASC
  ");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $vehicle_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
      $rows[] = ["start" => $r["start_datetime"], "end" => $r["end_datetime"]];
    }
  }

  // 2) เผื่อ booking เก่าๆ ที่ยังไม่เคยเขียน block (กันหลุด)
  $stmt2 = mysqli_prepare($connect, "
    SELECT start_datetime, end_datetime
    FROM booking
    WHERE vehicle_id = ?
      AND booking_status IN ('pending','confirmed')
    ORDER BY start_datetime ASC
  ");
  if ($stmt2) {
    mysqli_stmt_bind_param($stmt2, "i", $vehicle_id);
    mysqli_stmt_execute($stmt2);
    $res2 = mysqli_stmt_get_result($stmt2);
    while ($res2 && ($r = mysqli_fetch_assoc($res2))) {
      $rows[] = ["start" => $r["start_datetime"], "end" => $r["end_datetime"]];
    }
  }

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ต้องล็อกอินก่อน */
if(!isset($_SESSION['account_id'])){
  header("Location: login.php?redirect=" . urlencode("booking.php?id=".$carId));
  exit;
}
$account_id = (int)$_SESSION['account_id'];

/* now */
$nowLocal = (new DateTime())->format('Y-m-d H:i');

/* load vehicle from vahicle */
$vehicle = fetch_one($connect, "
  SELECT
    v.vahicle_id,
    v.brard AS brand,
    v.model,
    v.color,
    v.seat_count,
    v.price_per_day,
    v.price_hour,
    v.created_at AS vehicle_created_at,
    v.status,
    v.image
  FROM vahicle v
  WHERE v.vahicle_id = ?
  LIMIT 1
","i",[$carId]);

if(!$vehicle){
  die("ไม่พบรถคันนี้ในระบบ");
}

/* options */
$pickup_locations = fetch_all($connect, "SELECT pickup_location_id, location_name FROM pickupLocation ORDER BY pickup_location_id ASC");
$dropoff_locations = fetch_all($connect, "SELECT dropoff_location_id, location_name FROM dropoffLocation ORDER BY dropoff_location_id ASC");
$rental_period_types = fetch_all($connect, "SELECT period_type_id, period_name FROM rentalperiodtype ORDER BY period_type_id ASC");
$service_types = fetch_all($connect, "SELECT service_type_id, service_name, price FROM servicetype ORDER BY service_type_id ASC");

/* POST */
$errors = [];
$calc_summary = null;

if($_SERVER['REQUEST_METHOD']==='POST'){
  $pickup_location_id = (int)($_POST['pickup_location_id'] ?? 0);
  $dropoff_location_id = (int)($_POST['dropoff_location_id'] ?? 0);
  $period_type_id = (int)($_POST['period_type_id'] ?? 0);
  $service_type_id = (int)($_POST['service_type_id'] ?? 0);
  $start_datetime = trim($_POST['start_datetime'] ?? '');
  $end_datetime   = trim($_POST['end_datetime'] ?? '');

  if($pickup_location_id<=0) $errors[]="กรุณาเลือกสถานที่รับรถ";
  if($dropoff_location_id<=0) $errors[]="กรุณาเลือกสถานที่คืนรถ";
  if($period_type_id<=0) $errors[]="กรุณาเลือกรูปแบบการเช่า";
  if($service_type_id<=0) $errors[]="กรุณาเลือกบริการ";
  if($start_datetime==='') $errors[]="กรุณาเลือกวัน/เวลาเริ่มเช่า";
  if($end_datetime==='')   $errors[]="กรุณาเลือกวัน/เวลาคืนรถ";

  /* is hour/day */
  $isHour = false;
  if($period_type_id>0){
    $pt = fetch_one($connect,"SELECT period_name FROM rentalperiodtype WHERE period_type_id=?","i",[$period_type_id]);
    $period_name = strtolower($pt['period_name'] ?? '');
    $isHour = (strpos($period_name,'ชั่วโมง')!==false) || (stripos($period_name,'hour')!==false);
  }

  if(empty($errors)){
    try{
      $start = new DateTime($start_datetime);
      $end   = new DateTime($end_datetime);

      $now = new DateTime();
      if($start < $now) $errors[]="เวลาเริ่มเช่าต้องเป็นเวลาปัจจุบันหรืออนาคต";

      $minEnd = clone $start;
      if($isHour) $minEnd->modify('+1 hour'); else $minEnd->modify('+1 day');

      if($end < $minEnd){
        $errors[] = $isHour
          ? "แบบชั่วโมง: เวลาคืนต้องมากกว่าเวลาเริ่มอย่างน้อย 1 ชั่วโมง"
          : "แบบรายวัน: เวลาคืนต้องมากกว่าเวลาเริ่มอย่างน้อย 1 วัน";
      }
    }catch(Exception $e){
      $errors[]="รูปแบบวันเวลาไม่ถูกต้อง";
    }
  }

  if(empty($errors)){
    $sd = $start->format('Y-m-d H:i:s');
    $ed = $end->format('Y-m-d H:i:s');

    // กันจองซ้อนจาก booking
    $overlap = fetch_one($connect,"
      SELECT booking_id
      FROM booking
      WHERE vehicle_id=?
        AND booking_status IN ('pending','confirmed')
        AND NOT (end_datetime <= ? OR start_datetime >= ?)
      LIMIT 1
    ","iss",[$carId,$sd,$ed]);
    if($overlap) $errors[]="ช่วงเวลานี้รถถูกจองแล้ว (booking_id: ".$overlap['booking_id'].")";

    // กันจองซ้อนจาก vehicleavailabilityblock
    $block = fetch_one($connect,"
      SELECT block_id
      FROM vehicleavailabilityblock
      WHERE vehicle_id=?
        AND NOT (end_datetime <= ? OR start_datetime >= ?)
      LIMIT 1
    ","iss",[$carId,$sd,$ed]);
    if($block) $errors[]="ช่วงเวลานี้รถไม่ว่าง (block_id: ".$block['block_id'].")";
  }

  if(empty($errors)){
    $service = fetch_one($connect,"SELECT price FROM servicetype WHERE service_type_id=?","i",[$service_type_id]);
    $service_price = (float)($service['price'] ?? 0);

    $price_per_day  = (float)($vehicle['price_per_day'] ?? 0);
    $price_per_hour = (float)($vehicle['price_hour'] ?? 0);
    if($price_per_hour <= 0 && $price_per_day > 0) $price_per_hour = $price_per_day / 24.0;

    $diffSeconds = $end->getTimestamp() - $start->getTimestamp();
    $hours = (int)ceil($diffSeconds/3600); if($hours<1) $hours=1;
    $days  = (int)ceil($diffSeconds/86400); if($days<1) $days=1;

    if($isHour){
      $base = $hours * $price_per_hour;
      $total_price = round($base + $service_price, 2);
      $calc_summary = ['unit'=>'ชั่วโมง','qty'=>$hours,'base'=>$base,'service'=>$service_price,'total'=>$total_price];
    }else{
      $base = $days * $price_per_day;
      $total_price = round($base + $service_price, 2);
      $calc_summary = ['unit'=>'วัน','qty'=>$days,'base'=>$base,'service'=>$service_price,'total'=>$total_price];
    }

    // ✅ Transaction: booking + block ต้องสำเร็จพร้อมกัน
    mysqli_begin_transaction($connect);

    $stmt = mysqli_prepare($connect,"
      INSERT INTO booking
      (vehicle_id, account_id, pickup_location_id, dropoff_location_id,
       period_type_id, start_datetime, end_datetime, booking_status,
       total_price, created_at, service_type_id)
      VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)
    ");
    if(!$stmt){
      mysqli_rollback($connect);
      $errors[]="Prepare insert ไม่ผ่าน: ".mysqli_error($connect);
    }else{
      $status='pending';
      mysqli_stmt_bind_param($stmt,"iiiiisssdi",
        $carId,$account_id,$pickup_location_id,$dropoff_location_id,
        $period_type_id,$sd,$ed,$status,$total_price,$service_type_id
      );

      if(!mysqli_stmt_execute($stmt)){
        mysqli_rollback($connect);
        $errors[]="Insert booking ไม่สำเร็จ: ".mysqli_error($connect);
      }else{
        $created_booking_id = mysqli_insert_id($connect);

        // ✅ insert block ลง vehicleavailabilityblock ทันที
        $stmtB = mysqli_prepare($connect, "
          INSERT INTO vehicleavailabilityblock (vehicle_id, start_datetime, end_datetime, created_at)
          VALUES (?, ?, ?, NOW())
        ");
        if(!$stmtB){
          mysqli_rollback($connect);
          $errors[]="Prepare block ไม่ผ่าน: ".mysqli_error($connect);
        }else{
          mysqli_stmt_bind_param($stmtB, "iss", $carId, $sd, $ed);

          if(!mysqli_stmt_execute($stmtB)){
            mysqli_rollback($connect);
            $errors[]="Insert block ไม่สำเร็จ: ".mysqli_error($connect);
          }else{
            mysqli_commit($connect);

            // ✅ ไปหน้าจ่ายเงินทันที
            $_SESSION['flash_booking_id'] = $created_booking_id;
            header("Location: payment.php?booking_id=".$created_booking_id);
            exit;
          }
        }
      }
    }
  }
}

$img = norm_img($vehicle['image'] ?? '');
$title = trim(($vehicle['brand'] ?? '').' '.($vehicle['model'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>จองรถ</title>
  <link rel="stylesheet" href="./styles.css?v=2">

  <!-- ✅ Flatpickr -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>
<body>
<?php include 'header.php'; ?>

<main class="page">
  <div class="container">
    <div class="card booking-layout">
      <section class="booking-vehicle card">
        <div class="booking-vehicle__img">
          <img src="<?=h($img)?>" alt="<?=h($title)?>" onerror="this.onerror=null;this.src='vahicle/car2.jpg';">
        </div>
        <div class="booking-vehicle__info">
          <h2 class="booking-vehicle__title"><?=h($title)?></h2>
          <div class="booking-vehicle__meta">
            <span class="badge">สี: <?=h($vehicle['color'] ?? '-')?></span>
            <span class="badge">ที่นั่ง: <?=h($vehicle['seat_count'] ?? '-')?></span>
            <span class="badge">ราคา/วัน: <?=h($vehicle['price_per_day'] ?? '-')?></span>
          </div>
        </div>
      </section>

      <section class="booking-form card">
        <h2>ฟอร์มจองรถ</h2>

        <?php if(!empty($errors)): ?>
          <div class="alert error">
            <ul>
              <?php foreach($errors as $e): ?>
                <li><?=h($e)?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if($calc_summary): ?>
          <div class="alert info">
            คำนวณราคา: <?=h($calc_summary['qty'])?> <?=h($calc_summary['unit'])?> |
            ค่าเช่า: <?=h(number_format($calc_summary['base'],2))?> |
            บริการ: <?=h(number_format($calc_summary['service'],2))?> |
            รวม: <b><?=h(number_format($calc_summary['total'],2))?></b>
          </div>
        <?php endif; ?>

        <form method="post" class="form">
          <!-- ใช้ .form-row ที่มีอยู่ใน styles.css -->
          <div class="form-row">
            <div class="form-group">
              <label>จุดรับรถ</label>
              <select name="pickup_location_id" required>
                <option value="">-- เลือก --</option>
                <?php foreach($pickup_locations as $p): ?>
                  <option value="<?=h($p['pickup_location_id'])?>" <?=((int)($_POST['pickup_location_id']??0)==(int)$p['pickup_location_id'])?'selected':''?>>
                    <?=h($p['location_name'])?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>จุดคืนรถ</label>
              <select name="dropoff_location_id" required>
                <option value="">-- เลือก --</option>
                <?php foreach($dropoff_locations as $d): ?>
                  <option value="<?=h($d['dropoff_location_id'])?>" <?=((int)($_POST['dropoff_location_id']??0)==(int)$d['dropoff_location_id'])?'selected':''?>>
                    <?=h($d['location_name'])?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>รูปแบบการเช่า</label>
              <select name="period_type_id" id="period_type_id" required>
                <option value="">-- เลือก --</option>
                <?php foreach($rental_period_types as $pt): ?>
                  <option value="<?=h($pt['period_type_id'])?>" <?=((int)($_POST['period_type_id']??0)==(int)$pt['period_type_id'])?'selected':''?>>
                    <?=h($pt['period_name'])?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>บริการ</label>
              <select name="service_type_id" required>
                <option value="">-- เลือก --</option>
                <?php foreach($service_types as $st): ?>
                  <option value="<?=h($st['service_type_id'])?>" <?=((int)($_POST['service_type_id']??0)==(int)$st['service_type_id'])?'selected':''?>>
                    <?=h($st['service_name'])?> (+<?=h($st['price'])?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
              <label>ช่วงเวลาเช่า</label>

              <!-- ช่องเลือกช่วงเวลา (ปฏิทิน + เวลา) -->
              <input type="text" id="range_picker" placeholder="เลือกวัน/เวลาเริ่ม - วัน/เวลาคืน" autocomplete="off" required>

              <!-- ค่าจริงที่ส่งเข้า PHP -->
              <input type="hidden" id="start_datetime" name="start_datetime" value="<?=h($_POST['start_datetime'] ?? $nowLocal)?>">
              <input type="hidden" id="end_datetime"   name="end_datetime"   value="<?=h($_POST['end_datetime'] ?? '')?>">

              <small class="help-muted">* วันที่ที่มีการจองอยู่แล้วจะแสดงสีแดง และช่วงเวลาที่ชนจะเลือกไม่ได้</small>
            </div>
          </div>

          <button class="btn primary btn-full" type="submit">ยืนยันการจอง</button>
        </form>

      </section>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
(async function(){
  const carId = <?= (int)$carId ?>;
  const periodEl = document.getElementById('period_type_id');
  const rangeEl  = document.getElementById('range_picker');
  const startEl  = document.getElementById('start_datetime');
  const endEl    = document.getElementById('end_datetime');

  function getUnit(){
    const txt = (periodEl?.options[periodEl.selectedIndex]?.text || '').toLowerCase();
    if(txt.includes('ชั่วโมง') || txt.includes('hour')) return 'hour';
    return 'day';
  }

  // โหลดช่วงเวลาที่ถูกจอง/บล็อก
  let blocked = [];
  try{
    const res = await fetch(`booking.php?id=${carId}&ajax=blocked`);
    blocked = await res.json();
  }catch(e){
    blocked = [];
  }

  // ทำ disable ช่วงเวลาที่ชน (เลือกไม่ได้)
  const disabledRanges = blocked
    .filter(r => r && r.start && r.end)
    .map(r => ({ from: r.start.replace(' ', 'T'), to: r.end.replace(' ', 'T') }));

  // ทำชุดวัน (YYYY-MM-DD) เพื่อทำสีแดงบนปฏิทิน
  const bookedDaySet = new Set();
  for(const r of blocked){
    if(!r?.start || !r?.end) continue;
    const s = new Date(r.start.replace(' ', 'T'));
    const e = new Date(r.end.replace(' ', 'T'));
    if(isNaN(s) || isNaN(e)) continue;

    const d = new Date(s.getFullYear(), s.getMonth(), s.getDate());
    const last = new Date(e.getFullYear(), e.getMonth(), e.getDate());
    while(d <= last){
      bookedDaySet.add(d.toISOString().slice(0,10));
      d.setDate(d.getDate()+1);
    }
  }

  function fmtYmdHi(date){
    const pad = (n)=>String(n).padStart(2,'0');
    return date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate())+' '+pad(date.getHours())+':'+pad(date.getMinutes());
  }

  const fp = flatpickr(rangeEl, {
    enableTime: true,
    time_24hr: true,
    minuteIncrement: 15,
    dateFormat: "Y-m-d H:i",
    mode: "range",
    minDate: "today",
    disable: disabledRanges, // ✅ ช่วงที่ชน = เลือกไม่ได้

    // ✅ ทำวันมีจองเป็นสีแดง
    onDayCreate: function(dObj, dStr, fp, dayElem){
      const key = dayElem.dateObj.toISOString().slice(0,10);
      if(bookedDaySet.has(key)){
        dayElem.classList.add("booked-day");
      }
    },

    // ✅ แทนที่สคริปต์เดิม (syncEndMin)
    onChange: function(selectedDates){
      if(!selectedDates || selectedDates.length < 2) return;

      let start = selectedDates[0];
      let end   = selectedDates[1];

      // rule: แบบชั่วโมงขั้นต่ำ 1 ชม, แบบวันขั้นต่ำ 1 วัน
      const unit = getUnit();
      const minEnd = new Date(start.getTime());
      if(unit === 'hour') minEnd.setHours(minEnd.getHours()+1);
      else minEnd.setDate(minEnd.getDate()+1);

      if(end < minEnd){
        end = minEnd;
        fp.setDate([start, end], true);
      }

      startEl.value = fmtYmdHi(start);
      endEl.value   = fmtYmdHi(end);
    }
  });

  // ถ้าผู้ใช้ submit แล้ว error แล้วกลับมา ให้ set ค่าเดิม
  if(startEl.value && endEl.value){
    fp.setDate([startEl.value, endEl.value], true, "Y-m-d H:i");
  }

  // ถ้าเปลี่ยนรูปแบบเช่า ให้ re-check min rule
  periodEl?.addEventListener('change', () => {
    const dates = fp.selectedDates;
    if(dates && dates.length === 2){
      fp.setDate(dates, true);
    }
  });
})();
</script>

</body>
</html>

