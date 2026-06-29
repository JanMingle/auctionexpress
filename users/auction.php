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

function auctionNextOpenInfo(): array {
    $now = time();
    $today = date("Y-m-d");

    $todayTen = strtotime($today . " 10:00:00");
    $todayFive = strtotime($today . " 17:00:00");

    if ($now < $todayTen) {
        $next = $todayTen;
    } elseif ($now < $todayFive) {
        $next = $todayFive;
    } else {
        $tomorrow = date("Y-m-d", strtotime("+1 day"));
        $next = strtotime($tomorrow . " 10:00:00");
    }

    return [
        "timestamp" => $next,
        "iso" => date("c", $next),
        "label" => date("D, d M Y H:i", $next),
    ];
}

auctionEnsureWallet($conn, $tenant_id, $user_id);
auctionProcessMaturedPurchases($conn, $tenant_id, $user_id);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "buy_coins") {
        $lot_id = (int)($_POST["lot_id"] ?? 0);
        $buy_amount = (float)($_POST["buy_amount"] ?? 0);

        if ($lot_id <= 0) {
            $error = "Invalid auction selected.";
        } elseif ($buy_amount <= 0) {
            $error = "Please enter how many coins you want to buy.";
        } else {
            try {
                $conn->begin_transaction();

                $pendingStmt = $conn->prepare("
                    SELECT id
                    FROM auction_claims
                    WHERE tenant_id = ?
                    AND buyer_user_id = ?
                    AND status = 'pending_seller_approval'
                    LIMIT 1
                    FOR UPDATE
                ");
                $pendingStmt->bind_param("ii", $tenant_id, $user_id);
                $pendingStmt->execute();

                if ($pendingStmt->get_result()->fetch_assoc()) {
                    throw new Exception("You already have a coin purchase waiting for seller approval. Please wait for approval before buying again.");
                }

                $tenantStmt = $conn->prepare("
                    SELECT auction_status
                    FROM tenants
                    WHERE id = ?
                    LIMIT 1
                ");
                $tenantStmt->bind_param("i", $tenant_id);
                $tenantStmt->execute();
                $tenantRow = $tenantStmt->get_result()->fetch_assoc();

                if (($tenantRow["auction_status"] ?? "closed") !== "open") {
                    throw new Exception("Auction is currently closed.");
                }

                $lotStmt = $conn->prepare("
                    SELECT *
                    FROM auction_lots
                    WHERE id = ?
                    AND tenant_id = ?
                    AND status = 'open'
                    AND remaining_coins > 0
                    LIMIT 1
                    FOR UPDATE
                ");
                $lotStmt->bind_param("ii", $lot_id, $tenant_id);
                $lotStmt->execute();
                $lot = $lotStmt->get_result()->fetch_assoc();

                if (!$lot) {
                    throw new Exception("These coins are no longer available.");
                }

                $seller_user_id = (int)$lot["seller_user_id"];

                if ($seller_user_id === $user_id) {
                    throw new Exception("You cannot buy your own coins.");
                }

                $remaining_coins = (float)$lot["remaining_coins"];

                if ($buy_amount > $remaining_coins) {
                    throw new Exception("You cannot buy more than the available auction coins.");
                }

                $return_percent = (float)$lot["return_percent"];
                $return_coins = round($buy_amount * ($return_percent / 100), 2);
                $total_due = $buy_amount + $return_coins;

                auctionEnsureWallet($conn, $tenant_id, $user_id);
                auctionEnsureWallet($conn, $tenant_id, $seller_user_id);

                $buyerBalanceStmt = $conn->prepare("
                    SELECT available_coins
                    FROM member_coin_wallets
                    WHERE tenant_id = ?
                    AND user_id = ?
                    FOR UPDATE
                ");
                $buyerBalanceStmt->bind_param("ii", $tenant_id, $user_id);
                $buyerBalanceStmt->execute();
                $buyerBalanceRow = $buyerBalanceStmt->get_result()->fetch_assoc();
                $buyerBalanceAfter = (float)($buyerBalanceRow["available_coins"] ?? 0);

                $newRemaining = $remaining_coins - $buy_amount;
                $newLotStatus = $newRemaining <= 0 ? "claimed" : "open";

                $updateLot = $conn->prepare("
                    UPDATE auction_lots
                    SET remaining_coins = ?,
                        status = ?,
                        claimed_by = ?,
                        claimed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                ");
                $updateLot->bind_param(
                    "dsiii",
                    $newRemaining,
                    $newLotStatus,
                    $user_id,
                    $lot_id,
                    $tenant_id
                );
                $updateLot->execute();

                $claimStmt = $conn->prepare("
                    INSERT INTO auction_claims
                    (
                        tenant_id,
                        lot_id,
                        buyer_user_id,
                        seller_user_id,
                        principal_coins,
                        return_percent,
                        return_coins,
                        total_due_coins,
                        status,
                        claimed_at,
                        matures_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_seller_approval', NOW(), NULL)
                ");
                $claimStmt->bind_param(
                    "iiiidddd",
                    $tenant_id,
                    $lot_id,
                    $user_id,
                    $seller_user_id,
                    $buy_amount,
                    $return_percent,
                    $return_coins,
                    $total_due
                );
                $claimStmt->execute();
                $claim_id = $conn->insert_id;

                auctionAddLedger(
                    $conn,
                    $tenant_id,
                    $user_id,
                    $seller_user_id,
                    $lot_id,
                    $claim_id,
                    "coin_purchase_request",
                    0,
                    $buyerBalanceAfter,
                    "Coin purchase requested. No buyer coins were deducted.",
                    null
                );

                $conn->commit();
                $success = "Coin purchase submitted successfully. Please make payment to the seller account details shown.";
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
$nextOpen = auctionNextOpenInfo();
$hasPendingBuyerPurchase = auctionHasPendingBuyerPurchase($conn, $tenant_id, $user_id);

$bankSelect = auctionBankSelectSql($conn);

$liveStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS total_live_coins
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'open'
    AND remaining_coins > 0
");
$liveStatsStmt->bind_param("i", $tenant_id);
$liveStatsStmt->execute();
$liveStats = $liveStatsStmt->get_result()->fetch_assoc();
$totalLiveCoins = (float)($liveStats["total_live_coins"] ?? 0);

$myLiveStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS my_live_coins
    FROM auction_lots
    WHERE tenant_id = ?
    AND seller_user_id = ?
    AND status = 'open'
    AND remaining_coins > 0
");
$myLiveStatsStmt->bind_param("ii", $tenant_id, $user_id);
$myLiveStatsStmt->execute();
$myLiveStats = $myLiveStatsStmt->get_result()->fetch_assoc();
$myLiveCoins = (float)($myLiveStats["my_live_coins"] ?? 0);

$nextStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(member_coin_wallets.available_coins), 0) AS total_next_coins
    FROM users
    LEFT JOIN member_coin_wallets
        ON member_coin_wallets.user_id = users.id
        AND member_coin_wallets.tenant_id = users.tenant_id
    WHERE users.tenant_id = ?
    AND users.role = 'member'
    AND users.status = 'active'
");
$nextStatsStmt->bind_param("i", $tenant_id);
$nextStatsStmt->execute();
$nextStats = $nextStatsStmt->get_result()->fetch_assoc();
$totalNextCoins = (float)($nextStats["total_next_coins"] ?? 0);

if ($auctionStatus === "open") {
    $displayTotalCoins = $totalLiveCoins;
    $displayMyCoins = $myLiveCoins;
    $totalCoinsLabel = "Available Auction Coins";
    $myCoinsLabel = "My Selling Coins";
} else {
    $displayTotalCoins = $totalNextCoins;
    $displayMyCoins = $available_coins;
    $totalCoinsLabel = "Next Auction Available Coins";
    $myCoinsLabel = "My Next Auction Coins";
}

$auctionLots = null;

if ($auctionStatus === "open") {
    $lotsSql = "
        SELECT 
            auction_lots.id,
            auction_lots.seller_user_id,
            auction_lots.remaining_coins,
            auction_lots.return_percent,
            auction_lots.maturity_days,
            packages.package_name,
            users.first_name,
            users.last_name,
            users.username,
            users.member_code,
            $bankSelect
        FROM auction_lots
        INNER JOIN packages ON packages.id = auction_lots.package_id
        INNER JOIN users ON users.id = auction_lots.seller_user_id
        WHERE auction_lots.tenant_id = ?
        AND auction_lots.status = 'open'
        AND auction_lots.remaining_coins > 0
        ORDER BY 
            CASE WHEN auction_lots.seller_user_id = ? THEN 0 ELSE 1 END,
            auction_lots.remaining_coins DESC,
            auction_lots.created_at DESC
    ";

    $lotsStmt = $conn->prepare($lotsSql);
    $lotsStmt->bind_param("ii", $tenant_id, $user_id);
    $lotsStmt->execute();
    $auctionLots = $lotsStmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auction</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <?php auctionCommonStyles(); ?>

    <style>
        .schedule-card {
            border-radius: 22px;
            padding: 20px;
            background: #fff;
            box-shadow: 0 18px 38px rgba(16,36,31,0.10);
            height: 100%;
        }

        .schedule-label {
            color: #6c757d;
            font-size: 13px;
            font-weight: 700;
        }

        .schedule-value {
            font-size: 22px;
            font-weight: 900;
            margin-top: 4px;
        }

        .countdown-watch {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.28), transparent 35%),
                linear-gradient(135deg, #10241f, #073f2f);
            color: #fff;
            border-radius: 32px;
            padding: 34px;
            text-align: center;
            box-shadow: 0 24px 55px rgba(16,36,31,0.18);
            margin-bottom: 24px;
        }

        .countdown-title {
            font-size: 15px;
            font-weight: 800;
            opacity: 0.85;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .countdown-time {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 20px;
        }

        .countdown-unit {
            min-width: 120px;
            border-radius: 24px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.22);
            padding: 18px 14px;
        }

        .countdown-number {
            display: block;
            font-size: 44px;
            font-weight: 950;
            line-height: 1;
            letter-spacing: -0.05em;
        }

        .countdown-label {
            display: block;
            margin-top: 7px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            opacity: 0.8;
        }

        .next-auction-date {
            margin-top: 14px;
            font-size: 14px;
            opacity: 0.85;
        }

        .own-card-note {
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.28);
            color: #7a5a09;
            border-radius: 14px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 800;
            margin-top: 12px;
        }

        .closed-info-card {
            background: #fff;
            border-radius: 24px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 18px 38px rgba(16,36,31,0.10);
            color: #667085;
        }

        @media (max-width: 640px) {
            .countdown-watch {
                padding: 26px 18px;
            }

            .countdown-unit {
                min-width: 84px;
                padding: 14px 10px;
            }

            .countdown-number {
                font-size: 32px;
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
                <div class="topbar-title">Auction</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="auction-hero">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="mb-2">
                            <?php echo $auctionStatus === "open" ? "Live Auction" : "Next Auction"; ?>
                        </h2>

                        <p class="mb-2">
                            Auctions run daily at <strong>10:00</strong> and <strong>17:00</strong>.
                        </p>

                        <div class="auction-status-pill">
                            <span class="<?php echo $auctionStatus === 'open' ? 'status-dot-live' : 'status-dot-closed'; ?>"></span>
                            Auction is <?php echo strtoupper(htmlspecialchars($auctionStatus)); ?>
                        </div>
                    </div>

                    <div class="text-md-end">
                        <div class="small opacity-75">Next scheduled auction</div>
                        <strong><?php echo htmlspecialchars($nextOpen["label"]); ?></strong>
                    </div>
                </div>
            </div>

            <?php auctionTabs("auction.php"); ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($hasPendingBuyerPurchase): ?>
                <div class="alert alert-warning">
                    You already have a coin purchase waiting for seller approval. You can buy again after the seller approves or rejects it.
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="schedule-card">
                        <div class="schedule-label">Auction Schedule</div>
                        <div class="schedule-value">10:00 & 17:00</div>
                      
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label"><?php echo htmlspecialchars($totalCoinsLabel); ?></div>
                        <div class="stat-value"><?php echo auctionCoins($displayTotalCoins); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label"><?php echo htmlspecialchars($myCoinsLabel); ?></div>
                        <div class="stat-value"><?php echo auctionCoins($displayMyCoins); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Auction Earnings</div>
                        <div class="stat-value"><?php echo auctionCoins($total_earned); ?></div>
                    </div>
                </div>
            </div>

            <?php if ($auctionStatus !== "open"): ?>
                <div class="countdown-watch">
                    <div class="countdown-title">Next auction opens in</div>

                    <div class="countdown-time" id="auctionCountdown">
                        <div class="countdown-unit">
                            <span class="countdown-number" id="cdHours">00</span>
                            <span class="countdown-label">Hours</span>
                        </div>

                        <div class="countdown-unit">
                            <span class="countdown-number" id="cdMinutes">00</span>
                            <span class="countdown-label">Minutes</span>
                        </div>

                        <div class="countdown-unit">
                            <span class="countdown-number" id="cdSeconds">00</span>
                            <span class="countdown-label">Seconds</span>
                        </div>
                    </div>

                    <div class="next-auction-date">
                        Next auction: <?php echo htmlspecialchars($nextOpen["label"]); ?>
                    </div>
                </div>

                <div class="closed-info-card">
                    The auction is currently closed. When it opens, members selling coins will appear here.
                </div>
            <?php else: ?>

                <div class="card-box">
                    <h5 class="quick-card-title mb-3">Members Selling Coins</h5>

                    <?php if ($auctionLots && $auctionLots->num_rows > 0): ?>
                        <div class="row g-3">
                            <?php while ($lot = $auctionLots->fetch_assoc()): ?>
                                <?php
                                    $lotId = (int)$lot["id"];
                                    $sellerUserId = (int)$lot["seller_user_id"];
                                    $remaining = (float)$lot["remaining_coins"];
                                    $sellerName = auctionSellerLabel($lot);
                                    $sellerInitial = strtoupper(substr($sellerName ?: "U", 0, 1));
                                    $isOwnCoins = $sellerUserId === $user_id;

                                    $bankName = trim($lot["bank_name"] ?? "");
                                    if ($bankName === "") {
                                        $bankName = "Not added";
                                    }

                                    $memberCodeText = trim($lot["member_code"] ?? "");
                                    if ($memberCodeText === "") {
                                        $memberCodeText = "N/A";
                                    }

                                    $canBuy = !$isOwnCoins
                                        && !$hasPendingBuyerPurchase
                                        && $lotId > 0
                                        && $remaining > 0;
                                ?>

                                <div class="col-md-6 col-xl-4">
                                    <div class="auction-user-card">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="auction-avatar">
                                                <?php echo htmlspecialchars($sellerInitial); ?>
                                            </div>

                                            <div>
                                                <div class="auction-user-name">
                                                    <?php echo htmlspecialchars($memberCodeText); ?>
                                                    <?php if ($isOwnCoins): ?>
                                                        <span class="badge bg-warning text-dark ms-1">You</span>
                                                    <?php endif; ?>
                                                </div>

                                             
                                            </div>
                                        </div>

                                        <div class="auction-meta mb-3">
                                            Coins Available:
                                            <strong><?php echo auctionCoins($remaining); ?></strong><br>

                                            Bank Name:
                                            <strong><?php echo htmlspecialchars($bankName); ?></strong><br>

                                      
                                        </div>

                                        <?php if ($isOwnCoins): ?>
                                            <div class="own-card-note">
                                                These are your coins. You cannot buy from yourself.
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" onsubmit="return confirm('Submit this coin purchase?');">
                                                <input type="hidden" name="action" value="buy_coins">
                                                <input type="hidden" name="lot_id" value="<?php echo $lotId; ?>">

                                                <label class="form-label">Coins to buy</label>

                                                <div class="buy-row">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        max="<?php echo htmlspecialchars((string)$remaining); ?>"
                                                        name="buy_amount"
                                                        id="buy_amount_<?php echo $lotId; ?>"
                                                        class="form-control"
                                                        placeholder="Amount"
                                                        <?php echo $canBuy ? "" : "disabled"; ?>
                                                        required
                                                    >

                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-dark"
                                                        onclick="document.getElementById('buy_amount_<?php echo $lotId; ?>').value='<?php echo htmlspecialchars((string)$remaining); ?>';"
                                                        <?php echo $canBuy ? "" : "disabled"; ?>
                                                    >
                                                        Buy All
                                                    </button>
                                                </div>

                                                <button
                                                    class="btn btn-dark w-100 mt-3"
                                                    <?php echo $canBuy ? "" : "disabled"; ?>
                                                >
                                                    Buy Coins
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            No members are selling coins right now.
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </div>
    </main>
</div>

<script>
(function () {
    const countdownBox = document.getElementById("auctionCountdown");

    if (!countdownBox) {
        return;
    }

    const nextOpenAt = new Date("<?php echo htmlspecialchars($nextOpen["iso"]); ?>").getTime();

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function updateCountdown() {
        const now = new Date().getTime();
        let diff = Math.max(0, nextOpenAt - now);

        const hours = Math.floor(diff / (1000 * 60 * 60));
        diff -= hours * 1000 * 60 * 60;

        const minutes = Math.floor(diff / (1000 * 60));
        diff -= minutes * 1000 * 60;

        const seconds = Math.floor(diff / 1000);

        document.getElementById("cdHours").textContent = pad(hours);
        document.getElementById("cdMinutes").textContent = pad(minutes);
        document.getElementById("cdSeconds").textContent = pad(seconds);

        if (nextOpenAt - now <= 0) {
            setTimeout(function () {
                window.location.reload();
            }, 1500);
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
</script>

</body>
</html>