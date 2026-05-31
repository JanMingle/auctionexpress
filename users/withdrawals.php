<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "member") {
    header("Location: ../admin/dashboard.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$tenant_id = (int)$_SESSION["tenant_id"];
$name = $_SESSION["name"] ?? "Member";
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";
$displayName = $username ?: ($member_code ?: $name);

$success = "";
$error = "";

/* ===============================
   INTERNAL RULES
   These come from the tenant package but are not shown as "package" to members.
   =============================== */
$rules = getTenantPackageRules($conn, $tenant_id);

$maturity_days = (int)($rules["maturity_days"] ?? 30);
$withdraw_after_days = (int)($rules["withdraw_after_days"] ?? $maturity_days);
$return_calculation_type = $rules["return_calculation_type"] ?? "once_off";

/* ===============================
   HELPERS
   =============================== */
function withdrawalBadge($status) {
    if ($status === "pending") {
        return '<span class="badge badge-pending">Pending</span>';
    }

    if ($status === "approved") {
        return '<span class="badge badge-approved">Approved</span>';
    }

    if ($status === "paid") {
        return '<span class="badge badge-approved">Paid</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}

function savingBadge($status) {
    if ($status === "approved") {
        return '<span class="badge badge-approved">Approved</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawal Requested</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "pending" || $status === "pending_payment") {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function requestCode($member_code, $username, $request_id) {
    $baseCode = $member_code ?: ($username ?: "MEMBER");
    return $baseCode . "-SAV" . str_pad((int)$request_id, 5, "0", STR_PAD_LEFT);
}

function withdrawalRemainingDays($approved_at, $withdraw_after_days) {
    $elapsedDays = approvedElapsedDays($approved_at);
    $remaining = (int)$withdraw_after_days - (int)$elapsedDays;

    return max(0, $remaining);
}

function calculateCurrentWithdrawalAmount($saving, $rules) {
    $amount = (float)($saving["amount"] ?? 0);
    $type = $rules["return_calculation_type"] ?? "once_off";

    if ($type === "daily_simple" || $type === "daily_compound") {
        $elapsedDays = approvedElapsedDays($saving["approved_at"] ?? null);
        $liveReturn = calculatePackageReturn($amount, $rules, $elapsedDays);

        return [
            "amount_saved" => $amount,
            "return_amount" => (float)$liveReturn["return_amount"],
            "withdrawal_amount" => (float)$liveReturn["total_amount"],
            "days_used" => (int)$liveReturn["days_used"]
        ];
    }

    return [
        "amount_saved" => $amount,
        "return_amount" => (float)($saving["expected_return_amount"] ?? 0),
        "withdrawal_amount" => (float)($saving["expected_total_amount"] ?? 0),
        "days_used" => (int)($rules["maturity_days"] ?? 0)
    ];
}

/* ===============================
   FORM ACTION
   =============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $savings_request_id = (int)($_POST["savings_request_id"] ?? 0);
    $member_note = trim($_POST["member_note"] ?? "");

    if ($savings_request_id <= 0) {
        $error = "Invalid saving selected.";
    } else {
        $checkStmt = $conn->prepare("
            SELECT 
                id,
                amount,
                expected_return_amount,
                expected_total_amount,
                status,
                approved_at,
                matures_at
            FROM savings_requests
            WHERE id = ?
            AND tenant_id = ?
            AND user_id = ?
            AND status = 'approved'
            AND approved_at IS NOT NULL
            LIMIT 1
        ");
        $checkStmt->bind_param("iii", $savings_request_id, $tenant_id, $user_id);
        $checkStmt->execute();
        $saving = $checkStmt->get_result()->fetch_assoc();

        if (!$saving) {
            $error = "This saving is not ready for withdrawal yet.";
        } elseif (!canWithdrawByPackage($saving["approved_at"] ?? null, $rules)) {
            $remaining = withdrawalRemainingDays($saving["approved_at"] ?? null, $withdraw_after_days);
            $error = "This saving is not ready for withdrawal yet. Please wait " . $remaining . " more day" . ($remaining === 1 ? "" : "s") . ".";
        } else {
            $duplicateStmt = $conn->prepare("
                SELECT id, status
                FROM withdrawal_requests
                WHERE tenant_id = ?
                AND user_id = ?
                AND savings_request_id = ?
                AND status IN ('pending', 'approved', 'paid')
                LIMIT 1
            ");
            $duplicateStmt->bind_param("iii", $tenant_id, $user_id, $savings_request_id);
            $duplicateStmt->execute();
            $existingWithdrawal = $duplicateStmt->get_result()->fetch_assoc();

            if ($existingWithdrawal) {
                $error = "A withdrawal request already exists for this saving.";
            } else {
                $withdrawalData = calculateCurrentWithdrawalAmount($saving, $rules);

                $amount_saved = (float)$withdrawalData["amount_saved"];
                $return_amount = (float)$withdrawalData["return_amount"];
                $withdrawal_amount = (float)$withdrawalData["withdrawal_amount"];

                if ($withdrawal_amount <= 0) {
                    $error = "Could not calculate withdrawal amount.";
                } else {
                    $conn->begin_transaction();

                    try {
                        $insertStmt = $conn->prepare("
                            INSERT INTO withdrawal_requests
                            (
                                tenant_id,
                                user_id,
                                savings_request_id,
                                amount_saved,
                                return_amount,
                                withdrawal_amount,
                                member_note,
                                status
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");

                        $insertStmt->bind_param(
                            "iiiddds",
                            $tenant_id,
                            $user_id,
                            $savings_request_id,
                            $amount_saved,
                            $return_amount,
                            $withdrawal_amount,
                            $member_note
                        );

                        if (!$insertStmt->execute()) {
                            throw new Exception("Could not submit your withdrawal request. Please try again.");
                        }

                        $updateStmt = $conn->prepare("
                            UPDATE savings_requests
                            SET 
                                status = 'withdrawn',
                                withdrawn_at = NOW()
                            WHERE id = ?
                            AND tenant_id = ?
                            AND user_id = ?
                        ");
                        $updateStmt->bind_param("iii", $savings_request_id, $tenant_id, $user_id);

                        if (!$updateStmt->execute()) {
                            throw new Exception("Withdrawal request was created, but saving cycle could not be closed.");
                        }

                        $conn->commit();

                        $success = "Your withdrawal request has been submitted successfully.";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}

/* ===============================
   SAVINGS AVAILABLE OR WAITING
   =============================== */
$eligibleStmt = $conn->prepare("
    SELECT 
        sr.id,
        sr.amount,
        sr.expected_return_amount,
        sr.expected_total_amount,
        sr.created_at,
        sr.approved_at,
        sr.matures_at
    FROM savings_requests sr
    WHERE sr.tenant_id = ?
    AND sr.user_id = ?
    AND sr.status = 'approved'
    AND sr.approved_at IS NOT NULL
    AND NOT EXISTS (
        SELECT 1
        FROM withdrawal_requests wr
        WHERE wr.savings_request_id = sr.id
        AND wr.tenant_id = sr.tenant_id
        AND wr.user_id = sr.user_id
        AND wr.status IN ('pending', 'approved', 'paid')
    )
    ORDER BY sr.approved_at ASC, sr.created_at ASC
");
$eligibleStmt->bind_param("ii", $tenant_id, $user_id);
$eligibleStmt->execute();
$eligibleSavings = $eligibleStmt->get_result();

$eligibleRows = [];
$totalReadyAmount = 0;
$readyCount = 0;
$waitingCount = 0;

while ($saving = $eligibleSavings->fetch_assoc()) {
    $withdrawalData = calculateCurrentWithdrawalAmount($saving, $rules);
    $canWithdraw = canWithdrawByPackage($saving["approved_at"] ?? null, $rules);
    $remainingDays = withdrawalRemainingDays($saving["approved_at"] ?? null, $withdraw_after_days);
    $elapsedDays = approvedElapsedDays($saving["approved_at"] ?? null);

    if ($canWithdraw) {
        $readyCount++;
        $totalReadyAmount += (float)$withdrawalData["withdrawal_amount"];
    } else {
        $waitingCount++;
    }

    $eligibleRows[] = [
        "saving" => $saving,
        "withdrawalData" => $withdrawalData,
        "canWithdraw" => $canWithdraw,
        "remainingDays" => $remainingDays,
        "elapsedDays" => $elapsedDays
    ];
}

/* ===============================
   WITHDRAWAL HISTORY
   =============================== */
$historyStmt = $conn->prepare("
    SELECT 
        wr.id,
        wr.savings_request_id,
        wr.amount_saved,
        wr.return_amount,
        wr.withdrawal_amount,
        wr.status,
        wr.member_note,
        wr.admin_note,
        wr.requested_at,
        wr.processed_at,
        wr.paid_at,
        sr.matures_at,
        sr.approved_at AS saving_approved_at
    FROM withdrawal_requests wr
    INNER JOIN savings_requests sr ON sr.id = wr.savings_request_id
    WHERE wr.tenant_id = ?
    AND wr.user_id = ?
    ORDER BY wr.requested_at DESC
");
$historyStmt->bind_param("ii", $tenant_id, $user_id);
$historyStmt->execute();
$withdrawalHistory = $historyStmt->get_result();

/* ===============================
   STATS
   =============================== */
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_withdrawals,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_withdrawals,
        SUM(CASE WHEN status = 'approved' THEN withdrawal_amount ELSE 0 END) AS approved_amount,
        SUM(CASE WHEN status = 'paid' THEN withdrawal_amount ELSE 0 END) AS paid_amount
    FROM withdrawal_requests
    WHERE tenant_id = ?
    AND user_id = ?
");
$statsStmt->bind_param("ii", $tenant_id, $user_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_withdrawals = (int)($stats["total_withdrawals"] ?? 0);
$pending_withdrawals = (int)($stats["pending_withdrawals"] ?? 0);
$approved_amount = (float)($stats["approved_amount"] ?? 0);
$paid_amount = (float)($stats["paid_amount"] ?? 0);

/* ===============================
   SAVING CYCLE HISTORY
   =============================== */
$savingsHistoryStmt = $conn->prepare("
    SELECT
        id,
        amount,
        expected_return_amount,
        expected_total_amount,
        status,
        created_at,
        approved_at,
        matures_at,
        withdrawn_at
    FROM savings_requests
    WHERE tenant_id = ?
    AND user_id = ?
    ORDER BY created_at DESC
");
$savingsHistoryStmt->bind_param("ii", $tenant_id, $user_id);
$savingsHistoryStmt->execute();
$savingsHistory = $savingsHistoryStmt->get_result();

$preselectedSavingId = (int)($_GET["saving_id"] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Withdrawals</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <style>
        body {
            background:
                radial-gradient(circle at 8% 10%, rgba(216, 169, 40, 0.34), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(15, 107, 79, 0.28), transparent 32%),
                radial-gradient(circle at 50% 90%, rgba(216, 169, 40, 0.20), transparent 34%),
                linear-gradient(135deg, #fff4c7 0%, #fbf7ed 32%, #e7f7ef 72%, #dff5e9 100%) !important;
            background-attachment: fixed;
        }

        .app-main {
            background:
                radial-gradient(circle at 20% 15%, rgba(216,169,40,0.13), transparent 30%),
                radial-gradient(circle at 88% 30%, rgba(15,107,79,0.10), transparent 34%);
        }

        .app-content {
            position: relative;
        }

        .app-content::before {
            content: "R";
            position: fixed;
            right: 40px;
            bottom: 34px;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: linear-gradient(145deg, rgba(248,216,106,0.45), rgba(216,169,40,0.24));
            color: rgba(74,53,4,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 82px;
            font-weight: 900;
            transform: rotate(-14deg);
            pointer-events: none;
            z-index: 0;
        }

        .app-content > * {
            position: relative;
            z-index: 1;
        }

        .withdrawals-hero {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.34), transparent 34%),
                radial-gradient(circle at bottom left, rgba(255,255,255,0.12), transparent 32%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            border-radius: 32px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 30px 80px rgba(7, 63, 47, 0.32);
            position: relative;
            overflow: hidden;
        }

        .withdrawals-hero::after {
            content: "R";
            position: absolute;
            right: 34px;
            top: 24px;
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: #4a3504;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 900;
            opacity: 0.25;
            transform: rotate(-12deg);
        }

        .withdrawals-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .withdrawals-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            color: rgba(255,255,255,0.86);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 16px;
            position: relative;
            z-index: 2;
        }

        .withdrawals-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .withdrawals-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .withdrawals-hero-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 660px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .stat-card {
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 50px rgba(16,36,31,0.14) !important;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
            right: -60px;
            bottom: -70px;
            border-radius: 50%;
            opacity: 0.38;
        }

        .stat-card::after {
            content: "R";
            position: absolute;
            right: 18px;
            top: 12px;
            font-size: 38px;
            font-weight: 900;
            opacity: 0.08;
        }

        .stat-card .stat-label,
        .stat-card .stat-value {
            position: relative;
            z-index: 2;
        }

        .stat-card-green {
            background: linear-gradient(135deg, #ffffff 0%, #d8f5e5 100%) !important;
        }

        .stat-card-green::before {
            background: #0f6b4f;
        }

        .stat-card-green .stat-value {
            color: #073f2f !important;
        }

        .stat-card-gold {
            background: linear-gradient(135deg, #ffffff 0%, #ffed9f 100%) !important;
        }

        .stat-card-gold::before {
            background: #d8a928;
        }

        .stat-card-gold .stat-value {
            color: #7a5a09 !important;
        }

        .stat-card-blue {
            background: linear-gradient(135deg, #ffffff 0%, #dbeafe 100%) !important;
        }

        .stat-card-blue::before {
            background: #2563eb;
        }

        .stat-card-blue .stat-value {
            color: #1e3a8a !important;
        }

        .stat-card-red {
            background: linear-gradient(135deg, #ffffff 0%, #ffd6d6 100%) !important;
        }

        .stat-card-red::before {
            background: #dc2626;
        }

        .stat-card-red .stat-value {
            color: #991b1b !important;
        }

        .withdrawal-ready-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .withdrawal-history-card {
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.22), transparent 34%),
                radial-gradient(circle at bottom right, rgba(15,107,79,0.18), transparent 36%),
                linear-gradient(135deg, #ffffff 0%, #e7f7ef 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .section-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
            margin-bottom: 6px;
        }

        .section-subtitle {
            font-size: 13px;
            color: #667085;
            margin-bottom: 18px;
        }

        .ready-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            background: #fff8df;
            color: #7a5a09;
            border: 1px solid rgba(216,169,40,0.28);
            font-size: 12px;
            font-weight: 900;
        }

        .ready-pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #0f6b4f;
        }

        .reference-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.35);
            color: #7a5a09;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 13px;
            font-weight: 900;
            word-break: break-word;
        }

        .waiting-note {
            font-size: 12px;
            color: #667085;
            margin-top: 4px;
        }

        @media (max-width: 900px) {
            .withdrawals-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .withdrawals-hero-title {
                font-size: 27px;
            }

            .withdrawals-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
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
                <div class="app-topbar-title">My Withdrawals</div>
                <div class="app-topbar-subtitle">
                    Request withdrawal when your saving is ready.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="withdrawals-hero">
                <div class="withdrawals-kicker">
                    Withdrawal Centre
                </div>

                <div class="withdrawals-hero-title">
                    Withdraw matured savings safely
                </div>

                <p class="withdrawals-hero-text">
                    Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>. 
                    Savings will appear here when they are ready to withdraw.
                    Submit a request and your stokvel admin will process the payout.
                </p>
            </div>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-label">Total Requests</div>
                        <div class="stat-value"><?php echo $total_withdrawals; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Pending</div>
                        <div class="stat-value"><?php echo $pending_withdrawals; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Approved Amount</div>
                        <div class="stat-value">
                            <?php echo money($approved_amount); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Paid Amount</div>
                        <div class="stat-value">
                            <?php echo money($paid_amount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-box withdrawal-ready-card mb-4">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Ready for Withdrawal</div>
                        <div class="section-subtitle">
                            Approved savings will appear here. If a saving is still in the waiting period, it will show as not ready yet.
                        </div>
                    </div>

                    <span class="ready-pill">
                        Matured savings
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Saved</th>
                                <th>Return</th>
                                <th>Total Withdrawal</th>
                                <th>Approved On</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($eligibleRows) > 0): ?>
                                <?php foreach ($eligibleRows as $item): ?>
                                    <?php
                                        $saving = $item["saving"];
                                        $withdrawalData = $item["withdrawalData"];
                                        $canWithdraw = $item["canWithdraw"];
                                        $remainingDays = $item["remainingDays"];
                                        $elapsedDays = $item["elapsedDays"];
                                        $refCode = requestCode($member_code, $username, $saving["id"]);
                                    ?>

                                    <tr>
                                        <td>
                                            <span class="reference-code">
                                                <?php echo htmlspecialchars($refCode); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <strong><?php echo money($withdrawalData["amount_saved"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo money($withdrawalData["return_amount"]); ?>
                                            <div class="waiting-note">
                                                <?php echo (int)$elapsedDays; ?> day<?php echo $elapsedDays === 1 ? "" : "s"; ?> completed
                                            </div>
                                        </td>

                                        <td>
                                            <strong><?php echo money($withdrawalData["withdrawal_amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo !empty($saving["approved_at"]) ? date("d M Y H:i", strtotime($saving["approved_at"])) : "-"; ?>
                                        </td>

                                        <td>
                                            <?php if ($canWithdraw): ?>
                                                <span class="badge badge-approved">Ready</span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">Waiting</span>
                                                <div class="waiting-note">
                                                    <?php echo (int)$remainingDays; ?> day<?php echo $remainingDays === 1 ? "" : "s"; ?> left
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <?php if ($canWithdraw): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="savings_request_id" value="<?php echo (int)$saving["id"]; ?>">

                                                    <textarea 
                                                        name="member_note"
                                                        class="form-control mb-2"
                                                        rows="2"
                                                        placeholder="Optional note"
                                                    ></textarea>

                                                    <button 
                                                        type="submit" 
                                                        class="btn btn-dark btn-sm"
                                                        onclick="return confirm('Submit withdrawal request for <?php echo money($withdrawalData["withdrawal_amount"]); ?>?');"
                                                    >
                                                        Request Withdrawal
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-outline-dark btn-sm" disabled>
                                                    Not Ready
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No savings are available for withdrawal yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-box withdrawal-history-card mb-4">
                <h5 class="section-title">Withdrawal History</h5>
                <p class="section-subtitle">
                    Track your previous withdrawal requests.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Saved</th>
                                <th>Return</th>
                                <th>Withdrawal</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Paid</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($withdrawalHistory->num_rows > 0): ?>
                                <?php while ($row = $withdrawalHistory->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="reference-code">
                                                <?php echo htmlspecialchars(requestCode($member_code, $username, $row["savings_request_id"])); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo money($row["amount_saved"]); ?>
                                        </td>

                                        <td>
                                            <?php echo money($row["return_amount"]); ?>
                                        </td>

                                        <td>
                                            <strong><?php echo money($row["withdrawal_amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo withdrawalBadge($row["status"]); ?>
                                        </td>

                                        <td>
                                            <?php echo !empty($row["requested_at"]) ? date("d M Y H:i", strtotime($row["requested_at"])) : "-"; ?>
                                        </td>

                                        <td>
                                            <?php echo !empty($row["paid_at"]) ? date("d M Y H:i", strtotime($row["paid_at"])) : "-"; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No withdrawal requests yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-box withdrawal-history-card">
                <h5 class="section-title">Saving Cycles</h5>
                <p class="section-subtitle">
                    Your recent saving cycles and their status.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Amount</th>
                                <th>Return</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($savingsHistory->num_rows > 0): ?>
                                <?php while ($row = $savingsHistory->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="reference-code">
                                                <?php echo htmlspecialchars(requestCode($member_code, $username, $row["id"])); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo money($row["amount"]); ?>
                                        </td>

                                        <td>
                                            <?php echo money($row["expected_return_amount"]); ?>
                                        </td>

                                        <td>
                                            <strong><?php echo money($row["expected_total_amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo savingBadge($row["status"]); ?>
                                        </td>

                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No saving cycles yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>

        </div>
    </main>

</div>

</body>
</html>