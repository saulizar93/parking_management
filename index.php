<?php
include("includes/header.php");
include("includes/navbar.php");
?>

<div class="main-content">

    <div class="container mt-5">

        <div class="card shadow p-4">

            <h2 class="mb-4 text-center">
                Administrator Login
            </h2>

            <?php
                if(isset($_GET['error'])){
                    echo "
                    <div class='alert alert-danger'>
                        Invalid username or password.
                    </div>
                    ";
                }
            ?>
            <form action="scripts/login_process.php" method="POST">

                <div class="mb-3">
                    <label class="form-label">
                        Username
                    </label>

                    <input type="text"
                           name="username"
                           class="form-control"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Password
                    </label>

                    <input type="password"
                           name="password"
                           class="form-control"
                           required>
                </div>

                <button type="submit"
                        class="btn btn-primary w-100">

                    Login

                </button>

            </form>

        </div>

    </div>

</div>

<?php include("includes/footer.php"); ?>