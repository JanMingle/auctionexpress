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

$error = "";
$success = "";

$settingsInsert = $conn->prepare("
    INSERT IGNORE INTO stokvel_settings (tenant_id, return_rate_percent, maturity_days)
    VALUES (?, 10.00, 30)
");
$settingsInsert->bind_param("i", $tenant_id);
$settingsInsert->execute();

$settingsStmt = $conn->prepare("
    SELECT 
        return_rate_percent,
        maturity_days,
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

$return_rate_percent = (float)($settings["return_rate_percent"] ?? 10.00);
$maturity_days = (int)($settings["maturity_days"] ?? 30);

$minimum_saving_amount = 200.00;
$admin_fee_amount = 20.00;

$bank_name = $settings["bank_name"] ?? "";
$account_holder = $settings["account_holder"] ?? "";
$account_number = $settings["account_number"] ?? "";
$branch_code = $settings["branch_code"] ?? "";
$account_type = $settings["account_type"] ?? "";
$payment_reference_note = $settings["payment_reference_note"] ?? "";
function normalizeMoneyInput($value) {
    $value = trim((string)$value);
    $value = str_replace(" ", "", $value);

    // If user types 2,000 or 2,000.50, remove thousands comma.
    if (strpos($value, ",") !== false && strpos($value, ".") !== false) {
        $value = str_replace(",", "", $value);
    } else {
        // If user types 200,50 treat comma as decimal.
        $value = str_replace(",", ".", $value);
    }

    $value = preg_replace("/[^0-9.]/", "", $value);

    // Keep only the first decimal point.
    $parts = explode(".", $value);
    if (count($parts) > 2) {
        $value = array_shift($parts) . "." . implode("", $parts);
    }

    return $value;
}

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function getOpenPaymentRequest($conn, $tenant_id, $user_id) {
    $stmt = $conn->prepare("
        SELECT id, amount, status, created_at
        FROM savings_requests
        WHERE tenant_id = ?
        AND user_id = ?
        AND status IN ('pending', 'pending_payment', 'payment_submitted')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

$openRequest = getOpenPaymentRequest($conn, $tenant_id, $user_id);
$has_open_request = is_array($openRequest);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form_action = $_POST["form_action"] ?? "";

    if ($form_action === "create_saving") {
      $amount = normalizeMoneyInput($_POST["amount"] ?? "");
        $note = trim($_POST["note"] ?? "");

      if ($has_open_request) {
    $error = "You already have a saving request waiting for payment or admin approval.";
} elseif ($amount === "") {
    $error = "Please enter how much you want to save.";
} elseif (!is_numeric($amount) || (float)$amount <= 0) {
    $error = "Please enter a valid amount.";
} elseif ((float)$amount < $minimum_saving_amount) {
    $error = "Minimum saving amount is " . money($minimum_saving_amount) . ".";
} else {
    $amount = (float)$amount;

    // Admin fee is only added to the deposit amount.
    // It must not be included in return calculations.
    $deposit_amount = $amount + $admin_fee_amount;

    $expected_return_amount = ($amount * $return_rate_percent) / 100;
    $expected_total_amount = $amount + $expected_return_amount;

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
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment')
            ");

            $stmt->bind_param(
                "iiddidds",
                $tenant_id,
                $user_id,
                $amount,
                $return_rate_percent,
                $maturity_days,
                $expected_return_amount,
                $expected_total_amount,
                $note
            );

            if ($stmt->execute()) {
               $success = "Your saving request has been created. Please deposit " . money($deposit_amount) . " including the " . money($admin_fee_amount) . " admin fee, then upload proof of payment.";

                $openRequest = [
                    "id" => $conn->insert_id,
                    "amount" => $amount,
                    "status" => "pending_payment",
                    "created_at" => date("Y-m-d H:i:s")
                ];

                $has_open_request = true;
            } else {
                $error = "Could not submit your saving request. Please try again.";
            }
        }
    }

    if ($form_action === "upload_proof") {
        $savings_request_id = (int)($_POST["savings_request_id"] ?? 0);
        $payment_note = trim($_POST["payment_note"] ?? "");

        if ($savings_request_id <= 0) {
            $error = "Invalid saving request selected.";
        } else {
            $checkStmt = $conn->prepare("
                SELECT id, status
                FROM savings_requests
                WHERE id = ?
                AND tenant_id = ?
                AND user_id = ?
                AND status IN ('pending', 'pending_payment')
                LIMIT 1
            ");
            $checkStmt->bind_param("iii", $savings_request_id, $tenant_id, $user_id);
            $checkStmt->execute();
            $saving = $checkStmt->get_result()->fetch_assoc();

            if (!$saving) {
                $error = "This request cannot receive proof of payment anymore.";
            } elseif (!isset($_FILES["proof_of_payment"]) || $_FILES["proof_of_payment"]["error"] !== UPLOAD_ERR_OK) {
                $error = "Please upload a valid proof of payment file.";
            } else {
                $allowedExtensions = ["jpg", "jpeg", "png", "pdf", "webp"];
                $originalName = $_FILES["proof_of_payment"]["name"];
                $fileSize = (int)$_FILES["proof_of_payment"]["size"];
                $tmpName = $_FILES["proof_of_payment"]["tmp_name"];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if (!in_array($extension, $allowedExtensions, true)) {
                    $error = "Only JPG, PNG, WEBP, or PDF files are allowed.";
                } elseif ($fileSize > 5 * 1024 * 1024) {
                    $error = "Proof of payment file must not be larger than 5MB.";
                } else {
                    $uploadDir = "../uploads/proofs/";
                    $relativeDir = "uploads/proofs/";

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $safeFileName = "proof_" . $tenant_id . "_" . $user_id . "_" . $savings_request_id . "_" . time() . "_" . bin2hex(random_bytes(3)) . "." . $extension;
                    $targetPath = $uploadDir . $safeFileName;
                    $relativePath = $relativeDir . $safeFileName;

                    if (move_uploaded_file($tmpName, $targetPath)) {
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

                            $openRequest = [
                                "id" => $savings_request_id,
                                "amount" => $openRequest["amount"] ?? 0,
                                "status" => "payment_submitted",
                                "created_at" => date("Y-m-d H:i:s")
                            ];

                            $has_open_request = true;
                        } else {
                            $error = "Could not save proof of payment.";
                        }
                    } else {
                        $error = "Could not upload the proof of payment file.";
                    }
                }
            }
        }
    }
}

$openRequest = getOpenPaymentRequest($conn, $tenant_id, $user_id);
$has_open_request = is_array($openRequest);

$historyStmt = $conn->prepare("
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
$historyStmt->bind_param("ii", $tenant_id, $user_id);
$historyStmt->execute();
$history = $historyStmt->get_result();

$totalStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_requests,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN expected_total_amount 
            ELSE 0 
        END) AS approved_expected_total,

        SUM(CASE 
            WHEN status = 'approved' 
            THEN expected_return_amount 
            ELSE 0 
        END) AS approved_expected_returns,

        SUM(CASE 
            WHEN status = 'approved' 
            AND matures_at <= NOW() 
            THEN expected_total_amount 
            ELSE 0 
        END) AS matured_total,

        SUM(CASE 
            WHEN status = 'withdrawn' 
            THEN expected_total_amount 
            ELSE 0 
        END) AS withdrawn_total

    FROM savings_requests
    WHERE tenant_id = ?
    AND user_id = ?
");
$totalStatsStmt->bind_param("ii", $tenant_id, $user_id);
$totalStatsStmt->execute();
$totalStats = $totalStatsStmt->get_result()->fetch_assoc();

$total_requests = (int)($totalStats["total_requests"] ?? 0);
$approved_expected_total = (float)($totalStats["approved_expected_total"] ?? 0);
$approved_expected_returns = (float)($totalStats["approved_expected_returns"] ?? 0);
$matured_total = (float)($totalStats["matured_total"] ?? 0);
$withdrawn_total = (float)($totalStats["withdrawn_total"] ?? 0);

$activeApprovedStmt = $conn->prepare("
    SELECT amount, expected_return_amount, expected_total_amount, matures_at
    FROM savings_requests
    WHERE tenant_id = ?
    AND user_id = ?
    AND status = 'approved'
    AND matures_at IS NOT NULL
    AND matures_at > NOW()
    ORDER BY matures_at ASC
    LIMIT 1
");
$activeApprovedStmt->bind_param("ii", $tenant_id, $user_id);
$activeApprovedStmt->execute();
$activeApproved = $activeApprovedStmt->get_result()->fetch_assoc();

function requestBadge($status, $maturesAt = null) {
    if ($status === "pending" || $status === "pending_payment") {
        return '<span class="badge badge-pending">Awaiting Payment</span>';
    }

    if ($status === "payment_submitted") {
        return '<span class="badge badge-pending">Proof Submitted</span>';
    }

    if ($status === "rejected") {
        return '<span class="badge badge-rejected">Rejected</span>';
    }

    if ($status === "withdrawn") {
        return '<span class="badge badge-approved">Withdrawn</span>';
    }

    if ($status === "approved") {
        if (!empty($maturesAt) && strtotime($maturesAt) <= time()) {
            return '<span class="badge badge-approved">Matured</span>';
        }

        return '<span class="badge badge-pending">Maturing</span>';
    }

    return '<span class="badge bg-secondary">Unknown</span>';
}
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
        .savings-hero {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.24), transparent 34%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            border-radius: 30px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 28px 70px rgba(7, 63, 47, 0.28);
            position: relative;
            overflow: hidden;
        }

        .savings-hero::after {
            content: "R";
            position: absolute;
            right: 34px;
            top: 24px;
            width: 92px;
            height: 92px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: #4a3504;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: 900;
            opacity: 0.22;
            transform: rotate(-12deg);
        }

        .savings-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.84);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 800;
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
            font-size: 32px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .savings-hero-text {
            color: rgba(255,255,255,0.76);
            font-size: 14px;
            line-height: 1.6;
            max-width: 680px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .countdown-box {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.26), transparent 35%),
                linear-gradient(135deg, #0f6b4f, #073f2f);
            color: white;
            border-radius: 28px;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(7,63,47,0.24);
            position: relative;
            overflow: hidden;
        }

        .countdown-box::after {
            content: "";
            position: absolute;
            right: -45px;
            bottom: -45px;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: rgba(216,169,40,0.18);
        }

        .countdown-time {
            font-size: 28px;
            font-weight: 900;
            letter-spacing: -0.04em;
            position: relative;
            z-index: 2;
        }

        .countdown-label {
            font-size: 13px;
            color: rgba(255,255,255,0.72);
            position: relative;
            z-index: 2;
        }

        .bank-box {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.18), transparent 34%),
                linear-gradient(135deg, #fffdf7 0%, #eef7f1 100%);
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 22px;
            padding: 18px;
        }

        .saving-form-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.14), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
        }

        .bank-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.22), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
        }

        .history-card {
            background:
                radial-gradient(circle at top left, rgba(216,169,40,0.18), transparent 32%),
                radial-gradient(circle at bottom right, rgba(15,107,79,0.16), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #e7f7ef 100%) !important;
        }

        .savings-section-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
        }

        .proof-upload-card {
    background:
        radial-gradient(circle at top right, rgba(216,169,40,0.24), transparent 35%),
        linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
}

.proof-upload-box {
    background: #fffdf7;
    border: 1px dashed rgba(216,169,40,0.48);
    border-radius: 22px;
    padding: 18px;
}

.proof-upload-box input[type="file"] {
    min-height: 48px;
    font-size: 15px;
}

.proof-upload-box textarea {
    font-size: 16px;
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
                    Submit saving request, deposit money, and upload proof of payment.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="savings-hero">
                <div class="savings-kicker">
                    My Savings Circle
                </div>

                <div class="savings-hero-title">
                    Save, upload proof, and watch your money grow
                </div>

                <p class="savings-hero-text">
                    Hello, <strong><?php echo htmlspecialchars($name); ?></strong>. 
                    Your current stokvel rule is 
                    <strong><?php echo number_format($return_rate_percent, 2); ?>%</strong>
                    return after 
                    <strong><?php echo $maturity_days; ?> days</strong>
                    from admin approval.
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

            <?php if ($activeApproved): ?>
                <div class="countdown-box mb-4">
                    <div class="row align-items-center g-3">
                        <div class="col-md-7">
                            <div class="countdown-label">Your next approved saving matures in</div>
                            <div 
                                class="countdown-time js-countdown" 
                                data-target="<?php echo htmlspecialchars(date("c", strtotime($activeApproved["matures_at"]))); ?>"
                            >
                                Calculating...
                            </div>
                            <div class="countdown-label mt-1">
                                Maturity date: <?php echo date("d M Y H:i", strtotime($activeApproved["matures_at"])); ?>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <div class="countdown-label">Expected return</div>
                            <div class="countdown-time">
                                R<?php echo number_format((float)$activeApproved["expected_return_amount"], 2); ?>
                            </div>
                            <div class="countdown-label mt-1">
                                Expected total: R<?php echo number_format((float)$activeApproved["expected_total_amount"], 2); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($has_open_request): ?>
                <div class="alert alert-warning">
                    You have a saving request of 
                    <strong>R<?php echo number_format((float)($openRequest["amount"] ?? 0), 2); ?></strong>
                    currently marked as 
                    <strong><?php echo htmlspecialchars(str_replace("_", " ", $openRequest["status"] ?? "")); ?></strong>.
                    You can submit another request after admin approves or rejects this one.
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
                        <div class="stat-label">Approved Returns</div>
                        <div class="stat-value">
                            R<?php echo number_format($approved_expected_returns, 2); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Matured / Ready</div>
                        <div class="stat-value">
                            R<?php echo number_format($matured_total, 2); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Withdrawn</div>
                        <div class="stat-value">
                            R<?php echo number_format($withdrawn_total, 2); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card-box saving-form-card mb-4">
                        <h5 class="savings-section-title mb-3">Submit Saving Amount</h5>

                        <form method="POST" action="">
                            <input type="hidden" name="form_action" value="create_saving">

                            <div class="mb-3">
                                <label class="form-label">Amount You Want to Save *</label>
 <input 
    type="text" 
    name="amount" 
    id="amountInput"
    class="form-control" 
    inputmode="decimal"
    pattern="^[0-9]+([.,][0-9]{1,2})?$"
    placeholder="Minimum R200"
    <?php echo $has_open_request ? "disabled" : ""; ?>
    required
>
                            </div>
                            <div class="mt-3" id="depositPreview" style="display:none;">
    <div class="alert alert-info mb-0">
        <div><strong>Saving amount:</strong> <span id="savingAmountText">R0.00</span></div>
        <div><strong>Admin fee:</strong> <span id="adminFeeText">R20.00</span></div>
        <div><strong>Total to deposit:</strong> <span id="depositAmountText">R0.00</span></div>
        <hr>
        <div><strong>Expected return:</strong> <span id="returnAmountText">R0.00</span></div>
        <div><strong>Expected payout:</strong> <span id="payoutAmountText">R0.00</span></div>
        <small class="text-muted">
            The admin fee is not included when calculating your return.
        </small>
    </div>
</div>

                            <div class="live-return-box mb-3">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="live-return-label">Estimated Return</div>
                                        <div class="live-return-value" id="returnAmount">R0.00</div>
                                    </div>

                                    <div class="col-6">
                                        <div class="live-return-label">Estimated Total</div>
                                        <div class="live-return-value" id="totalAmount">R0.00</div>
                                    </div>
                                </div>

                                <div class="text-muted mt-2" style="font-size: 12px;">
                                    Return starts only after admin confirms your proof of payment.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Note</label>
                                <textarea 
                                    name="note" 
                                    class="form-control" 
                                    rows="4"
                                    placeholder="Optional note for the admin"
                                    <?php echo $has_open_request ? "disabled" : ""; ?>
                                ></textarea>
                            </div>

                            <button 
                                type="submit" 
                                class="btn btn-dark w-100"
                                <?php echo $has_open_request ? "disabled" : ""; ?>
                            >
                                <?php echo $has_open_request ? "Waiting for Current Request" : "Submit Saving Request"; ?>
                            </button>
                        </form>
                    </div>

                   <?php if ($has_open_request): ?>
    <div class="card-box bank-card">
        <h5 class="savings-section-title mb-3">Bank Details</h5>

        <div class="bank-box">
            <div class="alert alert-info mb-3">
                Deposit only after submitting your saving request.
                Your proof of payment must match the required deposit amount.
            </div>

            <p class="mb-1">
                <strong>Bank:</strong> 
                <?php echo htmlspecialchars($bank_name ?: "-"); ?>
            </p>

            <p class="mb-1">
                <strong>Account Holder:</strong> 
                <?php echo htmlspecialchars($account_holder ?: "-"); ?>
            </p>

            <p class="mb-1">
                <strong>Account Number:</strong> 
                <?php echo htmlspecialchars($account_number ?: "-"); ?>
            </p>

            <p class="mb-1">
                <strong>Branch Code:</strong> 
                <?php echo htmlspecialchars($branch_code ?: "-"); ?>
            </p>

            <p class="mb-1">
                <strong>Account Type:</strong> 
                <?php echo htmlspecialchars($account_type ?: "-"); ?>
            </p>

            <hr>

            <p class="text-muted mb-0">
                <?php echo nl2br(htmlspecialchars($payment_reference_note ?: "Use your full name as payment reference.")); ?>
            </p>
        </div>
    </div>
<?php endif; ?>
                </div>

                                    <?php if ($has_open_request && in_array(($openRequest["status"] ?? ""), ["pending", "pending_payment"], true)): ?>
                        <div class="card-box proof-upload-card mt-4" id="proofUploadCard">
                            <h5 class="savings-section-title mb-3">Upload Proof of Payment</h5>

                            <div class="proof-upload-box">
                                <div class="alert alert-info">
                                    Upload proof for your saving request of 
                                  <div class="alert alert-info">
    Upload proof for your total deposit of 
    <strong><?php echo money(((float)($openRequest["amount"] ?? 0)) + $admin_fee_amount); ?></strong>.
    <br>
    <small>
        Saving amount: <?php echo money((float)($openRequest["amount"] ?? 0)); ?> ·
        Admin fee: <?php echo money($admin_fee_amount); ?>
    </small>
</div>
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

                <div class="col-lg-7">
                    <div class="card-box history-card">
                        <h5 class="savings-section-title mb-3">My Previous Requests</h5>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Amount</th>
                                        <th>Return</th>
                                        <th>Total</th>
                                        <th>Proof</th>
                                        <th>Maturity</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($history->num_rows > 0): ?>
                                        <?php while ($row = $history->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong>
                                                        R<?php echo number_format((float)$row["amount"], 2); ?>
                                                    </strong>
                                                </td>

                                                <td>
                                                    R<?php echo number_format((float)$row["expected_return_amount"], 2); ?>
                                                    <div class="text-muted" style="font-size: 12px;">
                                                        <?php echo number_format((float)$row["return_rate_percent"], 2); ?>%
                                                    </div>
                                                </td>

                                                <td>
                                                    <strong>
                                                        R<?php echo number_format((float)$row["expected_total_amount"], 2); ?>
                                                    </strong>
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
                                                    <?php else: ?>
                                                        <span class="text-muted">Not uploaded</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if ($row["status"] === "withdrawn"): ?>
                                                        <strong>Closed</strong>
                                                        <div class="text-muted" style="font-size: 12px;">
                                                            Paid out / withdrawn
                                                        </div>

                                                    <?php elseif ($row["status"] === "approved" && !empty($row["matures_at"])): ?>
                                                        <?php if (strtotime($row["matures_at"]) > time()): ?>
                                                            <div 
                                                                class="js-countdown" 
                                                                data-target="<?php echo htmlspecialchars(date("c", strtotime($row["matures_at"]))); ?>"
                                                                style="font-weight: 700;"
                                                            >
                                                                Calculating...
                                                            </div>
                                                        <?php else: ?>
                                                            <strong>Ready</strong>
                                                        <?php endif; ?>

                                                        <div class="text-muted" style="font-size: 12px;">
                                                            <?php echo date("d M Y H:i", strtotime($row["matures_at"])); ?>
                                                        </div>

                                                    <?php elseif ($row["status"] === "payment_submitted"): ?>
                                                        <span class="text-muted">Waiting for admin approval</span>

                                                    <?php elseif ($row["status"] === "pending" || $row["status"] === "pending_payment"): ?>
                                                        <span class="text-muted">Upload proof first</span>

                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php echo requestBadge($row["status"], $row["matures_at"]); ?>
                                                </td>

                                                <td>
                                                    <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                                </td>

                                                <td class="text-end">
                                                <?php if ($row["status"] === "pending" || $row["status"] === "pending_payment"): ?>
    <a href="#proofUploadCard" class="btn btn-dark btn-sm">
        Upload Proof
    </a>

                                                    <?php elseif ($row["status"] === "approved" && !empty($row["matures_at"]) && strtotime($row["matures_at"]) <= time()): ?>
                                                        <a href="withdrawals.php?saving_id=<?php echo (int)$row["id"]; ?>" class="btn btn-dark btn-sm">
                                                            Request Withdrawal
                                                        </a>

                                                    <?php elseif ($row["status"] === "withdrawn"): ?>
                                                        <span class="badge badge-approved">Closed</span>

                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-size: 13px;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>

                                          
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                You have not submitted any saving request yet.
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const returnRate = <?php echo json_encode($return_rate_percent); ?>;
const amountInput = document.getElementById("amountInput");
const returnAmount = document.getElementById("returnAmount");
const totalAmount = document.getElementById("totalAmount");

function formatMoney(value) {
    return "R" + Number(value || 0).toLocaleString("en-ZA", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function calculateReturns() {
    if (!amountInput) return;

    const amount = parseFloat(amountInput.value) || 0;
    const returns = (amount * returnRate) / 100;
    const total = amount + returns;

    returnAmount.textContent = formatMoney(returns);
    totalAmount.textContent = formatMoney(total);
}

if (amountInput) {
    amountInput.addEventListener("input", calculateReturns);
    calculateReturns();
}

function updateCountdowns() {
    const countdowns = document.querySelectorAll(".js-countdown");

    countdowns.forEach(function (item) {
        const target = new Date(item.dataset.target).getTime();
        const now = new Date().getTime();
        const distance = target - now;

        if (distance <= 0) {
            item.textContent = "Ready to withdraw";
            return;
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        item.textContent = days + "d " + hours + "h " + minutes + "m " + seconds + "s";
    });
}

updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>
<script>
const amountInput = document.getElementById("amountInput");

const minimumSavingAmount = <?php echo json_encode($minimum_saving_amount); ?>;
const adminFeeAmount = <?php echo json_encode($admin_fee_amount); ?>;
const returnRatePercent = <?php echo json_encode($return_rate_percent); ?>;

const depositPreview = document.getElementById("depositPreview");
const savingAmountText = document.getElementById("savingAmountText");
const adminFeeText = document.getElementById("adminFeeText");
const depositAmountText = document.getElementById("depositAmountText");
const returnAmountText = document.getElementById("returnAmountText");
const payoutAmountText = document.getElementById("payoutAmountText");

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
    const expectedReturn = (amount * returnRatePercent) / 100;
    const expectedPayout = amount + expectedReturn;

    depositPreview.style.display = "block";

    savingAmountText.textContent = formatMoney(amount);
    adminFeeText.textContent = formatMoney(adminFeeAmount);
    depositAmountText.textContent = formatMoney(depositAmount);
    returnAmountText.textContent = formatMoney(expectedReturn);
    payoutAmountText.textContent = formatMoney(expectedPayout);

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