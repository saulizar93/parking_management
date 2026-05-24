<div class="sidebar bg-dark text-white p-3">

<?php if(isset($_SESSION['username'])): ?>

    <h5>
        Logged in as:
    </h5>

    <p>
        <?php echo $_SESSION['username']; ?>
    </p>

    <hr>

    <a class="btn btn-outline-light w-100 mb-2"
       href="/parking_management/admin/dashboard.php">

       Dashboard
    </a>

    <a class="btn btn-outline-light w-100 mb-2"
       href="/parking_management/attendant/checkin.php">

       Check In Vehicle
    </a>

    <a class="btn btn-outline-light w-100 mb-2"
       href="/parking_management/attendant/checkout.php">

       Check Out Vehicle
    </a>

    <a class="btn btn-outline-light w-100 mb-2"
        href="/parking_management/attendant/training.php">
        Training
    </a>

    <?php if($_SESSION['role'] == 'admin'): ?>

        <a class="btn btn-outline-light w-100 mb-2"
        href="/parking_management/admin/users.php">

        Manage Users
        </a>

    <?php endif; ?>

    <a class="btn btn-danger w-100"
       href="/parking_management/scripts/logout.php">

       Logout
    </a>

<?php else: ?>

    <a class="btn btn-primary w-100"
       href="/parking_management/index.php">

       Login
    </a>

<?php endif; ?>

</div>