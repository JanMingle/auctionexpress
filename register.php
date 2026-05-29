<?php
session_start();
require_once "config/db.php";

$error = "";

function generateTenantCode($conn, $stokvelName) {
    do {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $stokvelName));
        $prefix = substr($clean, 0, 4);

        if (strlen($prefix) < 2) {
            $prefix = "STKV";
        }

        $random = strtoupper(bin2hex(random_bytes(3)));
        $tenant_code = $prefix . "-" . $random;

        $stmt = $conn->prepare("SELECT id FROM tenants WHERE tenant_code = ? LIMIT 1");
        $stmt->bind_param("s", $tenant_code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
    } while ($exists);

    return $tenant_code;
}

function cleanNamePart($value) {
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-z0-9]/", "", $value);
    return $value ?: "owner";
}

function generateOwnerUsername($conn, $firstName) {
    $base = cleanNamePart($firstName);
    $username = $base . random_int(1000, 9999);

    while (true) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();

        if ($stmt->get_result()->num_rows === 0) {
            return $username;
        }

        $username = $base . random_int(1000, 9999);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $stokvel_name = trim($_POST["stokvel_name"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (
        empty($stokvel_name) ||
        empty($first_name) ||
        empty($last_name) ||
        empty($email) ||
        empty($phone) ||
        empty($password) ||
        empty($confirm_password)
    ) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 4) {
        $error = "Password must be at least 4 characters or digits.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $error = "This email address is already registered.";
        } else {
            $tenant_code = generateTenantCode($conn, $stokvel_name);
            $owner_username = generateOwnerUsername($conn, $first_name);
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $trial_ends_at = date("Y-m-d H:i:s", strtotime("+30 days"));
            $role = "owner";

            $conn->begin_transaction();

            try {
                $tenantStmt = $conn->prepare("
                    INSERT INTO tenants 
                    (stokvel_name, tenant_code, subscription_status, trial_ends_at) 
                    VALUES (?, ?, 'trial', ?)
                ");
                $tenantStmt->bind_param("sss", $stokvel_name, $tenant_code, $trial_ends_at);
                $tenantStmt->execute();

                $tenant_id = $conn->insert_id;

                $userStmt = $conn->prepare("
                    INSERT INTO users 
                    (
                        tenant_id,
                        first_name,
                        last_name,
                        email,
                        phone,
                        username,
                        member_code,
                        password,
                        role,
                        status
                    ) 
                    VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 'active')
                ");

                $userStmt->bind_param(
                    "isssssss",
                    $tenant_id,
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $owner_username,
                    $hashed_password,
                    $role
                );

                $userStmt->execute();

                $user_id = $conn->insert_id;

                $updateTenant = $conn->prepare("
                    UPDATE tenants 
                    SET owner_user_id = ? 
                    WHERE id = ?
                ");
                $updateTenant->bind_param("ii", $user_id, $tenant_id);
                $updateTenant->execute();

                $conn->commit();

                $_SESSION["user_id"] = $user_id;
                $_SESSION["tenant_id"] = $tenant_id;
                $_SESSION["role"] = $role;
                $_SESSION["name"] = $first_name . " " . $last_name;
                $_SESSION["stokvel_name"] = $stokvel_name;
                $_SESSION["username"] = $owner_username;
                $_SESSION["member_code"] = null;

                header("Location: admin/dashboard.php");
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Stokvel - Stokvel Circle</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        :root {
            --green: #0f6b4f;
            --green-dark: #073f2f;
            --gold: #d8a928;
            --cream: #fbf7ed;
            --ink: #10241f;
            --muted: #667085;
            --border: rgba(16, 36, 31, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(216, 169, 40, 0.22), transparent 30%),
                radial-gradient(circle at bottom right, rgba(15, 107, 79, 0.18), transparent 34%),
                linear-gradient(135deg, #fbf7ed 0%, #f5efe1 45%, #edf7f1 100%);
            color: var(--ink);
            overflow-x: hidden;
        }

        .money-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }

        .coin {
            position: absolute;
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            box-shadow: inset 0 0 0 5px rgba(255,255,255,0.25), 0 18px 35px rgba(16,36,31,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4a3504;
            font-weight: 900;
            font-size: 18px;
            opacity: 0.45;
            animation: floatCoin 9s ease-in-out infinite;
        }

        .coin:nth-child(1) { left: 7%; top: 14%; animation-delay: 0s; }
        .coin:nth-child(2) { right: 10%; top: 18%; width: 44px; height: 44px; font-size: 14px; animation-delay: 1.3s; }
        .coin:nth-child(3) { left: 14%; bottom: 14%; width: 48px; height: 48px; font-size: 15px; animation-delay: 2.1s; }
        .coin:nth-child(4) { right: 18%; bottom: 12%; animation-delay: 3.2s; }
        .coin:nth-child(5) { left: 50%; top: 7%; width: 36px; height: 36px; font-size: 12px; animation-delay: 4s; }

        .note {
            position: absolute;
            width: 82px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(15,107,79,0.9), rgba(7,63,47,0.9));
            box-shadow: 0 18px 35px rgba(16,36,31,0.14);
            opacity: 0.28;
            animation: floatNote 11s ease-in-out infinite;
        }

        .note::after {
            content: "R";
            position: absolute;
            inset: 9px 28px;
            border: 1px solid rgba(255,255,255,0.45);
            border-radius: 999px;
            color: rgba(255,255,255,0.8);
            font-weight: 800;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .note:nth-child(6) { left: 26%; top: 22%; transform: rotate(-14deg); animation-delay: 1s; }
        .note:nth-child(7) { right: 24%; top: 64%; transform: rotate(12deg); animation-delay: 2.6s; }
        .note:nth-child(8) { left: 58%; bottom: 18%; transform: rotate(-8deg); animation-delay: 3.7s; }

        @keyframes floatCoin {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-28px) rotate(14deg); }
        }

        @keyframes floatNote {
            0%, 100% { transform: translateY(0) rotate(-8deg); }
            50% { transform: translateY(24px) rotate(10deg); }
        }

        .auth-shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
        }

        .auth-card {
            width: 100%;
            max-width: 760px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(255,255,255,0.8);
            border-radius: 34px;
            padding: 34px;
            box-shadow: 0 30px 90px rgba(16,36,31,0.14);
            backdrop-filter: blur(18px);
        }

        .brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 13px;
            border-radius: 999px;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.28);
            color: #7a5a09;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .brand-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--gold);
            color: #3b2a05;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }

        .auth-title {
            font-size: 31px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            color: var(--ink);
        }

        .auth-subtitle {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 22px;
            max-width: 620px;
        }

        .section-title {
            font-size: 13px;
            font-weight: 900;
            color: var(--ink);
            margin: 18px 0 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--gold);
        }

        .form-label {
            font-size: 13px;
            font-weight: 800;
            color: #34433d;
            margin-bottom: 8px;
        }

        .form-control {
            border-radius: 18px;
            padding: 14px 16px;
            border: 1px solid var(--border);
            background: #ffffff;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px rgba(15,107,79,0.12);
        }

        .btn-stokvel {
            border: 0;
            border-radius: 18px;
            padding: 14px 18px;
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            color: white;
            font-weight: 900;
            box-shadow: 0 16px 30px rgba(15,107,79,0.24);
        }

        .btn-stokvel:hover {
            color: white;
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 18px;
            font-size: 14px;
        }

        .mini-note {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            font-size: 13px;
            color: var(--muted);
            text-align: center;
        }

        .auth-link {
            color: var(--green);
            font-weight: 800;
            text-decoration: none;
        }

        .auth-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 620px) {
            .auth-card {
                padding: 26px;
                border-radius: 28px;
            }

            .auth-title {
                font-size: 28px;
            }

            .coin, .note {
                opacity: 0.22;
            }
        }
    </style>
</head>
<body>

<div class="money-bg">
    <div class="coin">R</div>
    <div class="coin">R</div>
    <div class="coin">R</div>
    <div class="coin">R</div>
    <div class="coin">R</div>
    <div class="note"></div>
    <div class="note"></div>
    <div class="note"></div>
</div>

<div class="auth-shell">
    <div class="auth-card">

        <div class="brand-pill">
            <span class="brand-icon">S</span>
            Stokvel Circle
        </div>

        <h1 class="auth-title">Create your stokvel</h1>
        <p class="auth-subtitle">
            Start a private savings circle, invite members with your link, track deposits, returns, withdrawals, and group conversations.
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="section-title">Stokvel details</div>

            <div class="mb-3">
                <label class="form-label">Stokvel Name *</label>
                <input 
                    type="text" 
                    name="stokvel_name" 
                    class="form-control" 
                    placeholder="Example: Friends Wealth Circle"
                    required
                >
            </div>

            <div class="section-title">Owner details</div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">First Name *</label>
                    <input 
                        type="text" 
                        name="first_name" 
                        class="form-control" 
                        required
                    >
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Last Name *</label>
                    <input 
                        type="text" 
                        name="last_name" 
                        class="form-control" 
                        required
                    >
                </div>
            </div>

            <div class="row">
                <div class="col-md-7 mb-3">
                    <label class="form-label">Email Address *</label>
                    <input 
                        type="email" 
                        name="email" 
                        class="form-control"
                        placeholder="owner@example.com"
                        required
                    >
                </div>

                <div class="col-md-5 mb-3">
                    <label class="form-label">Phone Number *</label>
                    <input 
                        type="text" 
                        name="phone" 
                        class="form-control"
                        placeholder="0712345678"
                        required
                    >
                </div>
            </div>

            <div class="section-title">Security</div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Password *</label>
                    <input 
                        type="password" 
                        name="password" 
                        class="form-control" 
                        minlength="4"
                        placeholder="Minimum 4 characters"
                        required
                    >
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Confirm Password *</label>
                    <input 
                        type="password" 
                        name="confirm_password" 
                        class="form-control" 
                        minlength="4"
                        required
                    >
                </div>
            </div>

            <button type="submit" class="btn btn-stokvel w-100 mt-2">
                Create Stokvel Account
            </button>

            <div class="mini-note">
                Already have an account?
                <a href="login.php" class="auth-link">Login here</a>
            </div>

        </form>

    </div>
</div>

</body>
</html>