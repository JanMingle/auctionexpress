<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "owner" && $_SESSION["role"] !== "admin") {
    header("Location: ../users/dashboard.php");
    exit;
}

$tenant_id = (int)$_SESSION["tenant_id"];
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$name = $_SESSION["name"] ?? "Admin";
$displayName = $username ?: $name;
$search = trim($_GET["search"] ?? "");
$searchLike = "%" . $search . "%";

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $member_id = (int)($_POST["member_id"] ?? 0);
    $action = $_POST["action"] ?? "";

    if ($member_id <= 0) {
        $error = "Invalid member selected.";
    } else {
        if ($action === "approve") {
            $stmt = $conn->prepare("
                UPDATE users
                SET status = 'active'
                WHERE id = ?
                AND tenant_id = ?
                AND role = 'member'
            ");
            $stmt->bind_param("ii", $member_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Member approved successfully.";
            } else {
                $error = "Could not approve member.";
            }
        }

        if ($action === "suspend") {
            $stmt = $conn->prepare("
                UPDATE users
                SET status = 'suspended'
                WHERE id = ?
                AND tenant_id = ?
                AND role = 'member'
            ");
            $stmt->bind_param("ii", $member_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Member suspended successfully.";
            } else {
                $error = "Could not suspend member.";
            }
        }

        if ($action === "activate") {
            $stmt = $conn->prepare("
                UPDATE users
                SET status = 'active'
                WHERE id = ?
                AND tenant_id = ?
                AND role = 'member'
            ");
            $stmt->bind_param("ii", $member_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Member activated successfully.";
            } else {
                $error = "Could not activate member.";
            }
        }

        if ($action === "delete") {
            $stmt = $conn->prepare("
                DELETE FROM users
                WHERE id = ?
                AND tenant_id = ?
                AND role = 'member'
            ");
            $stmt->bind_param("ii", $member_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Member deleted successfully.";
            } else {
                $error = "Could not delete member. This member may already have savings or withdrawals linked to their account.";
            }
        }
    }
}

if ($search !== "") {
    $membersStmt = $conn->prepare("
        SELECT 
            id, 
            first_name, 
            last_name, 
            email, 
            phone,
            bank_name,
            bank_account_holder,
            bank_account_number,
            bank_branch_code,
            bank_account_type,
            banking_details_completed,
            username, 
            member_code, 
            status, 
            created_at
        FROM users
        WHERE tenant_id = ?
        AND role = 'member'
        AND (
            first_name LIKE ?
            OR last_name LIKE ?
            OR email LIKE ?
            OR phone LIKE ?
            OR username LIKE ?
            OR member_code LIKE ?
            OR bank_name LIKE ?
            OR bank_account_holder LIKE ?
            OR bank_account_number LIKE ?
        )
        ORDER BY 
            CASE 
                WHEN status = 'pending' THEN 1
                WHEN status = 'active' THEN 2
                WHEN status = 'suspended' THEN 3
                ELSE 4
            END,
            created_at DESC
    ");
    $membersStmt->bind_param(
        "isssssssss",
        $tenant_id,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
} else {
    $membersStmt = $conn->prepare("
        SELECT 
            id, 
            first_name, 
            last_name, 
            email, 
            phone,
            bank_name,
            bank_account_holder,
            bank_account_number,
            bank_branch_code,
            bank_account_type,
            banking_details_completed,
            username, 
            member_code, 
            status, 
            created_at
        FROM users
        WHERE tenant_id = ?
        AND role = 'member'
        ORDER BY 
            CASE 
                WHEN status = 'pending' THEN 1
                WHEN status = 'active' THEN 2
                WHEN status = 'suspended' THEN 3
                ELSE 4
            END,
            created_at DESC
    ");
    $membersStmt->bind_param("i", $tenant_id);
}

$membersStmt->execute();
$members = $membersStmt->get_result();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_members,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_members,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_members,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_members
    FROM users
    WHERE tenant_id = ?
    AND role = 'member'
");
$statsStmt->bind_param("i", $tenant_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_members = (int)($stats["total_members"] ?? 0);
$pending_members = (int)($stats["pending_members"] ?? 0);
$active_members = (int)($stats["active_members"] ?? 0);
$suspended_members = (int)($stats["suspended_members"] ?? 0);

$tenantStmt = $conn->prepare("
    SELECT tenant_code
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$tenantStmt->bind_param("i", $tenant_id);
$tenantStmt->execute();
$tenantData = $tenantStmt->get_result()->fetch_assoc();

$tenant_code = $tenantData["tenant_code"] ?? "";

$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$host = $_SERVER["HTTP_HOST"];
$basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"], 2), "/\\");

$memberLink = $scheme . "://" . $host . $basePath . "/signup.php?tenant=" . urlencode($tenant_code);

function memberDisplayName($member) {
    if (!empty($member["username"])) {
        return $member["username"];
    }

    if (!empty($member["member_code"])) {
        return $member["member_code"];
    }

    return trim(($member["first_name"] ?? "") . " " . ($member["last_name"] ?? ""));
}

function memberStatusBadge($status) {
    if ($status === "pending") {
        return '<span class="badge badge-pending">Pending</span>';
    }

    if ($status === "active") {
        return '<span class="badge badge-approved">Active</span>';
    }

    if ($status === "suspended") {
        return '<span class="badge badge-rejected">Suspended</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Members</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

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

        .members-hero {
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

        .members-hero::after {
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

        .members-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .members-kicker {
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

        .members-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .members-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .members-hero-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 700px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .stat-card {
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 50px rgba(16,36,31,0.14) !important;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
            right: -60px;
            bottom: -70px;
            border-radius: 50%;
            opacity: 0.38;
        }

        .stat-card::after {
            content: "R";
            position: absolute;
            right: 18px;
            top: 12px;
            font-size: 38px;
            font-weight: 900;
            opacity: 0.08;
        }

        .stat-card .stat-label,
        .stat-card .stat-value {
            position: relative;
            z-index: 2;
        }

        .stat-card-green {
            background: linear-gradient(135deg, #ffffff 0%, #d8f5e5 100%) !important;
        }

        .stat-card-green::before {
            background: #0f6b4f;
        }

        .stat-card-green .stat-value {
            color: #073f2f !important;
        }

        .stat-card-gold {
            background: linear-gradient(135deg, #ffffff 0%, #ffed9f 100%) !important;
        }

        .stat-card-gold::before {
            background: #d8a928;
        }

        .stat-card-gold .stat-value {
            color: #7a5a09 !important;
        }

        .stat-card-blue {
            background: linear-gradient(135deg, #ffffff 0%, #dbeafe 100%) !important;
        }

        .stat-card-blue::before {
            background: #2563eb;
        }

        .stat-card-blue .stat-value {
            color: #1e3a8a !important;
        }

        .stat-card-red {
            background: linear-gradient(135deg, #ffffff 0%, #ffd6d6 100%) !important;
        }

        .stat-card-red::before {
            background: #dc2626;
        }

        .stat-card-red .stat-value {
            color: #991b1b !important;
        }

        .invite-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.28), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .members-table-card {
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.22), transparent 34%),
                radial-gradient(circle at bottom right, rgba(15,107,79,0.18), transparent 36%),
                linear-gradient(135deg, #ffffff 0%, #e7f7ef 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .section-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
            margin-bottom: 6px;
        }

        .section-subtitle {
            font-size: 13px;
            color: #667085;
            margin-bottom: 0;
        }

        .invite-panel {
            background: #fffdf7;
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 20px;
            padding: 15px;
            font-size: 13px;
            word-break: break-all;
            color: #4b3a12;
        }

        .member-identity {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .member-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15,107,79,0.22);
            flex: 0 0 auto;
        }

        .member-name {
            font-weight: 900;
            color: #10241f;
        }

        .member-real-name {
            font-size: 12px;
            color: #667085;
            margin-top: 2px;
        }

        .code-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.35);
            color: #7a5a09;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .actions-wrap {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
.bank-details-panel {
    display: none;
    margin-top: 10px;
    background: #fffdf7;
    border: 1px dashed rgba(216,169,40,0.48);
    border-radius: 16px;
    padding: 12px;
    font-size: 13px;
    color: #4b3a12;
    min-width: 240px;
}

.bank-details-panel.show {
    display: block;
}

.bank-details-panel p {
    margin-bottom: 5px;
}

.bank-details-panel p:last-child {
    margin-bottom: 0;
}
        @media (max-width: 900px) {
            .members-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .members-hero-title {
                font-size: 27px;
            }

            .members-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }
        }
    </style>
</head>
<body>

<div class="app-shell">

    <?php include "../includes/sidebar.php"; ?>

    <main class="app-main">
        <div class="app-topbar">
            <div>
                <div class="app-topbar-title">Members</div>
                <div class="app-topbar-subtitle">
                    Manage members registered under your stokvel.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="members-hero">
                <div class="members-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="members-hero-title">
                    Build and manage your trusted circle
                </div>

                <p class="members-hero-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Approve new members, view their login codes, suspend inactive accounts,
                    and keep your stokvel membership organised.
                </p>
            </div>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-label">Total Members</div>
                        <div class="stat-value"><?php echo $total_members; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Pending Approval</div>
                        <div class="stat-value"><?php echo $pending_members; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Active Members</div>
                        <div class="stat-value"><?php echo $active_members; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Suspended</div>
                        <div class="stat-value"><?php echo $suspended_members; ?></div>
                    </div>
                </div>
            </div>

            <div class="card-box invite-card mb-4">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Member Registration Link</div>
                        <p class="section-subtitle">
                            Share this link with people you want to join your stokvel.
                        </p>
                    </div>

                    <span class="code-pill">
                        <?php echo htmlspecialchars($tenant_code ?: "No Code"); ?>
                    </span>
                </div>

                <div class="invite-panel mb-3" id="inviteLink">
                    <?php echo htmlspecialchars($memberLink); ?>
                </div>

                <button class="btn btn-dark btn-sm" onclick="copyInviteLink()">
                    Copy Link
                </button>
            </div>

            <div class="card-box members-table-card">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Registered Members</div>
                        <p class="section-subtitle">
                            Review member details, codes, and account status.
                        </p>
                    </div>

                    <span class="code-pill">
                        <?php echo $total_members; ?> member<?php echo $total_members === 1 ? "" : "s"; ?>
                    </span>
                </div>

                <form method="GET" class="mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-md-9">
            <input 
                type="text"
                name="search"
                class="form-control"
                value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Search by name, username, member code, phone, email, bank or account number..."
            >
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-dark w-100">
                Search
            </button>

            <?php if ($search !== ""): ?>
                <a href="members.php" class="btn btn-outline-dark">
                    Clear
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Login Code</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Banking</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($members->num_rows > 0): ?>
                                <?php while ($member = $members->fetch_assoc()): ?>
                                    <?php
                                        $displayUsername = memberDisplayName($member);
                                        $initials = strtoupper(substr($displayUsername ?: "MB", 0, 2));
                                        $realName = trim(($member["first_name"] ?? "") . " " . ($member["last_name"] ?? ""));
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="member-identity">
                                                <div class="member-avatar">
                                                    <?php echo htmlspecialchars($initials); ?>
                                                </div>

                                                <div>
                                                    <div class="member-name">
                                                        <?php echo htmlspecialchars($displayUsername ?: "-"); ?>
                                                    </div>

                                                    <div class="member-real-name">
                                                        <?php echo htmlspecialchars($realName ?: "-"); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="code-pill">
                                                <?php echo htmlspecialchars($member["member_code"] ?: "-"); ?>
                                            </span>

                                            <div class="text-muted mt-1" style="font-size: 12px;">
                                                <?php echo htmlspecialchars($member["username"] ?: "-"); ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($member["email"] ?: "-"); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($member["phone"] ?: "-"); ?>
                                        </td>

                                      <td>
    <?php if ((int)($member["banking_details_completed"] ?? 0) === 1): ?>
        <button 
            type="button" 
            class="btn btn-outline-dark btn-sm"
            onclick="toggleBankDetails('bankDetails<?php echo (int)$member["id"]; ?>')"
        >
            View Bank
        </button>

        <div class="bank-details-panel" id="bankDetails<?php echo (int)$member["id"]; ?>">
            <p><strong>Bank:</strong> <?php echo htmlspecialchars($member["bank_name"] ?: "-"); ?></p>
            <p><strong>Account Holder:</strong> <?php echo htmlspecialchars($member["bank_account_holder"] ?: "-"); ?></p>
            <p><strong>Account Number:</strong> <?php echo htmlspecialchars($member["bank_account_number"] ?: "-"); ?></p>
            <p><strong>Branch Code:</strong> <?php echo htmlspecialchars($member["bank_branch_code"] ?: "-"); ?></p>
            <p><strong>Account Type:</strong> <?php echo htmlspecialchars($member["bank_account_type"] ?: "-"); ?></p>
        </div>
    <?php else: ?>
        <span class="badge badge-pending">Missing</span>
    <?php endif; ?>
</td>
                                        <td>
                                            <?php echo memberStatusBadge($member["status"]); ?>
                                        </td>

                                        <td>
                                            <?php echo date("d M Y", strtotime($member["created_at"])); ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="actions-wrap">
                                                <?php if ($member["status"] === "pending"): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="member_id" value="<?php echo (int)$member["id"]; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button class="btn btn-success btn-sm" type="submit">
                                                            Approve
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($member["status"] === "active"): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="member_id" value="<?php echo (int)$member["id"]; ?>">
                                                        <input type="hidden" name="action" value="suspend">
                                                        <button class="btn btn-warning btn-sm" type="submit">
                                                            Suspend
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($member["status"] === "suspended" || $member["status"] === "inactive"): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="member_id" value="<?php echo (int)$member["id"]; ?>">
                                                        <input type="hidden" name="action" value="activate">
                                                        <button class="btn btn-primary btn-sm" type="submit">
                                                            Activate
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <form 
                                                    method="POST" 
                                                    class="d-inline"
                                                    onsubmit="return confirm('Are you sure you want to delete this member?');"
                                                >
                                                    <input type="hidden" name="member_id" value="<?php echo (int)$member["id"]; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button class="btn btn-outline-danger btn-sm" type="submit">
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No members have registered yet. Share your registration link to invite members.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>

        </div>
    </main>

</div>

<script>
function copyInviteLink() {
    const text = document.getElementById("inviteLink").innerText.trim();

    navigator.clipboard.writeText(text).then(function () {
        alert("Member registration link copied.");
    }).catch(function () {
        alert("Could not copy link. Please copy it manually.");
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function copyInviteLink() {
    const text = document.getElementById("inviteLink").innerText.trim();

    navigator.clipboard.writeText(text).then(function () {
        alert("Member registration link copied.");
    }).catch(function () {
        alert("Could not copy link. Please copy it manually.");
    });
}

function toggleBankDetails(panelId) {
    const selectedPanel = document.getElementById(panelId);

    if (!selectedPanel) {
        return;
    }

    document.querySelectorAll(".bank-details-panel").forEach(function (panel) {
        if (panel.id !== panelId) {
            panel.classList.remove("show");
        }
    });

    selectedPanel.classList.toggle("show");
}
</script>

</body>
</html>