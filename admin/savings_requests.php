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
    $request_id = (int)($_POST["request_id"] ?? 0);
    $action = $_POST["action"] ?? "";

    if ($request_id <= 0) {
        $error = "Invalid request selected.";
    } elseif (!in_array($action, ["approve", "reject"], true)) {
        $error = "Invalid action selected.";
    } else {
        if ($action === "approve") {
            $checkStmt = $conn->prepare("
                SELECT proof_of_payment_path
                FROM savings_requests
                WHERE id = ?
                AND tenant_id = ?
                AND status = 'payment_submitted'
                LIMIT 1
            ");
            $checkStmt->bind_param("ii", $request_id, $tenant_id);
            $checkStmt->execute();
            $request = $checkStmt->get_result()->fetch_assoc();

            if (!$request || empty($request["proof_of_payment_path"])) {
                $error = "This request cannot be approved until proof of payment is submitted.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE savings_requests
                    SET 
                        status = 'approved',
                        approved_at = NOW(),
                        matures_at = DATE_ADD(NOW(), INTERVAL maturity_days DAY)
                    WHERE id = ?
                    AND tenant_id = ?
                    AND status = 'payment_submitted'
                ");
                $stmt->bind_param("ii", $request_id, $tenant_id);

             if ($stmt->execute()) {
    $bonusStmt = $conn->prepare("
        SELECT 
            sr.id AS savings_request_id,
            sr.user_id AS referred_user_id,
            sr.amount,
            u.upline_user_id,
            ss.recruitment_bonus_percent
        FROM savings_requests sr
        INNER JOIN users u ON u.id = sr.user_id
        INNER JOIN stokvel_settings ss ON ss.tenant_id = sr.tenant_id
        WHERE sr.id = ?
        AND sr.tenant_id = ?
        LIMIT 1
    ");
    $bonusStmt->bind_param("ii", $request_id, $tenant_id);
    $bonusStmt->execute();
    $bonusData = $bonusStmt->get_result()->fetch_assoc();

    if (
        $bonusData &&
        !empty($bonusData["upline_user_id"]) &&
        (float)$bonusData["recruitment_bonus_percent"] > 0
    ) {
        $upliner_user_id = (int)$bonusData["upline_user_id"];
        $referred_user_id = (int)$bonusData["referred_user_id"];
        $saving_amount = (float)$bonusData["amount"];
        $bonus_percent = (float)$bonusData["recruitment_bonus_percent"];
        $bonus_amount = ($saving_amount * $bonus_percent) / 100;

        $insertBonus = $conn->prepare("
            INSERT IGNORE INTO referral_bonuses
            (
                tenant_id,
                upliner_user_id,
                referred_user_id,
                savings_request_id,
                saving_amount,
                bonus_percent,
                bonus_amount,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 'earned')
        ");

        $insertBonus->bind_param(
            "iiiiddd",
            $tenant_id,
            $upliner_user_id,
            $referred_user_id,
            $request_id,
            $saving_amount,
            $bonus_percent,
            $bonus_amount
        );

        $insertBonus->execute();
    }

    $success = "Saving request approved. The maturity countdown has started and any recruitment bonus has been recorded.";
} else {
    $error = "Could not approve saving request.";
}
            }
        }

        if ($action === "reject") {
            $stmt = $conn->prepare("
                UPDATE savings_requests
                SET 
                    status = 'rejected',
                    approved_at = NULL,
                    matures_at = NULL
                WHERE id = ?
                AND tenant_id = ?
                AND status IN ('pending', 'pending_payment', 'payment_submitted')
            ");
            $stmt->bind_param("ii", $request_id, $tenant_id);

            if ($stmt->execute()) {
                $success = "Saving request rejected.";
            } else {
                $error = "Could not reject saving request.";
            }
        }
    }
}

$requestsStmt = $conn->prepare("
    SELECT 
        savings_requests.id,
        savings_requests.amount,
        savings_requests.return_rate_percent,
        savings_requests.maturity_days,
        savings_requests.expected_return_amount,
        savings_requests.expected_total_amount,
        savings_requests.note,
        savings_requests.proof_of_payment_path,
        savings_requests.payment_note,
        savings_requests.payment_submitted_at,
        savings_requests.status,
        savings_requests.created_at,
        savings_requests.approved_at,
        savings_requests.matures_at,
        savings_requests.withdrawn_at,
        users.first_name,
        users.last_name,
        users.email,
        users.phone,
        users.username,
        users.member_code
    FROM savings_requests
    INNER JOIN users ON users.id = savings_requests.user_id
    WHERE savings_requests.tenant_id = ?
    ORDER BY 
        CASE
            WHEN savings_requests.status = 'payment_submitted' THEN 1
            WHEN savings_requests.status = 'pending_payment' THEN 2
            WHEN savings_requests.status = 'pending' THEN 3
            WHEN savings_requests.status = 'approved' THEN 4
            WHEN savings_requests.status = 'withdrawn' THEN 5
            WHEN savings_requests.status = 'rejected' THEN 6
            ELSE 7
        END,
        savings_requests.created_at DESC
");
$requestsStmt->bind_param("i", $tenant_id);
$requestsStmt->execute();
$requests = $requestsStmt->get_result();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_requests,

        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment') 
            THEN 1 ELSE 0 
        END) AS pending_payment_requests,

        SUM(CASE 
            WHEN status = 'payment_submitted' 
            THEN 1 ELSE 0 
        END) AS proof_submitted_requests,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN 1 ELSE 0 
        END) AS approved_requests,

        SUM(CASE 
            WHEN status = 'approved' 
            AND matures_at <= NOW() 
            THEN 1 ELSE 0 
        END) AS matured_requests,

        SUM(CASE 
            WHEN status = 'withdrawn' 
            THEN 1 ELSE 0 
        END) AS withdrawn_requests,

        SUM(CASE 
            WHEN status IN ('pending', 'pending_payment', 'payment_submitted', 'approved') 
            THEN amount ELSE 0 
        END) AS total_requested_amount,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN expected_return_amount ELSE 0 
        END) AS total_expected_returns,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN expected_total_amount ELSE 0 
        END) AS total_expected_amount,

        SUM(CASE 
            WHEN status = 'withdrawn' 
            THEN expected_total_amount ELSE 0 
        END) AS total_withdrawn_amount

    FROM savings_requests
    WHERE tenant_id = ?
");
$statsStmt->bind_param("i", $tenant_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_requests = (int)($stats["total_requests"] ?? 0);
$pending_payment_requests = (int)($stats["pending_payment_requests"] ?? 0);
$proof_submitted_requests = (int)($stats["proof_submitted_requests"] ?? 0);
$approved_requests = (int)($stats["approved_requests"] ?? 0);
$matured_requests = (int)($stats["matured_requests"] ?? 0);
$withdrawn_requests = (int)($stats["withdrawn_requests"] ?? 0);
$total_requested_amount = (float)($stats["total_requested_amount"] ?? 0);
$total_expected_returns = (float)($stats["total_expected_returns"] ?? 0);
$total_expected_amount = (float)($stats["total_expected_amount"] ?? 0);
$total_withdrawn_amount = (float)($stats["total_withdrawn_amount"] ?? 0);

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

function maturityLabel($row) {
    if ($row["status"] === "pending" || $row["status"] === "pending_payment") {
        return '<span class="badge badge-pending">Waiting for Payment</span>';
    }

    if ($row["status"] === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($row["status"] === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    if ($row["status"] === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn / Closed</span>';
    }

    if ($row["status"] === "approved" && !empty($row["matures_at"])) {
        $maturityTime = strtotime($row["matures_at"]);

        if ($maturityTime <= time()) {
            return '<span class="badge badge-approved">Matured</span>';
        }

        return '<span class="badge badge-pending">Maturing</span>';
    }

    return '<span class="badge bg-secondary">Not started</span>';
}

function statusBadge($status) {
    if ($status === "pending" || $status === "pending_payment") {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "approved") {
        return '<span class="badge badge-approved">Approved</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Saving Requests</title>
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

        .savings-hero {
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

        .savings-hero::after {
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

        .savings-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .savings-kicker {
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

        .savings-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .savings-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .savings-hero-text {
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

        .cycle-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .requests-card {
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

        .money-highlight {
            font-size: 28px;
            font-weight: 900;
            letter-spacing: -0.05em;
            color: #073f2f;
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

        .proof-note {
            font-size: 12px;
            color: #667085;
            margin-top: 5px;
            max-width: 220px;
        }

        .actions-wrap {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        @media (max-width: 900px) {
            .savings-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .savings-hero-title {
                font-size: 27px;
            }

            .savings-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }

            .money-highlight {
                font-size: 24px;
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
                <div class="app-topbar-title">Saving Requests</div>
                <div class="app-topbar-subtitle">
                    Confirm proof of payment before approving member savings.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="savings-hero">
                <div class="savings-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="savings-hero-title">
                    Review savings and start return cycles
                </div>

                <p class="savings-hero-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Members submit saving amounts and upload proof of payment. Once you approve a valid proof,
                    the return countdown starts automatically.
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
                        <div class="stat-value"><?php echo $total_requests; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Awaiting Payment</div>
                        <div class="stat-value"><?php echo $pending_payment_requests; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Proof Submitted</div>
                        <div class="stat-value"><?php echo $proof_submitted_requests; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Active Total</div>
                        <div class="stat-value">
                            <?php echo money($total_expected_amount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-box cycle-card mb-4">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <div>
                        <div class="section-title">Completed Savings Cycle</div>
                        <p class="section-subtitle">
                            Withdrawn or closed savings completed the full cycle and no longer count as active returns.
                        </p>
                    </div>

                    <div class="money-highlight">
                        <?php echo money($total_withdrawn_amount); ?>
                    </div>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-md-3">
                        <span class="code-pill">Approved: <?php echo $approved_requests; ?></span>
                    </div>
                    <div class="col-md-3">
                        <span class="code-pill">Matured: <?php echo $matured_requests; ?></span>
                    </div>
                    <div class="col-md-3">
                        <span class="code-pill">Withdrawn: <?php echo $withdrawn_requests; ?></span>
                    </div>
                    <div class="col-md-3">
                        <span class="code-pill">Returns: <?php echo money($total_expected_returns); ?></span>
                    </div>
                </div>
            </div>

            <div class="card-box requests-card">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <div class="section-title">All Member Saving Requests</div>
                        <p class="section-subtitle">
                            Review proof of payment, approve valid deposits, or reject incorrect requests.
                        </p>
                    </div>

                    <span class="code-pill">
                        <?php echo $total_requests; ?> request<?php echo $total_requests === 1 ? "" : "s"; ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Proof / Payment</th>
                                <th>Return</th>
                                <th>Total</th>
                                <th>Maturity</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($requests->num_rows > 0): ?>
                                <?php while ($row = $requests->fetch_assoc()): ?>
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
                                            <strong><?php echo money($row["amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php if (!empty($row["proof_of_payment_path"])): ?>
                                                <a 
                                                    href="../<?php echo htmlspecialchars($row["proof_of_payment_path"]); ?>" 
                                                    target="_blank"
                                                    class="btn btn-outline-dark btn-sm"
                                                >
                                                    View Proof
                                                </a>

                                                <?php if (!empty($row["payment_submitted_at"])): ?>
                                                    <div class="proof-note">
                                                        Submitted: <?php echo date("d M Y H:i", strtotime($row["payment_submitted_at"])); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($row["payment_note"])): ?>
                                                    <div class="proof-note">
                                                        <?php echo htmlspecialchars($row["payment_note"]); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">No proof yet</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <strong><?php echo money($row["expected_return_amount"]); ?></strong>
                                            <div class="text-muted" style="font-size: 12px;">
                                                <?php echo number_format((float)$row["return_rate_percent"], 2); ?>%
                                            </div>
                                        </td>

                                        <td>
                                            <strong><?php echo money($row["expected_total_amount"]); ?></strong>
                                        </td>

                                        <td>
                                            <?php echo maturityLabel($row); ?>

                                            <div class="text-muted mt-1" style="font-size: 12px;">
                                                <?php echo (int)$row["maturity_days"]; ?> days
                                            </div>

                                            <?php if (!empty($row["approved_at"])): ?>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    Approved: <?php echo date("d M Y H:i", strtotime($row["approved_at"])); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($row["matures_at"])): ?>
                                                <div style="font-size: 12px;">
                                                    Matures: <?php echo date("d M Y H:i", strtotime($row["matures_at"])); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($row["withdrawn_at"])): ?>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    Withdrawn: <?php echo date("d M Y H:i", strtotime($row["withdrawn_at"])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php echo statusBadge($row["status"]); ?>
                                        </td>

                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="actions-wrap">
                                                <?php if ($row["status"] === "payment_submitted"): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn btn-success btn-sm">
                                                            Approve
                                                        </button>
                                                    </form>

                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$row["id"]; ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            Reject
                                                        </button>
                                                    </form>

                                                <?php elseif ($row["status"] === "pending" || $row["status"] === "pending_payment"): ?>
                                                    <span class="text-muted" style="font-size: 13px;">
                                                        Waiting for proof
                                                    </span>

                                                <?php elseif ($row["status"] === "withdrawn"): ?>
                                                    <span class="badge badge-approved">
                                                        Closed
                                                    </span>

                                                <?php else: ?>
                                                    <span class="text-muted" style="font-size: 13px;">
                                                        No action
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        No saving requests have been submitted yet.
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