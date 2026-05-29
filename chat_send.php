<?php
session_start();
require_once "config/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION["user_id"], $_SESSION["tenant_id"])) {
    echo json_encode([
        "success" => false,
        "message" => "Not logged in."
    ]);
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$tenant_id = (int)$_SESSION["tenant_id"];
$message = trim($_POST["message"] ?? "");

if ($message === "") {
    echo json_encode([
        "success" => false,
        "message" => "Message cannot be empty."
    ]);
    exit;
}

if (mb_strlen($message) > 1000) {
    echo json_encode([
        "success" => false,
        "message" => "Message is too long. Maximum is 1000 characters."
    ]);
    exit;
}

$userCheck = $conn->prepare("
    SELECT id
    FROM users
    WHERE id = ?
    AND tenant_id = ?
    AND status = 'active'
    LIMIT 1
");
$userCheck->bind_param("ii", $user_id, $tenant_id);
$userCheck->execute();
$userExists = $userCheck->get_result()->fetch_assoc();

if (!$userExists) {
    echo json_encode([
        "success" => false,
        "message" => "Your account is not active."
    ]);
    exit;
}

$sender_type = "registered";

$stmt = $conn->prepare("
    INSERT INTO group_chat_messages
    (tenant_id, user_id, sender_type, message)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("iiss", $tenant_id, $user_id, $sender_type, $message);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Message sent."
    ]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Could not send message."
]);
exit;