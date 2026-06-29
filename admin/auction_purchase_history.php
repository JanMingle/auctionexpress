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
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$name = $_SESSION["name"] ?? "Admin";
$displayName = $username ?: $name;

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

$q = trim($_GET["q"] ?? "");
$status = trim($_GET["status"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$allowedStatuses = [
    "",
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
    AND auction_claims.status <> 'pending_seller_approval'
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
bindDynamicParams($countStmt, $types, $params);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$totalsSql = "
    SELECT
        COUNT(*) AS total_history,
        COALESCE(SUM(auction_claims.principal_coins), 0) AS history_principal,
        COALESCE(SUM(auction_claims.return_coins), 0) AS history_return,
        COALESCE(SUM(auction_claims.total_due_coins), 0) AS history_total_due
    FROM auction_claims
    INNER JOIN users buyer ON buyer.id = auction_claims.buyer_user_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE $where
";

$totalsStmt = $conn->prepare($totalsSql);
bindDynamicParams($totalsStmt, $types, $params);
$totalsStmt->execute();
$totals = $totalsStmt->get_result()->fetch_assoc();

$totalHistoryRecords = (int)($totals["total_history"] ?? 0);
$totalHistoryPrincipal = (float)($totals["history_principal"] ?? 0);
$totalHistoryReturn = (float)($totals["history_return"] ?? 0);
$totalHistoryDue = (float)($totals["history_total_due"] ?? 0);

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
bindDynamicParams($listStmt, $listTypes, $listParams);
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
            grid-template-columns: 1fr 220px 140px;
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
                <p class="mb-0">
                    These are purchases that have already been approved, matured, sold, rejected, or completed.
                </p>
            </div>

          <div class="auction-tabs">
    <a href="auction_pending_approval.php" class="auction-tab">Pending Approval</a>
    <a href="auction_purchase_history.php" class="auction-tab active">History</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">History Records</div>
            <div class="stat-value"><?php echo number_format($totalHistoryRecords); ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Coins Bought</div>
            <div class="stat-value"><?php echo coins($totalHistoryPrincipal); ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Total Return</div>
            <div class="stat-value"><?php echo coins($totalHistoryReturn); ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Total Due</div>
            <div class="stat-value"><?php echo coins($totalHistoryDue); ?></div>
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

                    <select name="status" class="form-select">
                        <option value="">All history statuses</option>
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
                    <h5 class="quick-card-title mb-0">Purchase History</h5>
                    <div class="text-muted small">
                        Showing <?php echo number_format($totalRows); ?> history record(s)
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

                                        <td>
                                            <strong><?php echo coins($p["total_due_coins"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo purchaseBadge($p["status"]); ?><br>
                                            <?php echo resaleBadge($p["resale_status"] ?? "not_listed"); ?>
                                        </td>

                                        <td>
                                            <small>
                                                Bought: <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?><br>
                                                Approved: <?php echo htmlspecialchars(displayDate($p["approved_at"])); ?><br>
                                                Matures: <?php echo htmlspecialchars(displayDate($p["matures_at"])); ?><br>
                                                Sold: <?php echo htmlspecialchars(displayDate($p["sold_at"] ?? null)); ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No history records found.
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