<?php

include("../includes/auth_check.php");
include("../includes/header.php");
include("../includes/navbar.php");
include("../db/connection.php");

$id = $_GET['id'];

$sql = "SELECT * FROM Operators
        WHERE operator_id='$id'";

$result = mysqli_query($conn, $sql);

$user = mysqli_fetch_assoc($result);

?>

<div class="main-content">

    <div class="card p-4 shadow">

        <h2>Edit User</h2>

        <form action="../scripts/update_user.php"
              method="POST">

            <input type="hidden"
                   name="id"
                   value="<?php echo $user['operator_id']; ?>">

            <div class="mb-3">
                <label>Username</label>

                <input type="text"
                       name="username"
                       class="form-control"
                       value="<?php echo $user['username']; ?>">
            </div>

            <div class="mb-3">
                <label>Full Name</label>

                <input type="text"
                       name="full_name"
                       class="form-control"
                       value="<?php echo $user['full_name']; ?>">
            </div>

            <div class="mb-3">
                <label>Role</label>

                <input type="text"
                       name="role"
                       class="form-control"
                       value="<?php echo $user['role']; ?>">
            </div>

            <button type="submit"
                    class="btn btn-success">

                Update User

            </button>

        </form>

    </div>

</div>

<?php include("../includes/footer.php"); ?>