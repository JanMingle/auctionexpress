<?php
session_start();

unset($_SESSION["system_owner_id"]);
unset($_SESSION["system_owner_name"]);
unset($_SESSION["system_owner_email"]);

header("Location: login.php");
exit;