<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "member") {
    header("Location: ../admin/dashboard.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$tenant_id = (int)$_SESSION["tenant_id"];
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";
$name = $_SESSION["name"] ?? "Member";

$displayName = $username ?: ($member_code ?: $name);
$refCode = $member_code ?: $username;

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
$paid_bonus = (float)($bonusStats["paid_bonus"] ?? 0);

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

    return trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
}

function statusBadge($status) {
    if ($status === "pending") {
        return '<span class="badge badge-pending">Pending</span>';
    }

    if ($status === "active" || $status === "earned" || $status === "paid") {
        return '<span class="badge badge-approved">' . ucfirst(htmlspecialchars($status)) . '</span>';
    }

    if ($status === "suspended" || $status === "cancelled") {
        return '<span class="badge badge-rejected">' . ucfirst(htmlspecialchars($status)) . '</span>';
    }

    return '<span class="badge bg-secondary">' . ucfirst(htmlspecialchars($status)) . '</span>';
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
                radial-gradient(circle at 8% 10%, rgba(216, 169, 40, 0.34), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(15, 107, 79, 0.28), transparent 32%),
                radial-gradient(circle at 50% 90%, rgba(216, 169, 40, 0.20), transparent 34%),
                linear-gradient(135deg, #fff4c7 0%, #fbf7ed 32%, #e7f7ef 72%, #dff5e9 100%) !important;
            background-attachment: fixed;
        }

        .referral-hero {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.34), transparent 34%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            border-radius: 32px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 30px 80px rgba(7, 63, 47, 0.32);
            position: relative;
            overflow: hidden;
        }

        .referral-hero::after {
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

        .referral-kicker {
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

        .referral-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .referral-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 720px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .referral-link-box {
            background: #fffdf7;
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 20px;
            padding: 15px;
            font-size: 13px;
            word-break: break-all;
            color: #4b3a12;
        }

        .referral-card-gold {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
        }

        .referral-card-green {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
        }

        .section-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
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
                    Share your link and track recruitment bonus earnings.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="referral-hero">
                <div class="referral-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="referral-title">
                    Grow your stokvel circle
                </div>

                <p class="referral-text">
                    Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Share your referral link. When someone joins under you and their saving is approved,
                    your recruitment bonus is recorded automatically.
                </p>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-label">Total Referrals</div>
                        <div class="stat-value"><?php echo $total_referrals; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Active Referrals</div>
                        <div class="stat-value"><?php echo $active_referrals; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Earned Bonus</div>
                        <div class="stat-value"><?php echo money($earned_bonus); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Paid Bonus</div>
                        <div class="stat-value"><?php echo money($paid_bonus); ?></div>
                    </div>
                </div>
            </div>

            <div class="card-box referral-card-gold mb-4">
                <div class="section-title mb-2">Your Referral Link</div>
                <p class="text-muted" style="font-size: 13px;">
                    Share this link with someone who wants to join the stokvel under you.
                </p>

                <div class="referral-link-box mb-3" id="referralLink">
                    <?php echo htmlspecialchars($referralLink); ?>
                </div>

                <button class="btn btn-dark btn-sm" onclick="copyReferralLink()">
                    Copy Referral Link
                </button>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card-box referral-card-green">
                        <div class="section-title mb-3">People Who Joined Under You</div>

                        <div class="table-responsive">
                            <table class="table align-middle">
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
                                                    <div class="text-muted" style="font-size:12px;">
                                                        <?php echo htmlspecialchars($row["member_code"] ?: "-"); ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($row["phone"] ?: "-"); ?></td>
                                                <td><?php echo statusBadge($row["status"]); ?></td>
                                                <td><?php echo date("d M Y", strtotime($row["created_at"])); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                No one has joined under your link yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card-box referral-card-gold">
                        <div class="section-title mb-3">Bonus History</div>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Referred Member</th>
                                        <th>Saving</th>
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
                                                    <div class="text-muted" style="font-size:12px;">
                                                        <?php echo number_format((float)$row["bonus_percent"], 2); ?>%
                                                    </div>
                                                </td>
                                                <td><?php echo statusBadge($row["status"]); ?></td>
                                                <td><?php echo date("d M Y H:i", strtotime($row["created_at"])); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">
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

    navigator.clipboard.writeText(text).then(function () {
        alert("Referral link copied.");
    }).catch(function () {
        alert("Could not copy link. Please copy it manually.");
    });
}
</script>

</body>
</html>