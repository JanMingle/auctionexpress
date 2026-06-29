<?php
session_start();
require_once "../config/db.php";
require_once "auction_helpers.php";

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

auctionEnsureWallet($conn, $tenant_id, $user_id);
auctionProcessMaturedPurchases($conn, $tenant_id, $user_id);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "approve_purchase") {
        $claim_id = (int)($_POST["claim_id"] ?? 0);

        if ($claim_id <= 0) {
            $error = "Invalid purchase selected.";
        } else {
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
                    SELECT maturity_days, source_claim_id
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

                $maturity_days = (int)($lot["maturity_days"] ?? 3);
                $source_claim_id = (int)($lot["source_claim_id"] ?? 0);

                if ($maturity_days <= 0) {
                    $maturity_days = 3;
                }

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
                    throw new Exception("You do not have enough locked auction coins to approve this purchase.");
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
                    $user_id,
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
                    "coin_purchase_seller_approved",
                    -$principal,
                    $sellerAvailable,
                    "Seller approved coin purchase. Buyer countdown started.",
                    null
                );

                $conn->commit();
                $success = "Coin purchase approved. The buyer countdown has started.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }

    if ($action === "reject_purchase") {
        $claim_id = (int)($_POST["claim_id"] ?? 0);

        if ($claim_id <= 0) {
            $error = "Invalid purchase selected.";
        } else {
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
                ");
                $updateClaim->bind_param("ii", $claim_id, $tenant_id);
                $updateClaim->execute();

                auctionAddLedger(
                    $conn,
                    $tenant_id,
                    $buyer_user_id,
                    $user_id,
                    $lot_id,
                    $claim_id,
                    "coin_purchase_rejected",
                    0,
                    $newBuyerAvailable,
                    "Seller rejected coin purchase. No buyer coins were deducted.",
                    null
                );

                $conn->commit();
                $success = "Coin purchase rejected.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
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
        buyer.member_code AS buyer_member_code
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
        COALESCE(SUM(principal_coins), 0) AS pending_principal,
        COALESCE(SUM(return_coins), 0) AS pending_return,
        COALESCE(SUM(total_due_coins), 0) AS pending_total_due
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
$totalPendingReturn = (float)($pendingTotals["pending_return"] ?? 0);
$totalPendingDue = (float)($pendingTotals["pending_total_due"] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pending Approval</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <?php auctionCommonStyles(); ?>
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
                <p class="mb-2">
                    These are coin purchase requests from other members waiting for your approval.
                </p>

                <div class="auction-status-pill">
                    <span class="<?php echo $auctionStatus === 'open' ? 'status-dot-live' : 'status-dot-closed'; ?>"></span>
                    Auction is <?php echo strtoupper(htmlspecialchars($auctionStatus)); ?>
                </div>
            </div>

            <?php auctionTabs("auction_pending_approval.php"); ?>

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
                        <div class="stat-label">Coins Requested From Me</div>
                        <div class="stat-value"><?php echo auctionCoins($totalPendingPrincipal); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Pending Return</div>
                        <div class="stat-value"><?php echo auctionCoins($totalPendingReturn); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Pending Total Due</div>
                        <div class="stat-value"><?php echo auctionCoins($totalPendingDue); ?></div>
                    </div>
                </div>
            </div>

            <div class="card-box">
                <h5 class="quick-card-title mb-3">Pending Approval</h5>

                <?php if ($pendingApprovals->num_rows > 0): ?>
                    <?php while ($approval = $pendingApprovals->fetch_assoc()): ?>
                        <div class="approval-card">
                            <div class="d-flex flex-wrap justify-content-between gap-3">
                                <div>
                                    <strong><?php echo htmlspecialchars(auctionBuyerLabel($approval)); ?></strong>
                                    wants to buy
                                    <strong><?php echo auctionCoins($approval["principal_coins"]); ?></strong>
                                    from you.

                                    <div class="text-muted small mt-1">
                                        Package:
                                        <?php echo htmlspecialchars($approval["package_name"]); ?>
                                        |
                                        Return:
                                        <?php echo number_format((float)$approval["return_percent"], 2); ?>%
                                        |
                                        Buyer will receive:
                                        <?php echo auctionCoins($approval["total_due_coins"]); ?>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <form method="POST" onsubmit="return confirm('Approve this coin purchase and start the buyer countdown?');">
                                        <input type="hidden" name="action" value="approve_purchase">
                                        <input type="hidden" name="claim_id" value="<?php echo (int)$approval["id"]; ?>">
                                        <button class="btn btn-dark btn-sm">
                                            Approve
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Reject this coin purchase?');">
                                        <input type="hidden" name="action" value="reject_purchase">
                                        <input type="hidden" name="claim_id" value="<?php echo (int)$approval["id"]; ?>">
                                        <button class="btn btn-outline-dark btn-sm">
                                            Reject
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        There are no purchases waiting for your approval.
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>
</body>
</html>