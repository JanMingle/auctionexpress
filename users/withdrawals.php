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
$displayName = $username ?: ($member_code ?: $name);

$success = "";
$error = "";

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
                matures_at
            FROM savings_requests
            WHERE id = ?
            AND tenant_id = ?
            AND user_id = ?
            AND status = 'approved'
            AND matures_at IS NOT NULL
            AND matures_at <= NOW()
            LIMIT 1
        ");
        $checkStmt->bind_param("iii", $savings_request_id, $tenant_id, $user_id);
        $checkStmt->execute();
        $saving = $checkStmt->get_result()->fetch_assoc();

        if (!$saving) {
            $error = "This saving is not ready for withdrawal yet.";
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
                $amount_saved = (float)$saving["amount"];
                $return_amount = (float)$saving["expected_return_amount"];
                $withdrawal_amount = (float)$saving["expected_total_amount"];

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

                if ($insertStmt->execute()) {
                    $success = "Your withdrawal request has been submitted successfully.";
                } else {
                    $error = "Could not submit your withdrawal request. Please try again.";
                }
            }
        }
    }
}

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
    AND sr.matures_at IS NOT NULL
    AND sr.matures_at <= NOW()
    AND NOT EXISTS (
        SELECT 1
        FROM withdrawal_requests wr
        WHERE wr.savings_request_id = sr.id
        AND wr.tenant_id = sr.tenant_id
        AND wr.user_id = sr.user_id
        AND wr.status IN ('pending', 'approved', 'paid')
    )
    ORDER BY sr.matures_at DESC
");
$eligibleStmt->bind_param("ii", $tenant_id, $user_id);
$eligibleStmt->execute();
$eligibleSavings = $eligibleStmt->get_result();

$historyStmt = $conn->prepare("
    SELECT 
        wr.id,
        wr.amount_saved,
        wr.return_amount,
        wr.withdrawal_amount,
        wr.status,
        wr.member_note,
        wr.admin_note,
        wr.requested_at,
        wr.processed_at,
        wr.paid_at,
        sr.matures_at
    FROM withdrawal_requests wr
    INNER JOIN savings_requests sr ON sr.id = wr.savings_request_id
    WHERE wr.tenant_id = ?
    AND wr.user_id = ?
    ORDER BY wr.requested_at DESC
");
$historyStmt->bind_param("ii", $tenant_id, $user_id);
$historyStmt->execute();
$withdrawalHistory = $historyStmt->get_result();

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

$preselectedSavingId = (int)($_GET["saving_id"] ?? 0);

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

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}
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

        .modal-content {
            border: 0;
            box-shadow: 0 30px 90px rgba(16,36,31,0.25);
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
                    Request withdrawal only after your saving has matured.
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
                    Matured savings will appear here when they are ready to withdraw.
                    Submit a request and your stokvel admin will approve or process the payout.
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
                            These are matured savings that do not already have a withdrawal request.
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
                                <th>Saved</th>
                                <th>Return</th>
                                <th>Total Withdrawal</th>
                                <th>Matured On</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($eligibleSavings->num_rows > 0): ?>
                                <?php while ($saving = $eligibleSavings->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo money($saving["amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo money($saving["expected_return_amount"]); ?>
                                        </td>

                                        <td>
                                            <strong><?php echo money($saving["expected_total_amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($saving["matures_at"])); ?>
                                        </td>

                                        <td class="text-end">
                                            <button 
                                                type="button"
                                                class="btn btn-dark btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#withdrawModal<?php echo (int)$saving["id"]; ?>"
                                            >
                                                Request Withdrawal
                                            </button>

                                            <div 
                                                class="modal fade" 
                                                id="withdrawModal<?php echo (int)$saving["id"]; ?>" 
                                                tabindex="-1"
                                                aria-hidden="true"
                                            >
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content" style="border-radius: 22px;">
                                                        <form method="POST">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Request Withdrawal</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>

                                                            <div class="modal-body text-start">
                                                                <input 
                                                                    type="hidden" 
                                                                    name="savings_request_id" 
                                                                    value="<?php echo (int)$saving["id"]; ?>"
                                                                >

                                                                <div class="alert alert-info">
                                                                    You are requesting to withdraw 
                                                                    <strong><?php echo money($saving["expected_total_amount"]); ?></strong>.
                                                                </div>

                                                                <div class="mb-3">
                                                                    <label class="form-label">Note to Admin</label>
                                                                    <textarea 
                                                                        name="member_note" 
                                                                        class="form-control" 
                                                                        rows="3"
                                                                        placeholder="Optional note"
                                                                    ></textarea>
                                                                </div>
                                                            </div>

                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                                    Cancel
                                                                </button>

                                                                <button type="submit" class="btn btn-dark">
                                                                    Submit Request
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No matured savings are ready for withdrawal yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-box withdrawal-history-card">
                <div class="mb-3">
                    <div class="section-title">Withdrawal History</div>
                    <div class="section-subtitle">
                        Track every withdrawal request, approval, rejection, and payment.
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Saved</th>
                                <th>Return</th>
                                <th>Withdrawal</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Admin Note</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($withdrawalHistory->num_rows > 0): ?>
                                <?php while ($row = $withdrawalHistory->fetch_assoc()): ?>
                                    <tr>
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
                                            <?php echo date("d M Y H:i", strtotime($row["requested_at"])); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($row["admin_note"] ?: "-"); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        You have not requested any withdrawals yet.
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($preselectedSavingId > 0): ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("withdrawModal<?php echo $preselectedSavingId; ?>");

    if (modal) {
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
});
</script>
<?php endif; ?>

</body>
</html>