<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$tenant_id = (int)($_SESSION["tenant_id"] ?? 0);
$user_id = (int)$_SESSION["user_id"];

$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$name = $_SESSION["name"] ?? "User";
$member_code = $_SESSION["member_code"] ?? "";
$displayName = $username ?: ($member_code ?: $name);

$fixedBidPeriodDays = 3;

function shares($amount) {
    return number_format((float)$amount, 2) . " shares";
}

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function bankingValue($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return '<span class="text-muted-soft">Not provided</span>';
    }

    return htmlspecialchars($value);
}

function memberLabel($row, $prefix = "seller_") {
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

$packageRules = getTenantPackageRules($conn, $tenant_id);

$current_package_name = $packageRules["package_name"] ?? "Current Package";
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM auction_claims
    WHERE tenant_id = ?
    AND buyer_user_id = ?
    AND status = 'pending_seller_approval'
");
$countStmt->bind_param("ii", $tenant_id, $user_id);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();

$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $conn->prepare("
    SELECT
        auction_claims.*,
        auction_lots.package_id,
        auction_lots.return_percent AS lot_return_percent,
        auction_lots.maturity_days AS lot_maturity_days,
        packages.package_name,

        seller.username AS seller_username,
        seller.member_code AS seller_member_code,
        seller.first_name AS seller_first_name,
        seller.last_name AS seller_last_name,
        seller.email AS seller_email,
        seller.phone AS seller_phone,
        seller.bank_name AS seller_bank_name,
        seller.bank_account_holder AS seller_bank_account_holder,
        seller.bank_account_number AS seller_bank_account_number,
        seller.bank_branch_code AS seller_bank_branch_code,
        seller.bank_account_type AS seller_bank_account_type,
        seller.banking_details_completed AS seller_banking_details_completed
    FROM auction_claims
    INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
    LEFT JOIN packages ON packages.id = auction_lots.package_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE auction_claims.tenant_id = ?
    AND auction_claims.buyer_user_id = ?
    AND auction_claims.status = 'pending_seller_approval'
    ORDER BY auction_claims.claimed_at DESC, auction_claims.id DESC
    LIMIT ?
    OFFSET ?
");
$stmt->bind_param("iiii", $tenant_id, $user_id, $perPage, $offset);
$stmt->execute();
$purchases = $stmt->get_result();

$totalPendingAmount = 0.00;

$sumStmt = $conn->prepare("
    SELECT COALESCE(SUM(principal_coins), 0) AS total_pending_amount
    FROM auction_claims
    WHERE tenant_id = ?
    AND buyer_user_id = ?
    AND status = 'pending_seller_approval'
");
$sumStmt->bind_param("ii", $tenant_id, $user_id);
$sumStmt->execute();
$sumRow = $sumStmt->get_result()->fetch_assoc();
$totalPendingAmount = (float)($sumRow["total_pending_amount"] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Coin Purchases</title>
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

        .purchases-shell {
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
            grid-template-columns: repeat(3, minmax(0, 1fr));
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

        .section-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }

        .section-heading {
            color: rgba(255,255,255,0.46);
            font-size: 15px;
            font-weight: 400;
            margin: 0;
        }

        .history-btn {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.13);
            color: rgba(255,255,255,0.76);
            border-radius: 999px;
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 900;
            text-decoration: none;
        }

        .history-btn:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.08);
        }

        .purchase-list {
            display: grid;
            gap: 16px;
        }

        .purchase-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 18px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            position: relative;
            overflow: hidden;
        }

        .purchase-card::before {
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
            margin-bottom: 8px;
        }

        .purchase-date {
            color: rgba(255,255,255,0.34);
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

        .payment-box {
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.20);
            border-radius: 5px;
            padding: 12px;
            color: #ffce8a;
            font-size: 11px;
            line-height: 1.5;
            margin-bottom: 14px;
        }

        .payment-box strong {
            color: #ffffff;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .bank-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
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

        .small-note {
            color: rgba(255,255,255,0.36);
            font-size: 10px;
            line-height: 1.45;
            margin-top: 10px;
        }

        .pending-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            padding: 7px 10px;
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.20);
            color: #ffb74d;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .pending-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #ffb74d;
            display: inline-block;
        }

        .empty-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 22px;
            color: rgba(255,255,255,0.46);
            text-align: center;
            font-size: 12px;
        }

        .go-btn {
            background: linear-gradient(135deg, #16a085, #1abc9c);
            border: 0;
            color: #ffffff;
            border-radius: 999px;
            padding: 10px 20px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            text-decoration: none;
            display: inline-flex;
            margin-top: 10px;
        }

        .text-muted-soft {
            color: rgba(255,255,255,0.36) !important;
        }

        .alert {
            border-radius: 5px;
            font-size: 12px;
            padding: 10px 12px;
        }

        .pagination .page-link {
            background: rgba(25, 39, 64, 0.86);
            border-color: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.72);
            font-size: 12px;
        }

        .pagination .page-item.active .page-link {
            background: #a83bd8;
            border-color: #a83bd8;
        }

        @media (max-width: 900px) {
            .summary-grid,
            .details-grid,
            .bank-grid {
                grid-template-columns: 1fr;
            }

            .share-number {
                font-size: 30px;
            }

            .bid-number {
                font-size: 18px;
            }

            .status-title {
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
                <div class="topbar-title">My Coin Purchases</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="purchases-shell">

                <div class="page-title">
                    My Coin Purchases
                </div>

                <div class="cover-card">
                    <div style="color: rgba(255,255,255,0.40); font-size: 11px;">
                        <?php echo htmlspecialchars($stokvel_name); ?>
                    </div>
                </div>

                <div class="status-panel">
                    <div class="status-title">
                        Pending seller approval
                    </div>

                    <div class="status-text">
                        These are the shares you bought and still need the seller to approve.
                        Transfer the ZAR amount to the seller using the banking details shown on each purchase.
                    </div>

                    <div class="status-text mt-2">
                        Return: <strong><?php echo number_format($current_return_percent, 2); ?>%</strong>
                        · Bid period after approval: <strong><?php echo (int)$fixedBidPeriodDays; ?> days</strong>
                        · Package: <strong><?php echo htmlspecialchars($current_package_name); ?></strong>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Pending Purchases</div>
                        <div class="summary-value"><?php echo number_format($totalRows); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Amount To Pay</div>
                        <div class="summary-value"><?php echo money($totalPendingAmount); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Shares Pending</div>
                        <div class="summary-value"><?php echo shares($totalPendingAmount); ?></div>
                    </div>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#pendingPurchases" class="tasks-link">
                        ⚙ Complete pending payments
                    </a>
                </div>

                <div class="section-row" id="pendingPurchases">
                    <h3 class="section-heading">
                        Pending Purchases
                    </h3>

                    <a href="auction_history.php" class="history-btn">
                        View Full Auction History
                    </a>
                </div>

                <?php if ($purchases->num_rows > 0): ?>
                    <div class="purchase-list">
                        <?php while ($p = $purchases->fetch_assoc()): ?>
                            <?php
                                $claimId = (int)$p["id"];
                                $bidNumber = "BID #" . str_pad((string)$claimId, 5, "0", STR_PAD_LEFT);

                                $principal = (float)$p["principal_coins"];
                                $paymentAmount = $principal;
                                $paymentReference = "BID " . str_pad((string)$claimId, 5, "0", STR_PAD_LEFT);

                                $displayReturnPercent = $current_return_percent;
                                $displayReturnShares = round(($principal * $displayReturnPercent) / 100, 2);
                                $displayTotalDue = $principal + $displayReturnShares;

                                $sellerName = memberLabel($p, "seller_");
                            ?>

                            <div class="purchase-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <div class="bid-number">
                                            <?php echo htmlspecialchars($bidNumber); ?>
                                        </div>

                                        <div class="purchase-date">
                                            Bought: <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?>
                                        </div>
                                    </div>

                                    <span class="pending-pill">
                                        <span class="pending-dot"></span>
                                        Pending
                                    </span>
                                </div>

                                <div class="share-row">
                                    <div class="share-number">
                                        <?php echo number_format($principal, 0); ?>
                                    </div>

                                    <div class="share-badge">
                                        Shares
                                    </div>
                                </div>

                                <div class="payment-box">
                                    <strong>Payment Required:</strong><br>
                                    Transfer <strong><?php echo money($paymentAmount); ?></strong>
                                    to the seller for the <strong><?php echo shares($principal); ?></strong> you bought.
                                    <br>
                                    Reference: <strong><?php echo htmlspecialchars($paymentReference); ?></strong>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-box">
                                        <div class="detail-label">Return</div>
                                        <div class="detail-value">
                                            <?php echo number_format($displayReturnPercent, 2); ?>%
                                            · <?php echo shares($displayReturnShares); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Total Due After Approval</div>
                                        <div class="detail-value"><?php echo shares($displayTotalDue); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Bid Period</div>
                                        <div class="detail-value"><?php echo (int)$fixedBidPeriodDays; ?> days</div>
                                    </div>
                                </div>

                                <div class="bank-grid">
                                    <div class="detail-box">
                                        <div class="detail-label">Seller</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($sellerName); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Seller Phone</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($p["seller_phone"] ?: "Not provided"); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Bank</div>
                                        <div class="detail-value">
                                            <?php echo bankingValue($p["seller_bank_name"] ?? ""); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Account Holder</div>
                                        <div class="detail-value">
                                            <?php echo bankingValue($p["seller_bank_account_holder"] ?? ""); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Account Number</div>
                                        <div class="detail-value">
                                            <?php echo bankingValue($p["seller_bank_account_number"] ?? ""); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Branch / Type</div>
                                        <div class="detail-value">
                                            <?php echo bankingValue($p["seller_bank_branch_code"] ?? ""); ?>
                                            ·
                                            <?php echo bankingValue($p["seller_bank_account_type"] ?? ""); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ((int)($p["seller_banking_details_completed"] ?? 0) !== 1): ?>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        Seller has not completed banking details.
                                    </div>
                                <?php endif; ?>

                                <div class="small-note">
                                    After payment, wait for the seller to approve. Once approved, your <?php echo (int)$fixedBidPeriodDays; ?> day countdown will start.
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-card">
                        <div>No pending coin purchases.</div>
                        <a href="auction.php" class="go-btn">
                            Go to Auction
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination mb-0">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? "active" : ""; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

</body>
</html>