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

$packageRulesPath = __DIR__ . "/../includes/package_rules.php";

if (file_exists($packageRulesPath)) {
    require_once $packageRulesPath;
}

$packageRules = [
    "package_id" => null,
    "package_name" => "Auction Package",
    "package_type" => "auction",
    "auction_return_percent" => 3.00,
    "auction_maturity_days" => 3,
    "auction_min_coins" => 0.00,
    "auction_max_coins" => 0.00,
    "enable_auction" => 1
];

if (function_exists("getTenantPackageRules")) {
    $packageRules = getTenantPackageRules($conn, $tenant_id);
}

$isAuctionPackage = function_exists("packageIsAuction")
    ? packageIsAuction($packageRules)
    : (($packageRules["package_type"] ?? "savings") === "auction");

function coins($amount) {
    return number_format((float)$amount, 2) . " coins";
}

function auctionBadge($status) {
    if ($status === "open" || $status === "active") {
        return '<span class="badge badge-approved">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }

    if ($status === "scheduled") {
        return '<span class="badge badge-pending">Scheduled</span>';
    }

    if ($status === "claimed" || $status === "paid") {
        return '<span class="badge badge-pending">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }

    if ($status === "closed" || $status === "inactive" || $status === "cancelled") {
        return '<span class="badge badge-rejected">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
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

function memberLabel($row) {
    if (!empty($row["username"])) {
        return $row["username"];
    }

    if (!empty($row["member_code"])) {
        return $row["member_code"];
    }

    return trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "credit_member") {
        $member_id = (int)($_POST["member_id"] ?? 0);
        $amount = (float)($_POST["amount"] ?? 0);
        $note = trim($_POST["note"] ?? "Admin coin credit");

        if ($member_id <= 0) {
            $error = "Please choose a member.";
        } elseif ($amount <= 0) {
            $error = "Please enter valid coins to add.";
        } else {
            try {
                $conn->begin_transaction();

                $checkStmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ?
                    AND tenant_id = ?
                    AND role = 'member'
                    LIMIT 1
                ");
                $checkStmt->bind_param("ii", $member_id, $tenant_id);
                $checkStmt->execute();

                if (!$checkStmt->get_result()->fetch_assoc()) {
                    throw new Exception("Member not found.");
                }

                ensureWallet($conn, $tenant_id, $member_id);

                $walletStmt = $conn->prepare("
                    SELECT available_coins
                    FROM member_coin_wallets
                    WHERE tenant_id = ?
                    AND user_id = ?
                    FOR UPDATE
                ");
                $walletStmt->bind_param("ii", $tenant_id, $member_id);
                $walletStmt->execute();
                $wallet = $walletStmt->get_result()->fetch_assoc();

                $new_balance = (float)($wallet["available_coins"] ?? 0) + $amount;

                $updateStmt = $conn->prepare("
                    UPDATE member_coin_wallets
                    SET available_coins = available_coins + ?
                    WHERE tenant_id = ?
                    AND user_id = ?
                ");
                $updateStmt->bind_param("dii", $amount, $tenant_id, $member_id);
                $updateStmt->execute();

                addLedger(
                    $conn,
                    $tenant_id,
                    $member_id,
                    null,
                    null,
                    null,
                    "admin_credit",
                    $amount,
                    $new_balance,
                    $note,
                    $admin_id
                );

                $conn->commit();
                $success = "Coins added to member wallet.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }

    if ($action === "open_auction") {
        if (!$isAuctionPackage) {
            $error = "This tenant is not on an auction package.";
        } elseif (empty($packageRules["package_id"])) {
            $error = "No auction package is linked to this tenant.";
        } else {
            try {
                $conn->begin_transaction();

             $package_id = (int)$packageRules["package_id"];

/*
    Auction must follow Owner Package settings.
    Do not use old auction_return_percent or auction_maturity_days here.
*/
$return_percent = (float)($packageRules["return_rate_percent"] ?? 0);
$maturity_days = (int)($packageRules["maturity_days"] ?? 0);
$min_coins = (float)($packageRules["minimum_saving_amount"] ?? 0);

if ($return_percent <= 0) {
                    throw new Exception("Auction return percentage is not valid.");
                }

                if ($maturity_days <= 0) {
                    throw new Exception("Auction maturity days is not valid.");
                }

                $tenantStmt = $conn->prepare("
                    UPDATE tenants
                    SET auction_status = 'open'
                    WHERE id = ?
                ");
                $tenantStmt->bind_param("i", $tenant_id);
                $tenantStmt->execute();

          $scheduledStmt = $conn->prepare("
    UPDATE auction_lots
    SET status = 'open',
        package_id = ?,
        return_percent = ?,
        maturity_days = ?,
        updated_at = NOW()
    WHERE tenant_id = ?
    AND status = 'scheduled'
    AND remaining_coins > 0
");
$scheduledStmt->bind_param(
    "idii",
    $package_id,
    $return_percent,
    $maturity_days,
    $tenant_id
);
$scheduledStmt->execute();

                $membersStmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE tenant_id = ?
                    AND role = 'member'
                    AND status = 'active'
                    ORDER BY id ASC
                ");
                $membersStmt->bind_param("i", $tenant_id);
                $membersStmt->execute();
                $membersResult = $membersStmt->get_result();

                $openedMembers = 0;
                $openedCoins = 0.00;

                while ($member = $membersResult->fetch_assoc()) {
                    $member_id = (int)$member["id"];

                    ensureWallet($conn, $tenant_id, $member_id);

                    $walletStmt = $conn->prepare("
                        SELECT available_coins, locked_coins
                        FROM member_coin_wallets
                        WHERE tenant_id = ?
                        AND user_id = ?
                        FOR UPDATE
                    ");
                    $walletStmt->bind_param("ii", $tenant_id, $member_id);
                    $walletStmt->execute();
                    $wallet = $walletStmt->get_result()->fetch_assoc();

                    $available = (float)($wallet["available_coins"] ?? 0);

                    if ($available <= 0) {
                        continue;
                    }

                    if ($min_coins > 0 && $available < $min_coins) {
                        continue;
                    }

                    $existingLotStmt = $conn->prepare("
                        SELECT id
                        FROM auction_lots
                        WHERE tenant_id = ?
                        AND seller_user_id = ?
                        AND status = 'open'
                        AND source_claim_id IS NULL
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $existingLotStmt->bind_param("ii", $tenant_id, $member_id);
                    $existingLotStmt->execute();
                    $existingLot = $existingLotStmt->get_result()->fetch_assoc();

                    if ($existingLot) {
                        $lot_id = (int)$existingLot["id"];

                        $updateLot = $conn->prepare("
                            UPDATE auction_lots
                            SET coin_amount = coin_amount + ?,
                                remaining_coins = remaining_coins + ?,
                                return_percent = ?,
                                maturity_days = ?,
                                updated_at = NOW()
                            WHERE id = ?
                            AND tenant_id = ?
                        ");
                        $updateLot->bind_param(
                            "dddiii",
                            $available,
                            $available,
                            $return_percent,
                            $maturity_days,
                            $lot_id,
                            $tenant_id
                        );
                        $updateLot->execute();
                    } else {
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
                                created_by
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)
                        ");
                        $insertLot->bind_param(
                            "iiidddii",
                            $tenant_id,
                            $package_id,
                            $member_id,
                            $available,
                            $available,
                            $return_percent,
                            $maturity_days,
                            $admin_id
                        );
                        $insertLot->execute();

                        $lot_id = $conn->insert_id;
                    }

                    $newAvailable = 0.00;
                    $newLocked = (float)($wallet["locked_coins"] ?? 0) + $available;

                    $updateWallet = $conn->prepare("
                        UPDATE member_coin_wallets
                        SET available_coins = ?,
                            locked_coins = ?
                        WHERE tenant_id = ?
                        AND user_id = ?
                    ");
                    $updateWallet->bind_param(
                        "ddii",
                        $newAvailable,
                        $newLocked,
                        $tenant_id,
                        $member_id
                    );
                    $updateWallet->execute();

                    addLedger(
                        $conn,
                        $tenant_id,
                        $member_id,
                        null,
                        $lot_id,
                        null,
                        "auction_opened",
                        -$available,
                        $newAvailable,
                        "Available coins moved into open auction.",
                        $admin_id
                    );

                    $openedMembers++;
                    $openedCoins += $available;
                }

                $conn->commit();

                if ($openedMembers > 0) {
                    $success = "Auction opened. " . coins($openedCoins) . " opened from " . $openedMembers . " member(s).";
                } else {
                    $success = "Auction opened. No available member coins were found to open.";
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }

    if ($action === "close_auction") {
        try {
            $conn->begin_transaction();

            $tenantStmt = $conn->prepare("
                UPDATE tenants
                SET auction_status = 'closed'
                WHERE id = ?
            ");
            $tenantStmt->bind_param("i", $tenant_id);
            $tenantStmt->execute();

            $lotsStmt = $conn->prepare("
                SELECT id, seller_user_id, remaining_coins, source_claim_id
                FROM auction_lots
                WHERE tenant_id = ?
                AND status = 'open'
                FOR UPDATE
            ");
            $lotsStmt->bind_param("i", $tenant_id);
            $lotsStmt->execute();
            $openLots = $lotsStmt->get_result();

            $returnedCoins = 0.00;
            $closedLots = 0;

            while ($lot = $openLots->fetch_assoc()) {
                $lot_id = (int)$lot["id"];
                $seller_user_id = (int)$lot["seller_user_id"];
                $remaining = (float)$lot["remaining_coins"];
                $source_claim_id = (int)($lot["source_claim_id"] ?? 0);

                if ($source_claim_id > 0) {
                    $rescheduleLot = $conn->prepare("
                        UPDATE auction_lots
                        SET status = 'scheduled',
                            updated_at = NOW()
                        WHERE id = ?
                        AND tenant_id = ?
                    ");
                    $rescheduleLot->bind_param("ii", $lot_id, $tenant_id);
                    $rescheduleLot->execute();

                    $closedLots++;
                    continue;
                }

                ensureWallet($conn, $tenant_id, $seller_user_id);

                $walletStmt = $conn->prepare("
                    SELECT available_coins, locked_coins
                    FROM member_coin_wallets
                    WHERE tenant_id = ?
                    AND user_id = ?
                    FOR UPDATE
                ");
                $walletStmt->bind_param("ii", $tenant_id, $seller_user_id);
                $walletStmt->execute();
                $wallet = $walletStmt->get_result()->fetch_assoc();

                if ($remaining > 0) {
                    $newAvailable = (float)($wallet["available_coins"] ?? 0) + $remaining;
                    $newLocked = max(0, (float)($wallet["locked_coins"] ?? 0) - $remaining);

                    $updateWallet = $conn->prepare("
                        UPDATE member_coin_wallets
                        SET available_coins = ?,
                            locked_coins = ?
                        WHERE tenant_id = ?
                        AND user_id = ?
                    ");
                    $updateWallet->bind_param(
                        "ddii",
                        $newAvailable,
                        $newLocked,
                        $tenant_id,
                        $seller_user_id
                    );
                    $updateWallet->execute();

                    addLedger(
                        $conn,
                        $tenant_id,
                        $seller_user_id,
                        null,
                        $lot_id,
                        null,
                        "auction_closed_return",
                        $remaining,
                        $newAvailable,
                        "Remaining unsold auction coins returned after auction was closed.",
                        $admin_id
                    );

                    $returnedCoins += $remaining;
                }

                $updateLot = $conn->prepare("
                    UPDATE auction_lots
                    SET status = 'closed',
                        remaining_coins = 0,
                        updated_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                ");
                $updateLot->bind_param("ii", $lot_id, $tenant_id);
                $updateLot->execute();

                $closedLots++;
            }

            $conn->commit();
            $success = "Auction closed. " . coins($returnedCoins) . " remaining coins returned from " . $closedLots . " open lot(s).";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$tenantStatusStmt = $conn->prepare("
    SELECT auction_status
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$tenantStatusStmt->bind_param("i", $tenant_id);
$tenantStatusStmt->execute();
$tenantStatusRow = $tenantStatusStmt->get_result()->fetch_assoc();
$auctionStatus = $tenantStatusRow["auction_status"] ?? "closed";

$memberOptionsStmt = $conn->prepare("
    SELECT id, first_name, last_name, username, member_code
    FROM users
    WHERE tenant_id = ?
    AND role = 'member'
    AND status = 'active'
    ORDER BY first_name ASC, last_name ASC
");
$memberOptionsStmt->bind_param("i", $tenant_id);
$memberOptionsStmt->execute();
$memberOptions = $memberOptionsStmt->get_result();

$membersStmt = $conn->prepare("
    SELECT 
        users.id,
        users.first_name,
        users.last_name,
        users.username,
        users.member_code,
        users.status,
        COALESCE(member_coin_wallets.available_coins, 0) AS available_coins,
        COALESCE(open_lots.open_coins, 0) AS open_auction_coins,
        COALESCE(scheduled_lots.scheduled_coins, 0) AS scheduled_auction_coins,
        (
            COALESCE(member_coin_wallets.available_coins, 0)
            + COALESCE(open_lots.open_coins, 0)
            + COALESCE(scheduled_lots.scheduled_coins, 0)
        ) AS total_user_coins
    FROM users
    LEFT JOIN member_coin_wallets
        ON member_coin_wallets.user_id = users.id
        AND member_coin_wallets.tenant_id = users.tenant_id
    LEFT JOIN (
        SELECT seller_user_id, SUM(remaining_coins) AS open_coins
        FROM auction_lots
        WHERE tenant_id = ?
        AND status = 'open'
        AND remaining_coins > 0
        GROUP BY seller_user_id
    ) open_lots ON open_lots.seller_user_id = users.id
    LEFT JOIN (
        SELECT seller_user_id, SUM(remaining_coins) AS scheduled_coins
        FROM auction_lots
        WHERE tenant_id = ?
        AND status = 'scheduled'
        AND remaining_coins > 0
        GROUP BY seller_user_id
    ) scheduled_lots ON scheduled_lots.seller_user_id = users.id
    WHERE users.tenant_id = ?
    AND users.role = 'member'
    ORDER BY total_user_coins DESC, users.created_at DESC
");
$membersStmt->bind_param("iii", $tenant_id, $tenant_id, $tenant_id);
$membersStmt->execute();
$members = $membersStmt->get_result();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(users.id) AS total_members,
        COALESCE(SUM(member_coin_wallets.available_coins), 0) AS total_available,
        COALESCE(SUM(member_coin_wallets.locked_coins), 0) AS total_locked
    FROM users
    LEFT JOIN member_coin_wallets
        ON member_coin_wallets.user_id = users.id
        AND member_coin_wallets.tenant_id = users.tenant_id
    WHERE users.tenant_id = ?
    AND users.role = 'member'
");
$statsStmt->bind_param("i", $tenant_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$openStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS total_open
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'open'
    AND remaining_coins > 0
");
$openStatsStmt->bind_param("i", $tenant_id);
$openStatsStmt->execute();
$openStats = $openStatsStmt->get_result()->fetch_assoc();

$scheduledStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS total_scheduled
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'scheduled'
    AND remaining_coins > 0
");
$scheduledStatsStmt->bind_param("i", $tenant_id);
$scheduledStatsStmt->execute();
$scheduledStats = $scheduledStatsStmt->get_result()->fetch_assoc();

$resaleOpenStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(remaining_coins), 0) AS total_resale_open
    FROM auction_lots
    WHERE tenant_id = ?
    AND status = 'open'
    AND remaining_coins > 0
    AND source_claim_id IS NOT NULL
");
$resaleOpenStatsStmt->bind_param("i", $tenant_id);
$resaleOpenStatsStmt->execute();
$resaleOpenStats = $resaleOpenStatsStmt->get_result()->fetch_assoc();

$boughtStatsStmt = $conn->prepare("
    SELECT COALESCE(SUM(total_due_coins), 0) AS total_bought_coins
    FROM auction_claims
    WHERE tenant_id = ?
    AND status IN ('active', 'matured')
    AND COALESCE(resale_status, 'not_listed') = 'not_listed'
");
$boughtStatsStmt->bind_param("i", $tenant_id);
$boughtStatsStmt->execute();
$boughtStats = $boughtStatsStmt->get_result()->fetch_assoc();

$totalAvailable = (float)($stats["total_available"] ?? 0);
$totalLocked = (float)($stats["total_locked"] ?? 0);
$totalOpen = (float)($openStats["total_open"] ?? 0);
$totalScheduled = (float)($scheduledStats["total_scheduled"] ?? 0);
$totalResaleOpen = (float)($resaleOpenStats["total_resale_open"] ?? 0);
$totalBoughtCoins = (float)($boughtStats["total_bought_coins"] ?? 0);

$totalPlatformCoins = $totalAvailable + $totalLocked + $totalBoughtCoins + $totalScheduled + $totalResaleOpen;
$totalNextSlotCoins = $totalAvailable + $totalScheduled;

$lotsStmt = $conn->prepare("
    SELECT 
        auction_lots.*,
        packages.package_name,
        users.first_name,
        users.last_name,
        users.username,
        users.member_code
    FROM auction_lots
    LEFT JOIN packages ON packages.id = auction_lots.package_id
    INNER JOIN users ON users.id = auction_lots.seller_user_id
    WHERE auction_lots.tenant_id = ?
    ORDER BY auction_lots.created_at DESC
    LIMIT 50
");
$lotsStmt->bind_param("i", $tenant_id);
$lotsStmt->execute();
$lots = $lotsStmt->get_result();

$returnPercent = (float)($packageRules["return_rate_percent"] ?? 0);
$maturityDays = (int)($packageRules["maturity_days"] ?? 0);
$packageName = $packageRules["package_name"] ?? "Auction Package";
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

        .auction-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.30);
            font-weight: 800;
            font-size: 13px;
        }

        .status-dot-live {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #29d17d;
        }

        .status-dot-closed {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #d8a928;
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
                        <h2 class="mb-2">Auction Coins</h2>
                        <p class="mb-2">
                            Add coins to members, then open or close the auction for all members.
                        </p>
                        <div class="auction-status-pill">
                            <span class="<?php echo $auctionStatus === 'open' ? 'status-dot-live' : 'status-dot-closed'; ?>"></span>
                            Auction is <?php echo strtoupper(htmlspecialchars($auctionStatus)); ?>
                        </div>
                    </div>

                    <div class="text-end">
                        <div><strong><?php echo htmlspecialchars($packageName); ?></strong></div>
                        <div>
                            <?php echo number_format($returnPercent, 2); ?>%
                            in
                            <?php echo $maturityDays; ?>
                            days
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isAuctionPackage): ?>
                <div class="alert alert-warning">
                    This tenant is currently not using an auction package. Auction controls are disabled.
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Members</div>
                        <div class="stat-value"><?php echo number_format((int)($stats["total_members"] ?? 0)); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Total Coins</div>
                        <div class="stat-value"><?php echo coins($totalPlatformCoins); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Next Slot Coins</div>
                        <div class="stat-value"><?php echo coins($totalNextSlotCoins); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-label">Live Auction Coins</div>
                        <div class="stat-value"><?php echo coins($totalOpen); ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="card-box h-100">
                        <h5 class="quick-card-title">Add Coins to Member</h5>
                        <p class="text-muted small">
                            This adds coins immediately to the member and updates the Total Coins card before the auction is opened.
                        </p>

                        <form method="POST">
                            <input type="hidden" name="action" value="credit_member">

                            <div class="mb-3">
                                <label class="form-label">Member</label>
                                <select name="member_id" class="form-select" required>
                                    <option value="">Choose member</option>
                                    <?php while ($m = $memberOptions->fetch_assoc()): ?>
                                        <option value="<?php echo (int)$m["id"]; ?>">
                                            <?php echo htmlspecialchars(memberLabel($m)); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Coins</label>
                                <input type="number" step="0.01" name="amount" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Note</label>
                                <input type="text" name="note" class="form-control" value="Admin coin credit">
                            </div>

                            <button class="btn btn-dark w-100" <?php echo !$isAuctionPackage ? "disabled" : ""; ?>>
                                Add Coins
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card-box h-100">
                        <h5 class="quick-card-title">Auction Control</h5>
                        <p class="text-muted small">
                            Open auction moves available member coins into auction. Close auction returns only the remaining unsold coins.
                        </p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <form method="POST" onsubmit="return confirm('Open auction for all members and move all available coins into auction?');">
                                    <input type="hidden" name="action" value="open_auction">
                                    <button class="btn btn-dark w-100 py-3" <?php echo !$isAuctionPackage ? "disabled" : ""; ?>>
                                        Open Auction
                                    </button>
                                </form>
                            </div>

                            <div class="col-md-6">
                                <form method="POST" onsubmit="return confirm('Close auction and return only remaining unsold coins?');">
                                    <input type="hidden" name="action" value="close_auction">
                                    <button class="btn btn-outline-dark w-100 py-3" <?php echo !$isAuctionPackage ? "disabled" : ""; ?>>
                                        Close Auction
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="alert alert-info mt-4 mb-0">
                            Current package return:
                            <strong><?php echo number_format($returnPercent, 2); ?>%</strong>
                            in
                            <strong><?php echo $maturityDays; ?> days</strong>.
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card-box">
                        <h5 class="quick-card-title mb-3">Member Coin Totals</h5>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Total Coins</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($members->num_rows > 0): ?>
                                        <?php while ($m = $members->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(memberLabel($m)); ?></strong>
                                                </td>

                                                <td>
                                                    <strong><?php echo coins($m["total_user_coins"]); ?></strong>
                                                </td>

                                                <td>
                                                    <?php echo htmlspecialchars(ucfirst($m["status"])); ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">
                                                No members yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-light border mt-3 mb-0">
                            This total combines the member’s available coins, live auction remaining coins, and scheduled next-slot coins.
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card-box">
                        <h5 class="quick-card-title mb-3">Auction Lots</h5>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Owner</th>
                                        <th>Opened</th>
                                        <th>Remaining</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($lots->num_rows > 0): ?>
                                        <?php while ($lot = $lots->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(memberLabel($lot)); ?></strong><br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($lot["package_name"] ?? $packageName); ?>
                                                    </small>
                                                </td>

                                                <td><?php echo coins($lot["coin_amount"]); ?></td>

                                                <td>
                                                    <strong><?php echo coins($lot["remaining_coins"]); ?></strong>
                                                </td>

                                                <td><?php echo auctionBadge($lot["status"]); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                No auction lots yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>