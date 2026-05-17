<?php
if(session_status() == PHP_SESSION_NONE){
    session_start();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>ACME Parking</title>

    <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <link rel="stylesheet"
    href="/parking_management/css/style.css">
</head>

<body>

<header class="banner">
    <h1>ACME Parking</h1>
</header>

<div class="container">