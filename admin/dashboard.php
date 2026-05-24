<?php
include("../includes/auth_check.php");
include("../includes/header.php");
include("../includes/navbar.php");

date_default_timezone_set('America/Los_Angeles');

// Live stats from DB
include("../db/connection.php");

$available = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM ParkingSlot WHERE is_available = 1"))[0];
$total     = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM ParkingSlot"))[0];
$occupied  = $total - $available;

$valet_count = mysqli_fetch_row(mysqli_query($conn,
    "SELECT COUNT(*) FROM ParkingSlip ps
     JOIN ParkingSlot s ON ps.slot_id = s.slot_id
     JOIN Floor f ON s.floor_id = f.floor_id
     WHERE ps.exit_time IS NULL AND ps.is_valet = 1 AND f.floor_number = 1"
))[0];

$valet_available = mysqli_fetch_row(mysqli_query($conn,
    "SELECT COUNT(*) FROM ParkingSlot s
     JOIN Floor f ON s.floor_id = f.floor_id
     WHERE f.floor_number = 1 AND s.is_available = 1"
))[0];

$valet_total = mysqli_fetch_row(mysqli_query($conn,
    "SELECT COUNT(*) FROM ParkingSlot s
     JOIN Floor f ON s.floor_id = f.floor_id
     WHERE f.floor_number = 1"
))[0];

$revenue_today = mysqli_fetch_row(mysqli_query($conn,
    "SELECT COALESCE(SUM(total_amount),0) FROM ParkingSlip
     WHERE DATE(exit_time) = CURDATE() AND payment_status = 'paid'"
))[0];

$checkouts_today = mysqli_fetch_row(mysqli_query($conn,
    "SELECT COUNT(*) FROM ParkingSlip
     WHERE DATE(exit_time) = CURDATE() AND payment_status = 'paid'"
))[0];

// Per-floor availability
$floor_result = mysqli_query($conn, "
    SELECT f.floor_number, f.section_name,
           COUNT(s.slot_id) AS total,
           SUM(s.is_available) AS available
    FROM Floor f
    LEFT JOIN ParkingSlot s ON s.floor_id = f.floor_id
    GROUP BY f.floor_id, f.floor_number, f.section_name
    ORDER BY f.floor_number
");
$floor_counts = mysqli_fetch_all($floor_result, MYSQLI_ASSOC);

// Recent activity (last 6 events)
$activity_result = mysqli_query($conn, "
    (SELECT 'in' AS action, c.vehicle_plate, ps.entry_time AS event_time,
            CONCAT('Floor ', f.floor_number, ', Slot ', s.slot_number) AS location,
            ps.is_valet
     FROM ParkingSlip ps
     JOIN Customer c ON ps.customer_id = c.customer_id
     JOIN ParkingSlot s ON ps.slot_id = s.slot_id
     JOIN Floor f ON s.floor_id = f.floor_id
     ORDER BY ps.entry_time DESC LIMIT 6)
    UNION ALL
    (SELECT 'out' AS action, c.vehicle_plate, ps.exit_time AS event_time,
            CONCAT('$', FORMAT(ps.total_amount,2), ' charged') AS location,
            0 AS is_valet
     FROM ParkingSlip ps
     JOIN Customer c ON ps.customer_id = c.customer_id
     WHERE ps.exit_time IS NOT NULL
     ORDER BY ps.exit_time DESC LIMIT 6)
    ORDER BY event_time DESC LIMIT 6
");
$activity = mysqli_fetch_all($activity_result, MYSQLI_ASSOC);
?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=DM+Mono:wght@500&display=swap" rel="stylesheet">

<div class="main-content">
<div class="container mt-4">

<style>
  .dash { font-family: 'DM Sans', sans-serif; }
  .header-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.75rem; flex-wrap:wrap; gap:12px; }
  .time-chip { font-family:'DM Mono',monospace; font-size:13px; padding:6px 14px; background:#f4f4f4; border:1px solid #e0e0e0; border-radius:8px; color:#555; }
  .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:1.25rem; }
  .stat-card { background:#f8f8f8; border-radius:10px; padding:1rem; }
  .stat-card .label { font-size:12px; color:#888; margin-bottom:6px; }
  .stat-card .value { font-size:26px; font-weight:500; line-height:1; }
  .stat-card .sub { font-size:12px; color:#888; margin-top:4px; }
  .section-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1.25rem; }
  .dash-card { background:#fff; border:1px solid #eee; border-radius:12px; padding:1rem 1.25rem; }
  .dash-card .card-title { font-size:12px; font-weight:500; color:#888; text-transform:uppercase; letter-spacing:.04em; margin-bottom:1rem; }
  .floor-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
  .floor-label { font-size:13px; color:#666; min-width:64px; }
  .bar-track { flex:1; height:6px; background:#eee; border-radius:99px; overflow:hidden; }
  .bar-fill { height:100%; border-radius:99px; }
  .floor-count { font-family:'DM Mono',monospace; font-size:12px; color:#888; min-width:36px; text-align:right; }
  .act-item { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f0; }
  .act-item:last-child { border-bottom:none; padding-bottom:0; }
  .act-icon { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }
  .act-plate { font-family:'DM Mono',monospace; font-size:13px; font-weight:500; }
  .act-detail { font-size:12px; color:#888; margin-top:1px; }
  .act-time { font-size:12px; color:#aaa; flex-shrink:0; }
  .feature-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:1rem; }
  .feat-card { background:#fff; border:1px solid #eee; border-radius:12px; padding:1rem; text-decoration:none; display:flex; flex-direction:column; gap:8px; transition:border-color .15s; }
  .feat-card:hover { border-color:#bbb; text-decoration:none; }
  .feat-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:18px; }
  .feat-name { font-size:13px; font-weight:500; color:#111; }
  .feat-desc { font-size:12px; color:#888; line-height:1.45; }
  .new-badge { font-size:11px; background:#E6F1FB; color:#185FA5; padding:1px 7px; border-radius:99px; margin-left:4px; }
  .section-divider { font-size:12px; font-weight:500; color:#888; text-transform:uppercase; letter-spacing:.04em; margin-bottom:10px; }
  @media(max-width:600px){ .section-row{grid-template-columns:1fr;} }
</style>

<div class="dash">

  <!-- Header -->
  <div class="header-row">
    <div>
      <p style="font-size:13px;color:#888;margin-bottom:2px;">
        <?= date('l, F j') ?>
      </p>
      <h2 style="font-size:22px;font-weight:500;margin:0;">ACME Parking Management</h2>
    </div>
    <div class="time-chip" id="clock"></div>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="label">Available slots</div>
      <div class="value" style="color:#3B6D11;"><?= $available ?></div>
      <div class="sub">of <?= $total ?> total</div>
    </div>
    <div class="stat-card">
      <div class="label">Active vehicles</div>
      <div class="value" style="color:#185FA5;"><?= $occupied ?></div>
      <div class="sub">currently parked</div>
    </div>
    <div class="stat-card">
        <div class="label">Valet vehicles</div>
        <div class="value" style="color:#854F0B;"><?= $valet_count ?></div>
        <div class="sub">
            <?= $valet_available ?>/<?= $valet_total ?> ground spots open
        </div>
    </div>
    <div class="stat-card">
      <div class="label">Today's revenue</div>
      <div class="value" style="color:#993C1D;">$<?= number_format($revenue_today, 0) ?></div>
      <div class="sub"><?= $checkouts_today ?> checkouts</div>
    </div>
  </div>

  <!-- Floor availability + Activity -->
  <div class="section-row">
    <div class="dash-card">
      <div class="card-title">Floor availability</div>
      <?php foreach ($floor_counts as $f):
        $avail = (int)$f['available'];
        $tot   = (int)$f['total'];
        $pct   = $tot > 0 ? round(($avail / $tot) * 100) : 0;
        $color = $avail === 0 ? '#E24B4A' : ($avail <= 3 ? '#BA7517' : '#1D9E75');
        $label = 'Floor ' . $f['floor_number'];
        if ($f['section_name']) $label .= ' – ' . htmlspecialchars($f['section_name']);
      ?>
      <div class="floor-row" style="margin-bottom:<?= $f === end($floor_counts) ? '0' : '10px' ?>;">
        <span class="floor-label" style="font-size:12px;"><?= $label ?></span>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
        </div>
        <span class="floor-count"><?= $avail ?>/<?= $tot ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="dash-card">
      <div class="card-title">Recent activity</div>
      <?php if (empty($activity)): ?>
        <p style="font-size:13px;color:#aaa;margin:0;">No activity yet today.</p>
      <?php endif; ?>
      <?php foreach ($activity as $evt):
        $is_valet = (int)($evt['is_valet'] ?? 0);
        $action   = $evt['action'];
        if ($is_valet && $action === 'in') {
            $bg = '#FEF6E8'; $color = '#854F0B'; $icon = '🔑';
            $detail = 'Valet check-in · ' . $evt['location'];
        } elseif ($action === 'in') {
            $bg = '#EAF3DE'; $color = '#3B6D11'; $icon = '↙';
            $detail = 'Check-in · ' . $evt['location'];
        } else {
            $bg = '#FAECE7'; $color = '#993C1D'; $icon = '↗';
            $detail = 'Check-out · ' . $evt['location'];
        }
        $time_str = date('g:i A', strtotime($evt['event_time']));
      ?>
      <div class="act-item">
        <div class="act-icon" style="background:<?= $bg ?>;color:<?= $color ?>;"><?= $icon ?></div>
        <div style="flex:1;">
          <div class="act-plate"><?= htmlspecialchars($evt['vehicle_plate']) ?></div>
          <div class="act-detail"><?= htmlspecialchars($detail) ?></div>
        </div>
        <div class="act-time"><?= $time_str ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Quick access -->
  <div class="section-divider">Quick access</div>
    <div class="feature-grid">
      <a href="/parking_management/attendant/checkin.php" class="feat-card">
        <div class="feat-icon" style="background:#E1F5EE;color:#0F6E56;">🚗</div>
        <div class="feat-name">Check in</div>
        <div class="feat-desc">Issue parking slips and assign slots automatically</div>
      </a>
      <a href="/parking_management/attendant/checkout.php" class="feat-card">
        <div class="feat-icon" style="background:#FAECE7;color:#993C1D;">🏁</div>
        <div class="feat-name">Check out</div>
        <div class="feat-desc">Calculate fees and free up slots instantly</div>
      </a>
      <a href="/parking_management/attendant/checkin.php" class="feat-card">
        <div class="feat-icon" style="background:#FAEEDA;color:#854F0B;">🔑</div>
        <div class="feat-name">Valet <span class="new-badge">New</span></div>
        <div class="feat-desc">Ground-floor reserved slots for high-end vehicles</div>
      </a>
      <a href="/parking_management/attendant/checkin.php" class="feat-card">
        <div class="feat-icon" style="background:#E6F1FB;color:#185FA5;">🚿</div>
        <div class="feat-name">Car wash <span class="new-badge">New</span></div>
        <div class="feat-desc">Request a wash at check-in, billed at checkout</div>
      </a>
    </div>

  </div>
  </div>
</div>

<script>
  function tick() {
    const now = new Date();
    const h = now.getHours(), m = now.getMinutes();
    const ampm = h >= 12 ? 'PM' : 'AM';
    document.getElementById('clock').textContent =
      (h % 12 || 12) + ':' + String(m).padStart(2,'0') + ' ' + ampm;
  }
  tick(); setInterval(tick, 10000);
</script>

<?php include("../includes/footer.php"); ?>