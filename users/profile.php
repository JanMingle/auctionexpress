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

$success = "";
$error = "";

$packageRules = getTenantPackageRules($conn, $tenant_id);

$isAuctionPackage = function_exists("packageIsAuction")
    ? packageIsAuction($packageRules)
    : (($packageRules["package_type"] ?? "savings") === "auction");

$packageName = $packageRules["package_name"] ?? "Package";
$returnPercent = (float)($packageRules["return_rate_percent"] ?? 0);
$maturityDays = (int)($packageRules["maturity_days"] ?? 30);

if ($maturityDays <= 0) {
    $maturityDays = 30;
}

$userStmt = $conn->prepare("
    SELECT 
        id,
        first_name,
        last_name,
        email,
        phone,
        username,
        member_code,
        status,
        bank_name,
        bank_account_holder,
        bank_account_number,
        bank_branch_code,
        bank_account_type,
        banking_details_completed,
        created_at
    FROM users
    WHERE id = ?
    AND tenant_id = ?
    LIMIT 1
");
$userStmt->bind_param("ii", $user_id, $tenant_id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$first_name = $user["first_name"] ?? "";
$last_name = $user["last_name"] ?? "";
$email = $user["email"] ?? "";
$phone = $user["phone"] ?? "";
$username = $user["username"] ?? "";
$member_code = $user["member_code"] ?? "";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";

$displayName = $username ?: ($member_code ?: trim($first_name . " " . $last_name));
$initial = strtoupper(substr(trim($displayName ?: "U"), 0, 1));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($first_name === "" || $last_name === "" || $email === "" || $phone === "") {
        $error = "Please complete all required profile fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($new_password !== "" && strlen($new_password) < 4) {
        $error = "Password must be at least 4 characters or digits.";
    } elseif ($new_password !== "" && $new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $checkEmailStmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            AND id <> ?
            LIMIT 1
        ");
        $checkEmailStmt->bind_param("si", $email, $user_id);
        $checkEmailStmt->execute();
        $emailExists = $checkEmailStmt->get_result()->fetch_assoc();

        if ($emailExists) {
            $error = "This email address is already used by another account.";
        } else {
            if ($new_password !== "") {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $updateStmt = $conn->prepare("
                    UPDATE users
                    SET 
                        first_name = ?,
                        last_name = ?,
                        email = ?,
                        phone = ?,
                        password = ?
                    WHERE id = ?
                    AND tenant_id = ?
                ");

                $updateStmt->bind_param(
                    "sssssii",
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $hashed_password,
                    $user_id,
                    $tenant_id
                );
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE users
                    SET 
                        first_name = ?,
                        last_name = ?,
                        email = ?,
                        phone = ?
                    WHERE id = ?
                    AND tenant_id = ?
                ");

                $updateStmt->bind_param(
                    "ssssii",
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $user_id,
                    $tenant_id
                );
            }

            if ($updateStmt->execute()) {
                $_SESSION["name"] = trim($first_name . " " . $last_name);

                $success = "Profile updated successfully.";

                $user["first_name"] = $first_name;
                $user["last_name"] = $last_name;
                $user["email"] = $email;
                $user["phone"] = $phone;

                $displayName = $username ?: ($member_code ?: trim($first_name . " " . $last_name));
                $initial = strtoupper(substr(trim($displayName ?: "U"), 0, 1));
            } else {
                $error = "Could not update your profile. Please try again.";
            }
        }
    }
}

function maskAccountNumber($accountNumber) {
    $accountNumber = trim((string)$accountNumber);

    if ($accountNumber === "") {
        return "-";
    }

    $lastFour = substr($accountNumber, -4);

    return "•••• •••• " . htmlspecialchars($lastFour);
}

function safeValue($value) {
    $value = trim((string)$value);
    return $value !== "" ? htmlspecialchars($value) : "-";
}

function displayDate($dateValue) {
    if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
        return "-";
    }

    return date("d M Y", strtotime($dateValue));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo time(); ?>">

    <style>
        <?php if ($isAuctionPackage): ?>
        body {
            background:
                radial-gradient(circle at 20% 0%, rgba(69, 90, 145, 0.22), transparent 34%),
                radial-gradient(circle at 90% 10%, rgba(168, 59, 216, 0.12), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 50%, #0b1424 100%) !important;
            color: rgba(255,255,255,0.86);
        }

        .app-main {
            background:
                radial-gradient(circle at 85% 5%, rgba(168, 59, 216, 0.12), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 100%) !important;
        }

        .app-topbar {
            background:
                linear-gradient(rgba(13,24,41,0.84), rgba(13,24,41,0.90)),
                radial-gradient(circle at top right, rgba(59,130,246,0.16), transparent 34%) !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
            color: #ffffff;
        }

        .app-topbar-title,
        .app-topbar-subtitle {
            color: rgba(255,255,255,0.88) !important;
        }

        .app-content::before {
            display: none !important;
        }

        .profile-shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .profile-page-title {
            font-size: 30px;
            font-weight: 400;
            color: rgba(255,255,255,0.72);
            margin-bottom: 20px;
        }

        .profile-cover {
            min-height: 180px;
            border-radius: 5px;
            background:
                linear-gradient(rgba(13,24,41,0.68), rgba(13,24,41,0.94)),
                radial-gradient(circle at right top, rgba(168,59,216,0.24), transparent 30%),
                radial-gradient(circle at left bottom, rgba(16,185,129,0.10), transparent 30%),
                linear-gradient(135deg, #162239, #0d1829);
            border: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 26px;
            padding: 26px;
            position: relative;
            overflow: hidden;
        }

        .profile-cover::after {
            content: "";
            position: absolute;
            right: 28px;
            top: 22px;
            width: 52px;
            height: 38px;
            border-top: 4px solid rgba(255,255,255,0.25);
            border-bottom: 4px solid rgba(255,255,255,0.25);
        }

        .profile-summary-row {
            display: flex;
            align-items: center;
            gap: 18px;
            position: relative;
            z-index: 2;
        }

        .profile-avatar {
            width: 90px;
            height: 90px;
            border-radius: 8px;
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            box-shadow: 0 18px 36px rgba(168,59,216,0.28);
            color: #ffffff;
            font-size: 42px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-name {
            font-size: 30px;
            font-weight: 300;
            color: rgba(255,255,255,0.82);
            margin-bottom: 6px;
        }

        .profile-meta {
            color: rgba(255,255,255,0.42);
            font-size: 14px;
            line-height: 1.7;
        }

        .package-strip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.62);
            border-radius: 5px;
            padding: 10px 13px;
            font-size: 13px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(330px, 0.75fr);
            gap: 26px;
        }

        .crypto-panel {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 5px;
            padding: 24px;
            box-shadow: 0 24px 42px rgba(0,0,0,0.16);
        }

        .crypto-panel-heading {
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            color: #ffffff;
            padding: 22px 24px;
            margin: -24px -24px 24px;
            border-radius: 5px 5px 0 0;
        }

        .crypto-panel-heading.green {
            background: linear-gradient(135deg, #32b96e, #1b9e5f);
        }

        .crypto-panel-heading.orange {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
        }

        .crypto-panel-title {
            font-size: 23px;
            font-weight: 400;
            margin-bottom: 5px;
        }

        .crypto-panel-subtitle {
            color: rgba(255,255,255,0.68);
            font-size: 14px;
        }

        .form-label {
            color: rgba(255,255,255,0.58) !important;
            font-size: 13px;
            font-weight: 700;
        }

        .form-control {
            background: rgba(13,24,41,0.72) !important;
            border: 1px solid rgba(255,255,255,0.08) !important;
            color: rgba(255,255,255,0.88) !important;
            border-radius: 5px !important;
            padding: 13px 14px !important;
        }

        .form-control::placeholder {
            color: rgba(255,255,255,0.28);
        }

        .form-control:focus {
            border-color: rgba(168,59,216,0.65) !important;
            box-shadow: 0 0 0 4px rgba(168,59,216,0.13) !important;
        }

        .btn-dark {
            background: linear-gradient(135deg, #a83bd8, #c447f0) !important;
            border: 0 !important;
            color: #ffffff !important;
            border-radius: 5px !important;
            padding: 12px 16px;
            box-shadow: 0 18px 34px rgba(168,59,216,0.24);
        }

        .btn-outline-dark {
            background: rgba(255,255,255,0.045) !important;
            color: rgba(255,255,255,0.74) !important;
            border: 1px solid rgba(255,255,255,0.10) !important;
            border-radius: 5px !important;
        }

        .readonly-box {
            background: rgba(13,24,41,0.62);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 5px;
            padding: 8px 0;
        }

        .readonly-line {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 13px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.045);
        }

        .readonly-line:last-child {
            border-bottom: 0;
        }

        .readonly-label {
            color: rgba(255,255,255,0.42);
            font-size: 13px;
        }

        .readonly-value {
            color: rgba(255,255,255,0.76);
            font-weight: 700;
            text-align: right;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            padding: 7px 11px;
            background: rgba(50,185,110,0.12);
            color: #55d58e;
            border: 1px solid rgba(50,185,110,0.18);
            font-size: 12px;
            font-weight: 800;
        }

        .status-pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #55d58e;
        }

        .locked-note {
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 5px;
            padding: 13px;
            color: rgba(255,255,255,0.45);
            font-size: 13px;
            line-height: 1.6;
        }

        .alert {
            border-radius: 5px;
        }

        hr {
            border-color: rgba(255,255,255,0.08);
            opacity: 1;
        }

        @media (max-width: 1050px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .profile-page-title {
                font-size: 27px;
            }

            .profile-cover {
                padding: 22px;
            }

            .profile-summary-row {
                align-items: flex-start;
            }

            .profile-avatar {
                width: 72px;
                height: 72px;
                font-size: 32px;
            }

            .profile-name {
                font-size: 24px;
            }

            .readonly-line {
                flex-direction: column;
                gap: 3px;
            }

            .readonly-value {
                text-align: left;
            }
        }

        <?php else: ?>

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

        .profile-shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .profile-cover {
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

        .profile-cover::after {
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

        .profile-page-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
            color: #ffffff;
        }

        .profile-summary-row {
            position: relative;
            z-index: 2;
        }

        .profile-avatar {
            display: none;
        }

        .profile-name {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            color: #ffffff;
        }

        .profile-meta {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 720px;
        }

        .package-strip {
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
        }

        .profile-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(330px, 0.75fr);
            gap: 26px;
        }

        .crypto-panel {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
            border: 1px solid rgba(255,255,255,0.88) !important;
            box-shadow: 0 22px 55px rgba(16,36,31,0.14) !important;
            border-radius: 26px;
            padding: 24px;
        }

        .crypto-panel-heading {
            margin-bottom: 18px;
        }

        .crypto-panel-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #10241f;
            margin-bottom: 6px;
        }

        .crypto-panel-subtitle {
            font-size: 13px;
            color: #667085;
        }

        .crypto-panel.side {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
        }

        .readonly-box {
            background: #fffdf7;
            border: 1px dashed rgba(216,169,40,0.48);
            border-radius: 18px;
            padding: 14px;
            font-size: 13px;
            color: #4b3a12;
            margin-bottom: 14px;
        }

        .readonly-line {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px solid rgba(16,36,31,0.08);
            padding: 9px 0;
        }

        .readonly-line:last-child {
            border-bottom: 0;
        }

        .readonly-label {
            color: #667085;
        }

        .readonly-value {
            font-weight: 900;
            color: #073f2f;
            text-align: right;
        }

        .status-pill {
            display: inline-flex;
            border-radius: 999px;
            padding: 7px 11px;
            background: #e7f7ef;
            color: #073f2f;
            font-size: 12px;
            font-weight: 900;
        }

        .locked-note {
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(16,36,31,0.08);
            border-radius: 16px;
            padding: 12px;
            color: #667085;
            font-size: 13px;
            line-height: 1.55;
        }

        @media (max-width: 1050px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .profile-cover {
                border-radius: 24px;
                padding: 24px;
            }

            .profile-name {
                font-size: 27px;
            }

            .profile-cover::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }

            .readonly-line {
                flex-direction: column;
                gap: 2px;
            }

            .readonly-value {
                text-align: left;
            }
        }

        <?php endif; ?>
    </style>
</head>
<body>

<div class="app-shell">

    <?php include "../includes/sidebar.php"; ?>

    <main class="app-main">
        <div class="app-topbar">
            <div>
                <div class="app-topbar-title">My Profile</div>
                <div class="app-topbar-subtitle">
                    Manage your personal details and view your locked account information.
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="profile-shell">

                <?php if ($isAuctionPackage): ?>
                    <div class="profile-page-title">
                        User Profile
                    </div>
                <?php endif; ?>

                <div class="profile-cover">
                    <div class="profile-summary-row">
                        <?php if ($isAuctionPackage): ?>
                            <div class="profile-avatar">
                                <?php echo htmlspecialchars($initial); ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="package-strip">
                                <?php echo htmlspecialchars($packageName); ?>
                                · <?php echo number_format($returnPercent, 2); ?>%
                                · <?php echo (int)$maturityDays; ?> days
                            </div>

                            <div class="profile-name">
                                <?php echo htmlspecialchars($displayName); ?>
                            </div>

                            <div class="profile-meta">
                                <?php echo htmlspecialchars($stokvel_name); ?><br>
                                Username: <strong><?php echo htmlspecialchars($username ?: "-"); ?></strong>
                                · Member Code: <strong><?php echo htmlspecialchars($member_code ?: "-"); ?></strong>
                            </div>
                        </div>
                    </div>
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

                <div class="profile-grid">
                    <div class="crypto-panel">
                        <div class="crypto-panel-heading">
                            <div class="crypto-panel-title">Editable Details</div>
                            <div class="crypto-panel-subtitle">
                                Update your name, email, phone, or password.
                            </div>
                        </div>

                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">First Name *</label>
                                    <input 
                                        type="text"
                                        name="first_name"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($first_name); ?>"
                                        required
                                    >
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Last Name *</label>
                                    <input 
                                        type="text"
                                        name="last_name"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($last_name); ?>"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input 
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($email); ?>"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input 
                                    type="text"
                                    name="phone"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($phone); ?>"
                                    required
                                >
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input 
                                    type="password"
                                    name="new_password"
                                    class="form-control"
                                    minlength="4"
                                    placeholder="Leave blank to keep current password"
                                >
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Confirm New Password</label>
                                <input 
                                    type="password"
                                    name="confirm_password"
                                    class="form-control"
                                    minlength="4"
                                    placeholder="Confirm only if changing password"
                                >
                            </div>

                            <button type="submit" class="btn btn-dark">
                                Save Profile Changes
                            </button>
                        </form>
                    </div>

                    <div>
                        <div class="crypto-panel side mb-4">
                            <div class="crypto-panel-heading <?php echo $isAuctionPackage ? "green" : ""; ?>">
                                <div class="crypto-panel-title">Account Details</div>
                                <div class="crypto-panel-subtitle">
                                    These details identify your membership.
                                </div>
                            </div>

                            <div class="readonly-box">
                                <div class="readonly-line">
                                    <div class="readonly-label">Username</div>
                                    <div class="readonly-value"><?php echo safeValue($username); ?></div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Member Code</div>
                                    <div class="readonly-value"><?php echo safeValue($member_code); ?></div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Status</div>
                                    <div class="readonly-value">
                                        <span class="status-pill">
                                            <?php echo htmlspecialchars(ucfirst($user["status"] ?? "-")); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Joined</div>
                                    <div class="readonly-value">
                                        <?php echo htmlspecialchars(displayDate($user["created_at"] ?? "")); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="crypto-panel side">
                            <div class="crypto-panel-heading <?php echo $isAuctionPackage ? "orange" : ""; ?>">
                                <div class="crypto-panel-title">Banking Details</div>
                                <div class="crypto-panel-subtitle">
                                    Banking details are locked for safety.
                                </div>
                            </div>

                            <?php if ((int)($user["banking_details_completed"] ?? 0) === 1): ?>
                                <div class="readonly-box">
                                    <div class="readonly-line">
                                        <div class="readonly-label">Bank</div>
                                        <div class="readonly-value"><?php echo safeValue($user["bank_name"] ?? ""); ?></div>
                                    </div>

                                    <div class="readonly-line">
                                        <div class="readonly-label">Account Holder</div>
                                        <div class="readonly-value"><?php echo safeValue($user["bank_account_holder"] ?? ""); ?></div>
                                    </div>

                                    <div class="readonly-line">
                                        <div class="readonly-label">Account Number</div>
                                        <div class="readonly-value">
                                            <?php echo maskAccountNumber($user["bank_account_number"] ?? ""); ?>
                                        </div>
                                    </div>

                                    <div class="readonly-line">
                                        <div class="readonly-label">Branch Code</div>
                                        <div class="readonly-value"><?php echo safeValue($user["bank_branch_code"] ?? ""); ?></div>
                                    </div>

                                    <div class="readonly-line">
                                        <div class="readonly-label">Account Type</div>
                                        <div class="readonly-value"><?php echo safeValue($user["bank_account_type"] ?? ""); ?></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    Banking details have not been completed yet.
                                </div>

                                <a href="banking_details.php" class="btn btn-dark">
                                    Add Banking Details
                                </a>
                            <?php endif; ?>

                            <div class="locked-note mt-3">
                                Banking details are used for payouts, seller payments, and admin records.
                                They are not editable from this profile page to protect your account records.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

</div>

</body>
</html>