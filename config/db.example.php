<?php
$host = "localhost";
$user = "DATABASE_USER";
$password = "DATABASE_PASSWORD";
$database = "DATABASE_NAME";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed.");
}