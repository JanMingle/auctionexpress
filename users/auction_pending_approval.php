<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";
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

$fixedBidPeriodDays = 3;

$packageRules = getTenantPackageRules($conn, $tenant_id);
$current_package_id = (int)($packageRules["package_id"] ?? 0);
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

function auctionMoney($amount) {
    return "R" . number_format((float)$amount, 2);
}

function shortDateTime($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function buyerDisplayName($row) {
    $username = trim($row["buyer_username"] ?? "");
    $memberCode = trim($row["buyer_member_code"] ?? "");
    $firstName = trim($row["buyer_first_name"] ?? "");
    $lastName = trim($row["buyer_last_name"] ?? "");

    if ($username !== "") {
        return $username;
    }

    if ($memberCode !== "") {
        return $memberCode;
    }

    $fullName = trim($firstName . " " . $lastName);

    return $fullName !== "" ? $fullName : "Buyer";
}

function safeText($value) {
    $value = trim((string)$value);
    return $value !== "" ? htmlspecialchars($value) : "Not provided";
}

auctionEnsureWallet($conn, $tenant_id, $user_id);
auctionProcessMaturedPurchases($conn, $tenant_id, $user_id);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $claim_id = (int)($_POST["claim_id"] ?? 0);

    if ($claim_id <= 0) {
        $error = "Invalid purchase selected.";
    } elseif ($action === "approve_purchase") {
        try {
            $conn->begin_transaction();

            $claimStmt = $conn->prepare("
                SELECT *
                FROM auction_claims
                WHERE id = ?
                AND tenant_id = ?
                AND seller_user_id = ?
                AND status = 'pending_seller_approval'
                LIMIT 1
                FOR UPDATE
            ");
            $claimStmt->bind_param("iii", $claim_id, $tenant_id, $user_id);
            $claimStmt->execute();
            $claim = $claimStmt->get_result()->fetch_assoc();

            if (!$claim) {
                throw new Exception("Purchase request not found or already handled.");
            }

            $buyer_user_id = (int)$claim["buyer_user_id"];
            $lot_id = (int)$claim["lot_id"];
            $principal = (float)$claim["principal_coins"];

            $lotStmt = $conn->prepare("
                SELECT source_claim_id
                FROM auction_lots
                WHERE id = ?
                AND tenant_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $lotStmt->bind_param("ii", $lot_id, $tenant_id);
            $lotStmt->execute();
            $lot = $lotStmt->get_result()->fetch_assoc();

            if (!$lot) {
                throw new Exception("Auction lot not found.");
            }

            $source_claim_id = (int)($lot["source_claim_id"] ?? 0);

            /*
                Current auction rule:
                - Bid period is fixed to 3 days.
                - Return percent follows the current owner package.
            */
            $return_percent = $current_return_percent;
            $maturity_days = $fixedBidPeriodDays;

            $return_coins = round(($principal * $return_percent) / 100, 2);
            $total_due_coins = $principal + $return_coins;
            $matures_at = date("Y-m-d H:i:s", strtotime("+" . $maturity_days . " days"));

            auctionEnsureWallet($conn, $tenant_id, $user_id);

            $sellerWalletStmt = $conn->prepare("
                SELECT available_coins, locked_coins
                FROM member_coin_wallets
                WHERE tenant_id = ?
                AND user_id = ?
                FOR UPDATE
            ");
            $sellerWalletStmt->bind_param("ii", $tenant_id, $user_id);
            $sellerWalletStmt->execute();
            $sellerWallet = $sellerWalletStmt->get_result()->fetch_assoc();

            $sellerAvailable = (float)($sellerWallet["available_coins"] ?? 0);
            $sellerLocked = (float)($sellerWallet["locked_coins"] ?? 0);

            if ($source_claim_id <= 0 && $sellerLocked < $principal) {
                throw new Exception("You do not have enough locked auction shares to approve this purchase.");
            }

            if ($source_claim_id <= 0) {
                $newSellerLocked = max(0, $sellerLocked - $principal);

                $updateSeller = $conn->prepare("
                    UPDATE member_coin_wallets
                    SET locked_coins = ?
                    WHERE tenant_id = ?
                    AND user_id = ?
                ");
                $updateSeller->bind_param(
                    "dii",
                    $newSellerLocked,
                    $tenant_id,
                    $user_id
                );
                $updateSeller->execute();
            }

            $updateLotPackage = $conn->prepare("
                UPDATE auction_lots
                SET package_id = ?,
                    return_percent = ?,
                    maturity_days = ?,
                    updated_at = NOW()
                WHERE id = ?
                AND tenant_id = ?
            ");
            $updateLotPackage->bind_param(
                "idiii",
                $current_package_id,
                $return_percent,
                $maturity_days,
                $lot_id,
                $tenant_id
            );
            $updateLotPackage->execute();

            $updateClaim = $conn->prepare("
                UPDATE auction_claims
                SET status = 'active',
                    return_percent = ?,
                    return_coins = ?,
                    total_due_coins = ?,
                    approved_at = NOW(),
                    approved_by = ?,
                    matures_at = ?
                WHERE id = ?
                AND tenant_id = ?
                AND seller_user_id = ?
            ");
            $updateClaim->bind_param(
                "dddisiii",
                $return_percent,
                $return_coins,
                $total_due_coins,
                $user_id,
                $matures_at,
                $claim_id,
                $tenant_id,
                $user_id
            );
            $updateClaim->execute();

            if ($source_claim_id > 0) {
                $markOriginalSold = $conn->prepare("
                    UPDATE auction_claims
                    SET resale_status = 'sold',
                        sold_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                    AND buyer_user_id = ?
                ");
                $markOriginalSold->bind_param(
                    "iii",
                    $source_claim_id,
                    $tenant_id,
                    $user_id
                );
                $markOriginalSold->execute();

                $closeSourceLot = $conn->prepare("
                    UPDATE auction_lots
                    SET remaining_coins = 0,
                        status = 'claimed',
                        updated_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                ");
                $closeSourceLot->bind_param("ii", $lot_id, $tenant_id);
                $closeSourceLot->execute();
            }

            auctionAddLedger(
                $conn,
                $tenant_id,
                $user_id,
                $buyer_user_id,
                $lot_id,
                $claim_id,
                "share_purchase_seller_approved",
                -$principal,
                $sellerAvailable,
                "Seller approved share purchase. Buyer countdown started.",
                null
            );

            $conn->commit();
            $success = "Share purchase approved. The buyer countdown has started.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } elseif ($action === "reject_purchase") {
        try {
            $conn->begin_transaction();

            $claimStmt = $conn->prepare("
                SELECT *
                FROM auction_claims
                WHERE id = ?
                AND tenant_id = ?
                AND seller_user_id = ?
                AND status = 'pending_seller_approval'
                LIMIT 1
                FOR UPDATE
            ");
            $claimStmt->bind_param("iii", $claim_id, $tenant_id, $user_id);
            $claimStmt->execute();
            $claim = $claimStmt->get_result()->fetch_assoc();

            if (!$claim) {
                throw new Exception("Purchase request not found or already handled.");
            }

            $buyer_user_id = (int)$claim["buyer_user_id"];
            $lot_id = (int)$claim["lot_id"];
            $principal = (float)$claim["principal_coins"];

            auctionEnsureWallet($conn, $tenant_id, $buyer_user_id);

            $buyerWalletStmt = $conn->prepare("
                SELECT available_coins
                FROM member_coin_wallets
                WHERE tenant_id = ?
                AND user_id = ?
                FOR UPDATE
            ");
            $buyerWalletStmt->bind_param("ii", $tenant_id, $buyer_user_id);
            $buyerWalletStmt->execute();
            $buyerWallet = $buyerWalletStmt->get_result()->fetch_assoc();

            $newBuyerAvailable = (float)($buyerWallet["available_coins"] ?? 0);

            $lotStmt = $conn->prepare("
                SELECT remaining_coins
                FROM auction_lots
                WHERE id = ?
                AND tenant_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $lotStmt->bind_param("ii", $lot_id, $tenant_id);
            $lotStmt->execute();
            $lot = $lotStmt->get_result()->fetch_assoc();

            if ($lot) {
                $newRemaining = (float)$lot["remaining_coins"] + $principal;

                $updateLot = $conn->prepare("
                    UPDATE auction_lots
                    SET remaining_coins = ?,
                        status = 'open',
                        updated_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                ");
                $updateLot->bind_param(
                    "dii",
                    $newRemaining,
                    $lot_id,
                    $tenant_id
                );
                $updateLot->execute();
            }

            $updateClaim = $conn->prepare("
                UPDATE auction_claims
                SET status = 'rejected',
                    rejected_at = NOW()
                WHERE id = ?
                AND tenant_id = ?
                AND seller_user_id = ?
            ");
            $updateClaim->bind_param("iii", $claim_id, $tenant_id, $user_id);
            $updateClaim->execute();

            auctionAddLedger(
                $conn,
                $tenant_id,
                $buyer_user_id,
                $user_id,
                $lot_id,
                $claim_id,
                "share_purchase_rejected",
                0,
                $newBuyerAvailable,
                "Seller rejected share purchase.",
                null
            );

            $conn->commit();
            $success = "Share purchase rejected.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$wallet = auctionGetWallet($conn, $tenant_id, $user_id);
$auctionStatus = auctionGetStatus($conn, $tenant_id);

$approvalStmt = $conn->prepare("
    SELECT 
        auction_claims.*,
        packages.package_name,

        buyer.first_name AS buyer_first_name,
        buyer.last_name AS buyer_last_name,
        buyer.username AS buyer_username,
        buyer.member_code AS buyer_member_code,
        buyer.email AS buyer_email,
        buyer.phone AS buyer_phone
    FROM auction_claims
    INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
    INNER JOIN packages ON packages.id = auction_lots.package_id
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    WHERE auction_claims.tenant_id = ?
    AND auction_claims.seller_user_id = ?
    AND auction_claims.status = 'pending_seller_approval'
    ORDER BY auction_claims.claimed_at ASC
");
$approvalStmt->bind_param("ii", $tenant_id, $user_id);
$approvalStmt->execute();
$pendingApprovals = $approvalStmt->get_result();

$pendingTotalsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_pending,
        COALESCE(SUM(principal_coins), 0) AS pending_principal
    FROM auction_claims
    WHERE tenant_id = ?
    AND seller_user_id = ?
    AND status = 'pending_seller_approval'
");
$pendingTotalsStmt->bind_param("ii", $tenant_id, $user_id);
$pendingTotalsStmt->execute();
$pendingTotals = $pendingTotalsStmt->get_result()->fetch_assoc();

$totalPendingRequests = (int)($pendingTotals["total_pending"] ?? 0);
$totalPendingPrincipal = (float)($pendingTotals["pending_principal"] ?? 0);
$totalPendingReturn = round(($totalPendingPrincipal * $current_return_percent) / 100, 2);
$totalPendingDue = $totalPendingPrincipal + $totalPendingReturn;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pending Shares</title>
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

        .topbar-title,
        .app-topbar-title {
            color: rgba(255,255,255,0.84) !important;
            font-size: 14px !important;
            font-weight: 700;
        }

        .topbar-subtitle,
        .topbar-user,
        .app-topbar-subtitle {
            color: rgba(255,255,255,0.55) !important;
            font-size: 11px !important;
        }

        .app-content::before {
            display: none !important;
        }

        .approval-shell {
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

        .auction-status-panel {
            background: rgba(22, 34, 57, 0.78);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 5px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .auction-status-title {
            font-size: 18px;
            font-weight: 300;
            color: rgba(255,255,255,0.62);
            margin-bottom: 6px;
        }

        .auction-status-subtitle {
            color: rgba(255,255,255,0.34);
            font-size: 12px;
            line-height: 1.5;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 12px;
            border-radius: 999px;
            padding: 7px 10px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            color: rgba(255,255,255,0.62);
            font-size: 11px;
            font-weight: 800;
        }

        .status-dot-live,
        .status-dot-closed {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-dot-live {
            background: #22c55e;
        }

        .status-dot-closed {
            background: #ef4444;
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

        .section-heading {
            color: rgba(255,255,255,0.46);
            font-size: 15px;
            font-weight: 400;
            margin: 0 0 14px;
        }

        .approval-list {
            display: grid;
            gap: 16px;
        }

        .approval-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 18px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            position: relative;
            overflow: hidden;
        }

        .approval-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #ff9800, #a83bd8);
            opacity: 0.9;
        }

        .bid-number {
            color: rgba(255,255,255,0.50);
            font-size: 20px;
            font-weight: 300;
            margin-bottom: 10px;
        }

        .buyer-line {
            color: rgba(255,255,255,0.62);
            font-size: 15px;
            margin-bottom: 4px;
        }

        .buyer-meta {
            color: rgba(255,255,255,0.36);
            font-size: 11px;
            margin-bottom: 14px;
        }

        .share-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .share-number {
            color: rgba(255,255,255,0.58);
            font-size: 34px;
            font-weight: 300;
            line-height: 1;
        }

        .share-badge {
            background: linear-gradient(135deg, #32b96e, #1b9e5f);
            color: #ffffff;
            border-radius: 5px;
            padding: 10px 12px;
            min-width: 64px;
            text-align: center;
            font-size: 11px;
            font-weight: 800;
        }

        .price-line {
            color: rgba(255,255,255,0.38);
            font-size: 12px;
            margin-bottom: 14px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .detail-box {
            background: rgba(13,24,41,0.58);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 5px;
            padding: 10px 11px;
        }

        .detail-label {
            color: rgba(255,255,255,0.34);
            font-size: 10px;
            margin-bottom: 3px;
        }

        .detail-value {
            color: rgba(255,255,255,0.72);
            font-size: 12px;
            font-weight: 700;
        }

        .approval-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 10px;
        }

        .btn-approve {
            background: linear-gradient(135deg, #16a085, #1abc9c);
            border: 0;
            color: #ffffff;
            border-radius: 999px;
            padding: 10px 20px;
            min-width: 120px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            box-shadow: 0 14px 24px rgba(26,188,156,0.16);
        }

        .btn-reject {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.13);
            color: rgba(255,255,255,0.76);
            border-radius: 999px;
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 900;
        }

        .empty-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 20px;
            color: rgba(255,255,255,0.46);
            text-align: center;
            font-size: 12px;
        }

        .alert {
            border-radius: 5px;
            font-size: 12px;
            padding: 10px 12px;
        }

        @media (max-width: 1000px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .details-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .summary-grid,
            .details-grid {
                grid-template-columns: 1fr;
            }

            .share-number {
                font-size: 30px;
            }

            .bid-number {
                font-size: 18px;
            }

            .auction-status-title {
                font-size: 16px;
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
                <div class="topbar-title">Pending Shares</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="approval-shell">

                <div class="page-title">
                    Pending Shares
                </div>

                <div class="cover-card">
                    <div style="color: rgba(255,255,255,0.40); font-size: 11px;">
                        <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="auction-status-panel">
                    <div class="auction-status-title">
                        Seller approval queue
                    </div>

                    <div class="auction-status-subtitle">
                        Approve only after you have confirmed the buyer payment. Once approved, the buyer countdown starts for <?php echo (int)$fixedBidPeriodDays; ?> days.
                    </div>

                    <div class="status-pill">
                        <span class="<?php echo $auctionStatus === 'open' ? 'status-dot-live' : 'status-dot-closed'; ?>"></span>
                        Auction is <?php echo strtoupper(htmlspecialchars($auctionStatus)); ?>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Pending Requests</div>
                        <div class="summary-value"><?php echo number_format($totalPendingRequests); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Shares Requested</div>
                        <div class="summary-value"><?php echo auctionCoins($totalPendingPrincipal); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Pending Return</div>
                        <div class="summary-value"><?php echo auctionCoins($totalPendingReturn); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Pending Total Due</div>
                        <div class="summary-value"><?php echo auctionCoins($totalPendingDue); ?></div>
                    </div>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#approvalList" class="tasks-link">
                        ⚙ Review Pending Shares
                    </a>
                </div>

                <h3 class="section-heading" id="approvalList">
                    Approval Requests
                </h3>

                <?php if ($pendingApprovals->num_rows > 0): ?>
                    <div class="approval-list">
                        <?php while ($approval = $pendingApprovals->fetch_assoc()): ?>
                            <?php
                                $claimId = (int)$approval["id"];
                                $bidNumber = "BID #" . str_pad((string)$claimId, 5, "0", STR_PAD_LEFT);

                                $principal = (float)$approval["principal_coins"];
                                $displayReturn = round(($principal * $current_return_percent) / 100, 2);
                                $displayTotalDue = $principal + $displayReturn;

                                $buyerName = buyerDisplayName($approval);
                                $buyerCode = trim($approval["buyer_member_code"] ?? "");
                                $buyerPhone = trim($approval["buyer_phone"] ?? "");
                                $buyerEmail = trim($approval["buyer_email"] ?? "");
                            ?>

                            <div class="approval-card">
                                <div class="bid-number">
                                    <?php echo htmlspecialchars($bidNumber); ?>
                                </div>

                                <div class="buyer-line">
                                    Buyer: <?php echo htmlspecialchars($buyerName); ?>
                                </div>

                                <div class="buyer-meta">
                                    Member Code: <?php echo htmlspecialchars($buyerCode !== "" ? $buyerCode : "Not provided"); ?>
                                    · Requested: <?php echo htmlspecialchars(shortDateTime($approval["claimed_at"] ?? "")); ?>
                                </div>

                                <div class="share-row">
                                    <div class="share-number">
                                        <?php echo number_format($principal, 0); ?>
                                    </div>

                                    <div class="share-badge">
                                        Shares
                                    </div>
                                </div>

                                <div class="price-line">
                                    Payment expected from buyer: <?php echo auctionMoney($principal); ?>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-box">
                                        <div class="detail-label">Buyer Phone</div>
                                        <div class="detail-value"><?php echo safeText($buyerPhone); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Buyer Email</div>
                                        <div class="detail-value"><?php echo safeText($buyerEmail); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Return</div>
                                        <div class="detail-value">
                                            <?php echo number_format($current_return_percent, 2); ?>%
                                            · <?php echo auctionCoins($displayReturn); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Total Due</div>
                                        <div class="detail-value"><?php echo auctionCoins($displayTotalDue); ?></div>
                                    </div>
                                </div>

                                <div class="approval-actions">
                                    <form method="POST" onsubmit="return confirm('Approve this share purchase and start the buyer countdown?');">
                                        <input type="hidden" name="action" value="approve_purchase">
                                        <input type="hidden" name="claim_id" value="<?php echo $claimId; ?>">
                                        <button class="btn-approve">
                                            Approve
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Reject this share purchase?');">
                                        <input type="hidden" name="action" value="reject_purchase">
                                        <input type="hidden" name="claim_id" value="<?php echo $claimId; ?>">
                                        <button class="btn-reject">
                                            Reject
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-card">
                        There are no share purchases waiting for your approval.
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>
</body>
</html>