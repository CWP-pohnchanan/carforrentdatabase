<?php
require_once 'connect.php';
mysqli_set_charset($connect, 'utf8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm_img($p){
  $p = trim((string)$p);
  if($p==='') return 'vahicle/car2.jpg';
  return ltrim(str_replace("\\","/",$p),"/");
}
function fetch_all($c,$sql){
  $r=mysqli_query($c,$sql);
  if(!$r) return [];
  $d=[];
  while($row=mysqli_fetch_assoc($r)) $d[]=$row;
  return $d;
}

/* ---------- โหลด options ---------- */
$provinces  = fetch_all($connect,"SELECT province_id,province_name FROM province ORDER BY province_name");
$shops      = fetch_all($connect,"SELECT shop_id,province_id,shop_name FROM rentalshop ORDER BY shop_name");
$categories = fetch_all($connect,"SELECT category_id,category_name FROM vehiclecategory ORDER BY category_name");
$types      = fetch_all($connect,"SELECT vehicle_type_id,type_name FROM vehicletype ORDER BY type_name");
$brands     = fetch_all($connect,"SELECT DISTINCT brard AS brand FROM vahicle WHERE brard<>'' ORDER BY brard");

/* ---------- รับค่า (เหลือเฉพาะที่ต้องการ) ---------- */
$province_id     = trim($_GET['province_id'] ?? '');
$shop_id         = trim($_GET['shop_id'] ?? '');
$category_id     = trim($_GET['category_id'] ?? '');
$vehicle_type_id = trim($_GET['vehicle_type_id'] ?? '');
$brand           = trim($_GET['brand'] ?? '');
$minp            = trim($_GET['min_price'] ?? '');
$maxp            = trim($_GET['max_price'] ?? '');

/* ---------- SQL (ปลอดภัย + ไม่ทำให้รถหายเพราะ join) ---------- */
$sql = "
SELECT
  v.Vahicle_id,
  v.brard,
  v.model,
  v.price_per_day,
  v.image,
  v.shop_id,
  v.category_id,
  v.vehicle_type_id,
  s.shop_name,
  s.province_id,
  p.province_name,
  c.category_name,
  t.type_name
FROM vahicle v
LEFT JOIN rentalshop s ON s.shop_id = v.shop_id
LEFT JOIN province p ON p.province_id = s.province_id
LEFT JOIN vehiclecategory c ON c.category_id = v.category_id
LEFT JOIN vehicletype t ON t.vehicle_type_id = v.vehicle_type_id
WHERE 1=1
";

$params = [];
$bindTypes = "";

/* ---- Filter จังหวัด: ใช้วิธีกรองผ่าน shop_id ที่อยู่ในจังหวัดนั้น ---- */
if ($province_id !== '' && ctype_digit($province_id)) {
  $sql .= " AND v.shop_id IN (SELECT shop_id FROM rentalshop WHERE province_id = ?)";
  $params[] = (int)$province_id;
  $bindTypes .= "i";

  // ✅ กันเคส shop_id ค้างผิดจังหวัด: ถ้าเลือกจังหวัดแล้ว shop_id ไม่อยู่จังหวัดนั้น ให้ไม่เอา shop_id ไปกรอง
  if ($shop_id !== '') {
    $ok = false;
    foreach($shops as $s){
      if ((string)$s['shop_id'] === (string)$shop_id && (string)$s['province_id'] === (string)$province_id) {
        $ok = true; break;
      }
    }
    if(!$ok) $shop_id = ''; // รีเซ็ตที่ฝั่ง PHP กันเหนียว
  }
}

/* ---- Filter สาขา ---- */
if ($shop_id !== '' && ctype_digit($shop_id)) {
  $sql .= " AND v.shop_id = ?";
  $params[] = (int)$shop_id;
  $bindTypes .= "i";
}

/* ---- Filter หมวด / ประเภท / แบรนด์ ---- */
if ($category_id !== '' && ctype_digit($category_id)) {
  $sql .= " AND v.category_id = ?";
  $params[] = (int)$category_id;
  $bindTypes .= "i";
}
if ($vehicle_type_id !== '' && ctype_digit($vehicle_type_id)) {
  $sql .= " AND v.vehicle_type_id = ?";
  $params[] = (int)$vehicle_type_id;
  $bindTypes .= "i";
}
if ($brand !== '') {
  $sql .= " AND v.brard = ?";
  $params[] = $brand;
  $bindTypes .= "s";
}

/* ---- Filter ราคา ---- */
if ($minp !== '' && is_numeric($minp)) {
  $sql .= " AND v.price_per_day >= ?";
  $params[] = (float)$minp;
  $bindTypes .= "d";
}
if ($maxp !== '' && is_numeric($maxp)) {
  $sql .= " AND v.price_per_day <= ?";
  $params[] = (float)$maxp;
  $bindTypes .= "d";
}

$sql .= " ORDER BY v.Vahicle_id DESC";

$stmt = mysqli_prepare($connect, $sql);
if (!$stmt) {
  die("Prepare failed: " . mysqli_error($connect));
}
if (!empty($params)) {
  mysqli_stmt_bind_param($stmt, $bindTypes, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>หน้าหลัก</title>
<link rel="stylesheet" href="styles.css" />
<style>
  .search-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
  .search-grid select,.search-grid input{padding:10px;border-radius:10px;border:1px solid #ddd}
  .car-card img{width:100%;height:200px;object-fit:cover;display:block;background:#eee}
  @media(max-width:900px){.search-grid{grid-template-columns:1fr 1fr}}
  @media(max-width:600px){.search-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php include 'header.php'; ?>

<section class="hero">
  <div class="hero-content">
    <h1>เช่ารถอย่างง่ายดาย</h1>
    <p>ค้นหารถจาก จังหวัด/สาขา/หมวดหมู่/ประเภทรถ/แบรนด์/ราคา</p>

    <form method="GET" action="index.php#cars">
      <div class="search-grid">

        <select name="province_id" id="provinceSelect">
          <option value="">จังหวัดทั้งหมด</option>
          <?php foreach($provinces as $p): ?>
            <option value="<?=h($p['province_id'])?>" <?=($province_id==$p['province_id'])?'selected':''?>>
              <?=h($p['province_name'])?>
            </option>
          <?php endforeach;?>
        </select>

        <select name="shop_id" id="shopSelect">
          <option value="">สาขาทั้งหมด</option>
          <?php foreach($shops as $s): ?>
            <option value="<?=h($s['shop_id'])?>"
                    data-province="<?=h($s['province_id'])?>"
                    <?=($shop_id==$s['shop_id'])?'selected':''?>>
              <?=h($s['shop_name'])?>
            </option>
          <?php endforeach;?>
        </select>

        <select name="category_id">
          <option value="">หมวดหมู่ทั้งหมด</option>
          <?php foreach($categories as $c): ?>
            <option value="<?=h($c['category_id'])?>" <?=($category_id==$c['category_id'])?'selected':''?>>
              <?=h($c['category_name'])?>
            </option>
          <?php endforeach;?>
        </select>

        <select name="vehicle_type_id">
          <option value="">ประเภทรถทั้งหมด</option>
          <?php foreach($types as $t): ?>
            <option value="<?=h($t['vehicle_type_id'])?>" <?=($vehicle_type_id==$t['vehicle_type_id'])?'selected':''?>>
              <?=h($t['type_name'])?>
            </option>
          <?php endforeach;?>
        </select>

        <select name="brand">
          <option value="">แบรนด์ทั้งหมด</option>
          <?php foreach($brands as $b): ?>
            <option value="<?=h($b['brand'])?>" <?=($brand==$b['brand'])?'selected':''?>>
              <?=h($b['brand'])?>
            </option>
          <?php endforeach;?>
        </select>

        <input type="number" name="min_price" placeholder="ราคาต่ำสุด/วัน" value="<?=h($minp)?>">
        <input type="number" name="max_price" placeholder="ราคาสูงสุด/วัน" value="<?=h($maxp)?>">

        <button type="submit">ค้นหา</button>
      </div>

      <div style="margin-top:10px;">
        <a href="index.php#cars" style="color:#fff;text-decoration:underline;">ล้างตัวกรอง</a>
      </div>
    </form>
  </div>
</section>

<section class="car-listings" id="cars">
  <div class="container">
    <h2>รถแนะนำ</h2>

    <div class="car-grid">
      <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <?php while($row = mysqli_fetch_assoc($result)): ?>
          <?php
            $title = trim(($row['brard'] ?? '') . ' ' . ($row['model'] ?? ''));
            $img = norm_img($row['image'] ?? '');
            $pname = $row['province_name'] ?? '-';
            $sname = $row['shop_name'] ?? '-';
            $cname = $row['category_name'] ?? '-';
            $tname = $row['type_name'] ?? '-';
          ?>
          <div class="car-card">
            <img src="<?=h($img)?>" alt="<?=h($title)?>" loading="lazy"
                 onerror="this.onerror=null;this.src='vahicle/car2.jpg';">
            <div class="car-info">
              <h3><?=h($title)?></h3>
              <p>จังหวัด: <?=h($pname)?> | สาขา: <?=h($sname)?></p>
              <p>หมวดหมู่: <?=h($cname)?> | ประเภท: <?=h($tname)?></p>
              <p>ราคา: <?=h($row['price_per_day'] ?? '-')?> บาท/วัน</p>
              <button onclick="window.location.href='booking.php?id=<?=urlencode($row['Vahicle_id'])?>'">
                จองตอนนี้
              </button>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <p style="text-align:center;">ไม่พบรถที่ตรงเงื่อนไข</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
const province = document.getElementById('provinceSelect');
const shop = document.getElementById('shopSelect');

function filterShops(resetShop){
  const pid = province.value;
  // ซ่อน/โชว์ option ตามจังหวัด
  [...shop.options].forEach((o,i)=>{
    if(i===0) return;
    const ok = (!pid || o.dataset.province===pid);
    o.hidden = !ok;
  });

  // ✅ สำคัญ: ถ้าเปลี่ยนจังหวัด ให้รีเซ็ตสาขาเป็น “ทั้งหมด”
  if(resetShop){
    shop.value = "";
  }
}

// โหลดครั้งแรก
filterShops(false);

// เปลี่ยนจังหวัด
province.addEventListener('change', ()=>filterShops(true));
</script>

</body>
</html>




