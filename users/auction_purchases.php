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

if (!function_exists("auctionResaleBadge")) {
    function auctionResaleBadge($status) {
        if ($status === "listed") {
            return '<span class="badge badge-pending">Scheduled for Next Auction</span>';
        }

        if ($status === "sold") {
            return '<span class="badge badge-approved">Sold</span>';
        }

        return '<span class="badge bg-secondary">Not Listed</span>';
    }
}

auctionEnsureWallet($conn, $tenant_id, $user_id);
auctionProcessMaturedPurchases($conn, $tenant_id, $user_id);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "sell_matured_purchase") {
        $claim_id = (int)($_POST["claim_id"] ?? 0);

        if ($claim_id <= 0) {
            $error = "Invalid purchase selected.";
        } else {
            try {
                $conn->begin_transaction();

                $claimStmt = $conn->prepare("
                    SELECT 
                        auction_claims.*,
                        auction_lots.package_id,
                        auction_lots.return_percent AS lot_return_percent,
                        auction_lots.maturity_days AS lot_maturity_days
                    FROM auction_claims
                    INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
                    WHERE auction_claims.id = ?
                    AND auction_claims.tenant_id = ?
                    AND auction_claims.buyer_user_id = ?
                    AND auction_claims.status = 'matured'
                    AND COALESCE(auction_claims.resale_status, 'not_listed') = 'not_listed'
                    LIMIT 1
                    FOR UPDATE
                ");
                $claimStmt->bind_param("iii", $claim_id, $tenant_id, $user_id);
                $claimStmt->execute();
                $claim = $claimStmt->get_result()->fetch_assoc();

                if (!$claim) {
                    throw new Exception("This purchase is not ready to sell or has already been listed.");
                }

                $coinsToSell = (float)$claim["total_due_coins"];

                if ($coinsToSell <= 0) {
                    throw new Exception("No coins available to sell.");
                }

                $package_id = (int)$claim["package_id"];
                $return_percent = (float)$claim["lot_return_percent"];
                $maturity_days = (int)$claim["lot_maturity_days"];

                if ($return_percent <= 0) {
                    $return_percent = (float)$claim["return_percent"];
                }

                if ($maturity_days <= 0) {
                    $maturity_days = 3;
                }

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
                        created_by,
                        source_claim_id
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)
                ");
                $insertLot->bind_param(
                    "iiidddiii",
                    $tenant_id,
                    $package_id,
                    $user_id,
                    $coinsToSell,
                    $coinsToSell,
                    $return_percent,
                    $maturity_days,
                    $user_id,
                    $claim_id
                );
                $insertLot->execute();

                $newLotId = $conn->insert_id;

                $updateClaim = $conn->prepare("
                    UPDATE auction_claims
                    SET resale_status = 'listed',
                        resale_lot_id = ?
                    WHERE id = ?
                    AND tenant_id = ?
                ");
                $updateClaim->bind_param("iii", $newLotId, $claim_id, $tenant_id);
                $updateClaim->execute();

                auctionAddLedger(
                    $conn,
                    $tenant_id,
                    $user_id,
                    null,
                    $newLotId,
                    $claim_id,
                    "coin_purchase_scheduled_for_resale",
                    $coinsToSell,
                    0,
                    "Matured coin purchase scheduled for the next auction slot.",
                    null
                );

                $conn->commit();
                $success = "Coins scheduled for the next auction slot.";
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

$purchasesStmt = $conn->prepare("
    SELECT 
        auction_claims.*,
        packages.package_name,
     seller.first_name AS seller_first_name,
seller.last_name AS seller_last_name,
seller.username AS seller_username,
seller.member_code AS seller_member_code,
seller.phone AS seller_phone,
seller.bank_name AS seller_bank_name,
seller.bank_account_holder AS seller_bank_account_holder,
seller.bank_account_number AS seller_bank_account_number,
seller.bank_branch_code AS seller_bank_branch_code,
seller.bank_account_type AS seller_bank_account_type,
seller.banking_details_completed AS seller_banking_details_completed
    FROM auction_claims
    INNER JOIN auction_lots ON auction_lots.id = auction_claims.lot_id
    INNER JOIN packages ON packages.id = auction_lots.package_id
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE auction_claims.tenant_id = ?
    AND auction_claims.buyer_user_id = ?
    ORDER BY auction_claims.claimed_at DESC
    LIMIT 100
");
$purchasesStmt->bind_param("ii", $tenant_id, $user_id);
$purchasesStmt->execute();
$purchases = $purchasesStmt->get_result();

$purchaseTotalsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_purchases,
        COALESCE(SUM(principal_coins), 0) AS total_principal,
        COALESCE(SUM(return_coins), 0) AS total_return,
        COALESCE(SUM(total_due_coins), 0) AS total_due,

        COALESCE(SUM(CASE WHEN status = 'pending_seller_approval' THEN principal_coins ELSE 0 END), 0) AS pending_principal,
        COALESCE(SUM(CASE WHEN status = 'active' THEN principal_coins ELSE 0 END), 0) AS active_principal,
        COALESCE(SUM(CASE WHEN status = 'matured' THEN principal_coins ELSE 0 END), 0) AS matured_principal
    FROM auction_claims
    WHERE tenant_id = ?
    AND buyer_user_id = ?
");
$purchaseTotalsStmt->bind_param("ii", $tenant_id, $user_id);
$purchaseTotalsStmt->execute();
$purchaseTotals = $purchaseTotalsStmt->get_result()->fetch_assoc();

$totalMyPurchases = (int)($purchaseTotals["total_purchases"] ?? 0);
$totalMyPrincipal = (float)($purchaseTotals["total_principal"] ?? 0);
$totalMyReturn = (float)($purchaseTotals["total_return"] ?? 0);
$totalMyDue = (float)($purchaseTotals["total_due"] ?? 0);

$totalPendingPrincipal = (float)($purchaseTotals["pending_principal"] ?? 0);
$totalActivePrincipal = (float)($purchaseTotals["active_principal"] ?? 0);
$totalMaturedPrincipal = (float)($purchaseTotals["matured_principal"] ?? 0);
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

    <?php auctionCommonStyles(); ?>

    <style>
        .purchase-card {
            background: #fff;
            border: 1px solid rgba(16,36,31,0.10);
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 14px 30px rgba(16,36,31,0.08);
            margin-bottom: 16px;
        }

        .purchase-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 14px;
        }

        .purchase-title {
            font-weight: 950;
            font-size: 18px;
        }

        .purchase-subtitle {
            color: #667085;
            font-size: 13px;
        }

        .purchase-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .purchase-mini {
            border-radius: 18px;
            background: #f8faf9;
            padding: 14px;
        }

        .purchase-mini-label {
            color: #667085;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .purchase-mini-value {
            font-weight: 950;
            margin-top: 4px;
        }

        .purchase-countdown {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.24), transparent 34%),
                linear-gradient(135deg, #10241f, #073f2f);
            color: #fff;
            border-radius: 20px;
            padding: 16px;
            margin-top: 16px;
        }

        .purchase-countdown-time {
            font-weight: 950;
            font-size: 28px;
            letter-spacing: -0.04em;
        }

        .purchase-countdown-label {
            font-size: 12px;
            opacity: 0.78;
            text-transform: uppercase;
            font-weight: 800;
        }

        @media (max-width: 900px) {
            .purchase-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            .purchase-grid {
                grid-template-columns: 1fr;
            }
        }
        .seller-bank-box {
    margin-top: 16px;
    border-radius: 18px;
    background: #fffaf0;
    border: 1px solid rgba(216,169,40,0.28);
    padding: 16px;
}

.seller-bank-title {
    font-weight: 950;
    margin-bottom: 10px;
    color: #10241f;
}

.seller-bank-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.seller-bank-item {
    background: #fff;
    border-radius: 14px;
    padding: 11px 12px;
}

.seller-bank-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    color: #667085;
}

.seller-bank-value {
    font-weight: 900;
    color: #10241f;
    margin-top: 3px;
    word-break: break-word;
}

@media (max-width: 620px) {
    .seller-bank-grid {
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
                <p class="mb-0">
                    Track your purchased coins, countdowns, returns, and sell matured coins into the next auction slot.
                </p>
            </div>

            <?php auctionTabs("auction_purchases.php"); ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">My Purchases</div>
                        <div class="stat-value"><?php echo number_format($totalMyPurchases); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Coins Bought</div>
                        <div class="stat-value"><?php echo auctionCoins($totalMyPrincipal); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">My Return</div>
                        <div class="stat-value"><?php echo auctionCoins($totalMyReturn); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Total Due</div>
                        <div class="stat-value"><?php echo auctionCoins($totalMyDue); ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-label">Pending Approval Coins</div>
                        <div class="stat-value"><?php echo auctionCoins($totalPendingPrincipal); ?></div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-label">Counting Down Coins</div>
                        <div class="stat-value"><?php echo auctionCoins($totalActivePrincipal); ?></div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-label">Matured Coins</div>
                        <div class="stat-value"><?php echo auctionCoins($totalMaturedPrincipal); ?></div>
                    </div>
                </div>
            </div>

            <div class="card-box">
                <h5 class="quick-card-title mb-3">Purchased Coins</h5>

                <?php if ($purchases->num_rows > 0): ?>
                    <?php while ($purchase = $purchases->fetch_assoc()): ?>
                        <?php
                            $purchaseId = (int)$purchase["id"];
                            $status = $purchase["status"];
                            $resaleStatus = $purchase["resale_status"] ?? "not_listed";
                            $maturesAt = $purchase["matures_at"] ?? null;
                            $canSell = $status === "matured" && $resaleStatus === "not_listed";

                            $countdownIso = "";
                            if (!empty($maturesAt) && $maturesAt !== "0000-00-00 00:00:00") {
                                $countdownIso = date("c", strtotime($maturesAt));
                            }
                        ?>

                        <div class="purchase-card">
                            <div class="purchase-top">
                                <div>
                                    <div class="purchase-title">
                                        <?php echo auctionCoins($purchase["principal_coins"]); ?> bought
                                    </div>

                                    <div class="purchase-subtitle">
                                        Seller:
                                        <strong><?php echo htmlspecialchars(auctionPurchaseSellerLabel($purchase)); ?></strong>
                                        |
                                        Package:
                                        <?php echo htmlspecialchars($purchase["package_name"]); ?>
                                    </div>
                                </div>

                                <div class="text-md-end">
                                    <?php echo auctionBadge($status); ?>
                                    <?php if ($status === "matured" || $resaleStatus !== "not_listed"): ?>
                                        <?php echo auctionResaleBadge($resaleStatus); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="purchase-grid">
                                <div class="purchase-mini">
                                    <div class="purchase-mini-label">Coins Bought</div>
                                    <div class="purchase-mini-value">
                                        <?php echo auctionCoins($purchase["principal_coins"]); ?>
                                    </div>
                                </div>

                                <div class="purchase-mini">
                                    <div class="purchase-mini-label">Return</div>
                                    <div class="purchase-mini-value">
                                        <?php echo auctionCoins($purchase["return_coins"]); ?>
                                        <small class="text-muted">
                                            <?php echo number_format((float)$purchase["return_percent"], 2); ?>%
                                        </small>
                                    </div>
                                </div>

                                <div class="purchase-mini">
                                    <div class="purchase-mini-label">Total Due</div>
                                    <div class="purchase-mini-value">
                                        <?php echo auctionCoins($purchase["total_due_coins"]); ?>
                                    </div>
                                </div>

                                <div class="purchase-mini">
                                    <div class="purchase-mini-label">Matures</div>
                                    <div class="purchase-mini-value">
                                        <?php echo htmlspecialchars(auctionDisplayDateOrWaiting($maturesAt)); ?>
                                    </div>
                                </div>
                            </div>

<?php
$sellerBankingCompleted = (int)($purchase["seller_banking_details_completed"] ?? 0);
$sellerPhone = trim($purchase["seller_phone"] ?? "");
$sellerBankName = trim($purchase["seller_bank_name"] ?? "");
$sellerAccountHolder = trim($purchase["seller_bank_account_holder"] ?? "");
$sellerAccountNumber = trim($purchase["seller_bank_account_number"] ?? "");
$sellerBranchCode = trim($purchase["seller_bank_branch_code"] ?? "");
$sellerAccountType = trim($purchase["seller_bank_account_type"] ?? "");
?>

<div class="seller-bank-box">
    <div class="seller-bank-title">
        Seller Banking Details
    </div>

    <?php if ($sellerBankingCompleted === 1): ?>
        <div class="seller-bank-grid">
            <div class="seller-bank-item">
                <div class="seller-bank-label">Bank Name</div>
                <div class="seller-bank-value">
                    <?php echo htmlspecialchars($sellerBankName ?: "Not added"); ?>
                </div>
            </div>

            <div class="seller-bank-item">
                <div class="seller-bank-label">Account Holder</div>
                <div class="seller-bank-value">
                    <?php echo htmlspecialchars($sellerAccountHolder ?: "Not added"); ?>
                </div>
            </div>

            <div class="seller-bank-item">
                <div class="seller-bank-label">Account Number</div>
                <div class="seller-bank-value">
                    <?php echo htmlspecialchars($sellerAccountNumber ?: "Not added"); ?>
                </div>
            </div>

            <div class="seller-bank-item">
                <div class="seller-bank-label">Branch Code</div>
                <div class="seller-bank-value">
                    <?php echo htmlspecialchars($sellerBranchCode ?: "Not added"); ?>
                </div>
            </div>

            <div class="seller-bank-item">
                <div class="seller-bank-label">Account Type</div>
                <div class="seller-bank-value">
                    <?php echo htmlspecialchars($sellerAccountType ?: "Not added"); ?>
                </div>
            </div>
            <div class="seller-bank-item">
    <div class="seller-bank-label">Seller Phone</div>
    <div class="seller-bank-value">
        <?php echo htmlspecialchars($sellerPhone ?: "Not added"); ?>
    </div>
</div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning mb-0">
            Seller has not completed banking details yet.
        </div>
    <?php endif; ?>
</div>

<?php if ($status === "active" && $countdownIso !== ""): ?>
                                <div class="purchase-countdown purchase-countdown-box" data-matures-at="<?php echo htmlspecialchars($countdownIso); ?>">
                                    <div class="purchase-countdown-label">Countdown to maturity</div>
                                    <div class="purchase-countdown-time">Loading...</div>
                                </div>
                            <?php endif; ?>

                            <?php if ($canSell): ?>
                                <form method="POST" class="mt-3" onsubmit="return confirm('Sell these matured coins in the next auction slot?');">
                                    <input type="hidden" name="action" value="sell_matured_purchase">
                                    <input type="hidden" name="claim_id" value="<?php echo $purchaseId; ?>">
                                    <button class="btn btn-dark">
                                        Sell Coins
                                    </button>
                                </form>
                            <?php elseif ($resaleStatus === "listed"): ?>
                                <div class="alert alert-info mt-3 mb-0">
                                    These coins are scheduled for the next auction slot.
                                </div>
                            <?php elseif ($resaleStatus === "sold"): ?>
                                <div class="alert alert-success mt-3 mb-0">
                                    These coins have been sold. You can buy another auction to start a new countdown.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        You have not bought any auction coins yet.
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script>
(function () {
    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function updateCountdowns() {
        document.querySelectorAll(".purchase-countdown-box").forEach(function (box) {
            const target = new Date(box.dataset.maturesAt).getTime();
            const output = box.querySelector(".purchase-countdown-time");

            if (!target || !output) {
                return;
            }

            const now = Date.now();
            let diff = Math.max(0, target - now);

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            diff -= days * 1000 * 60 * 60 * 24;

            const hours = Math.floor(diff / (1000 * 60 * 60));
            diff -= hours * 1000 * 60 * 60;

            const minutes = Math.floor(diff / (1000 * 60));
            diff -= minutes * 1000 * 60;

            const seconds = Math.floor(diff / 1000);

            output.textContent =
                pad(days) + "d : " +
                pad(hours) + "h : " +
                pad(minutes) + "m : " +
                pad(seconds) + "s";

            if (target - now <= 0) {
                output.textContent = "Matured";
            }
        });
    }

    updateCountdowns();
    setInterval(updateCountdowns, 1000);
})();
</script>

</body>
</html>