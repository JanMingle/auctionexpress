<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "owner" && $_SESSION["role"] !== "admin") {
    header("Location: ../users/dashboard.php");
    exit;
}

$tenant_id = (int)($_SESSION["tenant_id"] ?? 0);
$admin_id = (int)($_SESSION["user_id"] ?? 0);

$stokvel_name = $_SESSION["stokvel_name"] ?? "Auction Express";
$username = $_SESSION["username"] ?? "";
$name = $_SESSION["name"] ?? "Admin";
$member_code = $_SESSION["member_code"] ?? "";
$displayName = $username ?: ($member_code ?: $name);

$success = "";
$error = "";

$packageRules = getTenantPackageRules($conn, $tenant_id);

$isAuctionPackage = function_exists("packageIsAuction")
    ? packageIsAuction($packageRules)
    : (($packageRules["package_type"] ?? "savings") === "auction");

$referralPercent = (float)(
    $packageRules["referral_bonus_percent"]
    ?? $packageRules["recruitment_bonus_percent"]
    ?? 5
);

$minimumClaimAmount = (float)($packageRules["bonus_claim_minimum"] ?? 0);

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function cleanText($value) {
    $value = trim((string)$value);
    return $value !== "" ? htmlspecialchars($value) : "-";
}

function personNameFromRow($row, $prefix = "") {
    $username = trim($row[$prefix . "username"] ?? "");
    $memberCode = trim($row[$prefix . "member_code"] ?? "");
    $firstName = trim($row[$prefix . "first_name"] ?? "");
    $lastName = trim($row[$prefix . "last_name"] ?? "");

    if ($username !== "") {
        return $username;
    }

    if ($memberCode !== "") {
        return $memberCode;
    }

    $fullName = trim($firstName . " " . $lastName);

    return $fullName !== "" ? $fullName : "Member";
}

function statusBadge($status) {
    $status = trim((string)$status);

    if ($status === "active" || $status === "earned" || $status === "paid") {
        return '<span class="ref-status status-good">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }

    if ($status === "pending" || $status === "claimed") {
        return '<span class="ref-status status-pending">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }

    if ($status === "suspended" || $status === "rejected" || $status === "cancelled") {
        return '<span class="ref-status status-bad">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }

    return '<span class="ref-status status-neutral">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
}

/*
    Admin actions
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "mark_bonus_paid") {
        $bonus_id = (int)($_POST["bonus_id"] ?? 0);

        if ($bonus_id <= 0) {
            $error = "Invalid bonus selected.";
        } else {
            $stmt = $conn->prepare("
                UPDATE referral_bonuses
                SET status = 'paid'
                WHERE id = ?
                AND tenant_id = ?
                AND status = 'claimed'
            ");
            $stmt->bind_param("ii", $bonus_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Referral bonus marked as paid.";
            } else {
                $error = "Could not update referral bonus.";
            }
        }
    }

    if ($action === "mark_user_claims_paid") {
        $upliner_id = (int)($_POST["upliner_id"] ?? 0);

        if ($upliner_id <= 0) {
            $error = "Invalid member selected.";
        } else {
            $stmt = $conn->prepare("
                UPDATE referral_bonuses
                SET status = 'paid'
                WHERE tenant_id = ?
                AND upliner_user_id = ?
                AND status = 'claimed'
            ");
            $stmt->bind_param("ii", $tenant_id, $upliner_id);

            if ($stmt->execute()) {
                $success = "All claimed bonuses for this member were marked as paid.";
            } else {
                $error = "Could not update claimed bonuses.";
            }
        }
    }
}

/*
    Filters
*/
$q = trim($_GET["q"] ?? "");
$memberStatus = trim($_GET["member_status"] ?? "");
$bonusStatus = trim($_GET["bonus_status"] ?? "");

$allowedMemberStatuses = ["", "active", "pending", "suspended"];
if (!in_array($memberStatus, $allowedMemberStatuses, true)) {
    $memberStatus = "";
}

$allowedBonusStatuses = ["", "earned", "claimed", "paid"];
if (!in_array($bonusStatus, $allowedBonusStatuses, true)) {
    $bonusStatus = "";
}

/*
    Summary stats
*/
$summaryStmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN upline_user_id IS NOT NULL AND upline_user_id > 0 THEN upline_user_id END) AS users_with_referrals,
        COUNT(CASE WHEN upline_user_id IS NOT NULL AND upline_user_id > 0 THEN 1 END) AS total_referrals,
        SUM(CASE WHEN upline_user_id IS NOT NULL AND upline_user_id > 0 AND status = 'active' THEN 1 ELSE 0 END) AS active_referrals,
        SUM(CASE WHEN upline_user_id IS NOT NULL AND upline_user_id > 0 AND status = 'pending' THEN 1 ELSE 0 END) AS pending_referrals
    FROM users
    WHERE tenant_id = ?
");
$summaryStmt->bind_param("i", $tenant_id);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();

$usersWithReferrals = (int)($summary["users_with_referrals"] ?? 0);
$totalReferrals = (int)($summary["total_referrals"] ?? 0);
$activeReferrals = (int)($summary["active_referrals"] ?? 0);
$pendingReferrals = (int)($summary["pending_referrals"] ?? 0);

$bonusSummaryStmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'earned' THEN bonus_amount ELSE 0 END), 0) AS earned_bonus,
        COALESCE(SUM(CASE WHEN status = 'claimed' THEN bonus_amount ELSE 0 END), 0) AS claimed_bonus,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN bonus_amount ELSE 0 END), 0) AS paid_bonus,
        COUNT(*) AS bonus_records
    FROM referral_bonuses
    WHERE tenant_id = ?
");
$bonusSummaryStmt->bind_param("i", $tenant_id);
$bonusSummaryStmt->execute();
$bonusSummary = $bonusSummaryStmt->get_result()->fetch_assoc();

$earnedBonus = (float)($bonusSummary["earned_bonus"] ?? 0);
$claimedBonus = (float)($bonusSummary["claimed_bonus"] ?? 0);
$paidBonus = (float)($bonusSummary["paid_bonus"] ?? 0);
$bonusRecords = (int)($bonusSummary["bonus_records"] ?? 0);

/*
    Users with referrals
*/
$userWhere = "u.tenant_id = ? AND COALESCE(ref.total_referrals, 0) > 0";
$userParams = [$tenant_id, $tenant_id, $tenant_id];
$userTypes = "iii";

if ($q !== "") {
    $userWhere .= "
        AND (
            u.username LIKE ?
            OR u.member_code LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
        )
    ";

    $search = "%" . $q . "%";
    for ($i = 0; $i < 6; $i++) {
        $userParams[] = $search;
        $userTypes .= "s";
    }
}

if ($memberStatus !== "") {
    $userWhere .= " AND u.status = ?";
    $userParams[] = $memberStatus;
    $userTypes .= "s";
}

$usersSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.username,
        u.member_code,
        u.email,
        u.phone,
        u.status,
        u.created_at,

        COALESCE(ref.total_referrals, 0) AS total_referrals,
        COALESCE(ref.active_referrals, 0) AS active_referrals,
        COALESCE(ref.pending_referrals, 0) AS pending_referrals,

        COALESCE(bon.earned_bonus, 0) AS earned_bonus,
        COALESCE(bon.claimed_bonus, 0) AS claimed_bonus,
        COALESCE(bon.paid_bonus, 0) AS paid_bonus,
        COALESCE(bon.bonus_records, 0) AS bonus_records

    FROM users u

    LEFT JOIN (
        SELECT
            upline_user_id,
            COUNT(*) AS total_referrals,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_referrals,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_referrals
        FROM users
        WHERE tenant_id = ?
        AND upline_user_id IS NOT NULL
        AND upline_user_id > 0
        GROUP BY upline_user_id
    ) ref ON ref.upline_user_id = u.id

    LEFT JOIN (
        SELECT
            upliner_user_id,
            COUNT(*) AS bonus_records,
            SUM(CASE WHEN status = 'earned' THEN bonus_amount ELSE 0 END) AS earned_bonus,
            SUM(CASE WHEN status = 'claimed' THEN bonus_amount ELSE 0 END) AS claimed_bonus,
            SUM(CASE WHEN status = 'paid' THEN bonus_amount ELSE 0 END) AS paid_bonus
        FROM referral_bonuses
        WHERE tenant_id = ?
        GROUP BY upliner_user_id
    ) bon ON bon.upliner_user_id = u.id

    WHERE $userWhere
    ORDER BY ref.total_referrals DESC, bon.earned_bonus DESC, u.created_at DESC
";

$usersStmt = $conn->prepare($usersSql);
$usersStmt->bind_param($userTypes, ...$userParams);
$usersStmt->execute();
$usersWithReferralsResult = $usersStmt->get_result();

/*
    Referred members list
*/
$downlineWhere = "r.tenant_id = ? AND r.upline_user_id IS NOT NULL AND r.upline_user_id > 0";
$downlineParams = [$tenant_id];
$downlineTypes = "i";

if ($q !== "") {
    $downlineWhere .= "
        AND (
            r.username LIKE ?
            OR r.member_code LIKE ?
            OR r.first_name LIKE ?
            OR r.last_name LIKE ?
            OR r.email LIKE ?
            OR r.phone LIKE ?
            OR u.username LIKE ?
            OR u.member_code LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
        )
    ";

    $search = "%" . $q . "%";
    for ($i = 0; $i < 10; $i++) {
        $downlineParams[] = $search;
        $downlineTypes .= "s";
    }
}

if ($memberStatus !== "") {
    $downlineWhere .= " AND r.status = ?";
    $downlineParams[] = $memberStatus;
    $downlineTypes .= "s";
}

$downlineSql = "
    SELECT
        r.id,
        r.first_name,
        r.last_name,
        r.username,
        r.member_code,
        r.email,
        r.phone,
        r.status,
        r.created_at,

        u.username AS upliner_username,
        u.member_code AS upliner_member_code,
        u.first_name AS upliner_first_name,
        u.last_name AS upliner_last_name
    FROM users r
    INNER JOIN users u ON u.id = r.upline_user_id
    WHERE $downlineWhere
    ORDER BY r.created_at DESC
    LIMIT 50
";

$downlineStmt = $conn->prepare($downlineSql);
$downlineStmt->bind_param($downlineTypes, ...$downlineParams);
$downlineStmt->execute();
$downliners = $downlineStmt->get_result();

/*
    Bonus records
*/
$bonusWhere = "rb.tenant_id = ?";
$bonusParams = [$tenant_id];
$bonusTypes = "i";

if ($bonusStatus !== "") {
    $bonusWhere .= " AND rb.status = ?";
    $bonusParams[] = $bonusStatus;
    $bonusTypes .= "s";
}

if ($q !== "") {
    $bonusWhere .= "
        AND (
            upliner.username LIKE ?
            OR upliner.member_code LIKE ?
            OR upliner.first_name LIKE ?
            OR upliner.last_name LIKE ?
            OR referred.username LIKE ?
            OR referred.member_code LIKE ?
            OR referred.first_name LIKE ?
            OR referred.last_name LIKE ?
        )
    ";

    $search = "%" . $q . "%";
    for ($i = 0; $i < 8; $i++) {
        $bonusParams[] = $search;
        $bonusTypes .= "s";
    }
}

$bonusSql = "
    SELECT
        rb.id,
        rb.saving_amount,
        rb.bonus_percent,
        rb.bonus_amount,
        rb.status,
        rb.created_at,

        upliner.username AS upliner_username,
        upliner.member_code AS upliner_member_code,
        upliner.first_name AS upliner_first_name,
        upliner.last_name AS upliner_last_name,

        referred.username AS referred_username,
        referred.member_code AS referred_member_code,
        referred.first_name AS referred_first_name,
        referred.last_name AS referred_last_name
    FROM referral_bonuses rb
    INNER JOIN users upliner ON upliner.id = rb.upliner_user_id
    INNER JOIN users referred ON referred.id = rb.referred_user_id
    WHERE $bonusWhere
    ORDER BY rb.created_at DESC
    LIMIT 50
";

$bonusStmt = $conn->prepare($bonusSql);
$bonusStmt->bind_param($bonusTypes, ...$bonusParams);
$bonusStmt->execute();
$bonusRows = $bonusStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Referral Admin</title>
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

        .ref-admin-shell {
            max-width: 1040px;
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

        .filter-card,
        .table-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 16px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            margin-bottom: 18px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 1fr 160px 160px 90px;
            gap: 10px;
        }

        .form-control,
        .form-select {
            background: rgba(13,24,41,0.72) !important;
            border: 1px solid rgba(255,255,255,0.08) !important;
            color: rgba(255,255,255,0.78) !important;
            border-radius: 5px !important;
            font-size: 12px !important;
            padding: 10px 11px !important;
        }

        .form-control::placeholder {
            color: rgba(255,255,255,0.30);
        }

        .btn-filter {
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            border: 0;
            color: #ffffff;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 900;
            padding: 10px 12px;
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

        .section-heading {
            color: rgba(255,255,255,0.46);
            font-size: 15px;
            font-weight: 400;
            margin: 0 0 14px;
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

        .status-pending {
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

        .btn-small-paid {
            background: linear-gradient(135deg, #16a085, #1abc9c);
            border: 0;
            color: #ffffff;
            border-radius: 999px;
            padding: 7px 11px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
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

        @media (max-width: 1000px) {
            .summary-grid,
            .filter-row {
                grid-template-columns: 1fr;
            }

            .table-responsive {
                overflow-x: auto;
            }

            .ref-table {
                min-width: 760px;
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
                <div class="app-topbar-title">Referral Admin</div>
                <div class="app-topbar-subtitle">
                    View referral users, downliners, bonus claims, and payouts.
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="ref-admin-shell">

                <div class="page-title">
                    Referral Admin
                </div>

                <div class="cover-card">
                    <div style="color: rgba(255,255,255,0.40); font-size: 11px;">
                        Auction Express · <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="status-panel">
                    <div class="status-title">
                        Referral overview
                    </div>

                    <div class="status-text">
                        This page shows members who referred others, the people who joined under them,
                        and all referral bonus records for Auction Express.
                    </div>

                    <div class="status-text mt-2">
                        Current referral bonus: <strong><?php echo number_format($referralPercent, 2); ?>%</strong>
                        · Minimum claim: <strong><?php echo money($minimumClaimAmount); ?></strong>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Users With Referrals</div>
                        <div class="summary-value"><?php echo number_format($usersWithReferrals); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Total Referrals</div>
                        <div class="summary-value"><?php echo number_format($totalReferrals); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Earned Bonus</div>
                        <div class="summary-value"><?php echo money($earnedBonus); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Claimed Bonus</div>
                        <div class="summary-value"><?php echo money($claimedBonus); ?></div>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Active Referrals</div>
                        <div class="summary-value"><?php echo number_format($activeReferrals); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Pending Referrals</div>
                        <div class="summary-value"><?php echo number_format($pendingReferrals); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Paid Bonus</div>
                        <div class="summary-value"><?php echo money($paidBonus); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Bonus Records</div>
                        <div class="summary-value"><?php echo number_format($bonusRecords); ?></div>
                    </div>
                </div>

                <div class="filter-card">
                    <form method="GET" class="filter-row">
                        <input 
                            type="text" 
                            name="q" 
                            class="form-control"
                            placeholder="Search member, phone, email, or member code"
                            value="<?php echo htmlspecialchars($q); ?>"
                        >

                        <select name="member_status" class="form-select">
                            <option value="">All members</option>
                            <option value="active" <?php echo $memberStatus === "active" ? "selected" : ""; ?>>Active</option>
                            <option value="pending" <?php echo $memberStatus === "pending" ? "selected" : ""; ?>>Pending</option>
                            <option value="suspended" <?php echo $memberStatus === "suspended" ? "selected" : ""; ?>>Suspended</option>
                        </select>

                        <select name="bonus_status" class="form-select">
                            <option value="">All bonuses</option>
                            <option value="earned" <?php echo $bonusStatus === "earned" ? "selected" : ""; ?>>Earned</option>
                            <option value="claimed" <?php echo $bonusStatus === "claimed" ? "selected" : ""; ?>>Claimed</option>
                            <option value="paid" <?php echo $bonusStatus === "paid" ? "selected" : ""; ?>>Paid</option>
                        </select>

                        <button class="btn-filter">
                            Filter
                        </button>
                    </form>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#usersWithReferrals" class="tasks-link">
                        ⚙ Review referrals
                    </a>
                </div>

                <div class="table-card" id="usersWithReferrals">
                    <h3 class="section-heading">Users With Referrals</h3>

                    <div class="table-responsive">
                        <table class="ref-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Contact</th>
                                    <th>Referrals</th>
                                    <th>Bonuses</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($usersWithReferralsResult->num_rows > 0): ?>
                                    <?php while ($row = $usersWithReferralsResult->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars(personNameFromRow($row)); ?></strong>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["member_code"] ?? ""); ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php echo cleanText($row["phone"] ?? ""); ?>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["email"] ?? ""); ?>
                                                </div>
                                            </td>

                                            <td>
                                                <strong><?php echo number_format((int)$row["total_referrals"]); ?></strong>
                                                <div class="member-code-small">
                                                    Active: <?php echo number_format((int)$row["active_referrals"]); ?>
                                                    · Pending: <?php echo number_format((int)$row["pending_referrals"]); ?>
                                                </div>
                                            </td>

                                            <td>
                                                Earned: <strong><?php echo money($row["earned_bonus"]); ?></strong><br>
                                                Claimed: <strong><?php echo money($row["claimed_bonus"]); ?></strong><br>
                                                Paid: <strong><?php echo money($row["paid_bonus"]); ?></strong>
                                            </td>

                                            <td><?php echo statusBadge($row["status"]); ?></td>

                                            <td><?php echo htmlspecialchars(displayDate($row["created_at"])); ?></td>

                                            <td>
                                                <?php if ((float)$row["claimed_bonus"] > 0): ?>
                                                    <form method="POST" onsubmit="return confirm('Mark all claimed bonuses for this member as paid?');">
                                                        <input type="hidden" name="action" value="mark_user_claims_paid">
                                                        <input type="hidden" name="upliner_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <button class="btn-small-paid">
                                                            Mark Paid
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="empty-row">
                                            No users with referrals found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-card">
                    <h3 class="section-heading">Referred Members / Downliners</h3>

                    <div class="table-responsive">
                        <table class="ref-table">
                            <thead>
                                <tr>
                                    <th>Downliner</th>
                                    <th>Contact</th>
                                    <th>Upliner</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($downliners->num_rows > 0): ?>
                                    <?php while ($row = $downliners->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars(personNameFromRow($row)); ?></strong>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["member_code"] ?? ""); ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php echo cleanText($row["phone"] ?? ""); ?>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["email"] ?? ""); ?>
                                                </div>
                                            </td>

                                            <td>
                                                <strong><?php echo htmlspecialchars(personNameFromRow($row, "upliner_")); ?></strong>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["upliner_member_code"] ?? ""); ?>
                                                </div>
                                            </td>

                                            <td><?php echo statusBadge($row["status"]); ?></td>

                                            <td><?php echo htmlspecialchars(displayDate($row["created_at"])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="empty-row">
                                            No downliners found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-card">
                    <h3 class="section-heading">Referral Bonus Records</h3>

                    <div class="table-responsive">
                        <table class="ref-table">
                            <thead>
                                <tr>
                                    <th>Upliner</th>
                                    <th>Referred Member</th>
                                    <th>Qualifying Amount</th>
                                    <th>Bonus</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($bonusRows->num_rows > 0): ?>
                                    <?php while ($row = $bonusRows->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars(personNameFromRow($row, "upliner_")); ?></strong>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["upliner_member_code"] ?? ""); ?>
                                                </div>
                                            </td>

                                            <td>
                                                <strong><?php echo htmlspecialchars(personNameFromRow($row, "referred_")); ?></strong>
                                                <div class="member-code-small">
                                                    <?php echo cleanText($row["referred_member_code"] ?? ""); ?>
                                                </div>
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

                                            <td>
                                                <?php if (($row["status"] ?? "") === "claimed"): ?>
                                                    <form method="POST" onsubmit="return confirm('Mark this referral bonus as paid?');">
                                                        <input type="hidden" name="action" value="mark_bonus_paid">
                                                        <input type="hidden" name="bonus_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <button class="btn-small-paid">
                                                            Mark Paid
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="empty-row">
                                            No referral bonus records found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

</div>
</body>
</html>