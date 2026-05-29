<?php
session_start();
require_once "config/db.php";

$token = trim($_GET["token"] ?? "");

if ($token === "") {
    die("Invalid public chat link.");
}

$tenantStmt = $conn->prepare("
    SELECT id, stokvel_name, tenant_code, public_chat_enabled
    FROM tenants
    WHERE public_chat_token = ?
    LIMIT 1
");
$tenantStmt->bind_param("s", $token);
$tenantStmt->execute();
$tenant = $tenantStmt->get_result()->fetch_assoc();

if (!$tenant) {
    die("This public chat link is invalid.");
}

if ((int)$tenant["public_chat_enabled"] !== 1) {
    die("This public chat link is currently disabled.");
}

$tenant_id = (int)$tenant["id"];
$stokvel_name = $tenant["stokvel_name"];
$tenant_code = $tenant["tenant_code"];

$registerLink = "member_register.php?tenant=" . urlencode($tenant_code);
$loginLink = "login.php";

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

$maxGuestMessages = 5;
$totalGuestMessages = (int)($countData["total_messages"] ?? 0);
$messagesLeft = max(0, $maxGuestMessages - $totalGuestMessages);
$limitReached = $messagesLeft <= 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($stokvel_name); ?> Public Chat</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        body {
            margin: 0;
            background: #f5f7fb;
            font-family: Arial, sans-serif;
            color: #111827;
        }

        .public-shell {
            min-height: 100vh;
            padding: 24px;
        }

        .public-wrap {
            max-width: 980px;
            margin: 0 auto;
        }

        .public-header {
            background: #111827;
            color: #ffffff;
            border-radius: 22px;
            padding: 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .public-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .public-subtitle {
            font-size: 14px;
            color: #cbd5e1;
        }

        .chat-card {
            background: #ffffff;
            border-radius: 22px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .chat-top {
            padding: 18px 22px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .chat-top-title {
            font-weight: 700;
            font-size: 16px;
        }

        .chat-top-note {
            font-size: 13px;
            color: #6b7280;
        }

        .chat-messages {
            height: 560px;
            overflow-y: auto;
            padding: 22px;
            background: #f8fafc;
        }

        .message-row {
            display: flex;
            margin-bottom: 14px;
        }

        .message-bubble {
            max-width: 75%;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 12px 14px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
        }

        .message-bubble.guest {
            border-color: #fde68a;
            background: #fffbeb;
        }

        .message-meta {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .message-text {
            font-size: 14px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .guest-form {
            padding: 18px 22px;
            border-top: 1px solid #e5e7eb;
            background: #ffffff;
        }

        .guest-limit {
            font-size: 13px;
            color: #6b7280;
        }

        .guest-limit strong {
            color: #111827;
        }

        .btn {
            border-radius: 12px;
        }

        .form-control {
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
        }

        .form-control:focus {
            box-shadow: none;
            border-color: #111827;
        }

        @media (max-width: 760px) {
            .public-shell {
                padding: 14px;
            }

            .public-header {
                display: block;
            }

            .public-header .btn {
                margin-top: 12px;
                width: 100%;
            }

            .chat-top {
                display: block;
            }

            .chat-top .badge {
                margin-top: 10px;
            }

            .chat-messages {
                height: 520px;
            }

            .message-bubble {
                max-width: 92%;
            }
        }
    </style>
</head>
<body>

<div class="public-shell">
    <div class="public-wrap">

        <div class="public-header">
            <div>
                <div class="public-title">
                    <?php echo htmlspecialchars($stokvel_name); ?> Group Chat
                </div>
                <div class="public-subtitle">
                    You are viewing as a guest. You can send up to 5 messages before registering.
                </div>
            </div>

            <div>
                <a href="<?php echo htmlspecialchars($registerLink); ?>" class="btn btn-light">
                    Register to Join
                </a>

                <a href="<?php echo htmlspecialchars($loginLink); ?>" class="btn btn-outline-light">
                    Login
                </a>
            </div>
        </div>

        <div class="chat-card">
            <div class="chat-top">
                <div>
                    <div class="chat-top-title">Guest Chat Preview</div>
                    <div class="chat-top-note">
                        Join the conversation temporarily. Register to become a full member.
                    </div>
                </div>

                <span class="badge bg-dark" id="messagesLeftBadge">
                    <?php echo (int)$messagesLeft; ?> guest message<?php echo $messagesLeft === 1 ? "" : "s"; ?> left
                </span>
            </div>

            <div id="chatMessages" class="chat-messages">
                <div class="text-center text-muted py-4">
                    Loading messages...
                </div>
            </div>

            <div class="guest-form">
                <?php if ($limitReached): ?>
                    <div class="alert alert-warning mb-3">
                        You have reached the 5 message guest limit. Please register to continue participating.
                    </div>
                <?php endif; ?>

                <form id="guestChatForm" autocomplete="off">
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <input 
                                type="text"
                                id="guestName"
                                class="form-control"
                                placeholder="Your name"
                                maxlength="100"
                                <?php echo $limitReached ? "disabled" : ""; ?>
                                required
                            >
                        </div>

                        <div class="col-md-8">
                            <textarea
                                id="guestMessage"
                                class="form-control"
                                rows="1"
                                maxlength="1000"
                                placeholder="<?php echo $limitReached ? 'Register to continue chatting' : 'Type your message...'; ?>"
                                <?php echo $limitReached ? "disabled" : ""; ?>
                                required
                            ></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <div class="guest-limit" id="guestLimitText">
                            <?php if ($limitReached): ?>
                                Guest limit reached. Register to continue.
                            <?php else: ?>
                                You have <strong><?php echo (int)$messagesLeft; ?></strong> guest messages left.
                            <?php endif; ?>
                        </div>

                        <div>
                            <?php if ($limitReached): ?>
                                <a href="<?php echo htmlspecialchars($registerLink); ?>" class="btn btn-dark">
                                    Register to Continue
                                </a>
                            <?php else: ?>
                                <button type="submit" id="guestSendButton" class="btn btn-dark">
                                    Send Message
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
const chatMessages = document.getElementById("chatMessages");
const token = <?php echo json_encode($token); ?>;
const registerLink = <?php echo json_encode($registerLink); ?>;

const guestChatForm = document.getElementById("guestChatForm");
const guestName = document.getElementById("guestName");
const guestMessage = document.getElementById("guestMessage");
const guestSendButton = document.getElementById("guestSendButton");
const guestLimitText = document.getElementById("guestLimitText");
const messagesLeftBadge = document.getElementById("messagesLeftBadge");

let lastRenderedHTML = "";
let isSending = false;

const savedGuestName = localStorage.getItem("stokvel_guest_name");
if (guestName && savedGuestName) {
    guestName.value = savedGuestName;
}

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
                No messages yet.
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
        const sender = escapeHTML(msg.sender_name);
        const role = escapeHTML(msg.sender_role);
        const message = escapeHTML(msg.message);
        const time = escapeHTML(formatMessageTime(msg.created_at));
        const guestClass = msg.is_guest ? "guest" : "";

        html += `
            <div class="message-row">
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
    fetch("public_chat_fetch.php?token=" + encodeURIComponent(token), {
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
    .catch(() => {});
}

function lockGuestChat() {
    if (guestName) guestName.disabled = true;
    if (guestMessage) {
        guestMessage.disabled = true;
        guestMessage.placeholder = "Register to continue chatting";
    }

    if (guestSendButton) {
        guestSendButton.outerHTML = `<a href="${registerLink}" class="btn btn-dark">Register to Continue</a>`;
    }

    if (guestLimitText) {
        guestLimitText.innerHTML = "Guest limit reached. Register to continue.";
    }

    if (messagesLeftBadge) {
        messagesLeftBadge.textContent = "Guest limit reached";
    }
}

function updateGuestLimit(messagesLeft, limitReached) {
    if (limitReached) {
        lockGuestChat();
        return;
    }

    if (guestLimitText) {
        guestLimitText.innerHTML = `You have <strong>${messagesLeft}</strong> guest message${messagesLeft === 1 ? "" : "s"} left.`;
    }

    if (messagesLeftBadge) {
        messagesLeftBadge.textContent = `${messagesLeft} guest message${messagesLeft === 1 ? "" : "s"} left`;
    }
}

function sendGuestMessage() {
    if (!guestName || !guestMessage || isSending) {
        return;
    }

    const name = guestName.value.trim();
    const message = guestMessage.value.trim();

    if (!name) {
        alert("Please enter your name.");
        guestName.focus();
        return;
    }

    if (!message) {
        alert("Please type a message.");
        guestMessage.focus();
        return;
    }

    localStorage.setItem("stokvel_guest_name", name);

    isSending = true;

    if (guestSendButton) {
        guestSendButton.disabled = true;
    }

    const formData = new FormData();
    formData.append("token", token);
    formData.append("guest_name", name);
    formData.append("message", message);

    fetch("public_chat_send.php", {
        method: "POST",
        body: formData,
        headers: {
            "Accept": "application/json"
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            guestMessage.value = "";
            fetchMessages();
            updateGuestLimit(data.messages_left, data.limit_reached);
            setTimeout(function () {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }, 200);
        } else {
            alert(data.message || "Could not send message.");

            if (data.limit_reached) {
                lockGuestChat();
            }
        }
    })
    .catch(() => {
        alert("Could not send message. Please try again.");
    })
    .finally(() => {
        isSending = false;

        if (guestSendButton) {
            guestSendButton.disabled = false;
        }

        if (guestMessage && !guestMessage.disabled) {
            guestMessage.focus();
        }
    });
}

if (guestChatForm) {
    guestChatForm.addEventListener("submit", function (event) {
        event.preventDefault();
        sendGuestMessage();
    });
}

if (guestMessage) {
    guestMessage.addEventListener("keydown", function (event) {
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            sendGuestMessage();
        }
    });
}

fetchMessages();
setInterval(fetchMessages, 3000);
</script>

</body>
</html>