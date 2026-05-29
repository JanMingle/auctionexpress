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

$ledgerStmt = $conn->prepare("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.username,
        u.member_code,
        u.status AS user_status,

        COALESCE(s.total_requests, 0) AS total_requests,
        COALESCE(s.pending_payment_count, 0) AS pending_payment_count,
        COALESCE(s.proof_submitted_count, 0) AS proof_submitted_count,
        COALESCE(s.active_count, 0) AS active_count,
        COALESCE(s.withdrawn_count, 0) AS withdrawn_count,

        COALESCE(s.total_saved_submitted, 0) AS total_saved_submitted,
        COALESCE(s.pending_amount, 0) AS pending_amount,
        COALESCE(s.active_saved, 0) AS active_saved,
        COALESCE(s.active_returns, 0) AS active_returns,
        COALESCE(s.active_balance, 0) AS active_balance,
        COALESCE(s.withdrawn_total, 0) AS withdrawn_total,

        COALESCE(w.pending_withdrawal_amount, 0) AS pending_withdrawal_amount,
        COALESCE(w.approved_withdrawal_amount, 0) AS approved_withdrawal_amount,
        COALESCE(w.paid_withdrawal_amount, 0) AS paid_withdrawal_amount

    FROM users u

    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS total_requests,

            SUM(CASE WHEN status IN ('pending', 'pending_payment') THEN 1 ELSE 0 END) AS pending_payment_count,
            SUM(CASE WHEN status = 'payment_submitted' THEN 1 ELSE 0 END) AS proof_submitted_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn_count,

            SUM(CASE 
                WHEN status IN ('pending', 'pending_payment', 'payment_submitted', 'approved', 'withdrawn')
                THEN amount ELSE 0 
            END) AS total_saved_submitted,

            SUM(CASE 
                WHEN status IN ('pending', 'pending_payment', 'payment_submitted')
                THEN amount ELSE 0 
            END) AS pending_amount,

            SUM(CASE 
                WHEN status = 'approved'
                THEN amount ELSE 0 
            END) AS active_saved,

            SUM(CASE 
                WHEN status = 'approved'
                THEN expected_return_amount ELSE 0 
            END) AS active_returns,

            SUM(CASE 
                WHEN status = 'approved'
                THEN expected_total_amount ELSE 0 
            END) AS active_balance,

            SUM(CASE 
                WHEN status = 'withdrawn'
                THEN expected_total_amount ELSE 0 
            END) AS withdrawn_total

        FROM savings_requests
        WHERE tenant_id = ?
        GROUP BY user_id
    ) s ON s.user_id = u.id

    LEFT JOIN (
        SELECT
            user_id,
            SUM(CASE WHEN status = 'pending' THEN withdrawal_amount ELSE 0 END) AS pending_withdrawal_amount,
            SUM(CASE WHEN status = 'approved' THEN withdrawal_amount ELSE 0 END) AS approved_withdrawal_amount,
            SUM(CASE WHEN status = 'paid' THEN withdrawal_amount ELSE 0 END) AS paid_withdrawal_amount
        FROM withdrawal_requests
        WHERE tenant_id = ?
        GROUP BY user_id
    ) w ON w.user_id = u.id

    WHERE u.tenant_id = ?
    AND u.role = 'member'
    ORDER BY u.first_name ASC, u.last_name ASC
");
$ledgerStmt->bind_param("iii", $tenant_id, $tenant_id, $tenant_id);
$ledgerStmt->execute();
$members = $ledgerStmt->get_result();

$totalStatsStmt = $conn->prepare("
    SELECT
        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment', 'payment_submitted', 'approved', 'withdrawn')
            THEN amount ELSE 0 
        END) AS total_saved_submitted,

        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment', 'payment_submitted')
            THEN amount ELSE 0 
        END) AS pending_amount,

        SUM(CASE 
            WHEN status = 'approved'
            THEN expected_total_amount ELSE 0 
        END) AS active_balance,

        SUM(CASE 
            WHEN status = 'approved'
            THEN expected_return_amount ELSE 0 
        END) AS active_returns,

        SUM(CASE 
            WHEN status = 'withdrawn'
            THEN expected_total_amount ELSE 0 
        END) AS withdrawn_total
    FROM savings_requests
    WHERE tenant_id = ?
");
$totalStatsStmt->bind_param("i", $tenant_id);
$totalStatsStmt->execute();
$totals = $totalStatsStmt->get_result()->fetch_assoc();

$total_saved_submitted = (float)($totals["total_saved_submitted"] ?? 0);
$pending_amount = (float)($totals["pending_amount"] ?? 0);
$active_balance = (float)($totals["active_balance"] ?? 0);
$active_returns = (float)($totals["active_returns"] ?? 0);
$withdrawn_total = (float)($totals["withdrawn_total"] ?? 0);

$recentStmt = $conn->prepare("
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
        sr.withdrawn_at,
        u.first_name,
        u.last_name,
        u.username,
        u.member_code
    FROM savings_requests sr
    INNER JOIN users u ON u.id = sr.user_id
    WHERE sr.tenant_id = ?
    ORDER BY sr.created_at DESC
    LIMIT 10
");
$recentStmt->bind_param("i", $tenant_id);
$recentStmt->execute();
$recentActivity = $recentStmt->get_result();

function badge($status) {
    if (in_array($status, ["pending", "pending_payment"], true)) {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "approved") {
        return '<span class="badge badge-approved">Active</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Ledger</title>
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

        .ledger-hero {
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

        .ledger-hero::after {
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

        .ledger-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .ledger-kicker {
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

        .ledger-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .ledger-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .ledger-hero-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 720px;
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
        .stat-card .stat-value,
        .stat-card .stat-note {
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

        .ledger-table-card {
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.22), transparent 34%),
                radial-gradient(circle at bottom right, rgba(15,107,79,0.18), transparent 36%),
                linear-gradient(135deg, #ffffff 0%, #e7f7ef 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .activity-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
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

        .member-identity {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 190px;
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

        .member-sub {
            font-size: 12px;
            color: #667085;
            margin-top: 2px;
        }

        .money-strong {
            font-weight: 900;
            color: #073f2f;
            white-space: nowrap;
        }

        .queue-box {
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 900px) {
            .ledger-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .ledger-hero-title {
                font-size: 27px;
            }

            .ledger-hero::after {
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
                <div class="app-topbar-title">Ledger</div>
                <div class="app-topbar-subtitle">
                    Full stokvel financial overview by member.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="ledger-hero">
                <div class="ledger-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="ledger-hero-title">
                    Complete financial view of your stokvel
                </div>

                <p class="ledger-hero-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    This ledger shows member-by-member totals for submitted savings, pending payments,
                    active returns, active balances, completed withdrawals, and payout queues.
                </p>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-label">Total Saved Submitted</div>
                        <div class="stat-value"><?php echo money($total_saved_submitted); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Pending Money</div>
                        <div class="stat-value"><?php echo money($pending_amount); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Active Balance</div>
                        <div class="stat-value"><?php echo money($active_balance); ?></div>
                        <div class="stat-note text-muted" style="font-size: 13px;">
                            Returns: <?php echo money($active_returns); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Withdrawn / Closed</div>
                        <div class="stat-value"><?php echo money($withdrawn_total); ?></div>
                    </div>
                </div>
            </div>

            <div class="card-box ledger-table-card mb-4">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Member Ledger</div>
                        <p class="section-subtitle">
                            View savings and withdrawal totals for each member.
                        </p>
                    </div>

                    <span class="code-pill">
                        Financial overview
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Total Saved</th>
                                <th>Pending</th>
                                <th>Active Saved</th>
                                <th>Active Returns</th>
                                <th>Active Balance</th>
                                <th>Withdrawn</th>
                                <th>Withdrawal Queue</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($members->num_rows > 0): ?>
                                <?php while ($row = $members->fetch_assoc()): ?>
                                    <?php
                                        $displayMember = memberDisplay($row);
                                        $realName = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
                                        $initials = strtoupper(substr($displayMember ?: "MB", 0, 2));
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="member-identity">
                                                <div class="member-avatar">
                                                    <?php echo htmlspecialchars($initials); ?>
                                                </div>

                                                <div>
                                                    <div class="member-name">
                                                        <?php echo htmlspecialchars($displayMember ?: "-"); ?>
                                                    </div>

                                                    <div class="member-sub">
                                                        <?php echo htmlspecialchars($realName ?: "-"); ?>
                                                    </div>

                                                    <div class="member-sub">
                                                        <?php echo htmlspecialchars($row["phone"] ?: "-"); ?>
                                                    </div>

                                                    <?php if (!empty($row["member_code"])): ?>
                                                        <div class="member-sub">
                                                            Code: <?php echo htmlspecialchars($row["member_code"]); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="money-strong">
                                                <?php echo money($row["total_saved_submitted"]); ?>
                                            </span>

                                            <div class="member-sub">
                                                <?php echo (int)$row["total_requests"]; ?> request<?php echo (int)$row["total_requests"] === 1 ? "" : "s"; ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo money($row["pending_amount"]); ?>

                                            <div class="member-sub">
                                                Awaiting: <?php echo (int)$row["pending_payment_count"]; ?>
                                            </div>

                                            <div class="member-sub">
                                                Proof: <?php echo (int)$row["proof_submitted_count"]; ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo money($row["active_saved"]); ?>
                                        </td>

                                        <td>
                                            <?php echo money($row["active_returns"]); ?>
                                        </td>

                                        <td>
                                            <span class="money-strong">
                                                <?php echo money($row["active_balance"]); ?>
                                            </span>

                                            <div class="member-sub">
                                                Active cycles: <?php echo (int)$row["active_count"]; ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo money($row["withdrawn_total"]); ?>

                                            <div class="member-sub">
                                                Closed: <?php echo (int)$row["withdrawn_count"]; ?>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="queue-box">
                                                <div>
                                                    <strong>Pending:</strong>
                                                    <?php echo money($row["pending_withdrawal_amount"]); ?>
                                                </div>

                                                <div>
                                                    <strong>Approved:</strong>
                                                    <?php echo money($row["approved_withdrawal_amount"]); ?>
                                                </div>

                                                <div>
                                                    <strong>Paid:</strong>
                                                    <?php echo money($row["paid_withdrawal_amount"]); ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No members found yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>

            <div class="card-box activity-card">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Recent Saving Activity</div>
                        <p class="section-subtitle">
                            Latest saving requests submitted by members.
                        </p>
                    </div>

                    <span class="code-pill">
                        Latest 10
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Return</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($recentActivity->num_rows > 0): ?>
                                <?php while ($row = $recentActivity->fetch_assoc()): ?>
                                    <?php
                                        $displayMember = memberDisplay($row);
                                        $realName = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
                                    ?>

                                    <tr>
                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?php echo htmlspecialchars($displayMember ?: "-"); ?>
                                            </strong>

                                            <div class="member-sub">
                                                <?php echo htmlspecialchars($realName ?: "-"); ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo money($row["amount"]); ?>
                                        </td>

                                        <td>
                                            <?php echo money($row["expected_return_amount"]); ?>
                                        </td>

                                        <td>
                                            <span class="money-strong">
                                                <?php echo money($row["expected_total_amount"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo badge($row["status"]); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No recent activity yet.
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

</body>
</html>