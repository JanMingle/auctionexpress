<?php
session_start();
require_once "../config/db.php";

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

function purchaseBadge($status) {
    if ($status === "pending_seller_approval") {
        return '<span class="badge badge-pending">Pending Approval</span>';
    }

    if ($status === "active") {
        return '<span class="badge badge-approved">Counting Down</span>';
    }

    if ($status === "matured") {
        return '<span class="badge badge-approved">Matured</span>';
    }

    if ($status === "paid") {
        return '<span class="badge badge-approved">Paid</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    if ($status === "cancelled") {
        return '<span class="badge badge-rejected">Cancelled</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
}

function resaleBadge($status) {
    if ($status === "listed") {
        return '<span class="badge badge-pending">Listed Again</span>';
    }

    if ($status === "sold") {
        return '<span class="badge badge-approved">Resold</span>';
    }

    return '<span class="badge bg-secondary">Not Listed</span>';
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
            $maturity_days = (int)$claim["maturity_days"];
            $source_claim_id = (int)($claim["source_claim_id"] ?? 0);

            if ($maturity_days <= 0) {
                $maturity_days = 3;
            }

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
                Normal admin-added coins are held in locked_coins after auction opens.
                Resale coins from a matured purchase use source_claim_id and may not be in locked_coins.
            */
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

            $updateClaim = $conn->prepare("
                UPDATE auction_claims
                SET status = 'active',
                    approved_at = NOW(),
                    approved_by = ?,
                    matures_at = ?
                WHERE id = ?
                AND tenant_id = ?
            ");
            $updateClaim->bind_param(
                "isii",
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
$status = trim($_GET["status"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

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

$where = "
    auction_claims.tenant_id = ?
";

$params = [$tenant_id];
$types = "i";

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
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

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
    ORDER BY auction_claims.claimed_at DESC
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
    "q" => $q,
    "status" => $status
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auction Purchases</title>
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

        .filter-row {
            display: grid;
            grid-template-columns: 1fr 220px 140px;
            gap: 10px;
        }

        @media (max-width: 768px) {
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
                <div class="topbar-title">Auction Purchases</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="auction-hero">
                <h2 class="mb-2">Auction Purchases</h2>
                <p class="mb-0">
                    See who bought coins from who, and approve or reject purchases on behalf of a seller when needed.
                </p>
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
                        Search
                    </button>
                </form>
            </div>

            <div class="card-box">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="quick-card-title mb-0">Purchase Transactions</h5>
                    <div class="text-muted small">
                        Showing <?php echo number_format($totalRows); ?> transaction(s)
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
                                <th>Status</th>
                                <th>Dates</th>
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

                                        <td>
                                            <?php echo coins($p["return_coins"]); ?><br>
                                            <small class="text-muted">
                                                <?php echo number_format((float)$p["return_percent"], 2); ?>%
                                            </small>
                                        </td>

                                        <td><strong><?php echo coins($p["total_due_coins"]); ?></strong></td>

                                        <td>
                                            <?php echo purchaseBadge($p["status"]); ?><br>
                                            <?php echo resaleBadge($p["resale_status"] ?? "not_listed"); ?>
                                        </td>

                                        <td>
                                            <small>
                                                Bought: <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?><br>
                                                Approved: <?php echo htmlspecialchars(displayDate($p["approved_at"])); ?><br>
                                                Matures: <?php echo htmlspecialchars(displayDate($p["matures_at"])); ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?php if ($p["status"] === "pending_seller_approval"): ?>
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
                                            <?php else: ?>
                                                <span class="text-muted small">No action</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No purchase transactions found.
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