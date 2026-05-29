<?php
require_once "config/db.php";

header("Content-Type: application/json");

$token = trim($_GET["token"] ?? "");

if ($token === "") {
    echo json_encode([
        "success" => false,
        "message" => "Invalid link."
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

$stmt = $conn->prepare("
    SELECT 
        gcm.id,
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
            ?: strtolower($row["role"] ?? "user") . "_user";

        $senderRole = ucfirst($row["role"] ?? "Member");
    }

    $messages[] = [
        "id" => (int)$row["id"],
        "sender_name" => $senderName,
        "sender_role" => $senderRole,
        "message" => $row["message"],
        "created_at" => $row["created_at"],
        "is_guest" => $isGuest
    ];
}

$messages = array_reverse($messages);

echo json_encode([
    "success" => true,
    "messages" => $messages
]);
exit;