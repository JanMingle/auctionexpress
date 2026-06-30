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

$tenant_id = (int)$_SESSION["tenant_id"];
$admin_id = (int)$_SESSION["user_id"];
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$name = $_SESSION["name"] ?? "Admin";
$displayName = $username ?: $name;

$success = "";
$error = "";

$packageRules = getTenantPackageRules($conn, $tenant_id);

/*
    Always use the latest Owner Package values.
    This page is for seller/admin approval, so the return shown and approved
    must come from packages.return_rate_percent and packages.maturity_days.
*/
$current_package_id = (int)($packageRules["package_id"] ?? 0);
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);
$current_maturity_days = (int)($packageRules["maturity_days"] ?? 0);

$directPackageStmt = $conn->prepare("
    SELECT 
        p.id,
        p.package_name,
        p.return_rate_percent,
        p.maturity_days,
        p.minimum_saving_amount
    FROM tenants t
    INNER JOIN packages p ON p.id = t.package_id
    WHERE t.id = ?
    LIMIT 1
");
$directPackageStmt->bind_param("i", $tenant_id);
$directPackageStmt->execute();
$directPackage = $directPackageStmt->get_result()->fetch_assoc();

if ($directPackage) {
    $current_package_id = (int)$directPackage["id"];
    $current_return_percent = (float)$directPackage["return_rate_percent"];
    $current_maturity_days = (int)$directPackage["maturity_days"];
}

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

if ($current_maturity_days <= 0) {
    $current_maturity_days = 30;
}

function coins($amount) {
    return number_format((float)$amount, 2) . " coins";
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

    return trim($firstName . " " . $lastName);
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

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
}

function bindDynamicParams(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;

    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    call_user_func_array([$stmt, "bind_param"], $refs);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $claim_id = (int)($_POST["claim_id"] ?? 0);

    if ($claim_id <= 0) {
        $error = "Invalid purchase selected.";
    } elseif ($action === "admin_approve_purchase") {
        try {
            $conn->begin_transaction();

            $claimStmt = $conn->prepare("
                SELECT 
                    auction_claims.*,
                    auction_lots.maturity_days,
                    auction_lots.source_claim_id
                FROM auction_claims
                INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
                WHERE auction_claims.id = ?
                AND auction_claims.tenant_id = ?
                AND auction_claims.status = 'pending_seller_approval'
                LIMIT 1
                FOR UPDATE
            ");
            $claimStmt->bind_param("ii", $claim_id, $tenant_id);
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
    Use Owner Package values, not old auction_lots values.
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

            if ($source_claim_id <= 0 && $sellerLocked < $principal) {
                throw new Exception("Seller does not have enough locked auction coins for this approval.");
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
");
$updateClaim->bind_param(
    "dddisii",
    $return_percent,
    $return_coins,
    $total_due_coins,
    $admin_id,
    $matures_at,
    $claim_id,
    $tenant_id
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
                "admin_approved_coin_purchase",
                -$principal,
                $sellerAvailable,
                "Admin approved coin purchase on behalf of seller.",
                $admin_id
            );

            $conn->commit();
            $success = "Purchase approved on behalf of seller.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } elseif ($action === "admin_reject_purchase") {
        try {
            $conn->begin_transaction();

            $claimStmt = $conn->prepare("
                SELECT *
                FROM auction_claims
                WHERE id = ?
                AND tenant_id = ?
                AND status = 'pending_seller_approval'
                LIMIT 1
                FOR UPDATE
            ");
            $claimStmt->bind_param("ii", $claim_id, $tenant_id);
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
            ");
            $updateClaim->bind_param("ii", $claim_id, $tenant_id);
            $updateClaim->execute();

            addLedger(
                $conn,
                $tenant_id,
                $buyer_user_id,
                $seller_user_id,
                $lot_id,
                $claim_id,
                "admin_rejected_coin_purchase",
                0,
                0,
                "Admin rejected coin purchase on behalf of seller.",
                $admin_id
            );

            $conn->commit();
            $success = "Purchase rejected on behalf of seller.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$q = trim($_GET["q"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = "
    auction_claims.tenant_id = ?
    AND auction_claims.status = 'pending_seller_approval'
";

$params = [$tenant_id];
$types = "i";

if ($q !== "") {
    $where .= "
        AND (
            buyer.username LIKE ?
            OR buyer.member_code LIKE ?
            OR buyer.first_name LIKE ?
            OR buyer.last_name LIKE ?
            OR seller.username LIKE ?
            OR seller.member_code LIKE ?
            OR seller.first_name LIKE ?
            OR seller.last_name LIKE ?
        )
    ";

    $searchTerm = "%" . $q . "%";

    for ($i = 0; $i < 8; $i++) {
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
bindDynamicParams($countStmt, $types, $params);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$totalsSql = "
    SELECT
        COUNT(*) AS total_pending,
        COALESCE(SUM(auction_claims.principal_coins), 0) AS pending_principal,
        COALESCE(SUM(auction_claims.return_coins), 0) AS pending_return,
        COALESCE(SUM(auction_claims.total_due_coins), 0) AS pending_total_due
    FROM auction_claims
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE $where
";

$totalsStmt = $conn->prepare($totalsSql);
bindDynamicParams($totalsStmt, $types, $params);
$totalsStmt->execute();
$totals = $totalsStmt->get_result()->fetch_assoc();

$totalPendingRequests = (int)($totals["total_pending"] ?? 0);
$totalPendingPrincipal = (float)($totals["pending_principal"] ?? 0);

/*
    Summary cards must follow current Owner Package percentage.
*/
$totalPendingReturn = round(($totalPendingPrincipal * $current_return_percent) / 100, 2);
$totalPendingDue = $totalPendingPrincipal + $totalPendingReturn;

$listSql = "
    SELECT
        auction_claims.*,
        auction_lots.source_claim_id,
        packages.package_name,

        buyer.username AS buyer_username,
        buyer.member_code AS buyer_member_code,
        buyer.first_name AS buyer_first_name,
        buyer.last_name AS buyer_last_name,

        seller.username AS seller_username,
        seller.member_code AS seller_member_code,
        seller.first_name AS seller_first_name,
        seller.last_name AS seller_last_name
    FROM auction_claims
    INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
    LEFT JOIN packages ON packages.id = auction_lots.package_id
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE $where
    ORDER BY auction_claims.claimed_at ASC
    LIMIT ?
    OFFSET ?
";

$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$listStmt = $conn->prepare($listSql);
bindDynamicParams($listStmt, $listTypes, $listParams);
$listStmt->execute();
$purchases = $listStmt->get_result();

$queryStringBase = http_build_query([
    "q" => $q
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auction Pending Approval</title>
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
            border-radius: 30px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 22px 50px rgba(16,36,31,0.16);
        }

        .card-box {
            background: rgba(255,255,255,0.88);
            border: 1px solid rgba(255,255,255,0.78);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 45px rgba(16,36,31,0.10);
        }

        .quick-card-title {
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .table thead th {
            font-size: 12px;
            text-transform: uppercase;
            color: #6c757d;
        }

        .auction-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        .auction-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 15px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid rgba(16,36,31,0.12);
            color: #10241f;
            text-decoration: none;
            font-weight: 800;
            box-shadow: 0 10px 25px rgba(16,36,31,0.06);
        }

        .auction-tab.active {
            background: #10241f;
            color: #fff;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 1fr 140px;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .filter-row {
                grid-template-columns: 1fr;
            }
        }
        .stat-card {
    border-radius: 22px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 18px 38px rgba(16,36,31,0.10);
    height: 100%;
}

.stat-label {
    color: #6c757d;
    font-size: 13px;
    font-weight: 700;
}

.stat-value {
    font-size: 24px;
    font-weight: 900;
    margin-top: 4px;
}
    </style>
</head>

<body>
<div class="app-shell">
    <?php include "../includes/sidebar.php"; ?>

    <main class="app-main">
        <div class="app-topbar">
            <div>
                <div class="topbar-title">Pending Approval</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="auction-hero">
                <h2 class="mb-2">Pending Approval</h2>
                <p class="mb-0">
                    These are coin purchases waiting for the seller to approve. Admin can approve or reject on behalf of the seller.
                </p>
            </div>

            <div class="mt-2 small">
    Current Package Return:
    <strong><?php echo number_format($current_return_percent, 2); ?>%</strong>
    over
    <strong><?php echo (int)$current_maturity_days; ?> days</strong>
</div>

            <div class="auction-tabs">
                <a href="auction_pending_approval.php" class="auction-tab active">Pending Approval</a>
                <a href="auction_purchase_history.php" class="auction-tab">History</a>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

         <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Pending Requests</div>
            <div class="stat-value"><?php echo number_format($totalPendingRequests); ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Pending Coins Bought</div>
            <div class="stat-value"><?php echo coins($totalPendingPrincipal); ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Pending Return</div>
            <div class="stat-value"><?php echo coins($totalPendingReturn); ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Pending Total Due</div>
            <div class="stat-value"><?php echo coins($totalPendingDue); ?></div>
        </div>
    </div>
</div>

<div class="card-box mb-4">
                <form method="GET" class="filter-row">
                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        placeholder="Search buyer or seller"
                        value="<?php echo htmlspecialchars($q); ?>"
                    >

                    <button class="btn btn-dark">
                        Search
                    </button>
                </form>
            </div>

            <div class="card-box">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="quick-card-title mb-0">Purchases Waiting for Approval</h5>
                    <div class="text-muted small">
                        Showing <?php echo number_format($totalRows); ?> pending purchase(s)
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Coins Bought</th>
                                <th>Return</th>
                                <th>Total Due</th>
                                <th>Requested</th>
                                <th>Admin Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($purchases->num_rows > 0): ?>
                                <?php while ($p = $purchases->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars(memberLabel($p, "buyer_")); ?></strong>
                                        </td>

                                        <td>
                                            <strong><?php echo htmlspecialchars(memberLabel($p, "seller_")); ?></strong>
                                        </td>

                                        <td><?php echo coins($p["principal_coins"]); ?></td>

                      <?php
    /*
        Display using current Owner Package percentage.
        This avoids showing old saved return values.
    */
    $displayPrincipal = (float)$p["principal_coins"];
    $displayReturnPercent = $current_return_percent;
    $displayReturnCoins = round(($displayPrincipal * $displayReturnPercent) / 100, 2);
    $displayTotalDue = $displayPrincipal + $displayReturnCoins;
?>

<td>
    <?php echo coins($displayReturnCoins); ?><br>
    <small class="text-muted">
        <?php echo number_format($displayReturnPercent, 2); ?>%
    </small>
</td>

<td>
    <strong><?php echo coins($displayTotalDue); ?></strong>
</td>

                                        <td>
                                            <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?>
                                        </td>

                                        <td>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <form method="POST" onsubmit="return confirm('Approve this purchase on behalf of the seller?');">
                                                    <input type="hidden" name="action" value="admin_approve_purchase">
                                                    <input type="hidden" name="claim_id" value="<?php echo (int)$p["id"]; ?>">
                                                    <button class="btn btn-dark btn-sm">
                                                        Approve
                                                    </button>
                                                </form>

                                                <form method="POST" onsubmit="return confirm('Reject this purchase on behalf of the seller?');">
                                                    <input type="hidden" name="action" value="admin_reject_purchase">
                                                    <input type="hidden" name="claim_id" value="<?php echo (int)$p["id"]; ?>">
                                                    <button class="btn btn-outline-dark btn-sm">
                                                        Reject
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No pending approvals found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="mt-3">
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