<?php
session_start();
require_once "../config/db.php";

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

        .profile-hero {
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

        .profile-hero::after {
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

        .profile-kicker {
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

        .profile-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .profile-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 720px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .profile-card {
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

        .locked-note {
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(16,36,31,0.08);
            border-radius: 16px;
            padding: 12px;
            color: #667085;
            font-size: 13px;
            line-height: 1.55;
        }

        @media (max-width: 900px) {
            .profile-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .profile-title {
                font-size: 27px;
            }

            .profile-hero::after {
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
                    Manage your personal details and view your banking details.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="profile-hero">
                <div class="profile-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="profile-title">
                    Your member profile
                </div>

                <p class="profile-text">
                    Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    You can update your personal contact details here. Your username, member code,
                    and banking details are locked for record keeping.
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

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card-box profile-card">
                        <div class="section-title">Editable Details</div>
                        <p class="section-subtitle">
                            Update your name, email, phone, or password.
                        </p>

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
                </div>

                <div class="col-lg-5">
                    <div class="card-box bank-card mb-4">
                        <div class="section-title">Locked Login Details</div>
                        <p class="section-subtitle">
                            These details identify your stokvel membership and cannot be changed here.
                        </p>

                        <div class="readonly-box">
                            <div class="readonly-line">
                                <div class="readonly-label">Username</div>
                                <div class="readonly-value"><?php echo htmlspecialchars($username ?: "-"); ?></div>
                            </div>

                            <div class="readonly-line">
                                <div class="readonly-label">Member Code</div>
                                <div class="readonly-value"><?php echo htmlspecialchars($member_code ?: "-"); ?></div>
                            </div>

                            <div class="readonly-line">
                                <div class="readonly-label">Status</div>
                                <div class="readonly-value"><?php echo htmlspecialchars(ucfirst($user["status"] ?? "-")); ?></div>
                            </div>

                            <div class="readonly-line">
                                <div class="readonly-label">Joined</div>
                                <div class="readonly-value">
                                    <?php echo !empty($user["created_at"]) ? date("d M Y", strtotime($user["created_at"])) : "-"; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-box bank-card">
                        <div class="section-title">Banking Details</div>
                        <p class="section-subtitle">
                            Banking details are locked. Contact your stokvel admin if these details must be corrected.
                        </p>

                        <?php if ((int)($user["banking_details_completed"] ?? 0) === 1): ?>
                            <div class="readonly-box">
                                <div class="readonly-line">
                                    <div class="readonly-label">Bank</div>
                                    <div class="readonly-value"><?php echo htmlspecialchars($user["bank_name"] ?: "-"); ?></div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Account Holder</div>
                                    <div class="readonly-value"><?php echo htmlspecialchars($user["bank_account_holder"] ?: "-"); ?></div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Account Number</div>
                                    <div class="readonly-value"><?php echo maskAccountNumber($user["bank_account_number"] ?? ""); ?></div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Branch Code</div>
                                    <div class="readonly-value"><?php echo htmlspecialchars($user["bank_branch_code"] ?: "-"); ?></div>
                                </div>

                                <div class="readonly-line">
                                    <div class="readonly-label">Account Type</div>
                                    <div class="readonly-value"><?php echo htmlspecialchars($user["bank_account_type"] ?: "-"); ?></div>
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
                            Banking details are used for payout and admin records.
                            They are not editable from this profile page to protect stokvel records.
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

</div>

</body>
</html>