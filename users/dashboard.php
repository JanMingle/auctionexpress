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
        return '<span class="badge bg-warning text-dark">Pending Seller</span>';
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
    Extra values for crypto dashboard
*/
$activeMembersCount = 0;
$nextAuctionEntries = 0;
$nextAuctionCoins = 0;

$auctionStatus = "closed";
$totalLiveCoins = 0;
$totalNextCoins = 0;
$displayAuctionCoins = 0;
$auctionCoinsLabel = "Available Auction Coins";

$sharesOnSaleCount = 0;
$sharesOnSaleCoins = 0;
$sharesSoldCount = 0;
$soldSharesRevenue = 0;
$transactionsCount = 0;

/*
    Load package-specific dashboard data
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

    /*
        Extra crypto dashboard stats
    */
    $activeMembersStmt = $conn->prepare("
        SELECT COUNT(*) AS total_active_members
        FROM users
        WHERE tenant_id = ?
        AND role = 'member'
        AND status = 'active'
    ");
    $activeMembersStmt->bind_param("i", $tenant_id);
    $activeMembersStmt->execute();
    $activeMembersRow = $activeMembersStmt->get_result()->fetch_assoc();
    $activeMembersCount = (int)($activeMembersRow["total_active_members"] ?? 0);

 /*
    Match the same available auction coins logic used on users/auction.php.
    If auction is open, show live auction coins.
    If auction is closed, show next auction available coins from active member wallets.
*/

$auctionStatusStmt = $conn->prepare("
    SELECT auction_status
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$auctionStatusStmt->bind_param("i", $tenant_id);
$auctionStatusStmt->execute();
$auctionStatusRow = $auctionStatusStmt->get_result()->fetch_assoc();

$auctionStatus = $auctionStatusRow["auction_status"] ?? "closed";

$liveStatsStmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_entries,
        COALESCE(SUM(remaining_coins), 0) AS total_live_coins
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'open'
    AND remaining_coins > 0
");
$liveStatsStmt->bind_param("i", $tenant_id);
$liveStatsStmt->execute();
$liveStats = $liveStatsStmt->get_result()->fetch_assoc();

$totalLiveEntries = (int)($liveStats["total_entries"] ?? 0);
$totalLiveCoins = (float)($liveStats["total_live_coins"] ?? 0);

$nextStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(member_coin_wallets.available_coins), 0) AS total_next_coins
    FROM users
    LEFT JOIN member_coin_wallets
        ON member_coin_wallets.user_id = users.id
        AND member_coin_wallets.tenant_id = users.tenant_id
    WHERE users.tenant_id = ?
    AND users.role = 'member'
    AND users.status = 'active'
");
$nextStatsStmt->bind_param("i", $tenant_id);
$nextStatsStmt->execute();
$nextStats = $nextStatsStmt->get_result()->fetch_assoc();

$totalNextCoins = (float)($nextStats["total_next_coins"] ?? 0);

$scheduledEntriesStmt = $conn->prepare("
    SELECT COUNT(*) AS total_scheduled_entries
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'scheduled'
    AND remaining_coins > 0
");
$scheduledEntriesStmt->bind_param("i", $tenant_id);
$scheduledEntriesStmt->execute();
$scheduledEntriesRow = $scheduledEntriesStmt->get_result()->fetch_assoc();

$totalScheduledEntries = (int)($scheduledEntriesRow["total_scheduled_entries"] ?? 0);

if ($auctionStatus === "open") {
    $displayAuctionCoins = $totalLiveCoins;
    $nextAuctionEntries = $totalLiveEntries;
    $auctionCoinsLabel = "Available Auction Coins";
} else {
    $displayAuctionCoins = $totalNextCoins;
    $nextAuctionEntries = $totalScheduledEntries;
    $auctionCoinsLabel = "Next Auction Available Coins";
}

$nextAuctionCoins = $displayAuctionCoins;

    $sharesOnSaleStmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_on_sale,
            COALESCE(SUM(remaining_coins), 0) AS coins_on_sale
        FROM auction_lots
        WHERE tenant_id = ?
        AND seller_user_id = ?
        AND source_claim_id IS NOT NULL
        AND status IN ('scheduled', 'open')
        AND remaining_coins > 0
    ");
    $sharesOnSaleStmt->bind_param("ii", $tenant_id, $user_id);
    $sharesOnSaleStmt->execute();
    $sharesOnSaleRow = $sharesOnSaleStmt->get_result()->fetch_assoc();

    $sharesOnSaleCount = (int)($sharesOnSaleRow["total_on_sale"] ?? 0);
    $sharesOnSaleCoins = (float)($sharesOnSaleRow["coins_on_sale"] ?? 0);

    $soldSharesStmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_sold,
            COALESCE(SUM(principal_coins), 0) AS revenue
        FROM auction_claims
        WHERE tenant_id = ?
        AND seller_user_id = ?
        AND status NOT IN ('pending_seller_approval', 'rejected', 'cancelled')
    ");
    $soldSharesStmt->bind_param("ii", $tenant_id, $user_id);
    $soldSharesStmt->execute();
    $soldSharesRow = $soldSharesStmt->get_result()->fetch_assoc();

    $sharesSoldCount = (int)($soldSharesRow["total_sold"] ?? 0);
    $soldSharesRevenue = (float)($soldSharesRow["revenue"] ?? 0);

    $transactionsStmt = $conn->prepare("
        SELECT COUNT(*) AS total_transactions
        FROM auction_claims
        WHERE tenant_id = ?
        AND (
            buyer_user_id = ?
            OR seller_user_id = ?
        )
    ");
    $transactionsStmt->bind_param("iii", $tenant_id, $user_id, $user_id);
    $transactionsStmt->execute();
    $transactionsRow = $transactionsStmt->get_result()->fetch_assoc();

    $transactionsCount = (int)($transactionsRow["total_transactions"] ?? 0);
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

    <>
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

        <?php if ($isAuctionPackage): ?>
        body {
            background:
                radial-gradient(circle at 20% 0%, rgba(69, 90, 145, 0.22), transparent 34%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 50%, #0b1424 100%) !important;
            color: rgba(255,255,255,0.86);
        }

        .app-main {
            background:
                radial-gradient(circle at 85% 5%, rgba(168, 59, 216, 0.12), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 100%) !important;
        }

        .app-topbar {
            background:
                linear-gradient(rgba(13,24,41,0.84), rgba(13,24,41,0.90)),
                radial-gradient(circle at top right, rgba(59,130,246,0.16), transparent 34%) !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
            color: #ffffff;
        }

        .app-topbar-title,
        .app-topbar-subtitle {
            color: rgba(255,255,255,0.88) !important;
        }

        .app-content::before {
            display: none !important;
        }

        .crypto-dashboard {
            max-width: 1180px;
            margin: 0 auto;
        }

        .crypto-page-title {
            font-size: 30px;
            font-weight: 400;
            color: rgba(255,255,255,0.72);
            margin-bottom: 20px;
        }

        .crypto-top-cover {
            min-height: 115px;
            border-radius: 3px;
            background:
                linear-gradient(rgba(13,24,41,0.70), rgba(13,24,41,0.94)),
                radial-gradient(circle at right top, rgba(16,185,129,0.14), transparent 30%),
                linear-gradient(135deg, #162239, #0d1829);
            border: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 20px;
            padding: 22px;
            position: relative;
            overflow: hidden;
        }

        .crypto-top-cover::after {
            content: "";
            position: absolute;
            right: 28px;
            top: 22px;
            width: 46px;
            height: 32px;
            border-top: 4px solid rgba(255,255,255,0.35);
            border-bottom: 4px solid rgba(255,255,255,0.35);
        }

        .crypto-online-banner {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
            color: #ffffff;
            border-radius: 5px;
            padding: 18px 22px;
            font-size: 18px;
            margin-bottom: 22px;
            box-shadow: 0 18px 35px rgba(255,122,0,0.16);
        }

        .crypto-auction-panel {
            background: rgba(22, 34, 57, 0.78);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 4px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .crypto-auction-title {
            font-size: 30px;
            font-weight: 300;
            color: rgba(255,255,255,0.62);
            margin-bottom: 6px;
        }

        .crypto-auction-subtitle {
            color: rgba(255,255,255,0.32);
            font-size: 17px;
            margin-bottom: 26px;
        }

        .crypto-countdown-row {
            display: flex;
            align-items: center;
            gap: 18px;
            color: rgba(255,255,255,0.72);
            font-size: 17px;
        }

        .crypto-dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 26px;
            margin-bottom: 26px;
        }

        .crypto-dashboard-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            min-height: 165px;
            padding: 22px 22px 18px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 24px 42px rgba(0,0,0,0.16);
        }

        .crypto-icon-tile {
            position: absolute;
            left: 24px;
            top: -1px;
            width: 112px;
            height: 112px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 54px;
            font-weight: 300;
            box-shadow: 0 16px 30px rgba(0,0,0,0.16);
        }

        .crypto-icon-tile.cyan {
            background: linear-gradient(135deg, #00c6d7, #11a7d8);
        }

        .crypto-icon-tile.green {
            background: linear-gradient(135deg, #31b96f, #15905a);
        }

        .crypto-icon-tile.red {
            background: linear-gradient(135deg, #ff4338, #e92820);
        }

        .crypto-icon-tile.orange {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
        }

        .crypto-card-main {
            min-height: 105px;
            padding-left: 132px;
            text-align: right;
        }

        .crypto-card-label {
            color: rgba(255,255,255,0.50);
            font-size: 18px;
            margin-bottom: 5px;
        }

        .crypto-card-value {
            color: rgba(255,255,255,0.50);
            font-size: 31px;
            font-weight: 300;
            line-height: 1.15;
        }

        .crypto-card-secondary {
            margin-top: 22px;
        }

        .crypto-card-footer {
            border-top: 1px solid rgba(255,255,255,0.055);
            padding-top: 16px;
            color: rgba(255,255,255,0.45);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .next-auction-card {
            padding-top: 36px;
            margin-bottom: 26px;
        }

        .next-auction-ribbon {
            position: absolute;
            top: -1px;
            left: 26px;
            background: linear-gradient(135deg, #32b96e, #1b9e5f);
            color: #ffffff;
            border-radius: 4px;
            padding: 20px 24px;
            min-width: 245px;
            font-weight: 900;
            font-style: italic;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            box-shadow: 0 18px 30px rgba(27,158,95,0.20);
        }

        .next-auction-body {
            text-align: right;
            padding-left: 260px;
            min-height: 100px;
        }

        .crypto-offers-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .crypto-offers-heading {
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            color: #ffffff;
            padding: 24px 28px;
        }

        .crypto-offers-heading h4 {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 400;
        }

        .crypto-offers-heading p {
            margin: 0;
            color: rgba(255,255,255,0.70);
        }

        .crypto-offers-table {
            width: 100%;
            color: rgba(255,255,255,0.58);
            font-size: 14px;
        }

        .crypto-offers-table th {
            color: rgba(255,255,255,0.50);
            font-size: 15px;
            font-weight: 500;
            padding: 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.055);
        }

        .crypto-offers-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.045);
        }

        .crypto-empty {
            padding: 28px;
            text-align: center;
            color: rgba(255,255,255,0.42);
        }

        .crypto-action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }

        .crypto-action {
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.045);
            color: rgba(255,255,255,0.76);
            text-decoration: none;
            border-radius: 5px;
            padding: 13px 16px;
            font-weight: 700;
        }

        .crypto-action:hover {
            background: rgba(255,255,255,0.08);
            color: #ffffff;
        }

        @media (max-width: 1100px) {
            .crypto-dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .crypto-page-title {
                font-size: 27px;
            }

            .crypto-top-cover {
                margin-top: 4px;
            }

            .crypto-dashboard-grid {
                gap: 22px;
            }

            .crypto-dashboard-card {
                min-height: 180px;
            }

            .crypto-icon-tile {
                width: 104px;
                height: 104px;
                left: 24px;
                font-size: 48px;
            }

            .crypto-card-main {
                padding-left: 125px;
            }

            .crypto-card-label {
                font-size: 17px;
            }

            .crypto-card-value {
                font-size: 28px;
            }

            .next-auction-ribbon {
                position: relative;
                left: auto;
                top: auto;
                display: inline-block;
                min-width: 0;
                margin-bottom: 20px;
            }

            .next-auction-body {
                padding-left: 0;
                text-align: right;
            }

            .crypto-offers-table {
                min-width: 650px;
            }

            .crypto-offers-scroll {
                overflow-x: auto;
            }
        }
        <?php endif; ?>
        <?php if ($isAuctionPackage): ?>
/* Compact auction dashboard override */
body {
    font-size: 12px !important;
}

.app-topbar-title,
.topbar-title {
    font-size: 14px !important;
    font-weight: 700 !important;
}

.app-topbar-subtitle,
.topbar-subtitle,
.topbar-user {
    font-size: 11px !important;
}

.crypto-dashboard {
    max-width: 980px !important;
}

.crypto-page-title {
    font-size: 22px !important;
    margin-bottom: 14px !important;
}

.crypto-top-cover {
    min-height: 82px !important;
    padding: 16px !important;
    margin-bottom: 16px !important;
}

.crypto-top-cover div {
    font-size: 11px !important;
}

.crypto-top-cover::after {
    right: 20px !important;
    top: 18px !important;
    width: 34px !important;
    height: 24px !important;
    border-top-width: 3px !important;
    border-bottom-width: 3px !important;
}

.crypto-online-banner {
    padding: 12px 16px !important;
    font-size: 12px !important;
    margin-bottom: 16px !important;
    border-radius: 5px !important;
}

.crypto-auction-panel {
    padding: 16px !important;
    margin-bottom: 16px !important;
}

.crypto-auction-title {
    font-size: 18px !important;
    margin-bottom: 5px !important;
}

.crypto-auction-subtitle {
    font-size: 12px !important;
    margin-bottom: 14px !important;
}

.crypto-countdown-row {
    font-size: 12px !important;
    gap: 10px !important;
}

.crypto-action-row {
    gap: 8px !important;
    margin-bottom: 16px !important;
}

.crypto-action {
    padding: 8px 11px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 5px !important;
}

.crypto-dashboard-grid {
    gap: 12px !important;
    margin-bottom: 16px !important;
}

.crypto-dashboard-card {
    min-height: 118px !important;
    padding: 14px !important;
    border-radius: 5px !important;
    box-shadow: 0 18px 32px rgba(0,0,0,0.12) !important;
}

.crypto-icon-tile {
    left: 14px !important;
    top: -1px !important;
    width: 70px !important;
    height: 70px !important;
    font-size: 28px !important;
    border-radius: 5px !important;
}

.crypto-card-main {
    min-height: 70px !important;
    padding-left: 84px !important;
}

.crypto-card-label {
    font-size: 11px !important;
    margin-bottom: 4px !important;
}

.crypto-card-value {
    font-size: 18px !important;
    line-height: 1.2 !important;
}

.crypto-card-secondary {
    margin-top: 12px !important;
}

.crypto-card-footer {
    padding-top: 10px !important;
    font-size: 11px !important;
    gap: 6px !important;
}

.next-auction-card {
    padding-top: 28px !important;
    margin-bottom: 16px !important;
}

.next-auction-ribbon {
    left: 16px !important;
    padding: 11px 14px !important;
    min-width: 160px !important;
    font-size: 11px !important;
    border-radius: 5px !important;
}

.next-auction-body {
    padding-left: 175px !important;
    min-height: 62px !important;
}

.crypto-offers-card {
    margin-bottom: 18px !important;
}

.crypto-offers-heading {
    padding: 14px 16px !important;
}

.crypto-offers-heading h4 {
    font-size: 16px !important;
    margin-bottom: 4px !important;
}

.crypto-offers-heading p {
    font-size: 11px !important;
}

.crypto-offers-table {
    font-size: 11px !important;
}

.crypto-offers-table th {
    font-size: 11px !important;
    padding: 10px 12px !important;
}

.crypto-offers-table td {
    padding: 10px 12px !important;
}

.crypto-empty {
    padding: 18px !important;
    font-size: 11px !important;
}

.badge {
    font-size: 10px !important;
    padding: 5px 7px !important;
}

@media (max-width: 700px) {
    .crypto-page-title {
        font-size: 20px !important;
    }

    .crypto-dashboard-grid {
        gap: 12px !important;
    }

    .crypto-dashboard-card {
        min-height: 120px !important;
    }

    .crypto-icon-tile {
        width: 64px !important;
        height: 64px !important;
        left: 14px !important;
        font-size: 25px !important;
    }

    .crypto-card-main {
        padding-left: 78px !important;
    }

    .crypto-card-label {
        font-size: 10px !important;
    }

    .crypto-card-value {
        font-size: 16px !important;
    }

    .next-auction-ribbon {
        position: relative !important;
        left: auto !important;
        top: auto !important;
        display: inline-block !important;
        min-width: 0 !important;
        margin-bottom: 12px !important;
    }

    .next-auction-body {
        padding-left: 0 !important;
        text-align: right !important;
    }
}
<?php endif; ?>
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
                        Track your shares, offers, purchases, and next auction entries.
                    <?php else: ?>
                        Track your savings circle, returns, and withdrawals.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="app-content">

            <?php if ($isAuctionPackage): ?>

                <div class="crypto-dashboard">

                    <div class="crypto-page-title">
                        Dashboard
                    </div>

                    <div class="crypto-top-cover">
                        <div style="color: rgba(255,255,255,0.42); font-size: 13px;">
                            <?php echo htmlspecialchars($stokvel_name); ?>
                        </div>
                    </div>

                    <div class="crypto-online-banner">
                        Users Online <?php echo (int)$activeMembersCount; ?>
                    </div>

                    <div class="crypto-auction-panel">
                        <div class="crypto-auction-title">
                            Auction
                        </div>

                        <div class="crypto-auction-subtitle">
                            Our current package return is 
                            <?php echo number_format($returnPercent, 2); ?>%
                            over <?php echo (int)$maturityDays; ?> days.
                        </div>

                        <?php if ($activeShare): ?>
                            <div class="crypto-countdown-row">
                                <span>◷</span>
                                <span 
                                    class="js-countdown"
                                    data-target="<?php echo htmlspecialchars(date("c", strtotime($activeShare["matures_at"]))); ?>"
                                >
                                    Calculating...
                                </span>
                            </div>
                        <?php else: ?>
                            <div class="crypto-countdown-row">
                                <span>◷</span>
                                <span>No active countdown yet</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="crypto-action-row">
                        <a href="auction.php" class="crypto-action">Open Auction</a>
                        <a href="auction_purchases.php" class="crypto-action">My Coin Purchases</a>
                        <a href="auction_pending_approval.php" class="crypto-action">Pending Shares</a>
                        <a href="sell_shares.php" class="crypto-action">Sell Shares</a>
                        <a href="auction_history.php" class="crypto-action">Auction History</a>
                    </div>

                    <div class="crypto-dashboard-grid">

                        <div class="crypto-dashboard-card">
                            <div class="crypto-icon-tile orange">
                                ▣
                            </div>

                            <div class="crypto-card-main">
                                <div class="crypto-card-label">
                                    Pending Shares
                                </div>
                                <div class="crypto-card-value">
                                    <?php echo money($pendingPurchaseAmount); ?> ZAR
                                </div>
                            </div>

                            <div class="crypto-card-footer">
                                ⚠ Complete order
                            </div>
                        </div>

                        <div class="crypto-dashboard-card">
                            <div class="crypto-icon-tile green">
                                ▬
                            </div>

                            <div class="crypto-card-main">
                                <div class="crypto-card-label">
                                    Revenue
                                </div>
                                <div class="crypto-card-value">
                                    <?php echo money($soldSharesRevenue); ?>
                                </div>
                            </div>

                            <div class="crypto-card-footer">
                                ▣ Total Shares Sold: <?php echo (int)$sharesSoldCount; ?>
                            </div>
                        </div>

                        <div class="crypto-dashboard-card">
                            <div class="crypto-icon-tile red">
                                ⓘ
                            </div>

                            <div class="crypto-card-main">
                                <div class="crypto-card-label">
                                    Transactions
                                </div>
                                <div class="crypto-card-value">
                                    <?php echo (int)$transactionsCount; ?>
                                </div>
                            </div>

                            <div class="crypto-card-footer">
                                ◆ Shares I bought: <?php echo (int)$totalBought; ?>
                            </div>
                        </div>

                        <div class="crypto-dashboard-card">
                            <div class="crypto-icon-tile cyan">
                                $
                            </div>

                            <div class="crypto-card-main">
                                <div class="crypto-card-label">
                                    My Net Shares
                                </div>
                                <div class="crypto-card-value">
                                    <?php echo money($shareValue); ?>
                                </div>

                                <div class="crypto-card-secondary">
                                    <div class="crypto-card-label">
                                        My Bonuses
                                    </div>
                                    <div class="crypto-card-value">
                                        <?php echo money($walletEarned); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="crypto-card-footer">
                                ⟳ Just Updated
                            </div>
                        </div>

                    </div>

                    <div class="crypto-dashboard-card next-auction-card">
                        <div class="next-auction-ribbon">
                            Next Auction Entries
                        </div>

                        <div class="next-auction-body">
                           <div class="crypto-card-label">
    <?php echo htmlspecialchars($auctionCoinsLabel); ?>
</div>

<div class="crypto-card-value">
    <?php echo number_format($displayAuctionCoins, 2); ?> shares
</div>
                        </div>

                      <div class="crypto-card-footer">
    ◆ Auction status: <?php echo strtoupper(htmlspecialchars($auctionStatus)); ?>
    · Entries: <?php echo (int)$nextAuctionEntries; ?>
</div>
                    </div>

                    <div class="crypto-offers-card">
                        <div class="crypto-offers-heading">
                            <h4>Offers</h4>
                            <p>Offers to buy shares you placed on sale</p>
                        </div>

                        <div class="crypto-offers-scroll">
                            <table class="crypto-offers-table">
                                <thead>
                                    <tr>
                                        <th>Bidder</th>
                                        <th>Contact</th>
                                        <th>Bid Amount</th>
                                        <th>Bid Time</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($recentAuctionActivity && $recentAuctionActivity->num_rows > 0): ?>
                                        <?php while ($row = $recentAuctionActivity->fetch_assoc()): ?>
                                            <?php
                                                $isBuyer = (int)$row["buyer_user_id"] === $user_id;
                                                $otherMember = $isBuyer
                                                    ? memberLabel($row, "seller_")
                                                    : memberLabel($row, "buyer_");
                                            ?>

                                            <tr>
                                                <td><?php echo htmlspecialchars($otherMember); ?></td>
                                                <td><?php echo $isBuyer ? "Seller" : "Buyer"; ?></td>
                                                <td><?php echo money($row["principal_coins"]); ?></td>
                                                <td><?php echo htmlspecialchars(displayDate($row["claimed_at"])); ?></td>
                                                <td><?php echo auctionStatusBadge($row["status"]); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">
                                                <div class="crypto-empty">
                                                    No offers or auction transactions yet.
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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

        item.textContent = days + " Hour(s) " + minutes + " Minute(s) " + seconds + " Seconds";
    });
}

updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>

</body>
</html>