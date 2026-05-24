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

function checkin_fee_preview(int $is_valet, int $car_wash): array {
    $valet_fee   = $is_valet ? 10.00 : 0.00;
    $carwash_fee = $car_wash ? 30.00 : 0.00;
    $due_at_exit = $valet_fee + $carwash_fee;
    return compact('valet_fee', 'carwash_fee', 'due_at_exit');
}

$slip      = null;
$error     = '';
$lot_full  = false;

$current_hour = (int)date('G');
$wash_allowed = ($current_hour >= 8 && $current_hour < 16);

// Slot counts
$result          = mysqli_query($conn, "SELECT COUNT(*) FROM ParkingSlot WHERE is_available = 1");
$available_count = mysqli_fetch_row($result)[0];

$result      = mysqli_query($conn, "SELECT COUNT(*) FROM ParkingSlot");
$total_count = mysqli_fetch_row($result)[0];

// Slot counts per floor
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

if ($available_count == 0) {
    $lot_full = true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $plate        = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
    $vehicle_type = mysqli_real_escape_string($conn, trim($_POST['vehicle_type']  ?? 'car'));
    $first_name   = mysqli_real_escape_string($conn, trim($_POST['first_name']    ?? 'Walk-in'));
    $last_name    = mysqli_real_escape_string($conn, trim($_POST['last_name']     ?? 'Customer'));
    $phone        = mysqli_real_escape_string($conn, trim($_POST['phone_number']  ?? ''));
    $email        = mysqli_real_escape_string($conn, trim($_POST['email']         ?? ''));
    $plate_safe   = mysqli_real_escape_string($conn, $plate);

    // New valet & car-wash flags
    $is_valet     = isset($_POST['is_valet']) ? 1 : 0;

    // Car wash only allowed between 8AM and 4PM
    $current_hour = (int)date('G');
    $wash_allowed = ($current_hour >= 8 && $current_hour < 16);
    $car_wash     = ($is_valet && isset($_POST['car_wash']) && $wash_allowed) ? 1 : 0;
    

    if (!$plate) {
        $error = 'A license plate number is required.';
    }

    // Check for an already-active slip on this plate
    if (!$error) {
        $check = mysqli_query($conn, "
            SELECT ps.slip_id
            FROM ParkingSlip ps
            JOIN Customer c ON ps.customer_id = c.customer_id
            WHERE c.vehicle_plate = '$plate_safe'
              AND ps.exit_time IS NULL
        ");
        if (mysqli_num_rows($check) > 0) {
            $error = "Vehicle <strong>$plate</strong> already has an active parking slip.";
        }
    }

    // NEW: check Ground floor availability for valet
    if (!$error && $is_valet) {
        $ground_check = mysqli_query($conn, "
            SELECT COUNT(*) FROM ParkingSlot s
            JOIN Floor f ON s.floor_id = f.floor_id
            WHERE f.floor_number = 1 AND s.is_available = 1
        ");
        if (mysqli_fetch_row($ground_check)[0] == 0) {
            $error = 'No valet slots available on the Ground floor at this time.';
        }
    } elseif (!$error && $lot_full) {
        $error = 'The parking lot is currently full. No available slots.';
    }
    

    if (!$error) {

        // Find or create customer
        $cust_result = mysqli_query($conn,
            "SELECT customer_id FROM Customer WHERE vehicle_plate = '$plate_safe' LIMIT 1"
        );

        if (mysqli_num_rows($cust_result) > 0) {
            $customer_id = mysqli_fetch_assoc($cust_result)['customer_id'];
        } else {
            mysqli_query($conn, "
                INSERT INTO Customer (first_name, last_name, phone_number, email, vehicle_plate, vehicle_type)
                VALUES ('$first_name', '$last_name', '$phone', '$email', '$plate_safe', '$vehicle_type')
            ");
            $customer_id = mysqli_insert_id($conn);
        }

        // NEW: valet to Ground floor only; regular to any floor
        if ($is_valet) {
            $slot_result = mysqli_query($conn, "
                SELECT s.slot_id,
                       s.slot_number,
                       s.slot_type,
                       f.floor_number,
                       f.section_name
                FROM ParkingSlot s
                JOIN Floor f ON s.floor_id = f.floor_id
                WHERE s.is_available = 1
                  AND f.floor_number = 1
                ORDER BY RAND()
                LIMIT 1
            ");
        } else {
            $slot_result = mysqli_query($conn, "
                SELECT s.slot_id,
                       s.slot_number,
                       s.slot_type,
                       f.floor_number,
                       f.section_name
                FROM ParkingSlot s
                JOIN Floor f ON s.floor_id = f.floor_id
                WHERE s.is_available = 1
                ORDER BY RAND()
                LIMIT 1
            ");
        }
        
        $slot_row = mysqli_fetch_assoc($slot_result);

        $entry_time = date('Y-m-d H:i:s');

        // NEW: store is_valet and car_wash in ParkingSlip
        mysqli_query($conn, "
            INSERT INTO ParkingSlip (customer_id, slot_id, entry_time, payment_status, is_valet, car_wash)
            VALUES ($customer_id, {$slot_row['slot_id']}, '$entry_time', 'unpaid', $is_valet, $car_wash)
        ");
        // ────────────────────────────────────────────────────────────────────
        $slip_id = mysqli_insert_id($conn);

        // Mark slot occupied
        mysqli_query($conn,
            "UPDATE ParkingSlot SET is_available = 0 WHERE slot_id = {$slot_row['slot_id']}"
        );

        // Refresh counts
        $r               = mysqli_query($conn, "SELECT COUNT(*) FROM ParkingSlot WHERE is_available = 1");
        $available_count = mysqli_fetch_row($r)[0];

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

        $slip = [
            'slip_id'      => $slip_id,
            'plate'        => $plate,
            'vehicle_type' => $_POST['vehicle_type'],
            'floor_number' => $slot_row['floor_number'],
            'section_name' => $slot_row['section_name'],
            'slot_number'  => $slot_row['slot_number'],
            'slot_type'    => $slot_row['slot_type'],
            'entry_time'   => $entry_time,
            'is_valet'     => $is_valet,   // NEW
            'car_wash'     => $car_wash,   // NEW
        ];
    }
}
?>

<div class="main-content">
<div class="container mt-4">

    <h2 class="mb-4">Check In Vehicle</h2>

    <div class="alert <?= $lot_full ? 'alert-danger' : 'alert-info' ?> mb-4">
        <?php if ($lot_full): ?>
            <strong>Lot Full.</strong> There are no available slots at this time.
        <?php else: ?>
            <strong>Spaces Available:</strong>
            <?= $available_count ?> of <?= $total_count ?> slots are currently open.
        <?php endif; ?>

        <?php if (!empty($floor_counts)): ?>
            <hr class="my-2">
            <div class="row row-cols-auto g-2 mt-1">
                <?php foreach ($floor_counts as $floor): ?>
                    <?php
                        $avail = (int)$floor['available'];
                        $total = (int)$floor['total'];
                        $badge = $avail === 0 ? 'danger' : ($avail <= 3 ? 'warning' : 'success');
                    ?>
                    <div class="col">
                        <span class="badge bg-<?= $badge ?> fs-6 px-3 py-2 d-flex flex-column align-items-center" style="width: 150px;">
                            <span style="font-size: .8rem;">
                                Floor <?= $floor['floor_number'] ?>
                                <?php if ($floor['section_name']): ?>
                                    – <?= htmlspecialchars($floor['section_name']) ?>
                                <?php endif; ?>
                            </span>
                            <span style="font-size: .8rem; font-weight: bold;">
                                <?= $avail ?>/<?= $total ?>
                            </span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($slip): ?>

        <!-- Parking slip receipt -->
        <div class="card shadow p-4 mb-4" style="max-width: 480px; margin: 0 auto;">
            <h4 class="text-center mb-3">
                <?= $slip['is_valet'] ? '🔑 Valet Parking Slip Issued' : '🅿 Parking Slip Issued' ?>
            </h4>
            <hr>
            <table class="table table-sm table-borderless mb-0">
                <tbody>
                    <tr>
                        <th>Slip #</th>
                        <td><strong>#<?= str_pad($slip['slip_id'], 6, '0', STR_PAD_LEFT) ?></strong></td>
                    </tr>
                    <tr>
                        <th>License Plate</th>
                        <td><strong><?= htmlspecialchars($slip['plate']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>Vehicle Type</th>
                        <td><?= htmlspecialchars($slip['vehicle_type']) ?></td>
                    </tr>
                    <!-- Valet badge row -->
                    <?php if ($slip['is_valet']): ?>
                        <tr>
                            <th>Service</th>
                            <td>
                                <span class="badge bg-warning text-dark">⭐ Valet</span>
                                <?php if ($slip['car_wash']): ?>
                                    <span class="badge bg-info text-dark ms-1">🚿 Car Wash</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Fee Due at Exit</th>
                            <td>
                                <?php
                                    $preview = checkin_fee_preview($slip['is_valet'], $slip['car_wash']);
                                ?>
                                <small class="text-muted d-block">Valet: $<?= number_format($preview['valet_fee'], 2) ?></small>
                                <?php if ($slip['car_wash']): ?>
                                    <small class="text-muted d-block">Car wash: $<?= number_format($preview['carwash_fee'], 2) ?></small>
                                <?php endif; ?>
                                <strong>+ hourly parking rate</strong>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Assigned Floor</th>
                        <td>
                            Floor <?= $slip['floor_number'] ?>
                            <?php if ($slip['section_name']): ?>
                                – <?= htmlspecialchars($slip['section_name']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Slot Number</th>
                        <td><?= htmlspecialchars($slip['slot_number']) ?> (<?= htmlspecialchars($slip['slot_type']) ?>)</td>
                    </tr>
                    <tr>
                        <th>Entry Time</th>
                        <td><?= date('M j, Y  g:i A', strtotime($slip['entry_time'])) ?></td>
                    </tr>
                    <tr>
                        <th>Rate</th>
                        <td>
                            <?php if ($slip['is_valet']): ?>
                                $5.00 / hour + $10.00 valet fee
                                <?= $slip['car_wash'] ? '+ $30.00 car wash' : '' ?>
                            <?php else: ?>
                                $1.00 / hour (or part thereof)
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <hr>
            <p class="text-muted text-center mb-0" style="font-size: .85rem;">
                Spaces remaining: <strong><?= $available_count ?></strong>
            </p>
        </div>

        <div class="d-flex flex-column align-items-center gap-2 ms-2">
            <a href="/parking_management/attendant/checkin.php" class="btn btn-primary" style="width: 220px;">
                Check In Another Vehicle
            </a>
            <a href="/parking_management/admin/dashboard.php" class="btn btn-secondary" style="width: 220px;">
                Back to Dashboard
            </a>
        </div>

    <?php elseif (!$lot_full): ?>

        <!-- Check-in form -->
        <div class="card shadow p-4">
            <p class="text-muted mb-3">
                📷 The camera captures the license plate automatically.
                Verify the plate below, fill in any missing details, then click
                <strong>Issue Parking Slip</strong>.
            </p>

            <form method="POST">

                <h5 class="mb-3">Vehicle Information</h5>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">License Plate *</label>
                        <input type="text"
                               name="vehicle_plate"
                               class="form-control"
                               placeholder="e.g. ABC-1234"
                               value="<?= htmlspecialchars($_POST['vehicle_plate'] ?? '') ?>"
                               required
                               style="text-transform: uppercase; font-weight: bold; font-size: 1.1rem; letter-spacing: 2px;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Vehicle Type</label>
                        <select name="vehicle_type" class="form-select">
                            <option value="car">Car</option>
                            <option value="truck">Truck</option>
                            <option value="suv">SUV</option>
                            <option value="van">Van</option>
                            <option value="motorcycle">Motorcycle</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Entry Time</label>
                    <input type="text"
                           class="form-control"
                           value="<?= date('M j, Y  g:i A') ?>"
                           readonly>
                    <div class="form-text">Recorded automatically at the moment of submission.</div>
                </div>

                <!-- Valet / High-End section  -->
                <hr>
                <h5 class="mb-3">Premium Services</h5>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input"
                           type="checkbox"
                           role="switch"
                           name="is_valet"
                           id="valetToggle"
                           <?= isset($_POST['is_valet']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="valetToggle">
                        ⭐ Valet / High-End Vehicle
                        <span class="text-muted fw-normal" style="font-size: .85rem;">
                            — reserves a Ground-level slot &amp; adds a $10 valet fee
                        </span>
                    </label>
                </div>

                <!-- Car-wash sub-option — hidden unless valet is checked -->
                <div id="valetOptions" class="ms-4 mb-3" style="display: <?= isset($_POST['is_valet']) ? 'block' : 'none' ?>;">
                    <?php if ($wash_allowed): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="car_wash"
                                id="carWashCheck" <?= isset($_POST['car_wash']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="carWashCheck">
                                🚿 Request Car Wash
                                <span class="text-muted" style="font-size: .85rem;">— +$30.00</span>
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="text-muted" style="font-size: .9rem;">
                            🚫 Car wash unavailable — only offered between <strong>8:00 AM – 4:00 PM</strong>.
                        </div>
                    <?php endif; ?>
                </div>
                

                <hr>
                <h5 class="mb-3">
                    Customer Information
                    <span class="text-muted fw-normal" style="font-size: .9rem;">(optional for walk-ins)</span>
                </h5>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" placeholder="Walk-in">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" placeholder="Customer">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone_number" class="form-control" placeholder="555-0000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="optional">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">
                    🚗 Issue Parking Slip
                </button>

            </form>
        </div>

    <?php endif; ?>

</div>
</div>

<script>
    // Plate auto-uppercase
    const plateInput = document.querySelector('input[name="vehicle_plate"]');
    if (plateInput) {
        plateInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }

    // Show/hide car-wash option based on valet toggle
    const valetToggle  = document.getElementById('valetToggle');
    const valetOptions = document.getElementById('valetOptions');
    const carWashCheck = document.getElementById('carWashCheck');

    if (valetToggle) {
        valetToggle.addEventListener('change', function () {
            valetOptions.style.display = this.checked ? 'block' : 'none';
            if (!this.checked) carWashCheck.checked = false;
        });
    }
</script>

<?php include("../includes/footer.php"); ?>