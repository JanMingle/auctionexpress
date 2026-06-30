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

function coins($amount) {
    return number_format((float)$amount, 2) . " coins";
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
        return '<span class="text-muted">Not provided</span>';
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
$current_maturity_days = (int)($packageRules["maturity_days"] ?? 30);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

if ($current_maturity_days <= 0) {
    $current_maturity_days = 30;
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

        .purchase-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .purchase-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 16px;
            background: #fff;
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
            .purchase-grid {
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
                <div class="topbar-title">My Coin Purchases</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="auction-hero">
                <h2 class="mb-2">My Coin Purchases</h2>
                <p class="mb-1">
                    These are your latest coin purchases still waiting for seller approval.
                </p>
                <div class="small opacity-75">
                    Current package return:
                    <strong><?php echo number_format($current_return_percent, 2); ?>%</strong>
                    over
                    <strong><?php echo (int)$current_maturity_days; ?> days</strong>
                    — <?php echo htmlspecialchars($current_package_name); ?>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0 fw-bold">Pending Purchases</h5>

                <a href="auction_history.php" class="btn btn-outline-dark btn-sm">
                    View Full Auction History
                </a>
            </div>

            <?php if ($purchases->num_rows > 0): ?>
                <div class="purchase-grid">
                    <?php while ($p = $purchases->fetch_assoc()): ?>
                        <?php
                            $principal = (float)$p["principal_coins"];


                            /*
    1 coin = R1.
    Buyer must pay the seller the rand value of the coins bought.
*/
$paymentAmount = $principal;
$paymentReference = "Auction purchase #" . (int)$p["id"];

                            /*
                                Always display the current owner package percentage.
                                This prevents old 3% or old auction values from showing.
                            */
                            $displayReturnPercent = $current_return_percent;
                            $displayReturnCoins = round(($principal * $displayReturnPercent) / 100, 2);
                            $displayTotalDue = $principal + $displayReturnCoins;
                        ?>

                        <div class="purchase-card">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <h6 class="fw-bold mb-1">Pending Seller Approval</h6>
                                    <div class="text-muted small">
                                        Bought: <?php echo htmlspecialchars(displayDate($p["claimed_at"])); ?>
                                    </div>
                                </div>

                                <span class="badge bg-warning text-dark">
                                    Pending
                                </span>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-4">
                                    <div class="detail-box">
                                      <div class="detail-label">Coins Bought</div>
<strong><?php echo coins($principal); ?></strong><br>
<span class="text-muted">
    Amount to pay: <?php echo money($paymentAmount); ?>
</span>
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

                            <div class="alert alert-warning mb-3">
    <strong>Payment Required:</strong><br>
    Please transfer <strong><?php echo money($paymentAmount); ?></strong> to the seller for the 
    <strong><?php echo coins($principal); ?></strong> you bought.
    <br>
    Use the seller banking details below.
    <br>
    <small>
        Suggested reference: <strong><?php echo htmlspecialchars($paymentReference); ?></strong>
    </small>
</div>

                            <div class="detail-box mb-3">
                                <div class="detail-label">Seller Contact Details</div>
                                <div>
                                    <strong><?php echo htmlspecialchars(memberLabel($p, "seller_")); ?></strong>
                                </div>
                                <div class="metric">
                                    Phone:
                                    <strong><?php echo htmlspecialchars($p["seller_phone"] ?: "Not provided"); ?></strong>
                                </div>
                                <div class="metric">
                                    Email:
                                    <strong><?php echo htmlspecialchars($p["seller_email"] ?: "Not provided"); ?></strong>
                                </div>
                            </div>

                            <div class="detail-box">
                                <div class="detail-label">Seller Banking Details</div>

                                <?php if ((int)($p["seller_banking_details_completed"] ?? 0) !== 1): ?>
                                    <div class="alert alert-warning py-2 mb-2">
                                        Seller has not completed banking details.
                                    </div>
                                <?php endif; ?>

                                <div class="metric">
                                    Bank:
                                    <strong><?php echo bankingValue($p["seller_bank_name"] ?? ""); ?></strong>
                                </div>

                                <div class="metric">
                                    Account Holder:
                                    <strong><?php echo bankingValue($p["seller_bank_account_holder"] ?? ""); ?></strong>
                                </div>

                                <div class="metric">
                                    Account Number:
                                    <strong><?php echo bankingValue($p["seller_bank_account_number"] ?? ""); ?></strong>
                                </div>

                                <div class="metric">
                                    Branch Code:
                                    <strong><?php echo bankingValue($p["seller_bank_branch_code"] ?? ""); ?></strong>
                                </div>

                                <div class="metric">
                                    Account Type:
                                    <strong><?php echo bankingValue($p["seller_bank_account_type"] ?? ""); ?></strong>
                                </div>
                            </div>

                            <div class="alert alert-info py-2 mt-3 mb-0">
                                Waiting for the seller to approve this purchase.
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="card-box text-center py-5">
                    <h5 class="fw-bold mb-2">No pending coin purchases</h5>
                    <p class="text-muted mb-3">
                        You do not have any coin purchases waiting for seller approval.
                    </p>
                    <a href="auction.php" class="btn btn-dark">
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
    </main>
</div>

</body>
</html>