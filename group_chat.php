<?php
session_start();
require_once "config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$tenant_id = (int)$_SESSION["tenant_id"];
$role = $_SESSION["role"] ?? "member";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";
$name = $_SESSION["name"] ?? "User";

$displayName = $username ?: ($member_code ?: $name);

$membersStmt = $conn->prepare("
    SELECT id, first_name, last_name, username, member_code, role, status
    FROM users
    WHERE tenant_id = ?
    AND status = 'active'
    ORDER BY 
        CASE 
            WHEN role = 'owner' THEN 1
            WHEN role = 'admin' THEN 2
            ELSE 3
        END,
        username ASC,
        member_code ASC,
        first_name ASC
");
$membersStmt->bind_param("i", $tenant_id);
$membersStmt->execute();
$members = $membersStmt->get_result();

$totalMembers = $members->num_rows;

/*
|--------------------------------------------------------------------------
| Public Guest Chat Link
|--------------------------------------------------------------------------
*/
$publicStmt = $conn->prepare("
    SELECT tenant_code, public_chat_token, public_chat_enabled
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$publicStmt->bind_param("i", $tenant_id);
$publicStmt->execute();
$publicData = $publicStmt->get_result()->fetch_assoc();

$public_chat_token = $publicData["public_chat_token"] ?? "";
$public_chat_enabled = (int)($publicData["public_chat_enabled"] ?? 1);

if ($public_chat_token === "") {
    $public_chat_token = bin2hex(random_bytes(16));

    $updateToken = $conn->prepare("
        UPDATE tenants
        SET public_chat_token = ?
        WHERE id = ?
    ");
    $updateToken->bind_param("si", $public_chat_token, $tenant_id);
    $updateToken->execute();
}

$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$host = $_SERVER["HTTP_HOST"];
$basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\");
$publicChatLink = $scheme . "://" . $host . $basePath . "/public_chat.php?token=" . urlencode($public_chat_token);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Group Chat</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/css/app.css?v=<?php echo time(); ?>">

    <style>
        body {
            background:
                radial-gradient(circle at 8% 10%, rgba(216, 169, 40, 0.34), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(15, 107, 79, 0.28), transparent 32%),
                radial-gradient(circle at 50% 90%, rgba(216, 169, 40, 0.20), transparent 34%),
                linear-gradient(135deg, #fff4c7 0%, #fbf7ed 32%, #e7f7ef 72%, #dff5e9 100%) !important;
            background-attachment: fixed;
        }

        .app-main {
            background:
                radial-gradient(circle at 20% 15%, rgba(216,169,40,0.13), transparent 30%),
                radial-gradient(circle at 88% 30%, rgba(15,107,79,0.10), transparent 34%);
        }

        .app-content {
            position: relative;
        }

        .app-content::before {
            content: "R";
            position: fixed;
            right: 40px;
            bottom: 34px;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: linear-gradient(145deg, rgba(248,216,106,0.45), rgba(216,169,40,0.24));
            color: rgba(74,53,4,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 82px;
            font-weight: 900;
            transform: rotate(-14deg);
            pointer-events: none;
            z-index: 0;
        }

        .app-content > * {
            position: relative;
            z-index: 1;
        }

        .chat-hero {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.34), transparent 34%),
                radial-gradient(circle at bottom left, rgba(255,255,255,0.12), transparent 32%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            border-radius: 32px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 30px 80px rgba(7, 63, 47, 0.32);
            position: relative;
            overflow: hidden;
        }

        .chat-hero::after {
            content: "R";
            position: absolute;
            right: 34px;
            top: 24px;
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: #4a3504;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 900;
            opacity: 0.25;
            transform: rotate(-12deg);
        }

        .chat-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .chat-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            color: rgba(255,255,255,0.86);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 16px;
            position: relative;
            z-index: 2;
        }

        .chat-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .chat-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .chat-hero-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 700px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .public-chat-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.28), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            border-radius: 30px;
            padding: 24px;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
            backdrop-filter: blur(18px);
            margin-bottom: 24px;
        }

        .public-card-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
            margin-bottom: 6px;
        }

        .public-chat-link {
            background: #fffdf7;
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 18px;
            padding: 15px;
            font-size: 13px;
            word-break: break-all;
            color: #4b3a12;
        }

        .chat-layout {
            display: grid;
            grid-template-columns: 1fr 310px;
            gap: 22px;
            height: calc(100vh - 245px);
            min-height: 590px;
        }

        .chat-card {
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.12), transparent 30%),
                linear-gradient(135deg, rgba(255,255,255,0.95), rgba(238,247,241,0.92));
            border: 1px solid rgba(255,255,255,0.88);
            border-radius: 30px;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            backdrop-filter: blur(18px);
        }

        .chat-header {
            padding: 19px 21px;
            border-bottom: 1px solid rgba(16,36,31,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.18), transparent 34%),
                rgba(255,255,255,0.76);
        }

        .chat-title {
            font-weight: 900;
            font-size: 18px;
            color: #10241f;
            letter-spacing: -0.03em;
        }

        .chat-subtitle {
            font-size: 13px;
            color: #667085;
            margin-top: 2px;
        }

        .participant-count-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #fff8df;
            color: #7a5a09;
            border: 1px solid rgba(216,169,40,0.28);
            border-radius: 999px;
            padding: 7px 11px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .participant-count-pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #0f6b4f;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 22px;
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.10), transparent 28%),
                radial-gradient(circle at bottom right, rgba(15,107,79,0.10), transparent 32%),
                linear-gradient(135deg, #fbf7ed, #edf7f1);
        }

        .message-row {
            display: flex;
            margin-bottom: 14px;
        }

        .message-row.mine {
            justify-content: flex-end;
        }

        .message-bubble {
            max-width: 72%;
            background: rgba(255,255,255,0.96);
            border: 1px solid rgba(16,36,31,0.10);
            border-radius: 22px;
            padding: 12px 14px;
            box-shadow: 0 10px 24px rgba(16,36,31,0.08);
        }

        .message-bubble.guest {
            background: #fff8df;
            border-color: rgba(216,169,40,0.48);
        }

        .message-row.mine .message-bubble {
            background: linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            border-color: #073f2f;
            box-shadow: 0 14px 30px rgba(15,107,79,0.24);
        }

        .message-meta {
            font-size: 12px;
            color: #667085;
            margin-bottom: 5px;
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .message-row.mine .message-meta {
            color: rgba(255,255,255,0.72);
        }

        .message-text {
            font-size: 14px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .chat-form {
            padding: 16px;
            border-top: 1px solid rgba(16,36,31,0.08);
            background: rgba(255,255,255,0.94);
        }

        .chat-input-wrap {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .chat-input {
            resize: none;
            min-height: 50px;
            max-height: 120px;
            border-radius: 18px;
            background: #ffffff;
        }

        .send-btn {
            min-height: 50px;
            padding-left: 22px;
            padding-right: 22px;
            border-radius: 18px;
        }

        .typing-hint {
            font-size: 12px;
            color: #667085;
            margin-top: 8px;
        }

        .members-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.16), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88);
            border-radius: 30px;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14);
            padding: 20px;
            overflow-y: auto;
            backdrop-filter: blur(18px);
        }

        .members-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
            margin-bottom: 4px;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(16,36,31,0.08);
        }

        .member-item:last-child {
            border-bottom: 0;
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 900;
            flex: 0 0 auto;
            box-shadow: 0 12px 24px rgba(15,107,79,0.22);
        }

        .member-name {
            font-size: 14px;
            font-weight: 900;
            color: #10241f;
            word-break: break-word;
        }

        .member-role {
            font-size: 12px;
            color: #667085;
            margin-top: 2px;
        }

        .you-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 7px;
            border-radius: 999px;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.30);
            color: #7a5a09;
            font-size: 11px;
            font-weight: 900;
            margin-left: 4px;
        }

        @media (max-width: 1000px) {
            .chat-layout {
                grid-template-columns: 1fr;
                height: auto;
            }

            .chat-card {
                height: 70vh;
                min-height: 540px;
            }

            .members-card {
                max-height: 340px;
            }

            .message-bubble {
                max-width: 88%;
            }
        }

        @media (max-width: 560px) {
            .chat-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .chat-hero-title {
                font-size: 27px;
            }

            .chat-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }

            .chat-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .chat-input-wrap {
                gap: 8px;
            }

            .send-btn {
                padding-left: 14px;
                padding-right: 14px;
            }
        }
    </style>
</head>
<body>

<div class="app-shell">

    <?php include "includes/sidebar.php"; ?>

    <main class="app-main">
        <div class="app-topbar">
            <div>
                <div class="app-topbar-title">Group Chat</div>
                <div class="app-topbar-subtitle">
                    Your private stokvel conversation space.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="chat-hero">
                <div class="chat-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="chat-hero-title">
                    Talk money, updates, and savings together
                </div>

                <p class="chat-hero-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    This is your stokvel group conversation. Registered members can chat freely,
                    while guests can preview and send limited messages before joining.
                </p>
            </div>

            <?php if ($role === "owner" || $role === "admin"): ?>
                <div class="public-chat-card">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <div class="public-card-title">Public Guest Chat Link</div>
                            <p class="text-muted mb-0" style="font-size: 13px;">
                                Share this link with people who are not registered yet. They can view the chat
                                and send up to 5 guest messages before registering.
                            </p>
                        </div>

                        <span class="badge <?php echo $public_chat_enabled ? 'badge-approved' : 'badge-rejected'; ?>">
                            <?php echo $public_chat_enabled ? 'Enabled' : 'Disabled'; ?>
                        </span>
                    </div>

                    <div class="public-chat-link mt-3 mb-3" id="publicChatLink">
                        <?php echo htmlspecialchars($publicChatLink); ?>
                    </div>

                    <button type="button" class="btn btn-dark btn-sm" onclick="copyPublicChatLink()">
                        Copy Public Link
                    </button>

                    <a href="<?php echo htmlspecialchars($publicChatLink); ?>" target="_blank" class="btn btn-outline-dark btn-sm">
                        Open Guest View
                    </a>
                </div>
            <?php endif; ?>

            <div class="chat-layout">

                <div class="chat-card">
                    <div class="chat-header">
                        <div>
                            <div class="chat-title">
                                Money Circle Conversation
                            </div>
                            <div class="chat-subtitle">
                                Messages from registered members and limited guest visitors.
                            </div>
                        </div>

                        <span class="participant-count-pill">
                            <?php echo (int)$totalMembers; ?> active
                        </span>
                    </div>

                    <div id="chatMessages" class="chat-messages">
                        <div class="text-center text-muted py-4">
                            Loading messages...
                        </div>
                    </div>

                    <form id="chatForm" class="chat-form" autocomplete="off">
                        <div class="chat-input-wrap">
                            <textarea 
                                id="messageInput"
                                class="form-control chat-input"
                                placeholder="Type a message..."
                                rows="1"
                                maxlength="1000"
                                required
                            ></textarea>

                            <button id="sendButton" type="submit" class="btn btn-dark send-btn">
                                Send
                            </button>
                        </div>

                        <div class="typing-hint">
                            Press Enter to send. Use Shift + Enter for a new line.
                        </div>
                    </form>
                </div>

                <div class="members-card">
                    <div class="members-title">Participants</div>
                    <p class="text-muted mb-3" style="font-size: 13px;">
                        Active users in this stokvel.
                    </p>

                    <?php if ($members->num_rows > 0): ?>
                        <?php while ($member = $members->fetch_assoc()): ?>
                            <?php
                                $displayUsername = $member["username"] ?: $member["member_code"] ?: strtolower($member["role"]) . $member["id"];
                                $initials = strtoupper(substr($displayUsername, 0, 2));
                            ?>
                            <div class="member-item">
                                <div class="member-avatar">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>

                                <div>
                                    <div class="member-name">
                                        <?php echo htmlspecialchars($displayUsername); ?>

                                        <?php if ((int)$member["id"] === $user_id): ?>
                                            <span class="you-pill">You</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="member-role">
                                        <?php echo ucfirst(htmlspecialchars($member["role"])); ?>

                                        <?php if (!empty($member["member_code"])): ?>
                                            · <?php echo htmlspecialchars($member["member_code"]); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-muted">
                            No active participants found.
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    </main>

</div>

<script>
const chatMessages = document.getElementById("chatMessages");
const chatForm = document.getElementById("chatForm");
const messageInput = document.getElementById("messageInput");
const sendButton = document.getElementById("sendButton");

let lastRenderedHTML = "";
let isSending = false;

function escapeHTML(value) {
    const div = document.createElement("div");
    div.innerText = value ?? "";
    return div.innerHTML;
}

function formatMessageTime(value) {
    const date = new Date(value.replace(" ", "T"));

    if (isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString("en-ZA", {
        day: "2-digit",
        month: "short",
        hour: "2-digit",
        minute: "2-digit"
    });
}

function renderMessages(messages) {
    if (!Array.isArray(messages) || messages.length === 0) {
        const emptyHTML = `
            <div class="text-center text-muted py-4">
                No messages yet. Start the conversation.
            </div>
        `;

        if (lastRenderedHTML !== emptyHTML) {
            chatMessages.innerHTML = emptyHTML;
            lastRenderedHTML = emptyHTML;
        }

        return;
    }

    let html = "";

    messages.forEach(function (msg) {
        const mineClass = msg.is_mine ? "mine" : "";
        const guestClass = msg.is_guest ? "guest" : "";
        const sender = escapeHTML(msg.sender_name);
        const role = escapeHTML(msg.sender_role);
        const message = escapeHTML(msg.message);
        const time = escapeHTML(formatMessageTime(msg.created_at));

        html += `
            <div class="message-row ${mineClass}">
                <div class="message-bubble ${guestClass}">
                    <div class="message-meta">
                        <strong>${sender}</strong>
                        <span>·</span>
                        <span>${role}</span>
                        <span>·</span>
                        <span>${time}</span>
                    </div>
                    <div class="message-text">${message}</div>
                </div>
            </div>
        `;
    });

    if (html !== lastRenderedHTML) {
        const wasNearBottom = chatMessages.scrollTop + chatMessages.clientHeight >= chatMessages.scrollHeight - 120;

        chatMessages.innerHTML = html;
        lastRenderedHTML = html;

        if (wasNearBottom) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }
}

function fetchMessages() {
    fetch("chat_fetch.php", {
        method: "GET",
        headers: {
            "Accept": "application/json"
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderMessages(data.messages);
        }
    })
    .catch(() => {
        // Keep current messages visible if fetch fails.
    });
}

function sendMessage() {
    const message = messageInput.value.trim();

    if (!message || isSending) {
        return;
    }

    isSending = true;
    sendButton.disabled = true;

    const formData = new FormData();
    formData.append("message", message);

    fetch("chat_send.php", {
        method: "POST",
        body: formData,
        headers: {
            "Accept": "application/json"
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageInput.value = "";
            messageInput.style.height = "50px";
            fetchMessages();

            setTimeout(function () {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }, 200);
        } else {
            alert(data.message || "Could not send message.");
        }
    })
    .catch(() => {
        alert("Could not send message. Please try again.");
    })
    .finally(() => {
        isSending = false;
        sendButton.disabled = false;
        messageInput.focus();
    });
}

chatForm.addEventListener("submit", function (event) {
    event.preventDefault();
    sendMessage();
});

messageInput.addEventListener("keydown", function (event) {
    if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
});

messageInput.addEventListener("input", function () {
    this.style.height = "50px";
    this.style.height = Math.min(this.scrollHeight, 120) + "px";
});

function copyPublicChatLink() {
    const linkBox = document.getElementById("publicChatLink");

    if (!linkBox) {
        return;
    }

    const text = linkBox.innerText.trim();

    navigator.clipboard.writeText(text).then(function () {
        alert("Public chat link copied.");
    }).catch(function () {
        alert("Could not copy link. Please copy it manually.");
    });
}

fetchMessages();
setInterval(fetchMessages, 2500);
</script>

</body>
</html>