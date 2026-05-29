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
$name = $_SESSION["name"] ?? "Admin";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$role = $_SESSION["role"] ?? "admin";
$username = $_SESSION["username"] ?? "";

$displayName = $username ?: $name;

$tenantStmt = $conn->prepare("
    SELECT tenant_code, subscription_status, trial_ends_at
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$tenantStmt->bind_param("i", $tenant_id);
$tenantStmt->execute();
$tenant = $tenantStmt->get_result()->fetch_assoc();

$tenant_code = $tenant["tenant_code"] ?? "";
$subscription_status = $tenant["subscription_status"] ?? "trial";
$trial_ends_at = $tenant["trial_ends_at"] ?? null;

$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$host = $_SERVER["HTTP_HOST"];
$basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"], 2), "/\\");
$memberLink = $scheme . "://" . $host . $basePath . "/signup.php?tenant=" . urlencode($tenant_code);

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_members,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_members,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_members
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

$savingsStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_saving_requests,

        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment', 'payment_submitted') 
            THEN 1 ELSE 0 
        END) AS pending_saving_requests,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN expected_total_amount ELSE 0 
        END) AS approved_expected_total,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN expected_return_amount ELSE 0 
        END) AS approved_expected_returns,

        SUM(CASE 
            WHEN status = 'withdrawn' 
            THEN expected_total_amount ELSE 0 
        END) AS withdrawn_total

    FROM savings_requests
    WHERE tenant_id = ?
");
$savingsStatsStmt->bind_param("i", $tenant_id);
$savingsStatsStmt->execute();
$savingsStats = $savingsStatsStmt->get_result()->fetch_assoc();

$total_saving_requests = (int)($savingsStats["total_saving_requests"] ?? 0);
$pending_saving_requests = (int)($savingsStats["pending_saving_requests"] ?? 0);
$approved_expected_total = (float)($savingsStats["approved_expected_total"] ?? 0);
$approved_expected_returns = (float)($savingsStats["approved_expected_returns"] ?? 0);
$withdrawn_total = (float)($savingsStats["withdrawn_total"] ?? 0);

$withdrawalStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_withdrawals,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_withdrawals,
        SUM(CASE WHEN status = 'approved' THEN withdrawal_amount ELSE 0 END) AS approved_withdrawal_amount,
        SUM(CASE WHEN status = 'paid' THEN withdrawal_amount ELSE 0 END) AS paid_withdrawal_amount
    FROM withdrawal_requests
    WHERE tenant_id = ?
");
$withdrawalStatsStmt->bind_param("i", $tenant_id);
$withdrawalStatsStmt->execute();
$withdrawalStats = $withdrawalStatsStmt->get_result()->fetch_assoc();

$total_withdrawals = (int)($withdrawalStats["total_withdrawals"] ?? 0);
$pending_withdrawals = (int)($withdrawalStats["pending_withdrawals"] ?? 0);
$approved_withdrawal_amount = (float)($withdrawalStats["approved_withdrawal_amount"] ?? 0);
$paid_withdrawal_amount = (float)($withdrawalStats["paid_withdrawal_amount"] ?? 0);

$recentMembersStmt = $conn->prepare("
    SELECT id, first_name, last_name, username, member_code, phone, status, created_at
    FROM users
    WHERE tenant_id = ?
    AND role = 'member'
    ORDER BY created_at DESC
    LIMIT 5
");
$recentMembersStmt->bind_param("i", $tenant_id);
$recentMembersStmt->execute();
$recentMembers = $recentMembersStmt->get_result();

$recentSavingsStmt = $conn->prepare("
    SELECT 
        sr.id,
        sr.amount,
        sr.expected_return_amount,
        sr.expected_total_amount,
        sr.status,
        sr.created_at,
        sr.payment_submitted_at,
        sr.approved_at,
        sr.matures_at,
        u.username,
        u.member_code,
        u.first_name,
        u.last_name
    FROM savings_requests sr
    INNER JOIN users u ON u.id = sr.user_id
    WHERE sr.tenant_id = ?
    ORDER BY sr.created_at DESC
    LIMIT 5
");
$recentSavingsStmt->bind_param("i", $tenant_id);
$recentSavingsStmt->execute();
$recentSavings = $recentSavingsStmt->get_result();

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function memberDisplay($row) {
    if (!empty($row["username"])) {
        return $row["username"];
    }

    if (!empty($row["member_code"])) {
        return $row["member_code"];
    }

    return trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
}

function statusBadge($status) {
    if ($status === "pending" || $status === "pending_payment") {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "approved") {
        return '<span class="badge badge-approved">Approved</span>';
    }

    if ($status === "active") {
        return '<span class="badge badge-approved">Active</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn</span>';
    }

    if ($status === "paid") {
        return '<span class="badge badge-approved">Paid</span>';
    }

    if ($status === "rejected" || $status === "suspended") {
        return '<span class="badge badge-rejected">' . ucfirst(htmlspecialchars($status)) . '</span>';
    }

    return '<span class="badge bg-secondary">' . ucfirst(htmlspecialchars($status)) . '</span>';
}

$trialText = "Trial account";

if (!empty($trial_ends_at)) {
    $trialTime = strtotime($trial_ends_at);
    $daysLeft = ceil(($trialTime - time()) / 86400);

    if ($daysLeft > 0) {
        $trialText = $daysLeft . " day" . ($daysLeft === 1 ? "" : "s") . " left on trial";
    } else {
        $trialText = "Trial period ended";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
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

        .admin-hero {
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

        .admin-hero::after {
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

        .admin-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .admin-kicker {
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

        .admin-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .admin-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .admin-hero-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 680px;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            position: relative;
            z-index: 2;
        }

        .btn-hero-light {
            background: #ffffff;
            color: #073f2f;
            border: 0;
            border-radius: 16px;
            font-weight: 900;
            padding: 11px 15px;
            text-decoration: none;
            box-shadow: 0 12px 24px rgba(0,0,0,0.10);
        }

        .btn-hero-outline {
            background: rgba(255,255,255,0.08);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.28);
            border-radius: 16px;
            font-weight: 900;
            padding: 11px 15px;
            text-decoration: none;
        }

        .btn-hero-light:hover,
        .btn-hero-outline:hover {
            transform: translateY(-1px);
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

        .dashboard-card-green {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
        }

        .dashboard-card-gold {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
        }

        .dashboard-card-mixed {
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.22), transparent 34%),
                radial-gradient(circle at bottom right, rgba(15,107,79,0.18), transparent 36%),
                linear-gradient(135deg, #ffffff 0%, #e7f7ef 100%) !important;
        }

        .quick-card-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            margin-bottom: 6px;
            color: #10241f;
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

        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid rgba(16,36,31,0.08);
        }

        .activity-item:last-child {
            border-bottom: 0;
        }

        .activity-title {
            font-weight: 900;
            color: #10241f;
        }

        .activity-meta {
            font-size: 12px;
            color: #667085;
            margin-top: 3px;
        }

        .activity-amount {
            text-align: right;
            font-weight: 900;
            color: #073f2f;
            white-space: nowrap;
        }

        @media (max-width: 900px) {
            .admin-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .admin-hero-title {
                font-size: 27px;
            }

            .admin-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }

            .activity-item {
                align-items: flex-start;
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
                <div class="app-topbar-title">Admin Dashboard</div>
                <div class="app-topbar-subtitle">
                    Manage your stokvel, members, savings, withdrawals, and group activity.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="admin-hero">
                <div class="admin-kicker">
                    <?php echo ucfirst(htmlspecialchars($subscription_status)); ?> Account · <?php echo htmlspecialchars($trialText); ?>
                </div>

                <div class="admin-hero-title">
                    Welcome, <?php echo htmlspecialchars($displayName); ?>
                </div>

                <p class="admin-hero-text">
                    You are managing <strong><?php echo htmlspecialchars($stokvel_name); ?></strong>.
                    Use this dashboard to approve members, verify saving requests, track withdrawals,
                    and keep your stokvel circle organised.
                </p>

                <div class="hero-actions">
                    <a href="members.php" class="btn-hero-light">
                        Manage Members
                    </a>

                    <a href="savings_requests.php" class="btn-hero-outline">
                        Review Savings
                    </a>

                    <a href="../group_chat.php" class="btn-hero-outline">
                        Open Group Chat
                    </a>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-label">Total Members</div>
                        <div class="stat-value"><?php echo $total_members; ?></div>
                        <div class="text-muted" style="font-size: 12px; position: relative; z-index: 2;">
                            Active: <?php echo $active_members; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Pending Members</div>
                        <div class="stat-value"><?php echo $pending_members; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Saving Requests</div>
                        <div class="stat-value"><?php echo $total_saving_requests; ?></div>
                        <div class="text-muted" style="font-size: 12px; position: relative; z-index: 2;">
                            Pending: <?php echo $pending_saving_requests; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Active Balance</div>
                        <div class="stat-value">
                            <?php echo money($approved_expected_total); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="card-box dashboard-card-green">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                            <div>
                                <h5 class="quick-card-title mb-1">Invite Members</h5>
                                <p class="text-muted mb-0" style="font-size: 13px;">
                                    Share this link with people who must join your stokvel.
                                </p>
                            </div>

                            <a href="members.php" class="btn btn-outline-dark btn-sm">
                                Manage Members
                            </a>
                        </div>

                        <div class="invite-panel mb-3" id="inviteLink">
                            <?php echo htmlspecialchars($memberLink); ?>
                        </div>

                        <button class="btn btn-dark btn-sm" onclick="copyInviteLink()">
                            Copy Registration Link
                        </button>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card-box dashboard-card-gold">
                        <h5 class="quick-card-title">Today’s Focus</h5>
                        <p class="text-muted mb-3" style="font-size: 13px;">
                            Keep the money circle moving by approving members, verifying proof of payment,
                            and checking withdrawals.
                        </p>

                        <div class="d-grid gap-2">
                            <a href="members.php" class="btn btn-outline-dark">
                                View Members
                            </a>

                            <a href="savings_requests.php" class="btn btn-dark">
                                View Saving Requests
                            </a>

                            <a href="withdrawals.php" class="btn btn-outline-dark">
                                View Withdrawals
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card-box dashboard-card-mixed">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="quick-card-title mb-1">Recent Saving Activity</h5>
                                <p class="text-muted mb-0" style="font-size: 13px;">
                                    Latest member saving requests.
                                </p>
                            </div>

                            <a href="savings_requests.php" class="btn btn-outline-dark btn-sm">
                                View All
                            </a>
                        </div>

                        <?php if ($recentSavings->num_rows > 0): ?>
                            <?php while ($row = $recentSavings->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div>
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars(memberDisplay($row)); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                            · <?php echo statusBadge($row["status"]); ?>
                                        </div>
                                    </div>

                                    <div class="activity-amount">
                                        <?php echo money($row["expected_total_amount"]); ?>
                                        <div class="text-muted" style="font-size: 12px;">
                                            Saved <?php echo money($row["amount"]); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                No saving activity yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card-box dashboard-card-green">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="quick-card-title mb-1">Recent Members</h5>
                                <p class="text-muted mb-0" style="font-size: 13px;">
                                    Newest people who joined your stokvel.
                                </p>
                            </div>

                            <a href="members.php" class="btn btn-outline-dark btn-sm">
                                View All
                            </a>
                        </div>

                        <?php if ($recentMembers->num_rows > 0): ?>
                            <?php while ($member = $recentMembers->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div>
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars(memberDisplay($member)); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <?php echo htmlspecialchars($member["phone"] ?: "-"); ?>
                                            · <?php echo statusBadge($member["status"]); ?>
                                        </div>
                                    </div>

                                    <div class="activity-amount">
                                        <?php echo htmlspecialchars($member["member_code"] ?: "-"); ?>
                                        <div class="text-muted" style="font-size: 12px;">
                                            <?php echo date("d M Y", strtotime($member["created_at"])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                No members have joined yet.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-box dashboard-card-gold mt-4">
                        <h5 class="quick-card-title">Money Snapshot</h5>
                        <p class="text-muted" style="font-size: 13px;">
                            Current stokvel money movement.
                        </p>

                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Approved Returns</span>
                            <strong><?php echo money($approved_expected_returns); ?></strong>
                        </div>

                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Withdrawn / Closed</span>
                            <strong><?php echo money($withdrawn_total); ?></strong>
                        </div>

                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Pending Withdrawals</span>
                            <strong><?php echo $pending_withdrawals; ?></strong>
                        </div>

                        <div class="d-flex justify-content-between py-2">
                            <span class="text-muted">Paid Withdrawals</span>
                            <strong><?php echo money($paid_withdrawal_amount); ?></strong>
                        </div>
                    </div>
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

</body>
</html>