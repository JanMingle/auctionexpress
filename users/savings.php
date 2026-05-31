<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";

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
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";

$error = "";
$success = "";

/* ===============================
   PACKAGE RULES
   =============================== */
$packageRules = getTenantPackageRules($conn, $tenant_id);

$minimum_saving_amount = (float)$packageRules["minimum_saving_amount"];
$admin_fee_amount = (float)$packageRules["admin_fee_amount"];
$return_rate_percent = (float)$packageRules["return_rate_percent"];
$daily_return_percent = (float)$packageRules["daily_return_percent"];
$return_calculation_type = $packageRules["return_calculation_type"];
$maturity_days = (int)$packageRules["maturity_days"];
$withdraw_after_days = (int)$packageRules["withdraw_after_days"];
$show_daily_returns = (int)$packageRules["show_daily_returns"];
$require_proof_of_payment = (int)$packageRules["require_proof_of_payment"];

/* ===============================
   TENANT BANK SETTINGS
   =============================== */
$settingsInsert = $conn->prepare("
    INSERT IGNORE INTO stokvel_settings (tenant_id, return_rate_percent, maturity_days)
    VALUES (?, 10.00, 30)
");
$settingsInsert->bind_param("i", $tenant_id);
$settingsInsert->execute();

$settingsStmt = $conn->prepare("
    SELECT 
        bank_name,
        account_holder,
        account_number,
        branch_code,
        account_type,
        payment_reference_note
    FROM stokvel_settings
    WHERE tenant_id = ?
    LIMIT 1
");
$settingsStmt->bind_param("i", $tenant_id);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();

$bank_name = $settings["bank_name"] ?? "";
$account_holder = $settings["account_holder"] ?? "";
$account_number = $settings["account_number"] ?? "";
$branch_code = $settings["branch_code"] ?? "";
$account_type = $settings["account_type"] ?? "";
$payment_reference_note = $settings["payment_reference_note"] ?? "";

/* ===============================
   USER DETAILS
   =============================== */
$userStmt = $conn->prepare("
    SELECT 
        first_name,
        last_name,
        username,
        member_code
    FROM users
    WHERE id = ?
    AND tenant_id = ?
    LIMIT 1
");
$userStmt->bind_param("ii", $user_id, $tenant_id);
$userStmt->execute();
$userData = $userStmt->get_result()->fetch_assoc();

if ($userData) {
    $username = $userData["username"] ?? $username;
    $member_code = $userData["member_code"] ?? $member_code;
}

$displayName = $username ?: ($member_code ?: $name);

/* ===============================
   HELPERS
   =============================== */
function normalizeMoneyInput($value) {
    $value = trim((string)$value);
    $value = str_replace(" ", "", $value);

    if (strpos($value, ",") !== false && strpos($value, ".") !== false) {
        $value = str_replace(",", "", $value);
    } else {
        $value = str_replace(",", ".", $value);
    }

    $value = preg_replace("/[^0-9.]/", "", $value);

    $parts = explode(".", $value);
    if (count($parts) > 2) {
        $value = array_shift($parts) . "." . implode("", $parts);
    }

    return $value;
}

function requestCode($member_code, $username, $request_id) {
    $baseCode = $member_code ?: ($username ?: "MEMBER");
    return $baseCode . "-SAV" . str_pad((int)$request_id, 5, "0", STR_PAD_LEFT);
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

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}

function maturityText($row) {
    if (($row["status"] ?? "") === "approved" && !empty($row["matures_at"])) {
        $maturityTime = strtotime($row["matures_at"]);

        if ($maturityTime <= time()) {
            return "Matured";
        }

        return "Matures on " . date("d M Y H:i", $maturityTime);
    }

    if (($row["status"] ?? "") === "withdrawn") {
        return "Closed";
    }

    if (($row["status"] ?? "") === "rejected") {
        return "Rejected";
    }

    return "Not started yet";
}

/* ===============================
   OPEN REQUEST
   =============================== */
function getOpenSavingRequest($conn, $tenant_id, $user_id) {
    $stmt = $conn->prepare("
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
        AND status IN ('pending', 'pending_payment', 'payment_submitted', 'approved')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

$openRequest = getOpenSavingRequest($conn, $tenant_id, $user_id);
$has_open_request = !empty($openRequest);

/* ===============================
   FORM ACTIONS
   =============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form_action = $_POST["form_action"] ?? "";

    if ($form_action === "create_saving") {
        $amount = normalizeMoneyInput($_POST["amount"] ?? "");
        $note = trim($_POST["note"] ?? "");

        if ($has_open_request) {
            $error = "You already have a saving request waiting for payment, admin approval, or withdrawal.";
        } elseif ($amount === "") {
            $error = "Please enter how much you want to save.";
        } elseif (!is_numeric($amount) || (float)$amount <= 0) {
            $error = "Please enter a valid amount.";
        } elseif ((float)$amount < $minimum_saving_amount) {
            $error = "Minimum saving amount is " . packageMoney($minimum_saving_amount) . ".";
        } else {
            $amount = (float)$amount;
            $deposit_amount = $amount + $admin_fee_amount;

            $returnPreview = calculatePackageReturn($amount, $packageRules, $maturity_days);

            $expected_return_amount = (float)$returnPreview["return_amount"];
            $expected_total_amount = (float)$returnPreview["total_amount"];

            $stored_return_percent = $return_calculation_type === "once_off"
                ? $return_rate_percent
                : $daily_return_percent;

            $initial_status = $require_proof_of_payment === 1
                ? "pending_payment"
                : "payment_submitted";

            $stmt = $conn->prepare("
                INSERT INTO savings_requests
                (
                    tenant_id,
                    user_id,
                    amount,
                    return_rate_percent,
                    maturity_days,
                    expected_return_amount,
                    expected_total_amount,
                    note,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->bind_param(
                "iiddiddss",
                $tenant_id,
                $user_id,
                $amount,
                $stored_return_percent,
                $maturity_days,
                $expected_return_amount,
                $expected_total_amount,
                $note,
                $initial_status
            );

            if ($stmt->execute()) {
                $newRequestId = $conn->insert_id;
                $paymentRef = requestCode($member_code, $username, $newRequestId);

                if ($require_proof_of_payment === 1) {
                    $success = "Your saving request has been created. Please deposit " . packageMoney($deposit_amount) . " including the " . packageMoney($admin_fee_amount) . " admin fee, then upload proof of payment. Use reference: " . $paymentRef . ".";
                } else {
                    $success = "Your saving request has been created. Please deposit " . packageMoney($deposit_amount) . " including the " . packageMoney($admin_fee_amount) . " admin fee. Use reference: " . $paymentRef . ".";
                }

                $openRequest = getOpenSavingRequest($conn, $tenant_id, $user_id);
                $has_open_request = !empty($openRequest);
            } else {
                $error = "Could not create saving request. Please try again.";
            }
        }
    }

    if ($form_action === "upload_proof") {
        $savings_request_id = (int)($_POST["savings_request_id"] ?? 0);
        $payment_note = trim($_POST["payment_note"] ?? "");

        if ($savings_request_id <= 0) {
            $error = "Invalid saving request selected.";
        } elseif (!isset($_FILES["proof_of_payment"]) || $_FILES["proof_of_payment"]["error"] !== UPLOAD_ERR_OK) {
            $error = "Please select a valid proof of payment file.";
        } else {
            $checkStmt = $conn->prepare("
                SELECT id
                FROM savings_requests
                WHERE id = ?
                AND tenant_id = ?
                AND user_id = ?
                AND status IN ('pending', 'pending_payment')
                LIMIT 1
            ");
            $checkStmt->bind_param("iii", $savings_request_id, $tenant_id, $user_id);
            $checkStmt->execute();
            $validRequest = $checkStmt->get_result()->fetch_assoc();

            if (!$validRequest) {
                $error = "This saving request cannot accept proof of payment.";
            } else {
                $file = $_FILES["proof_of_payment"];
                $maxSize = 5 * 1024 * 1024;
                $allowedExtensions = ["jpg", "jpeg", "png", "pdf", "webp"];

                $originalName = $file["name"];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if ($file["size"] > $maxSize) {
                    $error = "Proof of payment must not be larger than 5MB.";
                } elseif (!in_array($extension, $allowedExtensions, true)) {
                    $error = "Only JPG, PNG, WEBP, and PDF files are allowed.";
                } else {
                    $uploadDir = __DIR__ . "/../uploads/proofs/tenant_" . $tenant_id . "/";

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $fileName = "proof_user_" . $user_id . "_request_" . $savings_request_id . "_" . time() . "." . $extension;
                    $targetPath = $uploadDir . $fileName;
                    $relativePath = "uploads/proofs/tenant_" . $tenant_id . "/" . $fileName;

                    if (move_uploaded_file($file["tmp_name"], $targetPath)) {
                        $updateStmt = $conn->prepare("
                            UPDATE savings_requests
                            SET
                                proof_of_payment_path = ?,
                                payment_note = ?,
                                payment_submitted_at = NOW(),
                                status = 'payment_submitted'
                            WHERE id = ?
                            AND tenant_id = ?
                            AND user_id = ?
                        ");

                        $updateStmt->bind_param(
                            "ssiii",
                            $relativePath,
                            $payment_note,
                            $savings_request_id,
                            $tenant_id,
                            $user_id
                        );

                        if ($updateStmt->execute()) {
                            $success = "Proof of payment uploaded successfully. Please wait for admin approval.";
                            $openRequest = getOpenSavingRequest($conn, $tenant_id, $user_id);
                            $has_open_request = !empty($openRequest);
                        } else {
                            $error = "Proof uploaded, but the request could not be updated.";
                        }
                    } else {
                        $error = "Could not upload proof of payment. Please try again.";
                    }
                }
            }
        }
    }
}

/* ===============================
   USER REQUEST HISTORY
   =============================== */
$requestsStmt = $conn->prepare("
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
    ORDER BY created_at DESC
");
$requestsStmt->bind_param("ii", $tenant_id, $user_id);
$requestsStmt->execute();
$requests = $requestsStmt->get_result();

$liveReturn = null;
$elapsedDays = 0;
$canWithdrawNow = false;

if ($has_open_request && ($openRequest["status"] ?? "") === "approved" && $show_daily_returns === 1) {
    $elapsedDays = approvedElapsedDays($openRequest["approved_at"] ?? null);
    $liveReturn = calculatePackageReturn((float)$openRequest["amount"], $packageRules, $elapsedDays);
    $canWithdrawNow = canWithdrawByPackage($openRequest["approved_at"] ?? null, $packageRules);
}

$currentPaymentReference = "";
if ($has_open_request) {
    $currentPaymentReference = requestCode($member_code, $username, (int)$openRequest["id"]);
}

$currentDepositAmount = $has_open_request
    ? ((float)$openRequest["amount"] + $admin_fee_amount)
    : 0.00;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Savings</title>
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

        .savings-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .savings-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 760px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .savings-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .bank-card,
        .proof-upload-card,
        .history-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .live-return-box {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #d8f5e5 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .live-return-label {
            font-size: 13px;
            color: #667085;
            font-weight: 800;
        }

        .live-return-value {
            font-size: 34px;
            font-weight: 900;
            color: #073f2f;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
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

        .bank-box,
        .proof-upload-box,
        .reference-box {
            background: #fffdf7;
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 20px;
            padding: 16px;
            color: #4b3a12;
            font-size: 14px;
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

        .rule-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 14px;
        }

        .rule-item {
            background: rgba(255,255,255,0.74);
            border: 1px solid rgba(16,36,31,0.08);
            border-radius: 16px;
            padding: 12px;
            font-size: 13px;
        }

        .rule-label {
            color: #667085;
            margin-bottom: 3px;
        }

        .rule-value {
            font-weight: 900;
            color: #073f2f;
        }

        .proof-upload-box input[type="file"] {
            min-height: 48px;
            font-size: 15px;
        }

        .proof-upload-box textarea,
        input,
        textarea {
            font-size: 16px;
        }

        @media (max-width: 900px) {
            .savings-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .savings-title {
                font-size: 27px;
            }

            .savings-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }

            .rule-grid {
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
                <div class="app-topbar-title">My Savings</div>
                <div class="app-topbar-subtitle">
                    Submit savings, upload proof, and track your returns.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="savings-hero">
                <div class="savings-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="savings-title">
                    Save and track your growth
                </div>

                <p class="savings-text">
                    Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Your stokvel is currently using the 
                    <strong><?php echo htmlspecialchars($packageRules["package_name"]); ?></strong>
                    package. Your saving rules are calculated from that package.
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

            <?php if ($has_open_request && ($openRequest["status"] ?? "") === "approved" && $show_daily_returns === 1 && $liveReturn): ?>
                <div class="card-box live-return-box mb-4">
                    <div class="live-return-label">
                        <?php echo htmlspecialchars(packageReturnLabel($packageRules)); ?>
                    </div>

                    <div class="live-return-value">
                        <?php echo packageMoney($liveReturn["total_amount"]); ?>
                    </div>

                    <p class="text-muted mb-2">
                        You have earned an extra 
                        <strong><?php echo packageMoney($liveReturn["return_amount"]); ?></strong>
                        after 
                        <strong><?php echo (int)$elapsedDays; ?></strong>
                        completed day<?php echo $elapsedDays === 1 ? "" : "s"; ?>.
                    </p>

                    <p class="text-muted mb-0">
                        Maturity period: <?php echo (int)$maturity_days; ?> days · 
                        Withdrawal allowed after: <?php echo (int)$withdraw_after_days; ?> days.
                    </p>

                    <?php if ($canWithdrawNow): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            You are now allowed to request a withdrawal.
                            <a href="withdrawals.php" class="alert-link">Go to withdrawals</a>.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            Withdrawal is not available yet.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card-box savings-card mb-4">
                        <div class="section-title">Submit Saving Request</div>
                        <p class="section-subtitle">
                            Enter the amount you want to save. The admin fee is added to the amount you deposit,
                            but it is not included in your return calculation.
                        </p>

                        <?php if ($has_open_request): ?>
                            <div class="alert alert-warning">
                                You already have an open saving request. You can submit another request after this cycle is rejected or withdrawn.
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="form_action" value="create_saving">

                            <div class="mb-3">
                                <label class="form-label">Saving Amount *</label>
                                <input 
                                    type="text" 
                                    name="amount" 
                                    id="amountInput"
                                    class="form-control" 
                                    inputmode="decimal"
                                    pattern="^[0-9]+([.,][0-9]{1,2})?$"
                                    placeholder="Minimum <?php echo packageMoney($minimum_saving_amount); ?>"
                                    <?php echo $has_open_request ? "disabled" : ""; ?>
                                    required
                                >

                                <div class="mt-3" id="depositPreview" style="display:none;">
                                    <div class="alert alert-info mb-0">
                                        <div><strong>Saving amount:</strong> <span id="savingAmountText">R0.00</span></div>
                                        <div><strong>Admin fee:</strong> <span id="adminFeeText">R0.00</span></div>
                                        <div><strong>Total to deposit:</strong> <span id="depositAmountText">R0.00</span></div>
                                        <hr>
                                        <div><strong>Return type:</strong> <span id="returnTypeText">Once-off return</span></div>
                                        <div><strong>Expected return:</strong> <span id="returnAmountText">R0.00</span></div>
                                        <div><strong>Expected payout:</strong> <span id="payoutAmountText">R0.00</span></div>
                                        <small class="text-muted">
                                            The admin fee is not included when calculating your return.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Note</label>
                                <textarea 
                                    name="note"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Optional note"
                                    <?php echo $has_open_request ? "disabled" : ""; ?>
                                ></textarea>
                            </div>

                            <button 
                                type="submit" 
                                class="btn btn-dark"
                                <?php echo $has_open_request ? "disabled" : ""; ?>
                            >
                                Submit Saving Request
                            </button>
                        </form>
                    </div>

                    <div class="card-box history-card">
                        <div class="section-title">My Saving Requests</div>
                        <p class="section-subtitle">
                            View your saving history and request statuses.
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
                                        <th>Maturity</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($requests->num_rows > 0): ?>
                                        <?php while ($row = $requests->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <span class="reference-code">
                                                        <?php echo htmlspecialchars(requestCode($member_code, $username, $row["id"])); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <?php echo packageMoney($row["amount"]); ?>
                                                </td>

                                                <td>
                                                    <?php echo packageMoney($row["expected_return_amount"]); ?>
                                                    <div class="text-muted" style="font-size: 12px;">
                                                        <?php echo number_format((float)$row["return_rate_percent"], 2); ?>%
                                                    </div>
                                                </td>

                                                <td>
                                                    <strong><?php echo packageMoney($row["expected_total_amount"]); ?></strong>
                                                </td>

                                                <td>
                                                    <?php echo statusBadge($row["status"]); ?>
                                                </td>

                                                <td>
                                                    <span style="font-size: 13px;">
                                                        <?php echo htmlspecialchars(maturityText($row)); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                No saving requests yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card-box bank-card mb-4">
                        <div class="section-title">Package Rules</div>
                        <p class="section-subtitle">
                            These rules come from your tenant’s assigned package.
                        </p>

                        <div class="rule-grid">
                            <div class="rule-item">
                                <div class="rule-label">Minimum Saving</div>
                                <div class="rule-value"><?php echo packageMoney($minimum_saving_amount); ?></div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-label">Admin Fee</div>
                                <div class="rule-value"><?php echo packageMoney($admin_fee_amount); ?></div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-label">Return Type</div>
                                <div class="rule-value"><?php echo htmlspecialchars(packageReturnLabel($packageRules)); ?></div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-label">Maturity Days</div>
                                <div class="rule-value"><?php echo (int)$maturity_days; ?> days</div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-label">Withdraw After</div>
                                <div class="rule-value"><?php echo (int)$withdraw_after_days; ?> days</div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-label">Rate</div>
                                <div class="rule-value">
                                    <?php if ($return_calculation_type === "once_off"): ?>
                                        <?php echo number_format($return_rate_percent, 2); ?>%
                                    <?php else: ?>
                                        <?php echo number_format($daily_return_percent, 2); ?>% daily
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($has_open_request): ?>
                        <div class="card-box bank-card mb-4">
                            <div class="section-title">Deposit Details</div>
                            <p class="section-subtitle">
                                Use these details only after submitting a saving request.
                            </p>

                            <div class="reference-box mb-3">
                                <div class="mb-2">
                                    <strong>Payment Reference:</strong>
                                </div>

                                <span class="reference-code">
                                    <?php echo htmlspecialchars($currentPaymentReference); ?>
                                </span>

                                <hr>

                                <p class="mb-1">
                                    <strong>Saving amount:</strong> <?php echo packageMoney((float)$openRequest["amount"]); ?>
                                </p>

                                <p class="mb-1">
                                    <strong>Admin fee:</strong> <?php echo packageMoney($admin_fee_amount); ?>
                                </p>

                                <p class="mb-0">
                                    <strong>Total deposit:</strong> <?php echo packageMoney($currentDepositAmount); ?>
                                </p>
                            </div>

                            <div class="bank-box">
                                <p class="mb-1"><strong>Bank:</strong> <?php echo htmlspecialchars($bank_name ?: "-"); ?></p>
                                <p class="mb-1"><strong>Account Holder:</strong> <?php echo htmlspecialchars($account_holder ?: "-"); ?></p>
                                <p class="mb-1"><strong>Account Number:</strong> <?php echo htmlspecialchars($account_number ?: "-"); ?></p>
                                <p class="mb-1"><strong>Branch Code:</strong> <?php echo htmlspecialchars($branch_code ?: "-"); ?></p>
                                <p class="mb-1"><strong>Account Type:</strong> <?php echo htmlspecialchars($account_type ?: "-"); ?></p>

                                <hr>

                                <p class="text-muted mb-0">
                                    <?php echo nl2br(htmlspecialchars($payment_reference_note ?: "Use your payment reference when depositing.")); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($has_open_request && $require_proof_of_payment === 1 && in_array(($openRequest["status"] ?? ""), ["pending", "pending_payment"], true)): ?>
                        <div class="card-box proof-upload-card" id="proofUploadCard">
                            <div class="section-title">Upload Proof of Payment</div>
                            <p class="section-subtitle">
                                Upload proof after depositing the required amount.
                            </p>

                            <div class="proof-upload-box">
                                <div class="alert alert-info">
                                    Upload proof for your total deposit of 
                                    <strong><?php echo packageMoney($currentDepositAmount); ?></strong>.
                                    <br>
                                    <small>
                                        Saving amount: <?php echo packageMoney((float)($openRequest["amount"] ?? 0)); ?> ·
                                        Admin fee: <?php echo packageMoney($admin_fee_amount); ?> ·
                                        Ref: <?php echo htmlspecialchars($currentPaymentReference); ?>
                                    </small>
                                </div>

                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="form_action" value="upload_proof">
                                    <input type="hidden" name="savings_request_id" value="<?php echo (int)($openRequest["id"] ?? 0); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Proof of Payment *</label>
                                        <input 
                                            type="file" 
                                            name="proof_of_payment" 
                                            class="form-control"
                                            accept=".jpg,.jpeg,.png,.pdf,.webp"
                                            required
                                        >
                                        <div class="text-muted mt-1" style="font-size: 12px;">
                                            Allowed: JPG, PNG, WEBP, PDF. Max size: 5MB.
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Note</label>
                                        <textarea 
                                            name="payment_note" 
                                            class="form-control" 
                                            rows="3"
                                            placeholder="Optional payment reference or note"
                                        ></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-dark w-100">
                                        Upload Proof
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$has_open_request): ?>
                        <div class="card-box bank-card">
                            <div class="section-title">Deposit Details Locked</div>
                            <p class="text-muted mb-0">
                                Submit a saving request first. The stokvel banking details and payment reference will appear after your request is created.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

</div>

<script>
const amountInput = document.getElementById("amountInput");

const minimumSavingAmount = <?php echo json_encode($minimum_saving_amount); ?>;
const adminFeeAmount = <?php echo json_encode($admin_fee_amount); ?>;
const returnRatePercent = <?php echo json_encode($return_rate_percent); ?>;
const dailyReturnPercent = <?php echo json_encode($daily_return_percent); ?>;
const returnCalculationType = <?php echo json_encode($return_calculation_type); ?>;
const maturityDays = <?php echo json_encode($maturity_days); ?>;

const depositPreview = document.getElementById("depositPreview");
const savingAmountText = document.getElementById("savingAmountText");
const adminFeeText = document.getElementById("adminFeeText");
const depositAmountText = document.getElementById("depositAmountText");
const returnAmountText = document.getElementById("returnAmountText");
const payoutAmountText = document.getElementById("payoutAmountText");
const returnTypeTextElement = document.getElementById("returnTypeText");

function formatMoney(amount) {
    return "R" + Number(amount || 0).toLocaleString("en-ZA", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function cleanAmount(value) {
    value = String(value || "").trim().replace(/\s/g, "");

    if (value.includes(",") && value.includes(".")) {
        value = value.replace(/,/g, "");
    } else {
        value = value.replace(/,/g, ".");
    }

    return value.replace(/[^0-9.]/g, "");
}

function updateDepositPreview() {
    if (!amountInput || !depositPreview) {
        return;
    }

    const amount = parseFloat(cleanAmount(amountInput.value)) || 0;

    if (amount <= 0) {
        depositPreview.style.display = "none";
        return;
    }

    const depositAmount = amount + adminFeeAmount;

    let expectedReturn = 0;
    let expectedPayout = amount;

    if (returnCalculationType === "daily_simple") {
        expectedReturn = ((amount * dailyReturnPercent) / 100) * maturityDays;
        expectedPayout = amount + expectedReturn;
    } else if (returnCalculationType === "daily_compound") {
        expectedPayout = amount * Math.pow((1 + (dailyReturnPercent / 100)), maturityDays);
        expectedReturn = expectedPayout - amount;
    } else {
        expectedReturn = (amount * returnRatePercent) / 100;
        expectedPayout = amount + expectedReturn;
    }

    const returnTypeText = returnCalculationType === "daily_simple"
        ? "Daily simple return"
        : returnCalculationType === "daily_compound"
            ? "Daily compound return"
            : "Once-off return";

    depositPreview.style.display = "block";

    savingAmountText.textContent = formatMoney(amount);
    adminFeeText.textContent = formatMoney(adminFeeAmount);
    depositAmountText.textContent = formatMoney(depositAmount);
    returnAmountText.textContent = formatMoney(expectedReturn);
    payoutAmountText.textContent = formatMoney(expectedPayout);

    if (returnTypeTextElement) {
        returnTypeTextElement.textContent = returnTypeText;
    }

    if (amount < minimumSavingAmount) {
        depositPreview.classList.add("border", "border-danger");
    } else {
        depositPreview.classList.remove("border", "border-danger");
    }
}

if (amountInput) {
    amountInput.addEventListener("input", updateDepositPreview);
    updateDepositPreview();
}
</script>

</body>
</html>