<?php

include("../db/connection.php");

$id = $_POST['id'];

$username = $_POST['username'];
$full_name = $_POST['full_name'];
$role = $_POST['role'];

$sql = "UPDATE Operators

        SET

        username='$username',
        full_name='$full_name',
        role='$role'

        WHERE operator_id='$id'";

mysqli_query($conn, $sql);

header("Location: ../admin/users.php");

?>