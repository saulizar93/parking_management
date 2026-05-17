<?php

include("../db/connection.php");

$username = $_POST['username'];
$password = $_POST['password'];
$full_name = $_POST['full_name'];
$role = $_POST['role'];

$sql = "INSERT INTO Operators
        (username, password, full_name, role)

        VALUES

        ('$username', '$password', '$full_name', '$role')";

mysqli_query($conn, $sql);

header("Location: ../admin/users.php");

?>