<?php
session_start();
require_once "../config/db.php";
require_once "auction_helpers.php";

date_default_timezone_set("Africa/Johannesburg");

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

$success = "";
$error = "";

$minBuyCoins = 200.00;
$fixedBidPeriodDays = 3;

function auctionNextOpenInfo(): array {
    $now = time();
    $today = date("Y-m-d");

    $todayTen = strtotime($today . " 10:00:00");
    $todayFive = strtotime($today . " 17:00:00");

    if ($now < $todayTen) {
        $next = $todayTen;
    } elseif ($now < $todayFive) {
        $next = $todayFive;
    } else {
        $tomorrow = date("Y-m-d", strtotime("+1 day"));
        $next = strtotime($tomorrow . " 10:00:00");
    }

    return [
        "timestamp" => $next,
        "iso" => date("c", $next),
        "label" => date("D, d M Y H:i", $next),
    ];
}

function auctionMoney($amount) {
    return "R" . number_format((float)$amount, 2);
}

function bankingDisplay($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return '<span class="text-muted">Not provided</span>';
    }

    return htmlspecialchars($value);
}

function maskBankAccount($accountNumber) {
    $accountNumber = trim((string)$accountNumber);

    if ($accountNumber === "") {
        return '<span class="text-muted">Not provided</span>';
    }

    $lastFour = substr($accountNumber, -4);

    return "•••• •••• " . htmlspecialchars($lastFour);
}

auctionEnsureWallet($conn, $tenant_id, $user_id);
auctionProcessMaturedPurchases($conn, $tenant_id, $user_id);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "buy_coins") {
        $lot_id = (int)($_POST["lot_id"] ?? 0);
        $buy_amount = (float)($_POST["buy_amount"] ?? 0);

        if ($lot_id <= 0) {
            $error = "Invalid auction selected.";
        } elseif ($buy_amount <= 0) {
            $error = "Please enter how many shares you want to buy.";
        } elseif ($buy_amount < $minBuyCoins) {
            $error = "Minimum purchase is " . number_format($minBuyCoins, 2) . " shares.";
        } else {
            try {
                $conn->begin_transaction();

                $pendingStmt = $conn->prepare("
                    SELECT id
                    FROM auction_claims
                    WHERE tenant_id = ?
                    AND buyer_user_id = ?
                    AND status = 'pending_seller_approval'
                    LIMIT 1
                    FOR UPDATE
                ");
                $pendingStmt->bind_param("ii", $tenant_id, $user_id);
                $pendingStmt->execute();

                if ($pendingStmt->get_result()->fetch_assoc()) {
                    throw new Exception("You already have a share purchase waiting for seller approval. Please wait for approval before buying again.");
                }

                $tenantStmt = $conn->prepare("
                    SELECT auction_status
                    FROM tenants
                    WHERE id = ?
                    LIMIT 1
                ");
                $tenantStmt->bind_param("i", $tenant_id);
                $tenantStmt->execute();
                $tenantRow = $tenantStmt->get_result()->fetch_assoc();

                if (($tenantRow["auction_status"] ?? "closed") !== "open") {
                    throw new Exception("Auction is currently closed.");
                }

                $lotStmt = $conn->prepare("
                    SELECT *
                    FROM auction_lots
                    WHERE id = ?
                    AND tenant_id = ?
                    AND status = 'open'
                    AND remaining_coins > 0
                    LIMIT 1
                    FOR UPDATE
                ");
                $lotStmt->bind_param("ii", $lot_id, $tenant_id);
                $lotStmt->execute();
                $lot = $lotStmt->get_result()->fetch_assoc();

                if (!$lot) {
                    throw new Exception("These shares are no longer available.");
                }

                $seller_user_id = (int)$lot["seller_user_id"];

                if ($seller_user_id === $user_id) {
                    throw new Exception("You cannot buy your own shares.");
                }

                $remaining_coins = (float)$lot["remaining_coins"];

                if ($buy_amount > $remaining_coins) {
                    throw new Exception("You cannot buy more than the available auction shares.");
                }

                if ($remaining_coins < $minBuyCoins) {
                    throw new Exception("This lot is below the minimum purchase amount.");
                }

                $newRemaining = round($remaining_coins - $buy_amount, 2);

                /*
                    Split rule:
                    Buyer can buy all, or split only if the remainder is at least 200.
                    Example:
                    500 available:
                    - buy 200 leaves 300 = allowed
                    - buy 500 leaves 0 = allowed
                    - buy 350 leaves 150 = blocked
                */
                if ($newRemaining > 0 && $newRemaining < $minBuyCoins) {
                    throw new Exception("You cannot split this lot and leave less than " . number_format($minBuyCoins, 2) . " shares. Please buy all or leave at least " . number_format($minBuyCoins, 2) . " shares.");
                }

                $return_percent = (float)$lot["return_percent"];
                $return_coins = round($buy_amount * ($return_percent / 100), 2);
                $total_due = $buy_amount + $return_coins;

                auctionEnsureWallet($conn, $tenant_id, $user_id);
                auctionEnsureWallet($conn, $tenant_id, $seller_user_id);

                $buyerBalanceStmt = $conn->prepare("
                    SELECT available_coins
                    FROM member_coin_wallets
                    WHERE tenant_id = ?
                    AND user_id = ?
                    FOR UPDATE
                ");
                $buyerBalanceStmt->bind_param("ii", $tenant_id, $user_id);
                $buyerBalanceStmt->execute();
                $buyerBalanceRow = $buyerBalanceStmt->get_result()->fetch_assoc();
                $buyerBalanceAfter = (float)($buyerBalanceRow["available_coins"] ?? 0);

                $newLotStatus = $newRemaining <= 0 ? "claimed" : "open";

                $updateLot = $conn->prepare("
                    UPDATE auction_lots
                    SET remaining_coins = ?,
                        status = ?,
                        claimed_by = ?,
                        claimed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                ");
                $updateLot->bind_param(
                    "dsiii",
                    $newRemaining,
                    $newLotStatus,
                    $user_id,
                    $lot_id,
                    $tenant_id
                );
                $updateLot->execute();

                $claimStmt = $conn->prepare("
                    INSERT INTO auction_claims
                    (
                        tenant_id,
                        lot_id,
                        buyer_user_id,
                        seller_user_id,
                        principal_coins,
                        return_percent,
                        return_coins,
                        total_due_coins,
                        status,
                        claimed_at,
                        matures_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_seller_approval', NOW(), NULL)
                ");
                $claimStmt->bind_param(
                    "iiiidddd",
                    $tenant_id,
                    $lot_id,
                    $user_id,
                    $seller_user_id,
                    $buy_amount,
                    $return_percent,
                    $return_coins,
                    $total_due
                );
                $claimStmt->execute();
                $claim_id = $conn->insert_id;

                auctionAddLedger(
                    $conn,
                    $tenant_id,
                    $user_id,
                    $seller_user_id,
                    $lot_id,
                    $claim_id,
                    "share_purchase_request",
                    0,
                    $buyerBalanceAfter,
                    "Share purchase requested. Buyer must pay seller in ZAR.",
                    null
                );

                $conn->commit();
                $success = "Share purchase submitted successfully. Please pay the seller using the banking details shown.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$wallet = auctionGetWallet($conn, $tenant_id, $user_id);
$available_coins = $wallet["available_coins"];
$locked_coins = $wallet["locked_coins"];
$total_earned = $wallet["total_earned"];

$auctionStatus = auctionGetStatus($conn, $tenant_id);
$nextOpen = auctionNextOpenInfo();
$hasPendingBuyerPurchase = auctionHasPendingBuyerPurchase($conn, $tenant_id, $user_id);

$liveStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS total_live_coins
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'open'
    AND remaining_coins > 0
");
$liveStatsStmt->bind_param("i", $tenant_id);
$liveStatsStmt->execute();
$liveStats = $liveStatsStmt->get_result()->fetch_assoc();
$totalLiveCoins = (float)($liveStats["total_live_coins"] ?? 0);

$myLiveStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS my_live_coins
    FROM auction_lots
    WHERE tenant_id = ?
    AND seller_user_id = ?
    AND status = 'open'
    AND remaining_coins > 0
");
$myLiveStatsStmt->bind_param("ii", $tenant_id, $user_id);
$myLiveStatsStmt->execute();
$myLiveStats = $myLiveStatsStmt->get_result()->fetch_assoc();
$myLiveCoins = (float)($myLiveStats["my_live_coins"] ?? 0);

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

if ($auctionStatus === "open") {
    $displayTotalCoins = $totalLiveCoins;
    $displayMyCoins = $myLiveCoins;
    $totalCoinsLabel = "Available Auction Shares";
    $myCoinsLabel = "My Selling Shares";
} else {
    $displayTotalCoins = $totalNextCoins;
    $displayMyCoins = $available_coins;
    $totalCoinsLabel = "Next Auction Available Shares";
    $myCoinsLabel = "My Next Auction Shares";
}

$auctionLots = null;

if ($auctionStatus === "open") {
    $lotsSql = "
        SELECT 
            auction_lots.id,
            auction_lots.seller_user_id,
            auction_lots.remaining_coins,
            auction_lots.return_percent,
            auction_lots.maturity_days,
            packages.package_name,

            users.first_name,
            users.last_name,
            users.username,
            users.member_code,
            users.bank_name,
            users.bank_account_holder,
            users.bank_account_number,
            users.bank_branch_code,
            users.bank_account_type,
            users.banking_details_completed
        FROM auction_lots
        INNER JOIN packages ON packages.id = auction_lots.package_id
        INNER JOIN users ON users.id = auction_lots.seller_user_id
        WHERE auction_lots.tenant_id = ?
        AND auction_lots.status = 'open'
        AND auction_lots.remaining_coins > 0
        ORDER BY 
            CASE WHEN auction_lots.seller_user_id = ? THEN 0 ELSE 1 END,
            auction_lots.remaining_coins DESC,
            auction_lots.created_at DESC
    ";

    $lotsStmt = $conn->prepare($lotsSql);
    $lotsStmt->bind_param("ii", $tenant_id, $user_id);
    $lotsStmt->execute();
    $auctionLots = $lotsStmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auction</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <style>
        body {
            background:
                radial-gradient(circle at 20% 0%, rgba(69, 90, 145, 0.22), transparent 34%),
                radial-gradient(circle at 90% 10%, rgba(168, 59, 216, 0.12), transparent 30%),
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
        .app-topbar-subtitle,
        .topbar-title,
        .topbar-subtitle,
        .topbar-user {
            color: rgba(255,255,255,0.88) !important;
        }

        .app-content::before {
            display: none !important;
        }

        .auction-shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .auction-page-title {
            font-size: 30px;
            font-weight: 400;
            color: rgba(255,255,255,0.72);
            margin-bottom: 20px;
        }

        .auction-cover {
            min-height: 118px;
            border-radius: 4px;
            background:
                linear-gradient(rgba(13,24,41,0.70), rgba(13,24,41,0.94)),
                radial-gradient(circle at right top, rgba(16,185,129,0.14), transparent 30%),
                linear-gradient(135deg, #162239, #0d1829);
            border: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 22px;
            padding: 22px;
            position: relative;
            overflow: hidden;
        }

        .auction-cover::after {
            content: "";
            position: absolute;
            right: 28px;
            top: 22px;
            width: 46px;
            height: 32px;
            border-top: 4px solid rgba(255,255,255,0.35);
            border-bottom: 4px solid rgba(255,255,255,0.35);
        }

        .online-banner {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
            color: #ffffff;
            border-radius: 5px;
            padding: 18px 22px;
            font-size: 18px;
            margin-bottom: 22px;
            box-shadow: 0 18px 35px rgba(255,122,0,0.16);
        }

        .auction-status-panel {
            background: rgba(22, 34, 57, 0.78);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 5px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .auction-status-title {
            font-size: 30px;
            font-weight: 300;
            color: rgba(255,255,255,0.62);
            margin-bottom: 6px;
        }

        .auction-status-subtitle {
            color: rgba(255,255,255,0.35);
            font-size: 16px;
            margin-bottom: 20px;
        }

        .auction-countdown-row {
            display: flex;
            align-items: center;
            gap: 18px;
            color: rgba(255,255,255,0.72);
            font-size: 17px;
        }

        .auction-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .summary-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 20px;
            box-shadow: 0 24px 42px rgba(0,0,0,0.16);
        }

        .summary-label {
            color: rgba(255,255,255,0.44);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .summary-value {
            color: rgba(255,255,255,0.76);
            font-size: 26px;
            font-weight: 300;
        }

        .tasks-card {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
            border-radius: 5px;
            padding: 22px 24px;
            color: #ffffff;
            margin-bottom: 28px;
            box-shadow: 0 18px 36px rgba(255,122,0,0.18);
        }

        .tasks-title {
            color: rgba(255,255,255,0.70);
            font-size: 18px;
            margin-bottom: 12px;
        }

        .tasks-link {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #ffffff;
            text-decoration: none;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .buy-section-title {
            color: rgba(255,255,255,0.46);
            font-size: 20px;
            font-weight: 400;
            margin: 0 0 18px;
        }

        .refresh-button {
            background: linear-gradient(135deg, #00c6d7, #11a7d8);
            color: #ffffff;
            border: 0;
            border-radius: 5px;
            padding: 14px 28px;
            font-weight: 900;
            text-decoration: none;
        }

        .lot-list {
            display: grid;
            gap: 26px;
        }

        .share-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 28px;
            box-shadow: 0 24px 42px rgba(0,0,0,0.16);
            position: relative;
            overflow: hidden;
        }

        .share-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #a83bd8, #11a7d8);
            opacity: 0.9;
        }

        .bid-number {
            color: rgba(255,255,255,0.50);
            font-size: 34px;
            font-weight: 300;
            margin-bottom: 18px;
        }

        .bank-line {
            color: rgba(255,255,255,0.52);
            font-size: 30px;
            font-weight: 300;
            margin-bottom: 22px;
        }

        .share-amount-row {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 12px;
        }

        .share-number {
            color: rgba(255,255,255,0.58);
            font-size: 58px;
            font-weight: 300;
            line-height: 1;
        }

        .share-badge {
            background: linear-gradient(135deg, #32b96e, #1b9e5f);
            color: #ffffff;
            border-radius: 5px;
            padding: 18px 20px;
            min-width: 92px;
            text-align: center;
            font-weight: 800;
        }

        .going-price {
            color: rgba(255,255,255,0.38);
            font-size: 19px;
            margin-bottom: 28px;
        }

        .seller-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .seller-detail {
            background: rgba(13,24,41,0.58);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 5px;
            padding: 13px 14px;
        }

        .seller-detail-label {
            color: rgba(255,255,255,0.34);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .seller-detail-value {
            color: rgba(255,255,255,0.72);
            font-size: 14px;
            font-weight: 700;
        }

        .bid-period {
            color: rgba(255,255,255,0.76);
            font-size: 17px;
            margin-bottom: 10px;
            font-weight: 800;
        }

        .fixed-period {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            border-radius: 5px;
            padding: 13px 14px;
            color: rgba(255,255,255,0.62);
            margin-bottom: 16px;
        }

        .buy-input {
            background: rgba(13,24,41,0.72) !important;
            border: 1px solid rgba(255,255,255,0.35) !important;
            color: rgba(255,255,255,0.86) !important;
            border-radius: 5px !important;
            padding: 16px !important;
            font-size: 17px;
        }

        .buy-input::placeholder {
            color: rgba(255,255,255,0.38);
        }

        .buy-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 12px;
        }

        .btn-buy-share {
            background: linear-gradient(135deg, #16a085, #1abc9c);
            border: 0;
            color: #ffffff;
            border-radius: 999px;
            padding: 14px 28px;
            min-width: 180px;
            font-weight: 900;
            text-transform: uppercase;
            box-shadow: 0 18px 32px rgba(26,188,156,0.18);
        }

        .btn-buy-all {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.13);
            color: rgba(255,255,255,0.78);
            border-radius: 999px;
            padding: 14px 22px;
            font-weight: 900;
        }

        .split-note {
            color: rgba(255,255,255,0.36);
            font-size: 13px;
            margin-top: 12px;
            line-height: 1.5;
        }

        .own-card-note {
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.20);
            color: #ffb74d;
            border-radius: 5px;
            padding: 13px 14px;
            font-size: 13px;
            font-weight: 800;
            margin-top: 16px;
        }

        .closed-info-card,
        .empty-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 28px;
            color: rgba(255,255,255,0.46);
            text-align: center;
        }

        .alert {
            border-radius: 5px;
        }

        @media (max-width: 900px) {
            .auction-summary-grid {
                grid-template-columns: 1fr;
            }

            .seller-details-grid {
                grid-template-columns: 1fr;
            }

            .share-number {
                font-size: 48px;
            }

            .bank-line {
                font-size: 25px;
            }

            .bid-number {
                font-size: 30px;
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
                <div class="topbar-title">Auction</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="auction-shell">

                <div class="auction-page-title">
                    Auction
                </div>

                <div class="auction-cover">
                    <div style="color: rgba(255,255,255,0.42); font-size: 13px;">
                        <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($hasPendingBuyerPurchase): ?>
                    <div class="alert alert-warning">
                        You already have a share purchase waiting for seller approval. You can buy again after the seller approves or rejects it.
                    </div>
                <?php endif; ?>

                <div class="auction-status-panel">
                    <div class="auction-status-title">
                        Auction is <?php echo ucfirst(htmlspecialchars($auctionStatus)); ?>
                        <a href="auction.php" class="refresh-button ms-3">Refresh</a>
                    </div>

                    <div class="auction-status-subtitle">
                        Auctions run daily at 10:00 and 17:00. Bid period is fixed at <?php echo (int)$fixedBidPeriodDays; ?> days.
                    </div>

                    <?php if ($auctionStatus !== "open"): ?>
                        <div class="auction-countdown-row" id="auctionCountdown">
                            <span>◷</span>
                            <span>
                                <span id="cdHours">00</span> Hour(s)
                                <span id="cdMinutes">00</span> Minute(s)
                                <span id="cdSeconds">00</span> Seconds
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="auction-summary-grid">
                    <div class="summary-card">
                        <div class="summary-label"><?php echo htmlspecialchars($totalCoinsLabel); ?></div>
                        <div class="summary-value"><?php echo auctionCoins($displayTotalCoins); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label"><?php echo htmlspecialchars($myCoinsLabel); ?></div>
                        <div class="summary-value"><?php echo auctionCoins($displayMyCoins); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Auction Earnings</div>
                        <div class="summary-value"><?php echo auctionCoins($total_earned); ?></div>
                    </div>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#buyShares" class="tasks-link">
                        ⚙ Buy Shares
                    </a>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3" id="buyShares">
                    <h3 class="buy-section-title mb-0">Buy Shares</h3>

                    <a href="auction.php" class="refresh-button">
                        Refresh
                    </a>
                </div>

                <?php if ($auctionStatus !== "open"): ?>
                    <div class="closed-info-card">
                        The auction is currently closed. When it opens, members selling shares will appear here.
                        <br>
                        Next auction: <?php echo htmlspecialchars($nextOpen["label"]); ?>
                    </div>
                <?php else: ?>

                    <?php if ($auctionLots && $auctionLots->num_rows > 0): ?>
                        <div class="lot-list">
                            <?php while ($lot = $auctionLots->fetch_assoc()): ?>
                                <?php
                                    $lotId = (int)$lot["id"];
                                    $sellerUserId = (int)$lot["seller_user_id"];
                                    $remaining = (float)$lot["remaining_coins"];
                                    $isOwnCoins = $sellerUserId === $user_id;

                                    $memberCodeText = trim($lot["member_code"] ?? "");
                                    if ($memberCodeText === "") {
                                        $memberCodeText = "N/A";
                                    }

                                    $bankName = trim($lot["bank_name"] ?? "");
                                    $accountHolder = trim($lot["bank_account_holder"] ?? "");
                                    $accountNumber = trim($lot["bank_account_number"] ?? "");
                                    $branchCode = trim($lot["bank_branch_code"] ?? "");
                                    $accountType = trim($lot["bank_account_type"] ?? "");
                                    $bankingDone = (int)($lot["banking_details_completed"] ?? 0);

                                    $canBuy = !$isOwnCoins
                                        && !$hasPendingBuyerPurchase
                                        && $lotId > 0
                                        && $remaining >= $minBuyCoins;

                                    $bidNumber = "BID #" . str_pad((string)$lotId, 5, "0", STR_PAD_LEFT);
                                ?>

                                <div class="share-card">
                                    <div class="bid-number">
                                        <?php echo htmlspecialchars($bidNumber); ?>
                                    </div>

                                    <div class="bank-line">
                                        Banking with <?php echo htmlspecialchars($bankName !== "" ? $bankName : "not provided"); ?>
                                    </div>

                                    <div class="share-amount-row">
                                        <div class="share-number">
                                            <?php echo number_format($remaining, 0); ?>
                                        </div>

                                        <div class="share-badge">
                                            Shares
                                        </div>
                                    </div>

                                    <div class="going-price">
                                        Going Price <?php echo auctionMoney($remaining); ?>
                                    </div>

                                    <div class="seller-details-grid">
                                        <div class="seller-detail">
                                            <div class="seller-detail-label">Member Code</div>
                                            <div class="seller-detail-value">
                                                <?php echo htmlspecialchars($memberCodeText); ?>
                                            </div>
                                        </div>

                                        <div class="seller-detail">
                                            <div class="seller-detail-label">Bid Period</div>
                                            <div class="seller-detail-value">
                                                <?php echo (int)$fixedBidPeriodDays; ?> days
                                            </div>
                                        </div>

                                        <div class="seller-detail">
                                            <div class="seller-detail-label">Bank</div>
                                            <div class="seller-detail-value">
                                                <?php echo bankingDisplay($bankName); ?>
                                            </div>
                                        </div>

                                        <div class="seller-detail">
                                            <div class="seller-detail-label">Account Holder</div>
                                            <div class="seller-detail-value">
                                                <?php echo bankingDisplay($accountHolder); ?>
                                            </div>
                                        </div>

                                        <div class="seller-detail">
                                            <div class="seller-detail-label">Account Number</div>
                                            <div class="seller-detail-value">
                                                <?php echo maskBankAccount($accountNumber); ?>
                                            </div>
                                        </div>

                                        <div class="seller-detail">
                                            <div class="seller-detail-label">Branch Code / Type</div>
                                            <div class="seller-detail-value">
                                                <?php echo bankingDisplay($branchCode); ?> · <?php echo bankingDisplay($accountType); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($bankingDone !== 1 && !$isOwnCoins): ?>
                                        <div class="alert alert-warning">
                                            This seller has not completed banking details.
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($isOwnCoins): ?>
                                        <div class="own-card-note">
                                            These are your shares. You cannot buy from yourself.
                                        </div>
                                    <?php elseif ($remaining < $minBuyCoins): ?>
                                        <div class="own-card-note">
                                            This lot is below the minimum purchase amount of <?php echo number_format($minBuyCoins, 2); ?> shares.
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="buy-share-form" onsubmit="return validateSharePurchase(this);">
                                            <input type="hidden" name="action" value="buy_coins">
                                            <input type="hidden" name="lot_id" value="<?php echo $lotId; ?>">

                                            <div class="bid-period">
                                                Select BID Period
                                            </div>

                                            <div class="fixed-period">
                                                Fixed period: <strong><?php echo (int)$fixedBidPeriodDays; ?> days</strong>
                                            </div>

                                            <input
                                                type="number"
                                                step="0.01"
                                                min="<?php echo htmlspecialchars((string)$minBuyCoins); ?>"
                                                max="<?php echo htmlspecialchars((string)$remaining); ?>"
                                                name="buy_amount"
                                                class="form-control buy-input"
                                                placeholder="Bid Amount"
                                                data-remaining="<?php echo htmlspecialchars((string)$remaining); ?>"
                                                data-min-buy="<?php echo htmlspecialchars((string)$minBuyCoins); ?>"
                                                <?php echo $canBuy ? "" : "disabled"; ?>
                                                required
                                            >

                                            <div class="buy-actions">
                                                <button
                                                    type="submit"
                                                    class="btn-buy-share"
                                                    <?php echo $canBuy ? "" : "disabled"; ?>
                                                >
                                                    Buy Share
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn-buy-all"
                                                    onclick="buyAllShares(this)"
                                                    <?php echo $canBuy ? "" : "disabled"; ?>
                                                >
                                                    Buy All
                                                </button>
                                            </div>

                                            <div class="split-note">
                                                Minimum purchase is <?php echo number_format($minBuyCoins, 2); ?> shares.
                                                If you split, the remaining shares must also be at least <?php echo number_format($minBuyCoins, 2); ?>.
                                                Buying all is allowed.
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-card">
                            No members are selling shares right now.
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<script>
(function () {
    const countdownBox = document.getElementById("auctionCountdown");

    if (!countdownBox) {
        return;
    }

    const nextOpenAt = new Date("<?php echo htmlspecialchars($nextOpen["iso"]); ?>").getTime();

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function updateCountdown() {
        const now = new Date().getTime();
        let diff = Math.max(0, nextOpenAt - now);

        const hours = Math.floor(diff / (1000 * 60 * 60));
        diff -= hours * 1000 * 60 * 60;

        const minutes = Math.floor(diff / (1000 * 60));
        diff -= minutes * 1000 * 60;

        const seconds = Math.floor(diff / 1000);

        const h = document.getElementById("cdHours");
        const m = document.getElementById("cdMinutes");
        const s = document.getElementById("cdSeconds");

        if (h) h.textContent = pad(hours);
        if (m) m.textContent = pad(minutes);
        if (s) s.textContent = pad(seconds);

        if (nextOpenAt - now <= 0) {
            setTimeout(function () {
                window.location.reload();
            }, 1500);
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
})();

function buyAllShares(button) {
    const form = button.closest("form");
    const input = form.querySelector("input[name='buy_amount']");

    if (!input) {
        return;
    }

    input.value = input.getAttribute("data-remaining");
}

function validateSharePurchase(form) {
    const input = form.querySelector("input[name='buy_amount']");

    if (!input) {
        alert("Please enter a bid amount.");
        return false;
    }

    const amount = parseFloat(input.value || "0");
    const remaining = parseFloat(input.getAttribute("data-remaining") || "0");
    const minBuy = parseFloat(input.getAttribute("data-min-buy") || "200");

    if (amount <= 0) {
        alert("Please enter a bid amount.");
        return false;
    }

    if (amount < minBuy) {
        alert("Minimum purchase is " + minBuy.toFixed(2) + " shares.");
        return false;
    }

    if (amount > remaining) {
        alert("You cannot buy more than the available shares.");
        return false;
    }

    const left = Math.round((remaining - amount) * 100) / 100;

    if (left > 0 && left < minBuy) {
        alert("You cannot leave less than " + minBuy.toFixed(2) + " shares. Please buy all or leave at least " + minBuy.toFixed(2) + " shares.");
        return false;
    }

    return confirm("Submit this share purchase?");
}
</script>

</body>
</html>