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

function memberLabel($row, $prefix = "") {
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

function safeText($value) {
    $value = trim((string)$value);
    return $value !== "" ? htmlspecialchars($value) : "Not provided";
}

function bankingValue($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return '<span class="text-muted-soft">Not provided</span>';
    }

    return htmlspecialchars($value);
}

function purchaseBadgeClass($status) {
    if ($status === "pending_seller_approval") {
        return "status-pending";
    }

    if ($status === "active") {
        return "status-active";
    }

    if ($status === "matured") {
        return "status-ready";
    }

    if ($status === "paid") {
        return "status-paid";
    }

    if ($status === "rejected") {
        return "status-rejected";
    }

    if ($status === "cancelled") {
        return "status-cancelled";
    }

    return "status-cancelled";
}

function purchaseBadgeText($status) {
    if ($status === "pending_seller_approval") {
        return "Pending";
    }

    if ($status === "active") {
        return "Counting";
    }

    if ($status === "matured") {
        return "Ready";
    }

    if ($status === "paid") {
        return "Paid";
    }

    if ($status === "rejected") {
        return "Rejected";
    }

    if ($status === "cancelled") {
        return "Cancelled";
    }

    return ucfirst($status ?: "Unknown");
}

function resaleBadgeText($status) {
    if ($status === "listed") {
        return "Queued";
    }

    if ($status === "sold") {
        return "Resold";
    }

    return "";
}

function ensureWallet(mysqli $conn, int $tenant_id, int $user_id): void {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO member_coin_wallets
        (tenant_id, user_id, available_coins, locked_coins, total_earned)
        VALUES (?, ?, 0, 0, 0)
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
}

function addLedger(
    mysqli $conn,
    int $tenant_id,
    int $user_id,
    ?int $related_user_id,
    ?int $auction_lot_id,
    ?int $auction_claim_id,
    string $type,
    float $amount,
    float $balance_after,
    string $note,
    ?int $created_by
): void {
    $stmt = $conn->prepare("
        INSERT INTO coin_ledger
        (
            tenant_id, user_id, related_user_id, auction_lot_id, auction_claim_id,
            type, amount, balance_after, note, created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iiiiisddsi",
        $tenant_id,
        $user_id,
        $related_user_id,
        $auction_lot_id,
        $auction_claim_id,
        $type,
        $amount,
        $balance_after,
        $note,
        $created_by
    );

    $stmt->execute();
}

$packageRules = getTenantPackageRules($conn, $tenant_id);

$current_package_id = (int)($packageRules["package_id"] ?? 0);
$current_package_name = $packageRules["package_name"] ?? "Current Package";
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

/*
    Seller approval/rejection can still happen from this history page.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $claim_id = (int)($_POST["claim_id"] ?? 0);

    if ($claim_id <= 0) {
        $error = "Invalid purchase selected.";
    } elseif ($action === "seller_approve_purchase") {
        try {
            $conn->begin_transaction();

            $claimStmt = $conn->prepare("
                SELECT 
                    auction_claims.*,
                    auction_lots.source_claim_id,
                    auction_lots.remaining_coins
                FROM auction_claims
                INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
                WHERE auction_claims.id = ?
                AND auction_claims.tenant_id = ?
                AND auction_claims.seller_user_id = ?
                AND auction_claims.status = 'pending_seller_approval'
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
            $seller_user_id = (int)$claim["seller_user_id"];
            $lot_id = (int)$claim["lot_id"];
            $principal = (float)$claim["principal_coins"];
            $source_claim_id = (int)($claim["source_claim_id"] ?? 0);

            /*
                Current rule:
                Return follows owner package.
                Bid period is fixed to 3 days.
            */
            $return_percent = $current_return_percent;
            $maturity_days = $fixedBidPeriodDays;

            $return_shares = round(($principal * $return_percent) / 100, 2);
            $total_due_shares = $principal + $return_shares;

            $matures_at = date("Y-m-d H:i:s", strtotime("+" . $maturity_days . " days"));

            ensureWallet($conn, $tenant_id, $seller_user_id);

            $sellerWalletStmt = $conn->prepare("
                SELECT available_coins, locked_coins
                FROM member_coin_wallets
                WHERE tenant_id = ?
                AND user_id = ?
                FOR UPDATE
            ");
            $sellerWalletStmt->bind_param("ii", $tenant_id, $seller_user_id);
            $sellerWalletStmt->execute();
            $sellerWallet = $sellerWalletStmt->get_result()->fetch_assoc();

            $sellerAvailable = (float)($sellerWallet["available_coins"] ?? 0);
            $sellerLocked = (float)($sellerWallet["locked_coins"] ?? 0);

            if ($source_claim_id <= 0 && $sellerLocked < $principal) {
                throw new Exception("You do not have enough locked auction shares for this approval.");
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
                    $seller_user_id
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
                $return_shares,
                $total_due_shares,
                $seller_user_id,
                $matures_at,
                $claim_id,
                $tenant_id,
                $seller_user_id
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
                    $seller_user_id
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

            addLedger(
                $conn,
                $tenant_id,
                $seller_user_id,
                $buyer_user_id,
                $lot_id,
                $claim_id,
                "seller_approved_share_purchase",
                -$principal,
                $sellerAvailable,
                "Seller approved share purchase.",
                $seller_user_id
            );

            $conn->commit();
            $success = "Purchase approved successfully.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } elseif ($action === "seller_reject_purchase") {
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
            $seller_user_id = (int)$claim["seller_user_id"];
            $lot_id = (int)$claim["lot_id"];
            $principal = (float)$claim["principal_coins"];

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
            $updateClaim->bind_param("iii", $claim_id, $tenant_id, $seller_user_id);
            $updateClaim->execute();

            addLedger(
                $conn,
                $tenant_id,
                $buyer_user_id,
                $seller_user_id,
                $lot_id,
                $claim_id,
                "seller_rejected_share_purchase",
                0,
                0,
                "Seller rejected share purchase.",
                $seller_user_id
            );

            $conn->commit();
            $success = "Purchase rejected successfully.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$view = trim($_GET["view"] ?? "all");
$status = trim($_GET["status"] ?? "");
$q = trim($_GET["q"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$allowedViews = ["all", "buyer", "seller"];
if (!in_array($view, $allowedViews, true)) {
    $view = "all";
}

$allowedStatuses = [
    "",
    "pending_seller_approval",
    "active",
    "matured",
    "paid",
    "rejected",
    "cancelled"
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = "";
}

$where = "auction_claims.tenant_id = ?";
$params = [$tenant_id];
$types = "i";

if ($view === "buyer") {
    $where .= " AND auction_claims.buyer_user_id = ?";
    $params[] = $user_id;
    $types .= "i";
} elseif ($view === "seller") {
    $where .= " AND auction_claims.seller_user_id = ?";
    $params[] = $user_id;
    $types .= "i";
} else {
    $where .= " AND (auction_claims.buyer_user_id = ? OR auction_claims.seller_user_id = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
    $types .= "ii";
}

if ($status !== "") {
    $where .= " AND auction_claims.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($q !== "") {
    $where .= "
        AND (
            buyer.username LIKE ?
            OR buyer.member_code LIKE ?
            OR buyer.first_name LIKE ?
            OR buyer.last_name LIKE ?
            OR buyer.email LIKE ?
            OR buyer.phone LIKE ?
            OR seller.username LIKE ?
            OR seller.member_code LIKE ?
            OR seller.first_name LIKE ?
            OR seller.last_name LIKE ?
            OR seller.email LIKE ?
            OR seller.phone LIKE ?
        )
    ";

    $searchTerm = "%" . $q . "%";
    for ($i = 0; $i < 12; $i++) {
        $params[] = $searchTerm;
        $types .= "s";
    }
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM auction_claims
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE $where
";

$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();

$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$summarySql = "
    SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(auction_claims.principal_coins), 0) AS total_principal,
        COALESCE(SUM(auction_claims.total_due_coins), 0) AS total_due,
        SUM(CASE WHEN auction_claims.status = 'pending_seller_approval' THEN 1 ELSE 0 END) AS pending_count
    FROM auction_claims
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE $where
";

$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();
$summaryRow = $summaryStmt->get_result()->fetch_assoc();

$totalItems = (int)($summaryRow["total_items"] ?? 0);
$totalPrincipal = (float)($summaryRow["total_principal"] ?? 0);
$totalDue = (float)($summaryRow["total_due"] ?? 0);
$pendingCount = (int)($summaryRow["pending_count"] ?? 0);

$listSql = "
    SELECT
        auction_claims.*,
        auction_lots.source_claim_id,
        auction_lots.package_id,
        auction_lots.return_percent AS lot_return_percent,
        auction_lots.maturity_days AS lot_maturity_days,
        packages.package_name,

        buyer.username AS buyer_username,
        buyer.member_code AS buyer_member_code,
        buyer.first_name AS buyer_first_name,
        buyer.last_name AS buyer_last_name,
        buyer.email AS buyer_email,
        buyer.phone AS buyer_phone,

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
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE $where
    ORDER BY auction_claims.claimed_at DESC, auction_claims.id DESC
    LIMIT ?
    OFFSET ?
";

$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$listStmt = $conn->prepare($listSql);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$purchases = $listStmt->get_result();

$queryStringBase = http_build_query([
    "view" => $view,
    "q" => $q,
    "status" => $status
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auction History</title>
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

        .history-shell {
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

        .filter-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 14px;
            margin-bottom: 18px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 1fr 160px 170px 90px;
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

        .history-list {
            display: grid;
            gap: 16px;
        }

        .history-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 18px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            position: relative;
            overflow: hidden;
        }

        .history-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #a83bd8, #11a7d8);
            opacity: 0.9;
        }

        .bid-number {
            color: rgba(255,255,255,0.50);
            font-size: 20px;
            font-weight: 300;
            margin-bottom: 8px;
        }

        .history-date {
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

        .bank-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
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

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            padding: 7px 10px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.20);
            color: #ffb74d;
        }

        .status-active {
            background: rgba(14,165,233,0.12);
            border: 1px solid rgba(14,165,233,0.18);
            color: #67d4ff;
        }

        .status-ready,
        .status-paid {
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.18);
            color: #73e39b;
        }

        .status-rejected {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.18);
            color: #ff8b8b;
        }

        .status-cancelled {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.075);
            color: rgba(255,255,255,0.66);
        }

        .resale-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 7px 10px;
            background: rgba(168,59,216,0.12);
            border: 1px solid rgba(168,59,216,0.18);
            color: #d884ff;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            margin-left: 6px;
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
            padding: 22px;
            color: rgba(255,255,255,0.46);
            text-align: center;
            font-size: 12px;
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
            .bank-grid,
            .filter-row {
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
                <div class="topbar-title">Auction History</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="history-shell">

                <div class="page-title">
                    Auction History
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
                        Buyer and seller transaction history
                    </div>

                    <div class="status-text">
                        Buyers see seller banking details for payments. Sellers see buyer contact details only.
                    </div>

                    <div class="status-text mt-2">
                        Return: <strong><?php echo number_format($current_return_percent, 2); ?>%</strong>
                        · Bid period: <strong><?php echo (int)$fixedBidPeriodDays; ?> days</strong>
                        · Package: <strong><?php echo htmlspecialchars($current_package_name); ?></strong>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Transactions</div>
                        <div class="summary-value"><?php echo number_format($totalItems); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Total Shares</div>
                        <div class="summary-value"><?php echo shares($totalPrincipal); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Total Due</div>
                        <div class="summary-value"><?php echo shares($totalDue); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">Pending</div>
                        <div class="summary-value"><?php echo number_format($pendingCount); ?></div>
                    </div>
                </div>

                <div class="filter-card">
                    <form method="GET" class="filter-row">
                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Search buyer or seller"
                            value="<?php echo htmlspecialchars($q); ?>"
                        >

                        <select name="view" class="form-select">
                            <option value="all" <?php echo $view === "all" ? "selected" : ""; ?>>All History</option>
                            <option value="buyer" <?php echo $view === "buyer" ? "selected" : ""; ?>>Shares I Bought</option>
                            <option value="seller" <?php echo $view === "seller" ? "selected" : ""; ?>>Shares I Sold</option>
                        </select>

                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <option value="pending_seller_approval" <?php echo $status === "pending_seller_approval" ? "selected" : ""; ?>>Pending</option>
                            <option value="active" <?php echo $status === "active" ? "selected" : ""; ?>>Counting</option>
                            <option value="matured" <?php echo $status === "matured" ? "selected" : ""; ?>>Ready</option>
                            <option value="paid" <?php echo $status === "paid" ? "selected" : ""; ?>>Paid</option>
                            <option value="rejected" <?php echo $status === "rejected" ? "selected" : ""; ?>>Rejected</option>
                            <option value="cancelled" <?php echo $status === "cancelled" ? "selected" : ""; ?>>Cancelled</option>
                        </select>

                        <button class="btn-filter">
                            Filter
                        </button>
                    </form>
                </div>

                <div class="tasks-card">
                    <div class="tasks-title">Tasks</div>
                    <a href="#historyList" class="tasks-link">
                        ⚙ Review auction history
                    </a>
                </div>

                <h3 class="section-heading" id="historyList">
                    Transactions
                </h3>

                <?php if ($purchases->num_rows > 0): ?>
                    <div class="history-list">
                        <?php while ($p = $purchases->fetch_assoc()): ?>
                            <?php
                                $claimId = (int)$p["id"];
                                $bidNumber = "BID #" . str_pad((string)$claimId, 5, "0", STR_PAD_LEFT);

                                $isBuyer = (int)$p["buyer_user_id"] === $user_id;
                                $isSeller = (int)$p["seller_user_id"] === $user_id;

                                $principal = (float)$p["principal_coins"];

                                $displayReturnPercent = $current_return_percent;
                                $displayReturnShares = round(($principal * $displayReturnPercent) / 100, 2);
                                $displayTotalDue = $principal + $displayReturnShares;

                                if ((float)($p["total_due_coins"] ?? 0) > 0 && $p["status"] !== "pending_seller_approval") {
                                    $displayReturnPercent = (float)($p["return_percent"] ?? $current_return_percent);
                                    $displayReturnShares = (float)($p["return_coins"] ?? $displayReturnShares);
                                    $displayTotalDue = (float)($p["total_due_coins"] ?? $displayTotalDue);
                                }

                                $roleText = $isBuyer ? "You bought shares" : "You sold shares";
                                $resaleText = resaleBadgeText($p["resale_status"] ?? "");
                            ?>

                            <div class="history-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <div class="bid-number">
                                            <?php echo htmlspecialchars($bidNumber); ?>
                                        </div>

                                        <div class="history-date">
                                            <?php echo htmlspecialchars($roleText); ?> · <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?>
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <span class="status-pill <?php echo purchaseBadgeClass($p["status"]); ?>">
                                            <?php echo htmlspecialchars(purchaseBadgeText($p["status"])); ?>
                                        </span>

                                        <?php if ($resaleText !== ""): ?>
                                            <span class="resale-pill">
                                                <?php echo htmlspecialchars($resaleText); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
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
                                    Share value <?php echo money($principal); ?>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-box">
                                        <div class="detail-label">Bought / Sold</div>
                                        <div class="detail-value"><?php echo shares($principal); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Return</div>
                                        <div class="detail-value">
                                            <?php echo number_format($displayReturnPercent, 2); ?>%
                                            · <?php echo shares($displayReturnShares); ?>
                                        </div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Total Due</div>
                                        <div class="detail-value"><?php echo shares($displayTotalDue); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Bid Period</div>
                                        <div class="detail-value"><?php echo (int)$fixedBidPeriodDays; ?> days</div>
                                    </div>
                                </div>

                                <?php if ($isBuyer): ?>
                                    <div class="bank-grid">
                                        <div class="detail-box">
                                            <div class="detail-label">Seller</div>
                                            <div class="detail-value"><?php echo htmlspecialchars(memberLabel($p, "seller_")); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Seller Phone</div>
                                            <div class="detail-value"><?php echo safeText($p["seller_phone"] ?? ""); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Bank</div>
                                            <div class="detail-value"><?php echo bankingValue($p["seller_bank_name"] ?? ""); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Account Holder</div>
                                            <div class="detail-value"><?php echo bankingValue($p["seller_bank_account_holder"] ?? ""); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Account Number</div>
                                            <div class="detail-value"><?php echo bankingValue($p["seller_bank_account_number"] ?? ""); ?></div>
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
                                <?php endif; ?>

                                <?php if ($isSeller): ?>
                                    <div class="bank-grid">
                                        <div class="detail-box">
                                            <div class="detail-label">Buyer</div>
                                            <div class="detail-value"><?php echo htmlspecialchars(memberLabel($p, "buyer_")); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Buyer Phone</div>
                                            <div class="detail-value"><?php echo safeText($p["buyer_phone"] ?? ""); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Buyer Email</div>
                                            <div class="detail-value"><?php echo safeText($p["buyer_email"] ?? ""); ?></div>
                                        </div>

                                        <div class="detail-box">
                                            <div class="detail-label">Privacy</div>
                                            <div class="detail-value">Buyer banking hidden</div>
                                        </div>
                                    </div>

                                    <?php if ($p["status"] === "pending_seller_approval"): ?>
                                        <div class="approval-actions">
                                            <form method="POST" onsubmit="return confirm('Approve this buyer purchase and start the buyer countdown?');">
                                                <input type="hidden" name="action" value="seller_approve_purchase">
                                                <input type="hidden" name="claim_id" value="<?php echo $claimId; ?>">
                                                <button class="btn-approve">
                                                    Approve
                                                </button>
                                            </form>

                                            <form method="POST" onsubmit="return confirm('Reject this buyer purchase?');">
                                                <input type="hidden" name="action" value="seller_reject_purchase">
                                                <input type="hidden" name="claim_id" value="<?php echo $claimId; ?>">
                                                <button class="btn-reject">
                                                    Reject
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <div class="details-grid mt-3">
                                    <div class="detail-box">
                                        <div class="detail-label">Requested</div>
                                        <div class="detail-value"><?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Approved</div>
                                        <div class="detail-value"><?php echo htmlspecialchars(displayDate($p["approved_at"])); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Matures</div>
                                        <div class="detail-value"><?php echo htmlspecialchars(displayDate($p["matures_at"])); ?></div>
                                    </div>

                                    <div class="detail-box">
                                        <div class="detail-label">Package</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($p["package_name"] ?? $current_package_name); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-card">
                        No auction history found.
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination mb-0">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? "active" : ""; ?>">
                                    <a class="page-link" href="?<?php echo htmlspecialchars($queryStringBase); ?>&page=<?php echo $i; ?>">
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