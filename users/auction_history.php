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
$displayName = $username ?: $name;

$success = "";
$error = "";

function coins($amount) {
    return number_format((float)$amount, 2) . " coins";
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function memberLabel($row, $prefix = "") {
    $username = $row[$prefix . "username"] ?? "";
    $memberCode = $row[$prefix . "member_code"] ?? "";
    $firstName = $row[$prefix . "first_name"] ?? "";
    $lastName = $row[$prefix . "last_name"] ?? "";

    if (!empty($username)) {
        return $username;
    }

    if (!empty($memberCode)) {
        return $memberCode;
    }

    $fullName = trim($firstName . " " . $lastName);

    return $fullName !== "" ? $fullName : "Member";
}

function purchaseBadge($status) {
    if ($status === "pending_seller_approval") {
        return '<span class="badge bg-warning text-dark">Pending Seller Approval</span>';
    }

    if ($status === "active") {
        return '<span class="badge bg-primary">Counting Down</span>';
    }

    if ($status === "matured") {
        return '<span class="badge bg-success">Matured</span>';
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

function resaleBadge($status) {
    if ($status === "listed") {
        return '<span class="badge bg-info text-dark">Listed Again</span>';
    }

    if ($status === "sold") {
        return '<span class="badge bg-success">Resold</span>';
    }

    return "";
}

function bankingValue($value) {
    $value = trim((string)$value);
    return $value !== "" ? htmlspecialchars($value) : '<span class="text-muted">Not provided</span>';
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

/*
    Current owner package rules.
    This keeps the displayed return percentage correct.
*/
$packageRules = getTenantPackageRules($conn, $tenant_id);

$current_package_id = (int)($packageRules["package_id"] ?? 0);
$current_package_name = $packageRules["package_name"] ?? "Current Package";
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);
$current_maturity_days = (int)($packageRules["maturity_days"] ?? 0);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

if ($current_maturity_days <= 0) {
    $current_maturity_days = 30;
}

/*
    Seller approval/rejection.
    Only the seller can approve/reject their own pending purchase requests.
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
                Use current Owner Package values.
                Do not use old auction_claims.return_percent.
                Do not use old auction_lots.return_percent.
            */
            $return_percent = $current_return_percent;
            $maturity_days = $current_maturity_days;

            $return_coins = round(($principal * $return_percent) / 100, 2);
            $total_due_coins = $principal + $return_coins;
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

            /*
                Normal admin-opened auction coins are locked.
                Resale coins use source_claim_id and may not be in locked_coins.
            */
            if ($source_claim_id <= 0 && $sellerLocked < $principal) {
                throw new Exception("You do not have enough locked auction coins for this approval.");
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
                $return_coins,
                $total_due_coins,
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
                "seller_approved_coin_purchase",
                -$principal,
                $sellerAvailable,
                "Seller approved coin purchase.",
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
                "seller_rejected_coin_purchase",
                0,
                0,
                "Seller rejected coin purchase.",
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
$perPage = 12;
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
        .auction-hero {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.34), transparent 34%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #fff;
            border-radius: 28px;
            padding: 26px;
            margin-bottom: 22px;
            box-shadow: 0 22px 50px rgba(16,36,31,0.16);
        }

        .card-box {
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(255,255,255,0.78);
            border-radius: 22px;
            padding: 20px;
            box-shadow: 0 18px 42px rgba(16,36,31,0.10);
        }

        .filter-row {
            display: grid;
            grid-template-columns: 1fr 180px 180px 120px;
            gap: 10px;
        }

        .history-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .history-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 16px;
            background: #fff;
        }

        .history-card-title {
            font-weight: 900;
            letter-spacing: -0.02em;
        }

        .detail-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px;
            font-size: 13px;
        }

        .detail-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .metric {
            font-size: 13px;
            color: #475569;
        }

        .metric strong {
            color: #0f172a;
        }

        @media (max-width: 992px) {
            .history-grid {
                grid-template-columns: 1fr;
            }

            .filter-row {
                grid-template-columns: 1fr;
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
            <div class="auction-hero">
                <h2 class="mb-2">Auction History</h2>
                <p class="mb-1">
                    Track coins you bought and coins you sold.
                </p>
                <div class="small opacity-75">
                    Current package return:
                    <strong><?php echo number_format($current_return_percent, 2); ?>%</strong>
                    over
                    <strong><?php echo (int)$current_maturity_days; ?> days</strong>
                    — <?php echo htmlspecialchars($current_package_name); ?>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card-box mb-4">
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
                        <option value="buyer" <?php echo $view === "buyer" ? "selected" : ""; ?>>Coins I Bought</option>
                        <option value="seller" <?php echo $view === "seller" ? "selected" : ""; ?>>Coins I Sold</option>
                    </select>

                    <select name="status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="pending_seller_approval" <?php echo $status === "pending_seller_approval" ? "selected" : ""; ?>>Pending Approval</option>
                        <option value="active" <?php echo $status === "active" ? "selected" : ""; ?>>Counting Down</option>
                        <option value="matured" <?php echo $status === "matured" ? "selected" : ""; ?>>Matured</option>
                        <option value="paid" <?php echo $status === "paid" ? "selected" : ""; ?>>Paid</option>
                        <option value="rejected" <?php echo $status === "rejected" ? "selected" : ""; ?>>Rejected</option>
                        <option value="cancelled" <?php echo $status === "cancelled" ? "selected" : ""; ?>>Cancelled</option>
                    </select>

                    <button class="btn btn-dark">
                        Filter
                    </button>
                </form>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0 fw-bold">Transactions</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format($totalRows); ?> transaction(s)
                </div>
            </div>

            <?php if ($purchases->num_rows > 0): ?>
                <div class="history-grid">
                    <?php while ($p = $purchases->fetch_assoc()): ?>
                        <?php
                            $isBuyer = (int)$p["buyer_user_id"] === $user_id;
                            $isSeller = (int)$p["seller_user_id"] === $user_id;

                            $principal = (float)$p["principal_coins"];

                            /*
                                Show the correct owner package percentage.
                                This avoids old 3% auction values showing here.
                            */
                            $displayReturnPercent = $current_return_percent;
                            $displayReturnCoins = round(($principal * $displayReturnPercent) / 100, 2);
                            $displayTotalDue = $principal + $displayReturnCoins;

                            $roleLabel = $isBuyer ? "You bought coins" : "You sold coins";
                        ?>

                        <div class="history-card">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <div class="history-card-title">
                                        <?php echo htmlspecialchars($roleLabel); ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?>
                                    </div>
                                </div>

                                <div class="text-end">
                                    <?php echo purchaseBadge($p["status"]); ?><br>
                                    <?php echo resaleBadge($p["resale_status"] ?? ""); ?>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Coins</div>
                                        <strong><?php echo coins($principal); ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Return</div>
                                        <strong><?php echo coins($displayReturnCoins); ?></strong><br>
                                        <span class="text-muted">
                                            <?php echo number_format($displayReturnPercent, 2); ?>%
                                        </span>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Total Due</div>
                                        <strong><?php echo coins($displayTotalDue); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <?php if ($isBuyer): ?>
                                <div class="detail-box mb-3">
                                    <div class="detail-label">Seller Details</div>
                                    <div>
                                        <strong><?php echo htmlspecialchars(memberLabel($p, "seller_")); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Phone: <strong><?php echo htmlspecialchars($p["seller_phone"] ?: "Not provided"); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Email: <strong><?php echo htmlspecialchars($p["seller_email"] ?: "Not provided"); ?></strong>
                                    </div>
                                </div>

                                <div class="detail-box mb-3">
                                    <div class="detail-label">Seller Banking Details</div>

                                    <?php if ((int)($p["seller_banking_details_completed"] ?? 0) !== 1): ?>
                                        <div class="alert alert-warning py-2 mb-2">
                                            Seller has not completed banking details.
                                        </div>
                                    <?php endif; ?>

                                    <div class="metric">
                                        Bank: <strong><?php echo bankingValue($p["seller_bank_name"] ?? ""); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Account Holder: <strong><?php echo bankingValue($p["seller_bank_account_holder"] ?? ""); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Account Number: <strong><?php echo bankingValue($p["seller_bank_account_number"] ?? ""); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Branch Code: <strong><?php echo bankingValue($p["seller_bank_branch_code"] ?? ""); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Account Type: <strong><?php echo bankingValue($p["seller_bank_account_type"] ?? ""); ?></strong>
                                    </div>
                                </div>

                                <?php if ($p["status"] === "pending_seller_approval"): ?>
                                    <div class="alert alert-info py-2 mb-0">
                                        Waiting for the seller to approve this purchase.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($isSeller): ?>
                                <div class="detail-box mb-3">
                                    <div class="detail-label">Buyer Contact Details</div>
                                    <div>
                                        <strong><?php echo htmlspecialchars(memberLabel($p, "buyer_")); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Phone: <strong><?php echo htmlspecialchars($p["buyer_phone"] ?: "Not provided"); ?></strong>
                                    </div>
                                    <div class="metric">
                                        Email: <strong><?php echo htmlspecialchars($p["buyer_email"] ?: "Not provided"); ?></strong>
                                    </div>
                                    <div class="text-muted small mt-2">
                                        Buyer banking details are not shown to sellers.
                                    </div>
                                </div>

                                <?php if ($p["status"] === "pending_seller_approval"): ?>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <form method="POST" onsubmit="return confirm('Approve this buyer purchase?');">
                                            <input type="hidden" name="action" value="seller_approve_purchase">
                                            <input type="hidden" name="claim_id" value="<?php echo (int)$p["id"]; ?>">
                                            <button class="btn btn-dark btn-sm">
                                                Approve Purchase
                                            </button>
                                        </form>

                                        <form method="POST" onsubmit="return confirm('Reject this buyer purchase?');">
                                            <input type="hidden" name="action" value="seller_reject_purchase">
                                            <input type="hidden" name="claim_id" value="<?php echo (int)$p["id"]; ?>">
                                            <button class="btn btn-outline-dark btn-sm">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="detail-box mt-3">
                                <div class="detail-label">Dates</div>
                                <div class="metric">
                                    Bought: <strong><?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?></strong>
                                </div>
                                <div class="metric">
                                    Approved: <strong><?php echo htmlspecialchars(displayDate($p["approved_at"])); ?></strong>
                                </div>
                                <div class="metric">
                                    Matures: <strong><?php echo htmlspecialchars(displayDate($p["matures_at"])); ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="card-box text-center text-muted py-5">
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
    </main>
</div>
</body>
</html>