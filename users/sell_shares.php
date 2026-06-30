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

$success = "";
$error = "";

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

function memberLabel($row, $prefix = "seller_") {
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

$packageRules = getTenantPackageRules($conn, $tenant_id);

$current_package_id = (int)($packageRules["package_id"] ?? 0);
$current_package_name = $packageRules["package_name"] ?? "Current Package";
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

/*
    Auto-mark active shares as matured once countdown is done.
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

/*
    Sell matured shares into the next auction queue.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $claim_id = (int)($_POST["claim_id"] ?? 0);

    if ($action === "sell_share") {
        if ($claim_id <= 0) {
            $error = "Invalid share selected.";
        } elseif ($current_package_id <= 0) {
            $error = "No package is linked to this tenant.";
        } else {
            try {
                $conn->begin_transaction();

                $claimStmt = $conn->prepare("
                    SELECT *
                    FROM auction_claims
                    WHERE id = ?
                    AND tenant_id = ?
                    AND buyer_user_id = ?
                    AND status IN ('active', 'matured')
                    AND COALESCE(resale_status, 'not_listed') = 'not_listed'
                    LIMIT 1
                    FOR UPDATE
                ");
                $claimStmt->bind_param("iii", $claim_id, $tenant_id, $user_id);
                $claimStmt->execute();
                $claim = $claimStmt->get_result()->fetch_assoc();

                if (!$claim) {
                    throw new Exception("This share is not available for selling.");
                }

                if (empty($claim["matures_at"]) || strtotime($claim["matures_at"]) > time()) {
                    throw new Exception("This share has not matured yet.");
                }

                $sharesForSale = (float)($claim["total_due_coins"] ?? 0);

                if ($sharesForSale <= 0) {
                    $principal = (float)($claim["principal_coins"] ?? 0);
                    $returnShares = (float)($claim["return_coins"] ?? 0);
                    $sharesForSale = $principal + $returnShares;
                }

                if ($sharesForSale <= 0) {
                    throw new Exception("Invalid shares available for sale.");
                }

                /*
                    Status scheduled means this is queued for the next auction.
                    Bid period is fixed to 3 days.
                */
                $insertLot = $conn->prepare("
                    INSERT INTO auction_lots
                    (
                        tenant_id,
                        package_id,
                        seller_user_id,
                        coin_amount,
                        remaining_coins,
                        return_percent,
                        maturity_days,
                        status,
                        source_claim_id,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)
                ");

                $insertLot->bind_param(
                    "iiidddiii",
                    $tenant_id,
                    $current_package_id,
                    $user_id,
                    $sharesForSale,
                    $sharesForSale,
                    $current_return_percent,
                    $fixedBidPeriodDays,
                    $claim_id,
                    $user_id
                );
                $insertLot->execute();

                $updateClaim = $conn->prepare("
                    UPDATE auction_claims
                    SET resale_status = 'listed',
                        listed_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                    AND buyer_user_id = ?
                ");
                $updateClaim->bind_param("iii", $claim_id, $tenant_id, $user_id);
                $updateClaim->execute();

                $conn->commit();

                $success = "Your shares have been queued for the next auction.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM auction_claims
    WHERE tenant_id = ?
    AND buyer_user_id = ?
    AND status IN ('active', 'matured')
");
$countStmt->bind_param("ii", $tenant_id, $user_id);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();

$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_shares,
        SUM(CASE 
            WHEN status = 'active' THEN 1 ELSE 0
        END) AS active_count,
        SUM(CASE 
            WHEN status = 'matured'
            AND COALESCE(resale_status, 'not_listed') = 'not_listed'
            THEN 1 ELSE 0
        END) AS ready_count,
        SUM(CASE 
            WHEN COALESCE(resale_status, 'not_listed') = 'listed'
            THEN 1 ELSE 0
        END) AS queued_count,
        COALESCE(SUM(CASE 
            WHEN status IN ('active', 'matured')
            THEN total_due_coins ELSE 0
        END), 0) AS total_value
    FROM auction_claims
    WHERE tenant_id = ?
    AND buyer_user_id = ?
    AND status IN ('active', 'matured')
");
$summaryStmt->bind_param("ii", $tenant_id, $user_id);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();

$totalShares = (int)($summary["total_shares"] ?? 0);
$activeCount = (int)($summary["active_count"] ?? 0);
$readyCount = (int)($summary["ready_count"] ?? 0);
$queuedCount = (int)($summary["queued_count"] ?? 0);
$totalValue = (float)($summary["total_value"] ?? 0);

$stmt = $conn->prepare("
    SELECT
        auction_claims.*,

        seller.username AS seller_username,
        seller.member_code AS seller_member_code,
        seller.first_name AS seller_first_name,
        seller.last_name AS seller_last_name,
        seller.email AS seller_email,
        seller.phone AS seller_phone
    FROM auction_claims
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE auction_claims.tenant_id = ?
    AND auction_claims.buyer_user_id = ?
    AND auction_claims.status IN ('active', 'matured')
    ORDER BY auction_claims.matures_at ASC, auction_claims.id DESC
    LIMIT ?
    OFFSET ?
");
$stmt->bind_param("iiii", $tenant_id, $user_id, $perPage, $offset);
$stmt->execute();
$sharesResult = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sell Shares</title>
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

        .sell-shell {
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

        .share-list {
            display: grid;
            gap: 16px;
        }

        .share-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 18px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            position: relative;
            overflow: hidden;
        }

        .share-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #32b96e, #a83bd8);
            opacity: 0.9;
        }

        .bid-number {
            color: rgba(255,255,255,0.50);
            font-size: 20px;
            font-weight: 300;
            margin-bottom: 8px;
        }

        .share-date {
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

        .value-line {
            color: rgba(255,255,255,0.38);
            font-size: 12px;
            margin-bottom: 14px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
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

        .countdown-box {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            border-radius: 5px;
            padding: 11px;
            color: rgba(255,255,255,0.62);
            margin-bottom: 12px;
            font-size: 11px;
        }

        .countdown-timer {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 7px 10px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            color: rgba(255,255,255,0.72);
            font-size: 11px;
            font-weight: 900;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            padding: 7px 10px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            color: rgba(255,255,255,0.66);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .status-ready {
            background: rgba(34,197,94,0.12);
            border-color: rgba(34,197,94,0.18);
            color: #73e39b;
        }

        .status-queued {
            background: rgba(14,165,233,0.12);
            border-color: rgba(14,165,233,0.18);
            color: #67d4ff;
        }

        .status-sold {
            background: rgba(168,59,216,0.12);
            border-color: rgba(168,59,216,0.18);
            color: #d884ff;
        }

        .btn-sell {
            background: linear-gradient(135deg, #16a085, #1abc9c);
            border: 0;
            color: #ffffff;
            border-radius: 999px;
            padding: 10px 20px;
            min-width: 130px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            box-shadow: 0 14px 24px rgba(26,188,156,0.16);
        }

        .small-note {
            color: rgba(255,255,255,0.36);
            font-size: 10px;
            line-height: 1.45;
            margin-top: 8px;
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
            .details-grid {
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
                <div class="topbar-title">Sell Shares</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="sell-shell">

                <div class="page-title">
                    Sell Shares
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

                <div class="status-panel">
                    <div class="status-title">
                        My approved shares
                    </div>

                    <div class="status-text">
                        Track the countdown on shares you bought. When the countdown reaches zero, you can queue them for the next auction.
                    </div>

                    <div class="status-text mt-2">
                        Return: <strong><?php echo number_format($current_return_percent, 2); ?>%</strong>
                        · Next bid period: <strong><?php echo (int)$fixedBidPeriodDays; ?> days</strong>
                        · Package: <strong><?php echo htmlspecialchars($current_package_name); ?></strong>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Total Shares</div>
                        <div class="summary-value"><?php echo number_format($totalShares); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Counting Down</div>
                        <div class="summary-value"><?php echo number_format($activeCount); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Ready To Sell</div>
                        <div class="summary-value"><?php echo number_format($readyCount); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Total Value</div>
                        <div class="summary-value"><?php echo shares($totalValue); ?></div>
                    </div>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#approvedShares" class="tasks-link">
                        ⚙ Sell matured shares
                    </a>
                </div>

                <div class="section-row" id="approvedShares">
                    <h3 class="section-heading">
                        Approved Shares
                    </h3>

                    <a href="auction_history.php" class="history-btn">
                        View Auction History
                    </a>
                </div>

                <?php if ($sharesResult->num_rows > 0): ?>
                    <div class="share-list">
                        <?php while ($s = $sharesResult->fetch_assoc()): ?>
                            <?php
                                $claimId = (int)$s["id"];
                                $bidNumber = "BID #" . str_pad((string)$claimId, 5, "0", STR_PAD_LEFT);

                                $principal = (float)($s["principal_coins"] ?? 0);
                                $returnShares = (float)($s["return_coins"] ?? 0);
                                $totalDue = (float)($s["total_due_coins"] ?? 0);

                                if ($totalDue <= 0) {
                                    $totalDue = $principal + $returnShares;
                                }

                                $maturesAt = $s["matures_at"] ?? "";
                                $matureTimestamp = $maturesAt ? strtotime($maturesAt) : 0;
                                $secondsLeft = $matureTimestamp > 0 ? max(0, $matureTimestamp - time()) : 0;

                                $resaleStatus = $s["resale_status"] ?? "not_listed";
                                if ($resaleStatus === "") {
                                    $resaleStatus = "not_listed";
                                }

                                $canSell = $secondsLeft <= 0 && $resaleStatus === "not_listed";
                            ?>

                            <div class="share-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <div class="bid-number">
                                            <?php echo htmlspecialchars($bidNumber); ?>
                                        </div>

                                        <div class="share-date">
                                            Approved: <?php echo htmlspecialchars(displayDate($s["approved_at"] ?? "")); ?>
                                        </div>
                                    </div>

                                    <?php if ($resaleStatus === "listed"): ?>
                                        <span class="status-pill status-queued">Queued</span>
                                    <?php elseif ($resaleStatus === "sold"): ?>
                                        <span class="status-pill status-sold">Sold</span>
                                    <?php elseif ($canSell): ?>
                                        <span class="status-pill status-ready">Ready</span>
                                    <?php else: ?>
                                        <span class="status-pill">Counting</span>
                                    <?php endif; ?>
                                </div>

                                <div class="share-row">
                                    <div class="share-number">
                                        <?php echo number_format($totalDue, 0); ?>
                                    </div>

                                    <div class="share-badge">
                                        Shares
                                    </div>
                                </div>

                                <div class="value-line">
                                    Sell value <?php echo money($totalDue); ?>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-box">
                                        <div class="detail-label">Bought</div>
                                        <div class="detail-value">
                                            <?php echo shares($principal); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Return</div>
                                        <div class="detail-value">
                                            <?php echo shares($returnShares); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Total Due</div>
                                        <div class="detail-value">
                                            <?php echo shares($totalDue); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="countdown-box">
                                    <?php if ($resaleStatus === "listed"): ?>
                                        Queued for next auction.
                                        <br>
                                        Listed: <?php echo htmlspecialchars(displayDate($s["listed_at"] ?? "")); ?>
                                    <?php elseif ($resaleStatus === "sold"): ?>
                                        Already sold.
                                        <br>
                                        Sold: <?php echo htmlspecialchars(displayDate($s["sold_at"] ?? "")); ?>
                                    <?php else: ?>
                                        Countdown:
                                        <span 
                                            class="countdown-timer"
                                            data-end="<?php echo $matureTimestamp > 0 ? ($matureTimestamp * 1000) : 0; ?>"
                                        >
                                            Loading...
                                        </span>

                                        <div class="small-note waiting-message" <?php echo $canSell ? 'style="display:none;"' : ""; ?>>
                                            Sell button will appear when the countdown reaches zero.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($resaleStatus === "not_listed"): ?>
                                    <form 
                                        method="POST" 
                                        class="sell-form"
                                        style="<?php echo $canSell ? "" : "display:none;"; ?>"
                                        onsubmit="return confirm('Sell these shares into the next auction queue?');"
                                    >
                                        <input type="hidden" name="action" value="sell_share">
                                        <input type="hidden" name="claim_id" value="<?php echo $claimId; ?>">

                                        <button class="btn-sell">
                                            Sell Shares
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-card">
                        <div>No approved shares yet.</div>
                        <div class="small-note">
                            Once a seller approves your purchase, your countdown will appear here.
                        </div>
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

<script>
function formatCountdown(ms) {
    if (ms <= 0) {
        return "Ready to sell";
    }

    var seconds = Math.floor(ms / 1000);
    var days = Math.floor(seconds / 86400);
    seconds = seconds % 86400;

    var hours = Math.floor(seconds / 3600);
    seconds = seconds % 3600;

    var minutes = Math.floor(seconds / 60);
    seconds = seconds % 60;

    return days + "d " + hours + "h " + minutes + "m " + seconds + "s";
}

function updateCountdowns() {
    document.querySelectorAll(".countdown-timer").forEach(function (el) {
        var end = parseInt(el.getAttribute("data-end"), 10);

        if (!end) {
            el.textContent = "No maturity date";
            return;
        }

        var diff = end - Date.now();

        el.textContent = formatCountdown(diff);

        if (diff <= 0) {
            var card = el.closest(".share-card");

            if (card) {
                var form = card.querySelector(".sell-form");
                var waiting = card.querySelector(".waiting-message");

                if (form) {
                    form.style.display = "block";
                }

                if (waiting) {
                    waiting.style.display = "none";
                }
            }
        }
    });
}

updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>

</body>
</html>