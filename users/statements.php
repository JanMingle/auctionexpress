<?php
session_start();
require_once "../config/db.php";

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
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";

$displayName = $username ?: ($member_code ?: $name);

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function statementStatusBadge($status) {
    if (in_array($status, ["pending", "pending_payment"], true)) {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "approved") {
        return '<span class="badge badge-approved">Active</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Closed</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    if ($status === "paid") {
        return '<span class="badge badge-approved">Paid</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_requests,

        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment', 'payment_submitted', 'approved', 'withdrawn')
            THEN amount ELSE 0 
        END) AS total_saved_submitted,

        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment', 'payment_submitted')
            THEN amount ELSE 0 
        END) AS pending_amount,

        SUM(CASE 
            WHEN status = 'approved'
            THEN expected_total_amount ELSE 0 
        END) AS active_balance,

        SUM(CASE 
            WHEN status = 'approved'
            THEN expected_return_amount ELSE 0 
        END) AS active_returns,

        SUM(CASE 
            WHEN status = 'withdrawn'
            THEN expected_total_amount ELSE 0 
        END) AS withdrawn_total
    FROM savings_requests
    WHERE tenant_id = ?
    AND user_id = ?
");
$summaryStmt->bind_param("ii", $tenant_id, $user_id);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();

$total_requests = (int)($summary["total_requests"] ?? 0);
$total_saved_submitted = (float)($summary["total_saved_submitted"] ?? 0);
$pending_amount = (float)($summary["pending_amount"] ?? 0);
$active_balance = (float)($summary["active_balance"] ?? 0);
$active_returns = (float)($summary["active_returns"] ?? 0);
$withdrawn_total = (float)($summary["withdrawn_total"] ?? 0);

$savingsStmt = $conn->prepare("
    SELECT
        id,
        amount,
        return_rate_percent,
        maturity_days,
        expected_return_amount,
        expected_total_amount,
        note,
        proof_of_payment_path,
        payment_note,
        payment_submitted_at,
        status,
        created_at,
        approved_at,
        matures_at,
        withdrawn_at
    FROM savings_requests
    WHERE tenant_id = ?
    AND user_id = ?
");
$savingsStmt->bind_param("ii", $tenant_id, $user_id);
$savingsStmt->execute();
$savingsResult = $savingsStmt->get_result();

$transactions = [];

while ($row = $savingsResult->fetch_assoc()) {
    $ref = "SAV-" . str_pad($row["id"], 5, "0", STR_PAD_LEFT);

    if (!empty($row["created_at"])) {
        $transactions[] = [
            "date" => $row["created_at"],
            "type" => "Saving Request Created",
            "reference" => $ref,
            "saved" => (float)$row["amount"],
            "return" => 0,
            "withdrawal" => 0,
            "status" => $row["status"],
            "description" => "Saving request submitted."
        ];
    }

    if (!empty($row["payment_submitted_at"])) {
        $transactions[] = [
            "date" => $row["payment_submitted_at"],
            "type" => "Proof of Payment Uploaded",
            "reference" => $ref,
            "saved" => (float)$row["amount"],
            "return" => 0,
            "withdrawal" => 0,
            "status" => "payment_submitted",
            "description" => "Proof of payment submitted for admin verification."
        ];
    }

    if (!empty($row["approved_at"])) {
        $transactions[] = [
            "date" => $row["approved_at"],
            "type" => "Saving Approved",
            "reference" => $ref,
            "saved" => (float)$row["amount"],
            "return" => (float)$row["expected_return_amount"],
            "withdrawal" => 0,
            "status" => "approved",
            "description" => "Saving approved. Maturity countdown started."
        ];
    }

    if (!empty($row["matures_at"])) {
        $matureStatus = strtotime($row["matures_at"]) <= time() ? "approved" : "pending_payment";

        $transactions[] = [
            "date" => $row["matures_at"],
            "type" => strtotime($row["matures_at"]) <= time() ? "Saving Matured" : "Maturity Scheduled",
            "reference" => $ref,
            "saved" => 0,
            "return" => (float)$row["expected_return_amount"],
            "withdrawal" => 0,
            "status" => $matureStatus,
            "description" => strtotime($row["matures_at"]) <= time()
                ? "Return matured and became ready for withdrawal."
                : "Saving is still waiting for maturity date."
        ];
    }

    if (!empty($row["withdrawn_at"])) {
        $transactions[] = [
            "date" => $row["withdrawn_at"],
            "type" => "Saving Closed",
            "reference" => $ref,
            "saved" => 0,
            "return" => 0,
            "withdrawal" => (float)$row["expected_total_amount"],
            "status" => "withdrawn",
            "description" => "Saving completed full cycle and was closed."
        ];
    }
}

$withdrawalStmt = $conn->prepare("
    SELECT
        id,
        savings_request_id,
        amount_saved,
        return_amount,
        withdrawal_amount,
        status,
        member_note,
        admin_note,
        requested_at,
        processed_at,
        paid_at
    FROM withdrawal_requests
    WHERE tenant_id = ?
    AND user_id = ?
");
$withdrawalStmt->bind_param("ii", $tenant_id, $user_id);
$withdrawalStmt->execute();
$withdrawalResult = $withdrawalStmt->get_result();

while ($row = $withdrawalResult->fetch_assoc()) {
    $ref = "WDR-" . str_pad($row["id"], 5, "0", STR_PAD_LEFT);

    if (!empty($row["requested_at"])) {
        $transactions[] = [
            "date" => $row["requested_at"],
            "type" => "Withdrawal Requested",
            "reference" => $ref,
            "saved" => 0,
            "return" => 0,
            "withdrawal" => (float)$row["withdrawal_amount"],
            "status" => "pending",
            "description" => "Withdrawal request submitted."
        ];
    }

    if (!empty($row["processed_at"])) {
        $transactions[] = [
            "date" => $row["processed_at"],
            "type" => $row["status"] === "rejected" ? "Withdrawal Rejected" : "Withdrawal Approved",
            "reference" => $ref,
            "saved" => 0,
            "return" => 0,
            "withdrawal" => (float)$row["withdrawal_amount"],
            "status" => $row["status"],
            "description" => $row["admin_note"] ?: "Withdrawal processed by admin."
        ];
    }

    if (!empty($row["paid_at"])) {
        $transactions[] = [
            "date" => $row["paid_at"],
            "type" => "Withdrawal Paid",
            "reference" => $ref,
            "saved" => 0,
            "return" => 0,
            "withdrawal" => (float)$row["withdrawal_amount"],
            "status" => "paid",
            "description" => "Withdrawal marked as paid."
        ];
    }
}

usort($transactions, function ($a, $b) {
    return strtotime($b["date"]) <=> strtotime($a["date"]);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Statement</title>
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

        .statement-hero {
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

        .statement-hero::after {
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

        .statement-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .statement-kicker {
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

        .statement-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .statement-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .statement-hero-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 680px;
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

        .statement-card {
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
            margin-bottom: 0;
        }

        .reference-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.35);
            color: #7a5a09;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .transaction-name {
            font-weight: 900;
            color: #10241f;
        }

        .description-text {
            color: #667085;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .statement-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .statement-hero-title {
                font-size: 27px;
            }

            .statement-hero::after {
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
                <div class="app-topbar-title">My Statement</div>
                <div class="app-topbar-subtitle">
                    Full history of your savings, returns, maturity, and withdrawals.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="statement-hero">
                <div class="statement-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="statement-hero-title">
                    Your money story, clearly tracked
                </div>

                <p class="statement-hero-text">
                    Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    This statement shows every saving request, proof upload, approval,
                    maturity event, withdrawal request, and payout in your stokvel journey.
                </p>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-label">Total Saved Submitted</div>
                        <div class="stat-value"><?php echo money($total_saved_submitted); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Pending Amount</div>
                        <div class="stat-value"><?php echo money($pending_amount); ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Active Balance</div>
                        <div class="stat-value"><?php echo money($active_balance); ?></div>
                        <div class="text-muted" style="font-size: 13px; position: relative; z-index: 2;">
                            Returns: <?php echo money($active_returns); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Withdrawn / Paid Out</div>
                        <div class="stat-value"><?php echo money($withdrawn_total); ?></div>
                    </div>
                </div>
            </div>

            <div class="card-box statement-card">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Statement History</div>
                        <p class="section-subtitle">
                            Latest activity appears first.
                        </p>
                    </div>

                    <span class="reference-pill">
                        <?php echo $total_requests; ?> saving request<?php echo $total_requests === 1 ? "" : "s"; ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction</th>
                                <th>Reference</th>
                                <th>Saved</th>
                                <th>Return</th>
                                <th>Withdrawal</th>
                                <th>Status</th>
                                <th>Description</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($transactions)): ?>
                                <?php foreach ($transactions as $item): ?>
                                    <tr>
                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($item["date"])); ?>
                                        </td>

                                        <td>
                                            <div class="transaction-name">
                                                <?php echo htmlspecialchars($item["type"]); ?>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="reference-pill">
                                                <?php echo htmlspecialchars($item["reference"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo $item["saved"] > 0 ? money($item["saved"]) : "-"; ?>
                                        </td>

                                        <td>
                                            <?php echo $item["return"] > 0 ? money($item["return"]) : "-"; ?>
                                        </td>

                                        <td>
                                            <?php echo $item["withdrawal"] > 0 ? money($item["withdrawal"]) : "-"; ?>
                                        </td>

                                        <td>
                                            <?php echo statementStatusBadge($item["status"]); ?>
                                        </td>

                                        <td>
                                            <span class="description-text">
                                                <?php echo htmlspecialchars($item["description"]); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No statement activity yet.
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