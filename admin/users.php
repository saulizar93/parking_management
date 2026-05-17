<?php

    include("../includes/auth_check.php");

    if($_SESSION['role'] != 'admin'){
        header("Location: dashboard.php");
        exit();
    }

?>

<?php
include("../includes/auth_check.php");
include("../includes/header.php");
include("../includes/navbar.php");
include("../db/connection.php");
?>

<div class="main-content">

    <h2 class="mb-4">Manage Users</h2>

    <!-- ADD USER FORM -->

    <div class="card p-4 mb-4 shadow">

        <h4>Add New User</h4>

        <form action="../scripts/create_user.php" method="POST">

            <div class="mb-3">
                <label>Username</label>

                <input type="text"
                       name="username"
                       class="form-control"
                       required>
            </div>

            <div class="mb-3">
                <label>Password</label>

                <input type="password"
                       name="password"
                       class="form-control"
                       required>
            </div>

            <div class="mb-3">
                <label>Full Name</label>

                <input type="text"
                       name="full_name"
                       class="form-control"
                       required>
            </div>

            <div class="mb-3">
                <label>Role</label>

                <select name="role"
                        class="form-control">

                    <option value="admin">Admin</option>
                    <option value="operator">Operator</option>

                </select>
            </div>

            <button type="submit"
                    class="btn btn-success">

                Add User

            </button>

        </form>

    </div>

    <!-- USERS TABLE -->

    <table class="table table-bordered table-striped">

        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>

            <?php

            $sql = "SELECT * FROM Operators";
            $result = mysqli_query($conn, $sql);

            while($row = mysqli_fetch_assoc($result)){

                echo "
                <tr>
                    <td>{$row['operator_id']}</td>
                    <td>{$row['username']}</td>
                    <td>{$row['full_name']}</td>
                    <td>{$row['role']}</td>

                    <td>

                        <a href='edit_user.php?id={$row['operator_id']}'
                           class='btn btn-primary btn-sm'>

                           Edit

                        </a>

                        <a href='../scripts/delete_user.php?id={$row['operator_id']}'
                           class='btn btn-danger btn-sm'
                           onclick=\"return confirm('Delete this user?')\">

                           Delete

                        </a>

                    </td>
                </tr>
                ";
            }

            ?>

        </tbody>

    </table>

</div>

<?php include("../includes/footer.php"); ?>