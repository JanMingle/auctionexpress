<?php
session_start();
require_once "config/db.php";

header("Content-Type: application/json");

$token = trim($_POST["token"] ?? "");
$guest_name = trim($_POST["guest_name"] ?? "");
$message = trim($_POST["message"] ?? "");

$maxGuestMessages = 5;

if ($token === "") {
    echo json_encode([
        "success" => false,
        "message" => "Invalid chat link."
    ]);
    exit;
}

if ($guest_name === "") {
    echo json_encode([
        "success" => false,
        "message" => "Please enter your name before sending a message."
    ]);
    exit;
}

if (mb_strlen($guest_name) > 100) {
    echo json_encode([
        "success" => false,
        "message" => "Name is too long."
    ]);
    exit;
}

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

$tenantStmt = $conn->prepare("
    SELECT id
    FROM tenants
    WHERE public_chat_token = ?
    AND public_chat_enabled = 1
    LIMIT 1
");
$tenantStmt->bind_param("s", $token);
$tenantStmt->execute();
$tenant = $tenantStmt->get_result()->fetch_assoc();

if (!$tenant) {
    echo json_encode([
        "success" => false,
        "message" => "This public chat link is invalid or disabled."
    ]);
    exit;
}

$tenant_id = (int)$tenant["id"];

if (empty($_SESSION["guest_chat_session_id"])) {
    $_SESSION["guest_chat_session_id"] = bin2hex(random_bytes(16));
}

$guest_session_id = $_SESSION["guest_chat_session_id"];

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total_messages
    FROM group_chat_messages
    WHERE tenant_id = ?
    AND sender_type = 'guest'
    AND guest_session_id = ?
    AND is_deleted = 0
");
$countStmt->bind_param("is", $tenant_id, $guest_session_id);
$countStmt->execute();
$countData = $countStmt->get_result()->fetch_assoc();

$totalGuestMessages = (int)($countData["total_messages"] ?? 0);

if ($totalGuestMessages >= $maxGuestMessages) {
    echo json_encode([
        "success" => false,
        "limit_reached" => true,
        "message" => "You have reached the 5 message guest limit. Please register to continue participating."
    ]);
    exit;
}

$sender_type = "guest";
$user_id = null;

$stmt = $conn->prepare("
    INSERT INTO group_chat_messages
    (
        tenant_id,
        user_id,
        sender_type,
        guest_name,
        guest_session_id,
        message
    )
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "iissss",
    $tenant_id,
    $user_id,
    $sender_type,
    $guest_name,
    $guest_session_id,
    $message
);

if ($stmt->execute()) {
    $messagesLeft = $maxGuestMessages - ($totalGuestMessages + 1);

    echo json_encode([
        "success" => true,
        "message" => "Message sent.",
        "messages_left" => $messagesLeft,
        "limit_reached" => $messagesLeft <= 0
    ]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Could not send message."
]);
exit;