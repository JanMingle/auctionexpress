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

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $withdrawal_id = (int)($_POST["withdrawal_id"] ?? 0);
    $action = $_POST["action"] ?? "";
    $admin_note = trim($_POST["admin_note"] ?? "");

    if ($withdrawal_id <= 0) {
        $error = "Invalid withdrawal request selected.";
    } elseif (!in_array($action, ["approve", "reject", "mark_paid"], true)) {
        $error = "Invalid action selected.";
    } else {
        if ($action === "approve") {
            $stmt = $conn->prepare("
                UPDATE withdrawal_requests
                SET status = 'approved',
                    admin_note = ?,
                    processed_at = NOW()
                WHERE id = ?
                AND tenant_id = ?
                AND status = 'pending'
            ");
            $stmt->bind_param("sii", $admin_note, $withdrawal_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Withdrawal request approved.";
            } else {
                $error = "Could not approve withdrawal request.";
            }
        }

        if ($action === "reject") {
            $stmt = $conn->prepare("
                UPDATE withdrawal_requests
                SET status = 'rejected',
                    admin_note = ?,
                    processed_at = NOW()
                WHERE id = ?
                AND tenant_id = ?
                AND status = 'pending'
            ");
            $stmt->bind_param("sii", $admin_note, $withdrawal_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Withdrawal request rejected.";
            } else {
                $error = "Could not reject withdrawal request.";
            }
        }

        if ($action === "mark_paid") {
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("
                    UPDATE withdrawal_requests
                    SET status = 'paid',
                        admin_note = ?,
                        paid_at = NOW()
                    WHERE id = ?
                    AND tenant_id = ?
                    AND status = 'approved'
                ");
                $stmt->bind_param("sii", $admin_note, $withdrawal_id, $tenant_id);
                $stmt->execute();

                if ($stmt->affected_rows <= 0) {
                    throw new Exception("Withdrawal could not be marked as paid.");
                }

                $closeSavingStmt = $conn->prepare("
                    UPDATE savings_requests sr
                    INNER JOIN withdrawal_requests wr 
                        ON wr.savings_request_id = sr.id
                    SET 
                        sr.status = 'withdrawn',
                        sr.withdrawn_at = NOW()
                    WHERE wr.id = ?
                    AND wr.tenant_id = ?
                    AND sr.tenant_id = ?
                    AND wr.status = 'paid'
                ");
                $closeSavingStmt->bind_param("iii", $withdrawal_id, $tenant_id, $tenant_id);
                $closeSavingStmt->execute();

                $conn->commit();

                $success = "Withdrawal marked as paid. The saving request is now closed and no longer counts as an active return.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Could not complete payout. Please try again.";
            }
        }
    }
}

$withdrawalsStmt = $conn->prepare("
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
        sr.matures_at,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.username,
        u.member_code
    FROM withdrawal_requests wr
    INNER JOIN users u ON u.id = wr.user_id
    INNER JOIN savings_requests sr ON sr.id = wr.savings_request_id
    WHERE wr.tenant_id = ?
    ORDER BY 
        CASE
            WHEN wr.status = 'pending' THEN 1
            WHEN wr.status = 'approved' THEN 2
            WHEN wr.status = 'paid' THEN 3
            WHEN wr.status = 'rejected' THEN 4
            ELSE 5
        END,
        wr.requested_at DESC
");
$withdrawalsStmt->bind_param("i", $tenant_id);
$withdrawalsStmt->execute();
$withdrawals = $withdrawalsStmt->get_result();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_withdrawals,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_withdrawals,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_withdrawals,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_withdrawals,
        SUM(CASE WHEN status = 'pending' THEN withdrawal_amount ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN status = 'approved' THEN withdrawal_amount ELSE 0 END) AS approved_amount,
        SUM(CASE WHEN status = 'paid' THEN withdrawal_amount ELSE 0 END) AS paid_amount
    FROM withdrawal_requests
    WHERE tenant_id = ?
");
$statsStmt->bind_param("i", $tenant_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_withdrawals = (int)($stats["total_withdrawals"] ?? 0);
$pending_withdrawals = (int)($stats["pending_withdrawals"] ?? 0);
$approved_withdrawals = (int)($stats["approved_withdrawals"] ?? 0);
$paid_withdrawals = (int)($stats["paid_withdrawals"] ?? 0);
$pending_amount = (float)($stats["pending_amount"] ?? 0);
$approved_amount = (float)($stats["approved_amount"] ?? 0);
$paid_amount = (float)($stats["paid_amount"] ?? 0);

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function memberDisplay($row) {
    if (!empty($row["username"])) {
        return $row["username"];
    }

    if (!empty($row["member_code"])) {
        return $row["member_code"];
    }

    return trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
}

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Withdrawals</title>
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
            max-width: 720px;
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
        .stat-card .stat-value,
        .stat-card .stat-note {
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

        .withdrawals-table-card {
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

        .code-pill {
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

        .member-identity {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 190px;
        }

        .member-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15,107,79,0.22);
            flex: 0 0 auto;
        }

        .member-name {
            font-weight: 900;
            color: #10241f;
        }

        .member-sub {
            font-size: 12px;
            color: #667085;
            margin-top: 2px;
        }

        .money-strong {
            font-weight: 900;
            color: #073f2f;
            white-space: nowrap;
        }

        .note-box {
            font-size: 13px;
            color: #10241f;
        }

        .note-muted {
            font-size: 12px;
            color: #667085;
            margin-top: 4px;
        }

        .actions-wrap {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .modal-content {
            border: 0;
            border-radius: 22px !important;
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
                <div class="app-topbar-title">Withdrawals</div>
                <div class="app-topbar-subtitle">
                    Approve, reject, and mark member withdrawals as paid.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="withdrawals-hero">
                <div class="withdrawals-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="withdrawals-hero-title">
                    Manage payouts and close completed cycles
                </div>

                <p class="withdrawals-hero-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Members request withdrawals after savings mature. Approve valid requests,
                    reject incorrect ones, and mark payouts as paid once money has been sent.
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
                        <div class="stat-label">Total Withdrawals</div>
                        <div class="stat-value"><?php echo $total_withdrawals; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Pending</div>
                        <div class="stat-value"><?php echo $pending_withdrawals; ?></div>
                        <div class="stat-note text-muted" style="font-size: 13px;">
                            <?php echo money($pending_amount); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Approved</div>
                        <div class="stat-value"><?php echo $approved_withdrawals; ?></div>
                        <div class="stat-note text-muted" style="font-size: 13px;">
                            <?php echo money($approved_amount); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Paid</div>
                        <div class="stat-value"><?php echo $paid_withdrawals; ?></div>
                        <div class="stat-note text-muted" style="font-size: 13px;">
                            <?php echo money($paid_amount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-box withdrawals-table-card">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">Member Withdrawal Requests</div>
                        <p class="section-subtitle">
                            Review matured savings withdrawal requests and process payouts.
                        </p>
                    </div>

                    <span class="code-pill">
                        <?php echo $total_withdrawals; ?> request<?php echo $total_withdrawals === 1 ? "" : "s"; ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Saved</th>
                                <th>Return</th>
                                <th>Withdrawal</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Notes</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($withdrawals->num_rows > 0): ?>
                                <?php while ($row = $withdrawals->fetch_assoc()): ?>
                                    <?php
                                        $displayMember = memberDisplay($row);
                                        $realName = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
                                        $initials = strtoupper(substr($displayMember ?: "MB", 0, 2));
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="member-identity">
                                                <div class="member-avatar">
                                                    <?php echo htmlspecialchars($initials); ?>
                                                </div>

                                                <div>
                                                    <div class="member-name">
                                                        <?php echo htmlspecialchars($displayMember ?: "-"); ?>
                                                    </div>

                                                    <div class="member-sub">
                                                        <?php echo htmlspecialchars($realName ?: "-"); ?>
                                                    </div>

                                                    <div class="member-sub">
                                                        <?php echo htmlspecialchars($row["phone"] ?: "-"); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <?php echo money($row["amount_saved"]); ?>
                                        </td>

                                        <td>
                                            <?php echo money($row["return_amount"]); ?>
                                        </td>

                                        <td>
                                            <span class="money-strong">
                                                <?php echo money($row["withdrawal_amount"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php echo withdrawalBadge($row["status"]); ?>
                                        </td>

                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($row["requested_at"])); ?>

                                            <?php if (!empty($row["matures_at"])): ?>
                                                <div class="member-sub">
                                                    Matured: <?php echo date("d M Y", strtotime($row["matures_at"])); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($row["processed_at"])): ?>
                                                <div class="member-sub">
                                                    Processed: <?php echo date("d M Y H:i", strtotime($row["processed_at"])); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($row["paid_at"])): ?>
                                                <div class="member-sub">
                                                    Paid: <?php echo date("d M Y H:i", strtotime($row["paid_at"])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="note-box">
                                                <strong>Member:</strong>
                                                <?php echo htmlspecialchars($row["member_note"] ?: "-"); ?>
                                            </div>

                                            <div class="note-muted">
                                                <strong>Admin:</strong>
                                                <?php echo htmlspecialchars($row["admin_note"] ?: "-"); ?>
                                            </div>
                                        </td>

                                        <td class="text-end">
                                            <div class="actions-wrap">
                                                <?php if ($row["status"] === "pending"): ?>
                                                    <button 
                                                        type="button"
                                                        class="btn btn-success btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#approveModal<?php echo (int)$row["id"]; ?>"
                                                    >
                                                        Approve
                                                    </button>

                                                    <button 
                                                        type="button"
                                                        class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#rejectModal<?php echo (int)$row["id"]; ?>"
                                                    >
                                                        Reject
                                                    </button>

                                                <?php elseif ($row["status"] === "approved"): ?>
                                                    <button 
                                                        type="button"
                                                        class="btn btn-dark btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#paidModal<?php echo (int)$row["id"]; ?>"
                                                    >
                                                        Mark Paid
                                                    </button>

                                                <?php elseif ($row["status"] === "paid"): ?>
                                                    <span class="badge badge-approved">Completed</span>

                                                <?php elseif ($row["status"] === "rejected"): ?>
                                                    <span class="badge badge-rejected">Closed</span>

                                                <?php else: ?>
                                                    <span class="text-muted" style="font-size: 13px;">No action</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="approveModal<?php echo (int)$row["id"]; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Approve Withdrawal</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>

                                                    <div class="modal-body">
                                                        <input type="hidden" name="withdrawal_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <input type="hidden" name="action" value="approve">

                                                        <div class="alert alert-info">
                                                            Approve withdrawal of 
                                                            <strong><?php echo money($row["withdrawal_amount"]); ?></strong>
                                                            for
                                                            <strong><?php echo htmlspecialchars($displayMember); ?></strong>?
                                                        </div>

                                                        <label class="form-label">Admin Note</label>
                                                        <textarea 
                                                            name="admin_note" 
                                                            class="form-control" 
                                                            rows="3"
                                                            placeholder="Optional note for this approval"
                                                        ></textarea>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                            Cancel
                                                        </button>
                                                        <button type="submit" class="btn btn-success">
                                                            Approve Withdrawal
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="modal fade" id="rejectModal<?php echo (int)$row["id"]; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reject Withdrawal</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>

                                                    <div class="modal-body">
                                                        <input type="hidden" name="withdrawal_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <input type="hidden" name="action" value="reject">

                                                        <div class="alert alert-warning">
                                                            Are you sure you want to reject this withdrawal request?
                                                        </div>

                                                        <label class="form-label">Reason / Admin Note</label>
                                                        <textarea 
                                                            name="admin_note" 
                                                            class="form-control" 
                                                            rows="3"
                                                            placeholder="Reason for rejection"
                                                        ></textarea>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                            Cancel
                                                        </button>
                                                        <button type="submit" class="btn btn-outline-danger">
                                                            Reject Withdrawal
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="modal fade" id="paidModal<?php echo (int)$row["id"]; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Mark Withdrawal as Paid</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>

                                                    <div class="modal-body">
                                                        <input type="hidden" name="withdrawal_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <input type="hidden" name="action" value="mark_paid">

                                                        <div class="alert alert-success">
                                                            Confirm that 
                                                            <strong><?php echo money($row["withdrawal_amount"]); ?></strong>
                                                            has been paid to 
                                                            <strong><?php echo htmlspecialchars($displayMember); ?></strong>.
                                                        </div>

                                                        <label class="form-label">Payment Note</label>
                                                        <textarea 
                                                            name="admin_note" 
                                                            class="form-control" 
                                                            rows="3"
                                                            placeholder="Example: Paid via EFT / cash / reference number"
                                                        ></textarea>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                            Cancel
                                                        </button>
                                                        <button type="submit" class="btn btn-dark">
                                                            Mark as Paid
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No withdrawal requests have been submitted yet.
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

</body>
</html>