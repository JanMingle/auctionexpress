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

$stmt = $conn->prepare("
    SELECT 
        gcm.id,
        gcm.user_id,
        gcm.sender_type,
        gcm.guest_name,
        gcm.message,
        gcm.created_at,
        users.first_name,
        users.last_name,
        users.role,
        users.username,
        users.member_code
    FROM group_chat_messages gcm
    LEFT JOIN users ON users.id = gcm.user_id
    WHERE gcm.tenant_id = ?
    AND gcm.is_deleted = 0
    ORDER BY gcm.created_at DESC, gcm.id DESC
    LIMIT 80
");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];

while ($row = $result->fetch_assoc()) {
    $isGuest = $row["sender_type"] === "guest";

    if ($isGuest) {
        $senderName = $row["guest_name"] ?: "Guest";
        $senderRole = "Guest";
    } else {
        $senderName = $row["username"] 
            ?: $row["member_code"] 
            ?: strtolower($row["role"] ?? "user") . ($row["user_id"] ?? "");

        $senderRole = ucfirst($row["role"] ?? "Member");
    }

    $messages[] = [
        "id" => (int)$row["id"],
        "user_id" => $row["user_id"] ? (int)$row["user_id"] : null,
        "sender_name" => $senderName,
        "sender_role" => $senderRole,
        "message" => $row["message"],
        "created_at" => $row["created_at"],
        "is_mine" => !$isGuest && (int)$row["user_id"] === $user_id,
        "is_guest" => $isGuest
    ];
}

$messages = array_reverse($messages);

echo json_encode([
    "success" => true,
    "messages" => $messages
]);
exit;