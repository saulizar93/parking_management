<?php
session_start();
date_default_timezone_set('America/Los_Angeles');

if (!isset($_SESSION['username'])) {
    header("Location: /parking_management/index.php");
    exit();
}

include("../includes/header.php");
include("../includes/navbar.php");
include("../db/connection.php");

$error     = '';
$slip_data = null;
$receipt   = null;

// compute full fee breakdown 
function calc_fee(int $minutes, int $is_valet, int $car_wash): array {
    $hours_billed = (int)ceil(max(1, $minutes) / 60);
    $hourly_rate  = $is_valet ? 5.00  : 1.00;
    $valet_fee    = $is_valet ? 10.00 : 0.00;
    $carwash_fee  = $car_wash ? 30.00 : 0.00;
    $total        = ($hours_billed * $hourly_rate) + $valet_fee + $carwash_fee;
    return compact('hours_billed', 'hourly_rate', 'valet_fee', 'carwash_fee', 'total');
}


// Confirm and process the checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_checkout'])) {

    $slip_id   = (int)$_POST['slip_id'];
    $exit_time = date('Y-m-d H:i:s');

    $result = mysqli_query($conn, "
        SELECT ps.slip_id,
               ps.entry_time,
               ps.slot_id,
               ps.is_valet,
               ps.car_wash,
               c.vehicle_plate,
               c.vehicle_type,
               c.first_name,
               c.last_name,
               s.slot_number,
               s.slot_type,
               f.floor_number,
               f.section_name
        FROM ParkingSlip ps
        JOIN Customer    c ON ps.customer_id = c.customer_id
        JOIN ParkingSlot s ON ps.slot_id     = s.slot_id
        JOIN Floor       f ON s.floor_id     = f.floor_id
        WHERE ps.slip_id = $slip_id
          AND ps.exit_time IS NULL
    ");

    if (mysqli_num_rows($result) === 0) {
        $error = 'Slip not found or already checked out.';
    } else {
        $row = mysqli_fetch_assoc($result);

        $entry_ts = strtotime($row['entry_time']);
        $exit_ts  = strtotime($exit_time);
        $minutes  = max(1, abs((int)(($exit_ts - $entry_ts) / 60)));

        $fee = calc_fee($minutes, (int)$row['is_valet'], (int)$row['car_wash']);

        $total_safe = number_format($fee['total'], 2, '.', '');
        mysqli_query($conn, "
            UPDATE ParkingSlip
            SET exit_time      = '$exit_time',
                total_amount   = $total_safe,
                payment_status = 'paid'
            WHERE slip_id = $slip_id
        ");

        mysqli_query($conn,
            "UPDATE ParkingSlot SET is_available = 1 WHERE slot_id = {$row['slot_id']}"
        );

        $receipt = array_merge($row, [
            'exit_time'    => $exit_time,
            'minutes'      => $minutes,
        ], $fee);  // merges hours_billed, hourly_rate, valet_fee, carwash_fee, total
    }
}

// Look up a slip by plate or slip number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup'])) {

    $plate_raw   = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
    $slip_id_raw = (int)($_POST['slip_id_direct'] ?? 0);
    $plate_safe  = mysqli_real_escape_string($conn, $plate_raw);

    if ($slip_id_raw > 0) {
        $result = mysqli_query($conn, "
            SELECT ps.slip_id,
                   ps.entry_time,
                   ps.slot_id,
                   ps.is_valet,
                   ps.car_wash,
                   c.vehicle_plate,
                   c.vehicle_type,
                   c.first_name,
                   c.last_name,
                   s.slot_number,
                   s.slot_type,
                   f.floor_number,
                   f.section_name
            FROM ParkingSlip ps
            JOIN Customer    c ON ps.customer_id = c.customer_id
            JOIN ParkingSlot s ON ps.slot_id     = s.slot_id
            JOIN Floor       f ON s.floor_id     = f.floor_id
            WHERE ps.slip_id = $slip_id_raw
              AND ps.exit_time IS NULL
        ");
    } elseif ($plate_raw) {
        $result = mysqli_query($conn, "
            SELECT ps.slip_id,
                   ps.entry_time,
                   ps.slot_id,
                   ps.is_valet,
                   ps.car_wash,
                   c.vehicle_plate,
                   c.vehicle_type,
                   c.first_name,
                   c.last_name,
                   s.slot_number,
                   s.slot_type,
                   f.floor_number,
                   f.section_name
            FROM ParkingSlip ps
            JOIN Customer    c ON ps.customer_id = c.customer_id
            JOIN ParkingSlot s ON ps.slot_id     = s.slot_id
            JOIN Floor       f ON s.floor_id     = f.floor_id
            WHERE c.vehicle_plate = '$plate_safe'
              AND ps.exit_time IS NULL
            ORDER BY ps.entry_time DESC
            LIMIT 1
        ");
    } else {
        $error = 'Please enter a license plate or slip number.';
    }

    if (!$error) {
        if (mysqli_num_rows($result) === 0) {
            $error = $slip_id_raw
                ? "No active slip found for slip #$slip_id_raw."
                : "No active slip found for plate: $plate_raw.";
        } else {
            $row     = mysqli_fetch_assoc($result);
            $minutes = max(1, (int)((time() - strtotime($row['entry_time'])) / 60));
            $fee     = calc_fee($minutes, (int)$row['is_valet'], (int)$row['car_wash']);

            $slip_data = array_merge($row, ['minutes' => $minutes], $fee);
        }
    }
}

// Active slips table
$active_slips = [];
if (!$receipt) {
    $res = mysqli_query($conn, "
        SELECT ps.slip_id,
               ps.is_valet,
               ps.car_wash,
               c.vehicle_plate,
               c.vehicle_type,
               ps.entry_time,
               CONCAT('Floor ', f.floor_number, ' – Slot ', s.slot_number) AS location,
               TIMESTAMPDIFF(MINUTE, ps.entry_time, NOW()) AS minutes_parked
        FROM ParkingSlip ps
        JOIN Customer    c ON ps.customer_id = c.customer_id
        JOIN ParkingSlot s ON ps.slot_id     = s.slot_id
        JOIN Floor       f ON s.floor_id     = f.floor_id
        WHERE ps.exit_time IS NULL
        ORDER BY ps.entry_time ASC
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        $active_slips[] = $r;
    }
}
?>

<div class="main-content">
<div class="container mt-4">

    <h2 class="mb-4">Check Out Vehicle</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($receipt): ?>
    <!-- CHECKOUT RECEIPT -->
    <div class="card shadow p-4 mb-4" style="max-width: 480px; margin: 0 auto;">
        <h4 class="text-center mb-3">✅ Checkout Complete</h4>
        <hr>
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr>
                    <th>Slip #</th>
                    <td><strong>#<?= str_pad($receipt['slip_id'], 6, '0', STR_PAD_LEFT) ?></strong></td>
                </tr>
                <tr>
                    <th>License Plate</th>
                    <td><strong><?= htmlspecialchars($receipt['vehicle_plate']) ?></strong></td>
                </tr>
                <tr>
                    <th>Customer</th>
                    <td><?= htmlspecialchars($receipt['first_name'] . ' ' . $receipt['last_name']) ?></td>
                </tr>
                <!-- valet/car-wash badges -->
                <?php if ($receipt['is_valet']): ?>
                <tr>
                    <th>Services</th>
                    <td>
                        <?php if ($s['is_valet']): ?>
                            <span class="badge bg-warning text-dark">⭐ Valet</span>
                        <?php endif; ?>
                        <?php if ($s['car_wash']): ?>
                            <span class="badge bg-info text-dark">🚿 Wash</span>
                        <?php endif; ?>
                        <?php if (!$s['is_valet'] && !$s['car_wash']): ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Location</th>
                    <td>
                        Floor <?= $receipt['floor_number'] ?>
                        <?php if ($receipt['section_name']): ?>– <?= htmlspecialchars($receipt['section_name']) ?><?php endif; ?>,
                        Slot <?= htmlspecialchars($receipt['slot_number']) ?>
                    </td>
                </tr>
                <tr>
                    <th>Entry Time</th>
                    <td><?= date('M j, Y  g:i A', strtotime($receipt['entry_time'])) ?></td>
                </tr>
                <tr>
                    <th>Exit Time</th>
                    <td><?= date('M j, Y  g:i A', strtotime($receipt['exit_time'])) ?></td>
                </tr>
                <tr>
                    <th>Duration</th>
                    <td><?= floor(abs($receipt['minutes']) / 60) ?>h <?= $receipt['minutes'] % 60 ?>m</td>
                </tr>
                
                <tr>
                    <th>Parking</th>
                    <td><?= $receipt['hours_billed'] ?> hr(s) × $<?= number_format($receipt['hourly_rate'], 2) ?></td>
                </tr>
                <?php if ($receipt['is_valet']): ?>
                <tr>
                    <th>Valet Fee</th>
                    <td>$<?= number_format($receipt['valet_fee'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($receipt['car_wash']): ?>
                <tr>
                    <th>Car Wash</th>
                    <td>$<?= number_format($receipt['carwash_fee'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-success">
                    <th>Total Charged</th>
                    <td><strong>$<?= number_format($receipt['total'], 2) ?></strong></td>
                </tr>
            </tbody>
        </table>
        <hr>
        <p class="text-muted text-center mb-0" style="font-size: .85rem;">
            Payment status: <strong>Paid</strong> &mdash; Slot <?= htmlspecialchars($receipt['slot_number']) ?> is now available.
        </p>
    </div>

    <div class="d-flex flex-column align-items-center gap-2 ms-2">
        <a href="/parking_management/attendant/checkout.php" class="btn btn-primary" style="width: 220px;">
            Check Out Another Vehicle
        </a>
        <a href="/parking_management/admin/dashboard.php" class="btn btn-secondary" style="width: 220px;">
            Back to Dashboard
        </a>
    </div>

    <?php elseif ($slip_data): ?>
    <!-- CONFIRM CHECKOUT -->
    <div class="card shadow p-4 mb-4">
        <h5 class="mb-3">Confirm Checkout</h5>
        <p class="text-muted" style="font-size: .9rem;">
            Review the details below and click <strong>Confirm &amp; Checkout</strong> to finalize.
        </p>

        <table class="table table-bordered mb-4">
            <tbody>
                <tr>
                    <th>Slip #</th>
                    <td>#<?= str_pad($slip_data['slip_id'], 6, '0', STR_PAD_LEFT) ?></td>
                </tr>
                <tr>
                    <th>License Plate</th>
                    <td><strong><?= htmlspecialchars($slip_data['vehicle_plate']) ?></strong></td>
                </tr>
                <tr>
                    <th>Customer</th>
                    <td><?= htmlspecialchars($slip_data['first_name'] . ' ' . $slip_data['last_name']) ?></td>
                </tr>
                
                <?php if ($slip_data['is_valet']): ?>
                <tr>
                    <th>Services</th>
                    <td>
                        <span class="badge bg-warning text-dark">⭐ Valet</span>
                        <?php if ($slip_data['car_wash']): ?>
                            <span class="badge bg-info text-dark ms-1">🚿 Car Wash</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Location</th>
                    <td>
                        Floor <?= $slip_data['floor_number'] ?>
                        <?php if ($slip_data['section_name']): ?>– <?= htmlspecialchars($slip_data['section_name']) ?><?php endif; ?>,
                        Slot <?= htmlspecialchars($slip_data['slot_number']) ?>
                    </td>
                </tr>
                <tr>
                    <th>Entry Time</th>
                    <td><?= date('M j, Y  g:i A', strtotime($slip_data['entry_time'])) ?></td>
                </tr>
                <tr>
                    <th>Time Parked So Far</th>
                    <td><?= floor($slip_data['minutes'] / 60) ?>h <?= $slip_data['minutes'] % 60 ?>m</td>
                </tr>
                
                <tr>
                    <th>Parking</th>
                    <td><?= $slip_data['hours_billed'] ?> hr(s) × $<?= number_format($slip_data['hourly_rate'], 2) ?></td>
                </tr>
                <?php if ($slip_data['is_valet']): ?>
                <tr>
                    <th>Valet Fee</th>
                    <td>$<?= number_format($slip_data['valet_fee'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($slip_data['car_wash']): ?>
                <tr>
                    <th>Car Wash</th>
                    <td>$<?= number_format($slip_data['carwash_fee'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-warning">
                    <th>Estimated Total</th>
                    <td><strong>$<?= number_format($slip_data['total'], 2) ?></strong></td>
                </tr>
            </tbody>
        </table>

        <form method="POST">
            <input type="hidden" name="slip_id" value="<?= $slip_data['slip_id'] ?>">
            <button type="submit" name="confirm_checkout" value="1"
                    class="btn btn-success btn-lg me-2">
                ✓ Confirm &amp; Checkout — $<?= number_format($slip_data['total'], 2) ?>
            </button>
            <a href="/parking_management/attendant/checkout.php" class="btn btn-secondary">
                Cancel
            </a>
        </form>
    </div>

    <?php else: ?>
    <!-- LOOKUP FORM + ACTIVE SLIPS TABLE -->
    <div class="card shadow p-4 mb-4">
        <h5 class="mb-3">Find Vehicle</h5>
        <form method="POST">
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label">License Plate</label>
                    <input type="text"
                        name="vehicle_plate"
                        class="form-control"
                        placeholder="e.g. ABC-1234"
                        style="text-transform: uppercase;">
                </div>
                <div class="col-sm-1 d-flex align-items-end justify-content-center pb-2">
                    <span class="text-muted">or</span>
                </div>
                <div class="col-sm-5">
                    <label class="form-label">Slip Number</label>
                    <input type="number"
                        name="slip_id_direct"
                        class="form-control"
                        placeholder="e.g. 5"
                        min="1">
                </div>
                <div class="col-12 col-sm-4">
                    <button type="submit" name="lookup" value="1"
                            class="btn btn-primary"
                            style="white-space: nowrap;">
                        🔍 Look Up
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Active slips table -->
    <div class="card shadow p-4">
        <h5 class="mb-3">Currently Parked Vehicles (<?= count($active_slips) ?>)</h5>
        <?php if ($active_slips): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Slip #</th>
                        <th>Plate</th>
                        <th>Type</th>
                        <th>Services</th>
                        <th>Location</th>
                        <th>Entry Time</th>
                        <th>Duration</th>
                        <th>Est. Fee</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($active_slips as $s):
                    $mins = (int)$s['minutes_parked'];
                    $fee  = calc_fee($mins, (int)$s['is_valet'], (int)$s['car_wash']);
                ?>
                    <tr>
                        <td>#<?= str_pad($s['slip_id'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td><strong><?= htmlspecialchars($s['vehicle_plate']) ?></strong></td>
                        <td><?= htmlspecialchars($s['vehicle_type']) ?></td>
                        
                        <td>
                            <?php if ($s['is_valet']): ?>
                                <span class="badge bg-warning text-dark">⭐ Valet</span>
                            <?php endif; ?>
                            <?php if ($s['car_wash']): ?>
                                <span class="badge bg-info text-dark">🚿 Wash</span>
                            <?php endif; ?>
                            <?php if (!$s['is_valet'] && !$s['car_wash']): ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($s['location']) ?></td>
                        <td><?= date('M j, g:i A', strtotime($s['entry_time'])) ?></td>
                        <td><?= floor($mins / 60) ?>h <?= $mins % 60 ?>m</td>
                        <td>$<?= number_format($fee['total'], 2) ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="slip_id_direct" value="<?= $s['slip_id'] ?>">
                                <button type="submit" name="lookup" value="1"
                                        class="btn btn-sm btn-outline-danger">
                                    Check Out
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="text-muted">No vehicles are currently parked.</p>
        <?php endif; ?>
    </div>

    <?php endif; ?>

</div>
</div>

<script>
    const plateInput = document.querySelector('input[name="vehicle_plate"]');
    if (plateInput) {
        plateInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
</script>

<?php include("../includes/footer.php"); ?>