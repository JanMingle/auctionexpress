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

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y H:i", strtotime($dateValue));
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

$current_package_id = (int)($packageRules["package_id"] ?? 0);
$current_package_name = $packageRules["package_name"] ?? "Current Package";
$current_return_percent = (float)($packageRules["return_rate_percent"] ?? 0);
$current_maturity_days = (int)($packageRules["maturity_days"] ?? 30);

if ($current_return_percent < 0) {
    $current_return_percent = 0;
}

if ($current_maturity_days <= 0) {
    $current_maturity_days = 30;
}

/*
    Auto-mark active shares as matured once countdown is done.
*/
$matureStmt = $conn->prepare("
    UPDATE auction_claims
    SET status = 'matured'
    WHERE tenant_id = ?
    AND buyer_user_id = ?
    AND status = 'active'
    AND matures_at IS NOT NULL
    AND matures_at <= NOW()
");
$matureStmt->bind_param("ii", $tenant_id, $user_id);
$matureStmt->execute();

/*
    Sell matured shares.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $claim_id = (int)($_POST["claim_id"] ?? 0);

    if ($action === "sell_share") {
        if ($claim_id <= 0) {
            $error = "Invalid share selected.";
        } elseif ($current_package_id <= 0) {
            $error = "No package is linked to this tenant.";
        } else {
            try {
                $conn->begin_transaction();

                $claimStmt = $conn->prepare("
                    SELECT 
                        auction_claims.*
                    FROM auction_claims
                    WHERE auction_claims.id = ?
                    AND auction_claims.tenant_id = ?
                    AND auction_claims.buyer_user_id = ?
                    AND auction_claims.status IN ('active', 'matured')
                    AND COALESCE(auction_claims.resale_status, 'not_listed') = 'not_listed'
                    LIMIT 1
                    FOR UPDATE
                ");
                $claimStmt->bind_param("iii", $claim_id, $tenant_id, $user_id);
                $claimStmt->execute();
                $claim = $claimStmt->get_result()->fetch_assoc();

                if (!$claim) {
                    throw new Exception("This share is not available for selling.");
                }

                if (empty($claim["matures_at"]) || strtotime($claim["matures_at"]) > time()) {
                    throw new Exception("This share has not matured yet.");
                }

                $coinsForSale = (float)($claim["total_due_coins"] ?? 0);

                if ($coinsForSale <= 0) {
                    $principal = (float)($claim["principal_coins"] ?? 0);
                    $returnCoins = (float)($claim["return_coins"] ?? 0);
                    $coinsForSale = $principal + $returnCoins;
                }

                if ($coinsForSale <= 0) {
                    throw new Exception("Invalid coins available for sale.");
                }

                /*
                    Put matured shares into auction queue.
                    Status scheduled means it is queued and will become open when admin opens auction.
                */
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
                        source_claim_id,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)
                ");

                $insertLot->bind_param(
                    "iiidddiii",
                    $tenant_id,
                    $current_package_id,
                    $user_id,
                    $coinsForSale,
                    $coinsForSale,
                    $current_return_percent,
                    $current_maturity_days,
                    $claim_id,
                    $user_id
                );
                $insertLot->execute();

                $lot_id = (int)$conn->insert_id;

                $updateClaim = $conn->prepare("
                    UPDATE auction_claims
                    SET resale_status = 'listed',
                        listed_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                    AND buyer_user_id = ?
                ");
                $updateClaim->bind_param("iii", $claim_id, $tenant_id, $user_id);
                $updateClaim->execute();

                $conn->commit();

                $success = "Your shares have been queued for the next auction.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM auction_claims
    WHERE tenant_id = ?
    AND buyer_user_id = ?
    AND status IN ('active', 'matured')
");
$countStmt->bind_param("ii", $tenant_id, $user_id);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();

$totalRows = (int)($countRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $conn->prepare("
    SELECT
        auction_claims.*,

        seller.username AS seller_username,
        seller.member_code AS seller_member_code,
        seller.first_name AS seller_first_name,
        seller.last_name AS seller_last_name,
        seller.email AS seller_email,
        seller.phone AS seller_phone
    FROM auction_claims
    INNER JOIN users seller ON seller.id = auction_claims.seller_user_id
    WHERE auction_claims.tenant_id = ?
    AND auction_claims.buyer_user_id = ?
    AND auction_claims.status IN ('active', 'matured')
    ORDER BY auction_claims.matures_at ASC, auction_claims.id DESC
    LIMIT ?
    OFFSET ?
");
$stmt->bind_param("iiii", $tenant_id, $user_id, $perPage, $offset);
$stmt->execute();
$shares = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sell Shares</title>
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

        .share-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .share-card {
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

        .countdown-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            font-weight: 800;
            font-size: 13px;
        }

        @media (max-width: 992px) {
            .share-grid {
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
                <div class="topbar-title">Sell Shares</div>
                <div class="topbar-subtitle"><?php echo htmlspecialchars($stokvel_name); ?></div>
            </div>

            <div class="topbar-user">
                <?php echo htmlspecialchars($displayName); ?>
            </div>
        </div>

        <div class="app-content">
            <div class="auction-hero">
                <h2 class="mb-2">Sell Shares</h2>
                <p class="mb-1">
                    View your approved coin purchases, track countdowns, and sell matured shares into the next auction queue.
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

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0 fw-bold">My Approved Shares</h5>

                <a href="auction_history.php" class="btn btn-outline-dark btn-sm">
                    View Auction History
                </a>
            </div>

            <?php if ($shares->num_rows > 0): ?>
                <div class="share-grid">
                    <?php while ($s = $shares->fetch_assoc()): ?>
                        <?php
                            $principal = (float)($s["principal_coins"] ?? 0);
                            $returnCoins = (float)($s["return_coins"] ?? 0);
                            $totalDue = (float)($s["total_due_coins"] ?? 0);

                            if ($totalDue <= 0) {
                                $totalDue = $principal + $returnCoins;
                            }

                            $maturesAt = $s["matures_at"] ?? "";
                            $matureTimestamp = $maturesAt ? strtotime($maturesAt) : 0;
                            $secondsLeft = $matureTimestamp > 0 ? max(0, $matureTimestamp - time()) : 0;

                            $resaleStatus = $s["resale_status"] ?? "not_listed";
                            if ($resaleStatus === "") {
                                $resaleStatus = "not_listed";
                            }

                            $canSell = $secondsLeft <= 0 && $resaleStatus === "not_listed";
                        ?>

                        <div class="share-card">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <h6 class="fw-bold mb-1">Approved Share</h6>
                                    <div class="text-muted small">
                                        Bought from <?php echo htmlspecialchars(memberLabel($s, "seller_")); ?>
                                    </div>
                                </div>

                                <?php if ($resaleStatus === "listed"): ?>
                                    <span class="badge bg-info text-dark">Queued</span>
                                <?php elseif ($resaleStatus === "sold"): ?>
                                    <span class="badge bg-success">Sold</span>
                                <?php elseif ($canSell): ?>
                                    <span class="badge bg-success">Ready to Sell</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Counting Down</span>
                                <?php endif; ?>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Bought</div>
                                        <strong><?php echo coins($principal); ?></strong><br>
                                        <span class="text-muted"><?php echo money($principal); ?></span>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Return</div>
                                        <strong><?php echo coins($returnCoins); ?></strong><br>
                                        <span class="text-muted">
                                            <?php echo number_format((float)($s["return_percent"] ?? 0), 2); ?>%
                                        </span>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Sell Value</div>
                                        <strong><?php echo coins($totalDue); ?></strong><br>
                                        <span class="text-muted"><?php echo money($totalDue); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="detail-box mb-3">
                                <div class="detail-label">Countdown</div>

                                <?php if ($resaleStatus === "listed"): ?>
                                    <strong>Queued for next auction</strong><br>
                                    <span class="text-muted">
                                        Listed on <?php echo htmlspecialchars(displayDate($s["listed_at"] ?? "")); ?>
                                    </span>
                                <?php elseif ($resaleStatus === "sold"): ?>
                                    <strong>Already sold</strong><br>
                                    <span class="text-muted">
                                        Sold on <?php echo htmlspecialchars(displayDate($s["sold_at"] ?? "")); ?>
                                    </span>
                                <?php else: ?>
                                    <span 
                                        class="countdown-pill countdown-timer"
                                        data-end="<?php echo $matureTimestamp > 0 ? ($matureTimestamp * 1000) : 0; ?>"
                                    >
                                        Loading countdown...
                                    </span>

                                    <div class="text-muted small mt-2 waiting-message" <?php echo $canSell ? 'style="display:none;"' : ""; ?>>
                                        Sell button will appear when the countdown reaches zero.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($resaleStatus === "not_listed"): ?>
                                <form 
                                    method="POST" 
                                    class="sell-form"
                                    style="<?php echo $canSell ? "" : "display:none;"; ?>"
                                    onsubmit="return confirm('Sell these shares into the next auction queue?');"
                                >
                                    <input type="hidden" name="action" value="sell_share">
                                    <input type="hidden" name="claim_id" value="<?php echo (int)$s["id"]; ?>">

                                    <button class="btn btn-dark w-100">
                                        Sell Shares
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="card-box text-center py-5">
                    <h5 class="fw-bold mb-2">No approved shares yet</h5>
                    <p class="text-muted mb-3">
                        Once a seller approves your coin purchase, your countdown will appear here.
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

<script>
function formatCountdown(ms) {
    if (ms <= 0) {
        return "Ready to sell";
    }

    var seconds = Math.floor(ms / 1000);
    var days = Math.floor(seconds / 86400);
    seconds = seconds % 86400;

    var hours = Math.floor(seconds / 3600);
    seconds = seconds % 3600;

    var minutes = Math.floor(seconds / 60);
    seconds = seconds % 60;

    return days + "d " + hours + "h " + minutes + "m " + seconds + "s";
}

function updateCountdowns() {
    document.querySelectorAll(".countdown-timer").forEach(function (el) {
        var end = parseInt(el.getAttribute("data-end"), 10);

        if (!end) {
            el.textContent = "No maturity date";
            return;
        }

        var diff = end - Date.now();

        el.textContent = formatCountdown(diff);

        if (diff <= 0) {
            var card = el.closest(".share-card");

            if (card) {
                var form = card.querySelector(".sell-form");
                var waiting = card.querySelector(".waiting-message");

                if (form) {
                    form.style.display = "block";
                }

                if (waiting) {
                    waiting.style.display = "none";
                }
            }
        }
    });
}

updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>
</body>
</html>