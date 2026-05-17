<?php

session_start();

include("../db/connection.php");

$username = $_POST['username'];
$password = $_POST['password'];

$sql = "SELECT * FROM Operators
        WHERE username='$username'
        AND password='$password'";

$result = mysqli_query($conn, $sql);

if(mysqli_num_rows($result) == 1){

    $user = mysqli_fetch_assoc($result);

    $_SESSION['username'] = $user['username'];  
    $_SESSION['role'] = $user['role'];

    header("Location: ../admin/dashboard.php");
    exit();

} else {

    header("Location: ../index.php?error=1");
    exit();

}

?>