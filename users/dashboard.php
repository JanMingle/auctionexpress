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
$name = $_SESSION["name"] ?? "Member";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";

$displayName = $username ?: ($member_code ?: $name);

$bankCheckStmt = $conn->prepare("
    SELECT banking_details_completed
    FROM users
    WHERE id = ?
    AND tenant_id = ?
    LIMIT 1
");
$bankCheckStmt->bind_param("ii", $user_id, $tenant_id);
$bankCheckStmt->execute();
$bankCheck = $bankCheckStmt->get_result()->fetch_assoc();

if (!$bankCheck || (int)($bankCheck["banking_details_completed"] ?? 0) !== 1) {
    header("Location: banking_details.php");
    exit;
}

$packageRules = getTenantPackageRules($conn, $tenant_id);

$isAuctionPackage = function_exists("packageIsAuction")
    ? packageIsAuction($packageRules)
    : (($packageRules["package_type"] ?? "savings") === "auction");

$isSavingsPackage = !$isAuctionPackage;

$packageName = $packageRules["package_name"] ?? "Package";
$returnPercent = (float)($packageRules["return_rate_percent"] ?? 0);
$maturityDays = (int)($packageRules["maturity_days"] ?? 30);

if ($maturityDays <= 0) {
    $maturityDays = 30;
}

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function coins($amount) {
    return number_format((float)$amount, 2) . " coins";
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function statusBadge($status, $maturesAt = null) {
    if ($status === "pending" || $status === "pending_payment") {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "approved") {
        if (!empty($maturesAt) && strtotime($maturesAt) <= time()) {
            return '<span class="badge badge-approved">Matured</span>';
        }

        return '<span class="badge badge-pending">Maturing</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}

function auctionStatusBadge($status) {
    if ($status === "pending_seller_approval") {
        return '<span class="badge bg-warning text-dark">Pending Seller Approval</span>';
    }

    if ($status === "active") {
        return '<span class="badge bg-primary">Counting Down</span>';
    }

    if ($status === "matured") {
        return '<span class="badge bg-success">Ready to Sell</span>';
    }

    if ($status === "paid") {
        return '<span class="badge bg-success">Paid</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge bg-danger">Rejected</span>';
    }

    if ($status === "cancelled") {
        return '<span class="badge bg-secondary">Cancelled</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
}

function memberLabel($row, $prefix = "") {
    $username = $row[$prefix . "username"] ?? "";
    $memberCode = $row[$prefix . "member_code"] ?? "";
    $firstName = $row[$prefix . "first_name"] ?? "";
    $lastName = $row[$prefix . "last_name"] ?? "";

    if ($username !== "") {
        return $username;
    }

    if ($memberCode !== "") {
        return $memberCode;
    }

    $fullName = trim($firstName . " " . $lastName);

    return $fullName !== "" ? $fullName : "Member";
}

/*
    Savings dashboard values
*/
$total_requests = 0;
$pending_requests = 0;
$approved_expected_returns = 0;
$approved_expected_total = 0;
$matured_total = 0;
$withdrawn_total = 0;
$activeSaving = null;
$recentRequests = null;

/*
    Auction dashboard values
*/
$walletAvailable = 0;
$walletLocked = 0;
$walletEarned = 0;
$totalBought = 0;
$pendingBuyerPurchases = 0;
$activeShares = 0;
$maturedShares = 0;
$queuedSales = 0;
$shareValue = 0;
$pendingPurchaseAmount = 0;
$pendingSellerApprovals = 0;
$pendingSellerCoins = 0;
$activeShare = null;
$recentAuctionActivity = null;

/*
    Load package-specific dashboard data.
*/
if ($isSavingsPackage) {
    $statsStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_requests,

            SUM(CASE 
                WHEN status IN ('pending', 'pending_payment', 'payment_submitted') 
                THEN 1 ELSE 0 
            END) AS pending_requests,

            SUM(CASE 
                WHEN status = 'approved' 
                THEN expected_return_amount ELSE 0 
            END) AS approved_expected_returns,

            SUM(CASE 
                WHEN status = 'approved' 
                THEN expected_total_amount ELSE 0 
            END) AS approved_expected_total,

            SUM(CASE 
                WHEN status = 'approved' AND matures_at <= NOW()
                THEN expected_total_amount ELSE 0 
            END) AS matured_total,

            SUM(CASE 
                WHEN status = 'withdrawn'
                THEN expected_total_amount ELSE 0 
            END) AS withdrawn_total

        FROM savings_requests
        WHERE tenant_id = ?
        AND user_id = ?
    ");
    $statsStmt->bind_param("ii", $tenant_id, $user_id);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();

    $total_requests = (int)($stats["total_requests"] ?? 0);
    $pending_requests = (int)($stats["pending_requests"] ?? 0);
    $approved_expected_returns = (float)($stats["approved_expected_returns"] ?? 0);
    $approved_expected_total = (float)($stats["approved_expected_total"] ?? 0);
    $matured_total = (float)($stats["matured_total"] ?? 0);
    $withdrawn_total = (float)($stats["withdrawn_total"] ?? 0);

    $activeStmt = $conn->prepare("
        SELECT 
            amount,
            expected_return_amount,
            expected_total_amount,
            status,
            created_at,
            approved_at,
            matures_at
        FROM savings_requests
        WHERE tenant_id = ?
        AND user_id = ?
        AND status = 'approved'
        AND matures_at IS NOT NULL
        AND matures_at > NOW()
        ORDER BY matures_at ASC
        LIMIT 1
    ");
    $activeStmt->bind_param("ii", $tenant_id, $user_id);
    $activeStmt->execute();
    $activeSaving = $activeStmt->get_result()->fetch_assoc();

    $recentStmt = $conn->prepare("
        SELECT 
            id,
            amount,
            expected_return_amount,
            expected_total_amount,
            status,
            created_at,
            approved_at,
            matures_at,
            withdrawn_at
        FROM savings_requests
        WHERE tenant_id = ?
        AND user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentStmt->bind_param("ii", $tenant_id, $user_id);
    $recentStmt->execute();
    $recentRequests = $recentStmt->get_result();
} else {
    /*
        Auction package dashboard.
        First auto-mature approved auction shares whose countdown is done.
    */
    $matureStmt = $conn->prepare("
        UPDATE auction_claims
        SET status = 'matured'
        WHERE tenant_id = ?
        AND buyer_user_id = ?
        AND status = 'active'
        AND matures_at IS NOT NULL
        AND matures_at <= NOW()
    ");
    $matureStmt->bind_param("ii", $tenant_id, $user_id);
    $matureStmt->execute();

    $walletStmt = $conn->prepare("
        SELECT available_coins, locked_coins, total_earned
        FROM member_coin_wallets
        WHERE tenant_id = ?
        AND user_id = ?
        LIMIT 1
    ");
    $walletStmt->bind_param("ii", $tenant_id, $user_id);
    $walletStmt->execute();
    $wallet = $walletStmt->get_result()->fetch_assoc();

    $walletAvailable = (float)($wallet["available_coins"] ?? 0);
    $walletLocked = (float)($wallet["locked_coins"] ?? 0);
    $walletEarned = (float)($wallet["total_earned"] ?? 0);

    $auctionStatsStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_bought,

            SUM(CASE 
                WHEN status = 'pending_seller_approval' 
                THEN 1 ELSE 0 
            END) AS pending_buyer_purchases,

            SUM(CASE 
                WHEN status = 'active' 
                THEN 1 ELSE 0 
            END) AS active_shares,

            SUM(CASE 
                WHEN status = 'matured' 
                AND COALESCE(resale_status, 'not_listed') = 'not_listed'
                THEN 1 ELSE 0 
            END) AS matured_shares,

            SUM(CASE 
                WHEN COALESCE(resale_status, 'not_listed') = 'listed'
                THEN 1 ELSE 0 
            END) AS queued_sales,

            SUM(CASE 
                WHEN status IN ('active', 'matured')
                THEN total_due_coins ELSE 0 
            END) AS share_value,

            SUM(CASE 
                WHEN status = 'pending_seller_approval'
                THEN principal_coins ELSE 0 
            END) AS pending_purchase_amount

        FROM auction_claims
        WHERE tenant_id = ?
        AND buyer_user_id = ?
    ");
    $auctionStatsStmt->bind_param("ii", $tenant_id, $user_id);
    $auctionStatsStmt->execute();
    $auctionStats = $auctionStatsStmt->get_result()->fetch_assoc();

    $totalBought = (int)($auctionStats["total_bought"] ?? 0);
    $pendingBuyerPurchases = (int)($auctionStats["pending_buyer_purchases"] ?? 0);
    $activeShares = (int)($auctionStats["active_shares"] ?? 0);
    $maturedShares = (int)($auctionStats["matured_shares"] ?? 0);
    $queuedSales = (int)($auctionStats["queued_sales"] ?? 0);
    $shareValue = (float)($auctionStats["share_value"] ?? 0);
    $pendingPurchaseAmount = (float)($auctionStats["pending_purchase_amount"] ?? 0);

    $sellerStatsStmt = $conn->prepare("
        SELECT
            COUNT(*) AS pending_seller_approvals,
            COALESCE(SUM(principal_coins), 0) AS pending_seller_coins
        FROM auction_claims
        WHERE tenant_id = ?
        AND seller_user_id = ?
        AND status = 'pending_seller_approval'
    ");
    $sellerStatsStmt->bind_param("ii", $tenant_id, $user_id);
    $sellerStatsStmt->execute();
    $sellerStats = $sellerStatsStmt->get_result()->fetch_assoc();

    $pendingSellerApprovals = (int)($sellerStats["pending_seller_approvals"] ?? 0);
    $pendingSellerCoins = (float)($sellerStats["pending_seller_coins"] ?? 0);

    $activeShareStmt = $conn->prepare("
        SELECT
            id,
            principal_coins,
            return_percent,
            return_coins,
            total_due_coins,
            status,
            approved_at,
            matures_at,
            resale_status
        FROM auction_claims
        WHERE tenant_id = ?
        AND buyer_user_id = ?
        AND status = 'active'
        AND matures_at IS NOT NULL
        AND matures_at > NOW()
        AND COALESCE(resale_status, 'not_listed') = 'not_listed'
        ORDER BY matures_at ASC
        LIMIT 1
    ");
    $activeShareStmt->bind_param("ii", $tenant_id, $user_id);
    $activeShareStmt->execute();
    $activeShare = $activeShareStmt->get_result()->fetch_assoc();

    $recentAuctionStmt = $conn->prepare("
        SELECT
            auction_claims.*,

            buyer.username AS buyer_username,
            buyer.member_code AS buyer_member_code,
            buyer.first_name AS buyer_first_name,
            buyer.last_name AS buyer_last_name,

            seller.username AS seller_username,
            seller.member_code AS seller_member_code,
            seller.first_name AS seller_first_name,
            seller.last_name AS seller_last_name
        FROM auction_claims
        INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
        INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
        WHERE auction_claims.tenant_id = ?
        AND (
            auction_claims.buyer_user_id = ?
            OR auction_claims.seller_user_id = ?
        )
        ORDER BY auction_claims.claimed_at DESC, auction_claims.id DESC
        LIMIT 5
    ");
    $recentAuctionStmt->bind_param("iii", $tenant_id, $user_id, $user_id);
    $recentAuctionStmt->execute();
    $recentAuctionActivity = $recentAuctionStmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $isAuctionPackage ? "Auction Dashboard" : "Member Dashboard"; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <style>
        .member-hero {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.22), transparent 34%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            border-radius: 30px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 22px 50px rgba(16,36,31,0.16);
            position: relative;
            overflow: hidden;
        }

        .member-hero::after {
            content: "<?php echo $isAuctionPackage ? 'C' : 'R'; ?>";
            position: absolute;
            right: 34px;
            top: 24px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: #4a3504;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: 900;
            opacity: 0.22;
            transform: rotate(-12deg);
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.82);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 16px;
        }

        .hero-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .member-hero-title {
            font-size: 32px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .member-hero-text {
            color: rgba(255,255,255,0.75);
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
        }

        .btn-hero-outline {
            background: transparent;
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.28);
            border-radius: 16px;
            font-weight: 900;
            padding: 11px 15px;
            text-decoration: none;
        }

        .btn-hero-outline:hover,
        .btn-hero-light:hover {
            transform: translateY(-1px);
        }

        .saving-focus-card {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.75);
            border-radius: 28px;
            padding: 24px;
            box-shadow: 0 18px 50px rgba(16,36,31,0.10);
            backdrop-filter: blur(18px);
            margin-bottom: 24px;
        }

        .focus-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
            align-items: center;
        }

        .focus-title {
            font-size: 18px;
            font-weight: 900;
            color: #10241f;
            letter-spacing: -0.03em;
            margin-bottom: 6px;
        }

        .focus-text {
            color: #667085;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 0;
        }

        .countdown-panel {
            background: linear-gradient(135deg, #fff8df, #eef7f1);
            border: 1px solid rgba(216,169,40,0.28);
            border-radius: 22px;
            padding: 18px;
        }

        .countdown-label {
            font-size: 12px;
            font-weight: 800;
            color: #7a5a09;
            margin-bottom: 5px;
        }

        .countdown-time {
            font-size: 24px;
            font-weight: 900;
            color: #073f2f;
            letter-spacing: -0.04em;
        }

        .quick-card-title {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: -0.03em;
            margin-bottom: 6px;
        }

        .request-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid rgba(16,36,31,0.08);
        }

        .request-item:last-child {
            border-bottom: 0;
        }

        .request-title {
            font-weight: 900;
            color: #10241f;
        }

        .request-meta {
            font-size: 12px;
            color: #667085;
            margin-top: 3px;
        }

        .request-amount {
            text-align: right;
            font-weight: 900;
            color: #073f2f;
            white-space: nowrap;
        }

        .package-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 8px 12px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.16);
            color: rgba(255,255,255,0.82);
            font-size: 12px;
            font-weight: 800;
            position: relative;
            z-index: 2;
            margin-bottom: 16px;
        }

        @media (max-width: 900px) {
            .focus-grid {
                grid-template-columns: 1fr;
            }

            .member-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .member-hero-title {
                font-size: 27px;
            }

            .member-hero::after {
                width: 70px;
                height: 70px;
                font-size: 28px;
                right: 18px;
                top: 18px;
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
                <div class="app-topbar-title">
                    <?php echo $isAuctionPackage ? "Auction Dashboard" : "Member Dashboard"; ?>
                </div>
                <div class="app-topbar-subtitle">
                    <?php if ($isAuctionPackage): ?>
                        Track your coin purchases, seller approvals, shares, and auction queue.
                    <?php else: ?>
                        Track your savings circle, returns, and withdrawals.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="app-content">

            <?php if ($isAuctionPackage): ?>

                <div class="member-hero">
                    <div class="hero-kicker">
                        <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>

                    <div class="package-pill">
                        <?php echo htmlspecialchars($packageName); ?>
                        · <?php echo number_format($returnPercent, 2); ?>%
                        · <?php echo (int)$maturityDays; ?> days
                    </div>

                    <div class="member-hero-title">
                        Welcome, <?php echo htmlspecialchars($displayName); ?>
                    </div>

                    <p class="member-hero-text">
                        This is your auction dashboard. Buy coins from other members, track seller approvals,
                        watch your approved shares mature, and sell matured shares back into the auction queue.
                    </p>

                    <div class="hero-actions">
                        <a href="auction.php" class="btn-hero-light">
                            Open Auction
                        </a>

                        <a href="auction_purchases.php" class="btn-hero-outline">
                            My Coin Purchases
                        </a>

                        <a href="sell_shares.php" class="btn-hero-outline">
                            Sell Shares
                        </a>

                        <a href="auction_history.php" class="btn-hero-outline">
                            Auction History
                        </a>
                    </div>
                </div>

                <?php if ($activeShare): ?>
                    <div class="saving-focus-card">
                        <div class="focus-grid">
                            <div>
                                <div class="focus-title">
                                    Your share is counting down
                                </div>
                                <p class="focus-text">
                                    You bought <strong><?php echo coins($activeShare["principal_coins"]); ?></strong>.
                                    Your expected return is 
                                    <strong><?php echo coins($activeShare["return_coins"]); ?></strong>,
                                    giving you a sell value of
                                    <strong><?php echo coins($activeShare["total_due_coins"]); ?></strong>.
                                </p>
                            </div>

                            <div class="countdown-panel">
                                <div class="countdown-label">Ready to sell in</div>
                                <div 
                                    class="countdown-time js-countdown"
                                    data-target="<?php echo htmlspecialchars(date("c", strtotime($activeShare["matures_at"]))); ?>"
                                >
                                    Calculating...
                                </div>

                                <div class="text-muted mt-1" style="font-size: 12px;">
                                    Maturity date: <?php echo date("d M Y H:i", strtotime($activeShare["matures_at"])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="saving-focus-card">
                        <div class="focus-grid">
                            <div>
                                <div class="focus-title">
                                    <?php echo $maturedShares > 0 ? "You have shares ready to sell" : "Ready to buy auction coins?"; ?>
                                </div>
                                <p class="focus-text">
                                    <?php if ($maturedShares > 0): ?>
                                        Some of your approved shares have completed their countdown. You can list them into the next auction queue.
                                    <?php else: ?>
                                        Buy coins from the auction. Once the seller approves, your countdown starts and you can sell the matured shares later.
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="text-lg-end">
                                <?php if ($maturedShares > 0): ?>
                                    <a href="sell_shares.php" class="btn btn-dark">
                                        Sell Matured Shares
                                    </a>
                                <?php else: ?>
                                    <a href="auction.php" class="btn btn-dark">
                                        Go to Auction
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card stat-card-green">
                            <div class="stat-label">Pending Purchases</div>
                            <div class="stat-value"><?php echo $pendingBuyerPurchases; ?></div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card stat-card-gold">
                            <div class="stat-label">Active Shares</div>
                            <div class="stat-value"><?php echo $activeShares; ?></div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card stat-card-blue">
                            <div class="stat-label">Ready to Sell</div>
                            <div class="stat-value"><?php echo $maturedShares; ?></div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card stat-card-red">
                            <div class="stat-label">Queued Sales</div>
                            <div class="stat-value"><?php echo $queuedSales; ?></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card-box card-box-soft-green">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="quick-card-title mb-1">Recent Auction Activity</h5>
                                    <p class="text-muted mb-0" style="font-size: 13px;">
                                        Latest coins you bought or sold.
                                    </p>
                                </div>

                                <a href="auction_history.php" class="btn btn-outline-dark btn-sm">
                                    View All
                                </a>
                            </div>

                            <?php if ($recentAuctionActivity && $recentAuctionActivity->num_rows > 0): ?>
                                <?php while ($row = $recentAuctionActivity->fetch_assoc()): ?>
                                    <?php
                                        $isBuyer = (int)$row["buyer_user_id"] === $user_id;
                                        $title = $isBuyer ? "You bought coins" : "You sold coins";
                                        $otherMember = $isBuyer
                                            ? memberLabel($row, "seller_")
                                            : memberLabel($row, "buyer_");
                                    ?>

                                    <div class="request-item">
                                        <div>
                                            <div class="request-title">
                                                <?php echo htmlspecialchars($title); ?>
                                            </div>
                                            <div class="request-meta">
                                                <?php echo htmlspecialchars(displayDate($row["claimed_at"])); ?>
                                                · <?php echo auctionStatusBadge($row["status"]); ?>
                                                · <?php echo htmlspecialchars($otherMember); ?>
                                            </div>
                                        </div>

                                        <div class="request-amount">
                                            <?php echo coins($row["principal_coins"]); ?>
                                            <div class="text-muted" style="font-size: 12px;">
                                                Total <?php echo coins($row["total_due_coins"]); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    No auction activity yet. Start by buying coins from the auction.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card-box card-box-soft-gold mb-4">
                            <h5 class="quick-card-title">Auction Snapshot</h5>
                            <p class="text-muted" style="font-size: 13px;">
                                Your current auction position.
                            </p>

                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span class="text-muted">Pending Buyer Payments</span>
                                <strong><?php echo coins($pendingPurchaseAmount); ?></strong>
                            </div>

                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span class="text-muted">Share Value</span>
                                <strong><?php echo coins($shareValue); ?></strong>
                            </div>

                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span class="text-muted">Seller Approvals</span>
                                <strong><?php echo $pendingSellerApprovals; ?></strong>
                            </div>

                            <div class="d-flex justify-content-between py-2">
                                <span class="text-muted">Seller Coins Pending</span>
                                <strong><?php echo coins($pendingSellerCoins); ?></strong>
                            </div>
                        </div>

                        <div class="card-box card-box-soft-mixed">
                            <h5 class="quick-card-title">Quick Actions</h5>
                            <p class="text-muted" style="font-size: 13px;">
                                Continue your auction journey.
                            </p>

                            <div class="d-grid gap-2">
                                <a href="auction.php" class="btn btn-dark">
                                    Open Auction
                                </a>

                                <a href="auction_purchases.php" class="btn btn-outline-dark">
                                    My Coin Purchases
                                </a>

                                <a href="auction_pending_approval.php" class="btn btn-outline-dark">
                                    Pending Approval
                                    <?php if ($pendingSellerApprovals > 0): ?>
                                        (<?php echo $pendingSellerApprovals; ?>)
                                    <?php endif; ?>
                                </a>

                                <a href="sell_shares.php" class="btn btn-outline-dark">
                                    Sell Shares
                                    <?php if ($maturedShares > 0): ?>
                                        (<?php echo $maturedShares; ?> ready)
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <div class="member-hero">
                    <div class="hero-kicker">
                        <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>

                    <div class="package-pill">
                        <?php echo htmlspecialchars($packageName); ?>
                        · <?php echo number_format($returnPercent, 2); ?>%
                        · <?php echo (int)$maturityDays; ?> days
                    </div>

                    <div class="member-hero-title">
                        Welcome, <?php echo htmlspecialchars($displayName); ?>
                    </div>

                    <p class="member-hero-text">
                        This is your personal stokvel space. Submit savings, upload proof of payment,
                        watch your returns mature, and withdraw when your cycle is complete.
                    </p>

                    <div class="hero-actions">
                        <a href="savings.php" class="btn-hero-light">
                            Start Saving
                        </a>

                        <a href="statements.php" class="btn-hero-outline">
                            View Statement
                        </a>

                        <a href="../group_chat.php" class="btn-hero-outline">
                            Open Group Chat
                        </a>
                    </div>
                </div>

                <?php if ($activeSaving): ?>
                    <div class="saving-focus-card">
                        <div class="focus-grid">
                            <div>
                                <div class="focus-title">
                                    Your current saving is growing
                                </div>
                                <p class="focus-text">
                                    You saved <strong><?php echo money($activeSaving["amount"]); ?></strong>.
                                    Your expected return is 
                                    <strong><?php echo money($activeSaving["expected_return_amount"]); ?></strong>,
                                    giving you an expected total of 
                                    <strong><?php echo money($activeSaving["expected_total_amount"]); ?></strong>.
                                </p>
                            </div>

                            <div class="countdown-panel">
                                <div class="countdown-label">Matures in</div>
                                <div 
                                    class="countdown-time js-countdown"
                                    data-target="<?php echo htmlspecialchars(date("c", strtotime($activeSaving["matures_at"]))); ?>"
                                >
                                    Calculating...
                                </div>

                                <div class="text-muted mt-1" style="font-size: 12px;">
                                    Maturity date: <?php echo date("d M Y H:i", strtotime($activeSaving["matures_at"])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="saving-focus-card">
                        <div class="focus-grid">
                            <div>
                                <div class="focus-title">
                                    Ready to start a new saving cycle?
                                </div>
                                <p class="focus-text">
                                    Submit the amount you want to save, upload proof of payment,
                                    and your admin will approve it before the return countdown starts.
                                </p>
                            </div>

                            <div class="text-lg-end">
                                <a href="savings.php" class="btn btn-dark">
                                    Submit Saving Request
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card stat-card-green">
                            <div class="stat-label">My Requests</div>
                            <div class="stat-value"><?php echo $total_requests; ?></div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card stat-card-gold">
                            <div class="stat-label">Pending</div>
                            <div class="stat-value"><?php echo $pending_requests; ?></div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card stat-card-blue">
                            <div class="stat-label">Active Returns</div>
                            <div class="stat-value">
                                <?php echo money($approved_expected_returns); ?>
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

                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card-box card-box-soft-green">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="quick-card-title mb-1">Recent Saving Activity</h5>
                                    <p class="text-muted mb-0" style="font-size: 13px;">
                                        Latest requests and saving cycles.
                                    </p>
                                </div>

                                <a href="savings.php" class="btn btn-outline-dark btn-sm">
                                    View All
                                </a>
                            </div>

                            <?php if ($recentRequests && $recentRequests->num_rows > 0): ?>
                                <?php while ($row = $recentRequests->fetch_assoc()): ?>
                                    <div class="request-item">
                                        <div>
                                            <div class="request-title">
                                                Saving Request
                                            </div>
                                            <div class="request-meta">
                                                <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                                · <?php echo statusBadge($row["status"], $row["matures_at"]); ?>
                                            </div>
                                        </div>

                                        <div class="request-amount">
                                            <?php echo money($row["expected_total_amount"]); ?>
                                            <div class="text-muted" style="font-size: 12px;">
                                                Saved <?php echo money($row["amount"]); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    No saving activity yet. Start by submitting your first saving request.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card-box card-box-soft-gold mb-4">
                            <h5 class="quick-card-title">Money Snapshot</h5>
                            <p class="text-muted" style="font-size: 13px;">
                                Your active and completed stokvel value.
                            </p>

                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span class="text-muted">Matured / Ready</span>
                                <strong><?php echo money($matured_total); ?></strong>
                            </div>

                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span class="text-muted">Withdrawn</span>
                                <strong><?php echo money($withdrawn_total); ?></strong>
                            </div>

                            <div class="d-flex justify-content-between py-2">
                                <span class="text-muted">Active Balance</span>
                                <strong><?php echo money($approved_expected_total); ?></strong>
                            </div>
                        </div>

                        <div class="card-box card-box-soft-mixed">
                            <h5 class="quick-card-title">Quick Actions</h5>
                            <p class="text-muted" style="font-size: 13px;">
                                Continue your stokvel journey.
                            </p>

                            <div class="d-grid gap-2">
                                <a href="savings.php" class="btn btn-dark">
                                    Submit Saving Request
                                </a>

                                <a href="withdrawals.php" class="btn btn-outline-dark">
                                    My Withdrawals
                                </a>

                                <a href="statements.php" class="btn btn-outline-dark">
                                    My Statement
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </main>

</div>

<script>
function updateCountdowns() {
    const countdowns = document.querySelectorAll(".js-countdown");

    countdowns.forEach(function (item) {
        const target = new Date(item.dataset.target).getTime();
        const now = new Date().getTime();
        const distance = target - now;

        if (distance <= 0) {
            item.textContent = "<?php echo $isAuctionPackage ? 'Ready to sell' : 'Ready to withdraw'; ?>";
            return;
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        item.textContent = days + "d " + hours + "h " + minutes + "m " + seconds + "s";
    });
}

updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>

</body>
</html>