<?php

include("../db/connection.php");

$id = $_GET['id'];

$sql = "DELETE FROM Operators
        WHERE operator_id = '$id'";

mysqli_query($conn, $sql);

header("Location: ../admin/users.php");

?>