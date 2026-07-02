<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] === "owner" || $_SESSION["role"] === "admin") {
    header("Location: ../admin/dashboard.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$tenant_id = (int)$_SESSION["tenant_id"];

$packageRules = getTenantPackageRules($conn, $tenant_id);

$isAuctionPackage = function_exists("packageIsAuction")
    ? packageIsAuction($packageRules)
    : (($packageRules["package_type"] ?? "savings") === "auction");

$enable_referrals = $isAuctionPackage || ((int)($packageRules["enable_referrals"] ?? 0) === 1);
$enable_bonus_claims = (int)($packageRules["enable_bonus_claims"] ?? 1);
$minimum_claim_amount = (float)($packageRules["bonus_claim_minimum"] ?? 0);
$referralPercent = (float)(
    $packageRules["referral_bonus_percent"]
    ?? $packageRules["recruitment_bonus_percent"]
    ?? 5
);

$stokvel_name = $_SESSION["stokvel_name"] ?? "Auction Express";
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";
$name = $_SESSION["name"] ?? "Member";

$displayName = $username ?: ($member_code ?: $name);
$refCode = $member_code ?: $username;
$error = "";
$success = "";

$tenantStmt = $conn->prepare("
    SELECT tenant_code
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$tenantStmt->bind_param("i", $tenant_id);
$tenantStmt->execute();
$tenant = $tenantStmt->get_result()->fetch_assoc();

$tenant_code = $tenant["tenant_code"] ?? "";

$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$host = $_SERVER["HTTP_HOST"];
$basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"], 2), "/\\");

$referralLink = $scheme . "://" . $host . $basePath . "/signup.php?tenant=" . urlencode($tenant_code) . "&ref=" . urlencode($refCode);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form_action = $_POST["form_action"] ?? "";

    if ($form_action === "claim_bonus") {
        if ($enable_bonus_claims !== 1) {
            $error = "Bonus claims are currently disabled.";
        } else {
            $claimCheckStmt = $conn->prepare("
                SELECT
                    SUM(bonus_amount) AS claimable_bonus
                FROM referral_bonuses
                WHERE tenant_id = ?
                AND upliner_user_id = ?
                AND status = 'earned'
            ");
            $claimCheckStmt->bind_param("ii", $tenant_id, $user_id);
            $claimCheckStmt->execute();
            $claimData = $claimCheckStmt->get_result()->fetch_assoc();

            $claimable_bonus = (float)($claimData["claimable_bonus"] ?? 0);

            if ($claimable_bonus < $minimum_claim_amount) {
                $error = "You can only claim your bonus when it reaches R" . number_format($minimum_claim_amount, 2) . " or more.";
            } else {
                $claimStmt = $conn->prepare("
                    UPDATE referral_bonuses
                    SET status = 'claimed'
                    WHERE tenant_id = ?
                    AND upliner_user_id = ?
                    AND status = 'earned'
                ");
                $claimStmt->bind_param("ii", $tenant_id, $user_id);

                if ($claimStmt->execute()) {
                    $success = "Your bonus claim of R" . number_format($claimable_bonus, 2) . " has been submitted. Please wait for admin payout.";
                } else {
                    $error = "Could not submit your bonus claim. Please try again.";
                }
            }
        }
    }
}

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_referrals,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_referrals,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_referrals
    FROM users
    WHERE tenant_id = ?
    AND upline_user_id = ?
");
$statsStmt->bind_param("ii", $tenant_id, $user_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_referrals = (int)($stats["total_referrals"] ?? 0);
$active_referrals = (int)($stats["active_referrals"] ?? 0);
$pending_referrals = (int)($stats["pending_referrals"] ?? 0);

$bonusStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_bonus_records,
        SUM(CASE WHEN status = 'earned' THEN bonus_amount ELSE 0 END) AS earned_bonus,
        SUM(CASE WHEN status = 'claimed' THEN bonus_amount ELSE 0 END) AS claimed_bonus,
        SUM(CASE WHEN status = 'paid' THEN bonus_amount ELSE 0 END) AS paid_bonus
    FROM referral_bonuses
    WHERE tenant_id = ?
    AND upliner_user_id = ?
");
$bonusStatsStmt->bind_param("ii", $tenant_id, $user_id);
$bonusStatsStmt->execute();
$bonusStats = $bonusStatsStmt->get_result()->fetch_assoc();

$total_bonus_records = (int)($bonusStats["total_bonus_records"] ?? 0);
$earned_bonus = (float)($bonusStats["earned_bonus"] ?? 0);
$claimed_bonus = (float)($bonusStats["claimed_bonus"] ?? 0);
$paid_bonus = (float)($bonusStats["paid_bonus"] ?? 0);
$can_claim_bonus = $earned_bonus >= $minimum_claim_amount && $enable_bonus_claims === 1;

$referralsStmt = $conn->prepare("
    SELECT 
        id,
        first_name,
        last_name,
        username,
        member_code,
        phone,
        status,
        created_at
    FROM users
    WHERE tenant_id = ?
    AND upline_user_id = ?
    ORDER BY created_at DESC
");
$referralsStmt->bind_param("ii", $tenant_id, $user_id);
$referralsStmt->execute();
$referrals = $referralsStmt->get_result();

$bonusStmt = $conn->prepare("
    SELECT 
        rb.id,
        rb.saving_amount,
        rb.bonus_percent,
        rb.bonus_amount,
        rb.status,
        rb.created_at,
        u.username,
        u.member_code,
        u.first_name,
        u.last_name
    FROM referral_bonuses rb
    INNER JOIN users u ON u.id = rb.referred_user_id
    WHERE rb.tenant_id = ?
    AND rb.upliner_user_id = ?
    ORDER BY rb.created_at DESC
");
$bonusStmt->bind_param("ii", $tenant_id, $user_id);
$bonusStmt->execute();
$bonuses = $bonusStmt->get_result();

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function personName($row) {
    if (!empty($row["username"])) {
        return $row["username"];
    }

    if (!empty($row["member_code"])) {
        return $row["member_code"];
    }

    $fullName = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));

    return $fullName !== "" ? $fullName : "Member";
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function statusBadge($status) {
    $status = trim((string)$status);

    if ($status === "pending") {
        return '<span class="ref-status status-pending">Pending</span>';
    }

    if ($status === "active" || $status === "earned" || $status === "paid") {
        return '<span class="ref-status status-good">' . ucfirst(htmlspecialchars($status)) . '</span>';
    }

    if ($status === "claimed") {
        return '<span class="ref-status status-claimed">Claimed</span>';
    }

    if ($status === "suspended" || $status === "cancelled") {
        return '<span class="ref-status status-bad">' . ucfirst(htmlspecialchars($status)) . '</span>';
    }

    return '<span class="ref-status status-neutral">' . ucfirst(htmlspecialchars($status ?: "Unknown")) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Referrals</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <style>
        body {
            background:
                radial-gradient(circle at 20% 0%, rgba(69, 90, 145, 0.18), transparent 34%),
                radial-gradient(circle at 90% 10%, rgba(168, 59, 216, 0.10), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 50%, #0b1424 100%) !important;
            color: rgba(255,255,255,0.82);
            font-size: 12px;
        }

        .app-main {
            background:
                radial-gradient(circle at 85% 5%, rgba(168, 59, 216, 0.10), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 100%) !important;
        }

        .app-topbar {
            background:
                linear-gradient(rgba(13,24,41,0.84), rgba(13,24,41,0.90)),
                radial-gradient(circle at top right, rgba(59,130,246,0.12), transparent 34%) !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
            color: #ffffff;
        }

        .app-topbar-title,
        .topbar-title {
            color: rgba(255,255,255,0.84) !important;
            font-size: 14px !important;
            font-weight: 700 !important;
        }

        .app-topbar-subtitle,
        .topbar-subtitle,
        .topbar-user {
            color: rgba(255,255,255,0.55) !important;
            font-size: 11px !important;
        }

        .app-content::before {
            display: none !important;
        }

        .ref-shell {
            max-width: 980px;
            margin: 0 auto;
        }

        .page-title {
            font-size: 22px;
            font-weight: 400;
            color: rgba(255,255,255,0.66);
            margin-bottom: 14px;
        }

        .cover-card {
            min-height: 82px;
            border-radius: 4px;
            background:
                linear-gradient(rgba(13,24,41,0.70), rgba(13,24,41,0.94)),
                radial-gradient(circle at right top, rgba(168,59,216,0.13), transparent 30%),
                linear-gradient(135deg, #162239, #0d1829);
            border: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 16px;
            padding: 16px;
            position: relative;
            overflow: hidden;
        }

        .cover-card::after {
            content: "";
            position: absolute;
            right: 20px;
            top: 18px;
            width: 34px;
            height: 24px;
            border-top: 3px solid rgba(255,255,255,0.26);
            border-bottom: 3px solid rgba(255,255,255,0.26);
        }

        .status-panel {
            background: rgba(22, 34, 57, 0.78);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 5px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .status-title {
            font-size: 18px;
            font-weight: 300;
            color: rgba(255,255,255,0.62);
            margin-bottom: 6px;
        }

        .status-text {
            color: rgba(255,255,255,0.34);
            font-size: 12px;
            line-height: 1.5;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 14px;
            box-shadow: 0 18px 32px rgba(0,0,0,0.12);
        }

        .summary-label {
            color: rgba(255,255,255,0.40);
            font-size: 10px;
            margin-bottom: 5px;
        }

        .summary-value {
            color: rgba(255,255,255,0.72);
            font-size: 17px;
            font-weight: 300;
        }

        .tasks-card {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
            border-radius: 5px;
            padding: 15px 18px;
            color: #ffffff;
            margin-bottom: 20px;
            box-shadow: 0 14px 28px rgba(255,122,0,0.14);
        }

        .tasks-title {
            color: rgba(255,255,255,0.70);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .tasks-link {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .ref-grid {
            display: grid;
            grid-template-columns: 1fr 0.85fr;
            gap: 16px;
            margin-bottom: 18px;
        }

        .ref-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 18px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            position: relative;
            overflow: hidden;
        }

        .ref-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #a83bd8, #11a7d8);
            opacity: 0.9;
        }

        .ref-card.orange::before {
            background: linear-gradient(180deg, #ff9800, #a83bd8);
        }

        .ref-card.green::before {
            background: linear-gradient(180deg, #32b96e, #11a7d8);
        }

        .card-title-small {
            color: rgba(255,255,255,0.66);
            font-size: 16px;
            font-weight: 400;
            margin-bottom: 6px;
        }

        .card-text-small {
            color: rgba(255,255,255,0.36);
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .ref-link-box {
            background: rgba(13,24,41,0.72);
            border: 1px dashed rgba(168,59,216,0.36);
            border-radius: 5px;
            padding: 12px;
            color: rgba(255,255,255,0.72);
            font-size: 11px;
            line-height: 1.5;
            word-break: break-all;
            margin-bottom: 12px;
        }

        .btn-copy,
        .btn-claim {
            background: linear-gradient(135deg, #16a085, #1abc9c);
            border: 0;
            color: #ffffff;
            border-radius: 999px;
            padding: 10px 18px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            box-shadow: 0 14px 24px rgba(26,188,156,0.16);
        }

        .btn-disabled {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.13);
            color: rgba(255,255,255,0.50);
            border-radius: 999px;
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 900;
        }

        .claim-amount {
            font-size: 28px;
            font-weight: 300;
            color: rgba(255,255,255,0.76);
            line-height: 1.1;
            margin-bottom: 5px;
        }

        .section-heading {
            color: rgba(255,255,255,0.46);
            font-size: 15px;
            font-weight: 400;
            margin: 0 0 14px;
        }

        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .table-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 16px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            overflow: hidden;
        }

        .ref-table {
            width: 100%;
            color: rgba(255,255,255,0.68);
            font-size: 11px;
        }

        .ref-table th {
            color: rgba(255,255,255,0.38);
            font-weight: 700;
            padding: 10px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            white-space: nowrap;
        }

        .ref-table td {
            padding: 10px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.045);
            vertical-align: top;
        }

        .ref-table tr:last-child td {
            border-bottom: 0;
        }

        .member-code-small {
            color: rgba(255,255,255,0.34);
            font-size: 10px;
            margin-top: 2px;
        }

        .ref-status {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 9px;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-pending,
        .status-claimed {
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.20);
            color: #ffb74d;
        }

        .status-good {
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.18);
            color: #73e39b;
        }

        .status-bad {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.18);
            color: #ff8b8b;
        }

        .status-neutral {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            color: rgba(255,255,255,0.66);
        }

        .empty-row {
            text-align: center;
            color: rgba(255,255,255,0.38);
            padding: 18px !important;
        }

        .alert {
            border-radius: 5px;
            font-size: 12px;
            padding: 10px 12px;
        }

        .text-muted-soft {
            color: rgba(255,255,255,0.36);
            font-size: 11px;
        }

        @media (max-width: 1000px) {
            .summary-grid,
            .ref-grid,
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .page-title {
                font-size: 20px;
            }

            .status-title {
                font-size: 16px;
            }

            .summary-value {
                font-size: 16px;
            }

            .claim-amount {
                font-size: 23px;
            }

            .table-responsive {
                overflow-x: auto;
            }

            .ref-table {
                min-width: 620px;
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
                <div class="app-topbar-title">My Referrals</div>
                <div class="app-topbar-subtitle">
                    Share your Auction Express link and track referral bonuses.
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="ref-shell">

                <div class="page-title">
                    My Referrals
                </div>

                <div class="cover-card">
                    <div style="color: rgba(255,255,255,0.40); font-size: 11px;">
                        Auction Express · <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>
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

                <?php if (!$enable_referrals): ?>
                    <div class="alert alert-warning">
                        Referrals are currently disabled for this package.
                    </div>
                <?php endif; ?>

                <div class="status-panel">
                    <div class="status-title">
                        Grow your Auction Express network
                    </div>

                    <div class="status-text">
                        Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                        Share your referral link. When someone joins under you and qualifies, your referral bonus is recorded automatically.
                    </div>

                    <div class="status-text mt-2">
                        Referral bonus: <strong><?php echo number_format($referralPercent, 2); ?>%</strong>
                        · Minimum claim: <strong><?php echo money($minimum_claim_amount); ?></strong>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Total Referrals</div>
                        <div class="summary-value"><?php echo number_format($total_referrals); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Active Referrals</div>
                        <div class="summary-value"><?php echo number_format($active_referrals); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Earned Bonus</div>
                        <div class="summary-value"><?php echo money($earned_bonus); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Paid Bonus</div>
                        <div class="summary-value"><?php echo money($paid_bonus); ?></div>
                    </div>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#referralLinkCard" class="tasks-link">
                        ⚙ Copy referral link
                    </a>
                </div>

                <div class="ref-grid">
                    <div class="ref-card orange" id="referralLinkCard">
                        <div class="card-title-small">Your Referral Link</div>

                        <div class="card-text-small">
                            Share this link with someone who wants to join Auction Express under you.
                        </div>

                        <div class="ref-link-box" id="referralLink">
                            <?php echo htmlspecialchars($referralLink); ?>
                        </div>

                        <button class="btn-copy" onclick="copyReferralLink()">
                            Copy Link
                        </button>
                    </div>

                    <div class="ref-card green">
                        <div class="card-title-small">Claim Referral Bonus</div>

                        <div class="card-text-small">
                            You can claim once your earned bonus reaches the minimum claim amount.
                        </div>

                        <div class="claim-amount">
                            <?php echo money($earned_bonus); ?>
                        </div>

                        <div class="text-muted-soft mb-3">
                            Claimed and waiting payout: <?php echo money($claimed_bonus); ?>
                        </div>

                        <?php if ($can_claim_bonus): ?>
                            <form method="POST">
                                <input type="hidden" name="form_action" value="claim_bonus">

                                <button 
                                    type="submit" 
                                    class="btn-claim"
                                    onclick="return confirm('Claim your available referral bonus now?');"
                                >
                                    Claim Bonus
                                </button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn-disabled" disabled>
                                Minimum <?php echo money($minimum_claim_amount); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tables-grid">
                    <div class="table-card">
                        <h3 class="section-heading">
                            People Who Joined Under You
                        </h3>

                        <div class="table-responsive">
                            <table class="ref-table">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($referrals->num_rows > 0): ?>
                                        <?php while ($row = $referrals->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(personName($row)); ?></strong>
                                                    <div class="member-code-small">
                                                        <?php echo htmlspecialchars($row["member_code"] ?: "-"); ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($row["phone"] ?: "-"); ?></td>
                                                <td><?php echo statusBadge($row["status"]); ?></td>
                                                <td><?php echo htmlspecialchars(displayDate($row["created_at"])); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="empty-row">
                                                No one has joined under your link yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="table-card">
                        <h3 class="section-heading">
                            Bonus History
                        </h3>

                        <div class="table-responsive">
                            <table class="ref-table">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Amount</th>
                                        <th>Bonus</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($bonuses->num_rows > 0): ?>
                                        <?php while ($row = $bonuses->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(personName($row)); ?></strong>
                                                </td>
                                                <td><?php echo money($row["saving_amount"]); ?></td>
                                                <td>
                                                    <strong><?php echo money($row["bonus_amount"]); ?></strong>
                                                    <div class="member-code-small">
                                                        <?php echo number_format((float)$row["bonus_percent"], 2); ?>%
                                                    </div>
                                                </td>
                                                <td><?php echo statusBadge($row["status"]); ?></td>
                                                <td><?php echo htmlspecialchars(displayDate($row["created_at"])); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="empty-row">
                                                No bonus records yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

</div>

<script>
function copyReferralLink() {
    const text = document.getElementById("referralLink").innerText.trim();

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () {
            alert("Referral link copied.");
        }).catch(function () {
            fallbackCopy(text);
        });

        return;
    }

    fallbackCopy(text);
}

function fallbackCopy(text) {
    const area = document.createElement("textarea");
    area.value = text;
    area.style.position = "fixed";
    area.style.left = "-9999px";
    document.body.appendChild(area);
    area.focus();
    area.select();

    try {
        document.execCommand("copy");
        alert("Referral link copied.");
    } catch (e) {
        alert("Could not copy link. Please copy it manually.");
    }

    document.body.removeChild(area);
}
</script>

</body>
</html>