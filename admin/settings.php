<?php
session_start();
require_once "../config/db.php";
require_once "../includes/package_rules.php";

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

/*
    The owner package is now the main source of truth.
    This pulls the assigned package from tenants.package_id
    and keeps stokvel_settings synced for old pages that still read stokvel_settings.
*/
$packageRules = syncPackageSettingsForTenant($conn, $tenant_id);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    /*
        These three are no longer taken from the form.
        They come directly from the assigned owner package.
    */
    $return_rate_percent = (float)($packageRules["return_rate_percent"] ?? 10);
    $maturity_days = (int)($packageRules["maturity_days"] ?? 30);
    $recruitment_bonus_percent = (float)($packageRules["recruitment_bonus_percent"] ?? 0);

    $bank_name = trim($_POST["bank_name"] ?? "");
    $account_holder = trim($_POST["account_holder"] ?? "");
    $account_number = trim($_POST["account_number"] ?? "");
    $branch_code = trim($_POST["branch_code"] ?? "");
    $account_type = trim($_POST["account_type"] ?? "");
    $payment_reference_note = trim($_POST["payment_reference_note"] ?? "");

    $stmt = $conn->prepare("
        UPDATE stokvel_settings
        SET
            return_rate_percent = ?,
            maturity_days = ?,
            recruitment_bonus_percent = ?,
            bank_name = ?,
            account_holder = ?,
            account_number = ?,
            branch_code = ?,
            account_type = ?,
            payment_reference_note = ?
        WHERE tenant_id = ?
    ");

    $stmt->bind_param(
        "didssssssi",
        $return_rate_percent,
        $maturity_days,
        $recruitment_bonus_percent,
        $bank_name,
        $account_holder,
        $account_number,
        $branch_code,
        $account_type,
        $payment_reference_note,
        $tenant_id
    );

    if ($stmt->execute()) {
        $success = "Settings updated successfully. Package rules are controlled from Owner Packages.";
    } else {
        $error = "Could not update settings.";
    }

    /*
        Re-sync after saving, just to keep values clean.
    */
    $packageRules = syncPackageSettingsForTenant($conn, $tenant_id);
}

$settingsStmt = $conn->prepare("
    SELECT
        return_rate_percent,
        maturity_days,
        recruitment_bonus_percent,
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

/*
    Display package values, not manually edited settings values.
*/
$return_rate_percent = (float)($packageRules["return_rate_percent"] ?? ($settings["return_rate_percent"] ?? 10.00));
$maturity_days = (int)($packageRules["maturity_days"] ?? ($settings["maturity_days"] ?? 30));
$recruitment_bonus_percent = (float)($packageRules["recruitment_bonus_percent"] ?? ($settings["recruitment_bonus_percent"] ?? 0.00));

$bank_name = $settings["bank_name"] ?? "";
$account_holder = $settings["account_holder"] ?? "";
$account_number = $settings["account_number"] ?? "";
$branch_code = $settings["branch_code"] ?? "";
$account_type = $settings["account_type"] ?? "";
$payment_reference_note = $settings["payment_reference_note"] ?? "";

$assigned_package_name = $packageRules["package_name"] ?? "No package assigned";
$assigned_package_type = $packageRules["package_type"] ?? "savings";

$sample_amount = 500;
$sample_return = ($sample_amount * (float)$return_rate_percent) / 100;
$sample_total = $sample_amount + $sample_return;

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stokvel Settings</title>
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

        .settings-hero {
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

        .settings-hero::after {
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

        .settings-hero::before {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            right: -80px;
            bottom: -105px;
            background: rgba(216,169,40,0.16);
        }

        .settings-kicker {
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

        .settings-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d8a928;
        }

        .settings-hero-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .settings-hero-text {
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

        .rules-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .bank-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
        }

        .preview-card {
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

        .preview-box {
            background: #fffdf7;
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 20px;
            padding: 16px;
            color: #4b3a12;
        }

        .preview-line {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(16,36,31,0.08);
        }

        .preview-line:last-child {
            border-bottom: 0;
        }

        .preview-label {
            color: #667085;
            font-size: 13px;
        }

        .preview-value {
            font-weight: 900;
            color: #073f2f;
            text-align: right;
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

        @media (max-width: 900px) {
            .settings-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .settings-hero-title {
                font-size: 27px;
            }

            .settings-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }

            .preview-line {
                flex-direction: column;
                gap: 2px;
            }

            .preview-value {
                text-align: left;
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
                <div class="app-topbar-title">Stokvel Settings</div>
                <div class="app-topbar-subtitle">
                    Control returns, maturity period, and payment banking details.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="settings-hero">
                <div class="settings-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="settings-hero-title">
                    Set the rules for your money circle
                </div>

                <p class="settings-hero-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Configure the return percentage, maturity days, and bank details that members will use
                    when submitting saving requests and proof of payment.
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
                        <div class="stat-label">Return Percentage</div>
                        <div class="stat-value">
                            <?php echo number_format((float)$return_rate_percent, 2); ?>%
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Maturity Period</div>
                        <div class="stat-value">
                            <?php echo (int)$maturity_days; ?> days
                        </div>
                    </div>
                </div>

          

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Sample Return</div>
                        <div class="stat-value">
                            <?php echo money($sample_return); ?>
                        </div>
                        <div class="stat-note text-muted" style="font-size: 13px;">
                            Based on <?php echo money($sample_amount); ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Sample Total</div>
                        <div class="stat-value">
                            <?php echo money($sample_total); ?>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card-box rules-card mb-4">
                            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                                <div>
                                    <div class="section-title">Savings Rules</div>
                                    <p class="section-subtitle mb-0">
                                        These rules are copied into each saving request when a member submits.
                                    </p>
                                </div>

                                <span class="code-pill">Return setup</span>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Return Percentage (%)</label>
                                <input 
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="return_rate_percent"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($return_rate_percent); ?>"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Maturity Days</label>
                                <input 
                                    type="number"
                                    min="1"
                                    name="maturity_days"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($maturity_days); ?>"
                                    required
                                >
                            </div>
                        </div>

                              <div class="mb-3">
    <label class="form-label">Recruitment Bonus Percentage (%)</label>
    <input 
        type="number"
        step="0.01"
        min="0"
        name="recruitment_bonus_percent"
        class="form-control"
        value="<?php echo htmlspecialchars($recruitment_bonus_percent); ?>"
        required
    >
    <div class="text-muted mt-1" style="font-size: 12px;">
        Example: If this is 5%, and a referred member saves R500, the upliner earns R25.
    </div>
</div>

                        <div class="card-box bank-card">
                            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                                <div>
                                    <div class="section-title">Bank Details for Member Deposits</div>
                                    <p class="section-subtitle mb-0">
                                        These details will be shown to members after they submit a saving request.
                                    </p>
                                </div>

                                <span class="code-pill">Deposit details</span>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Bank Name</label>
                                <input 
                                    type="text"
                                    name="bank_name"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($bank_name); ?>"
                                    placeholder="Example: FNB"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Account Holder</label>
                                <input 
                                    type="text"
                                    name="account_holder"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($account_holder); ?>"
                                    placeholder="Example: Friends Wealth Circle"
                                >
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Account Number</label>
                                    <input 
                                        type="text"
                                        name="account_number"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($account_number); ?>"
                                    >
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Branch Code</label>
                                    <input 
                                        type="text"
                                        name="branch_code"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($branch_code); ?>"
                                    >
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Account Type</label>
                                <input 
                                    type="text"
                                    name="account_type"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($account_type); ?>"
                                    placeholder="Example: Savings / Current"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Payment Reference Instruction</label>
                                <textarea 
                                    name="payment_reference_note"
                                    class="form-control"
                                    rows="4"
                                    placeholder="Example: Use your full name as payment reference"
                                ><?php echo htmlspecialchars($payment_reference_note); ?></textarea>
                            </div>

                            <button class="btn btn-dark" type="submit">
                                Save Settings
                            </button>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card-box preview-card mb-4">
                            <div class="section-title">Current Rule Preview</div>
                            <p class="section-subtitle">
                                Example of what members will see when they save.
                            </p>

                            <div class="preview-box">
                                <div class="preview-line">
                                    <div class="preview-label">Sample saving</div>
                                    <div class="preview-value"><?php echo money($sample_amount); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Return percentage</div>
                                    <div class="preview-value"><?php echo number_format((float)$return_rate_percent, 2); ?>%</div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Estimated return</div>
                                    <div class="preview-value"><?php echo money($sample_return); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Estimated total</div>
                                    <div class="preview-value"><?php echo money($sample_total); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Maturity period</div>
                                    <div class="preview-value"><?php echo (int)$maturity_days; ?> days</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-box preview-card">
                            <div class="section-title">Bank Preview</div>
                            <p class="section-subtitle">
                                This is the banking information members will use for deposits.
                            </p>

                            <div class="preview-box">
                                <div class="preview-line">
                                    <div class="preview-label">Bank</div>
                                    <div class="preview-value"><?php echo htmlspecialchars($bank_name ?: "-"); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Account Holder</div>
                                    <div class="preview-value"><?php echo htmlspecialchars($account_holder ?: "-"); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Account Number</div>
                                    <div class="preview-value"><?php echo htmlspecialchars($account_number ?: "-"); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Branch Code</div>
                                    <div class="preview-value"><?php echo htmlspecialchars($branch_code ?: "-"); ?></div>
                                </div>

                                <div class="preview-line">
                                    <div class="preview-label">Account Type</div>
                                    <div class="preview-value"><?php echo htmlspecialchars($account_type ?: "-"); ?></div>
                                </div>

                                <div class="mt-3">
                                    <div class="preview-label mb-1">Payment Reference Instruction</div>
                                    <div style="font-weight: 700; color: #073f2f;">
                                        <?php echo nl2br(htmlspecialchars($payment_reference_note ?: "No payment reference instruction added yet.")); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

        </div>
    </main>

</div>

</body>
</html>