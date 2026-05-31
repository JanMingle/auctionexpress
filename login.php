<?php
session_start();
require_once "config/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login_identifier = trim($_POST["login_identifier"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($login_identifier) || empty($password)) {
        $error = "Please enter your username/member code and password.";
    } else {
        $stmt = $conn->prepare("
            SELECT 
                users.id,
                users.tenant_id,
                users.first_name,
                users.last_name,
                users.email,
                users.phone,
                users.username,
                users.member_code,
                users.password,
                users.role,
               users.status,
users.banking_details_completed,
users.bank_name,
users.bank_account_holder,
users.bank_account_number,
users.bank_branch_code,
users.bank_account_type,
tenants.stokvel_name,
tenants.subscription_status
            FROM users
            INNER JOIN tenants ON tenants.id = users.tenant_id
            WHERE users.email = ?
               OR users.username = ?
               OR users.member_code = ?
            LIMIT 1
        ");
        $stmt->bind_param("sss", $login_identifier, $login_identifier, $login_identifier);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user["status"] === "pending") {
                $error = "Your account is waiting for admin approval.";
            } elseif ($user["status"] === "suspended") {
                $error = "Your account has been suspended.";
            } elseif ($user["status"] !== "active") {
                $error = "Your account is not active.";
            } elseif ($user["subscription_status"] === "cancelled" || $user["subscription_status"] === "suspended") {
                $error = "This stokvel account is currently not active.";
            } elseif (password_verify($password, $user["password"])) {
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["tenant_id"] = $user["tenant_id"];
                $_SESSION["role"] = $user["role"];
                $_SESSION["name"] = $user["first_name"] . " " . $user["last_name"];
                $_SESSION["stokvel_name"] = $user["stokvel_name"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["member_code"] = $user["member_code"];

            if ($user["role"] === "owner" || $user["role"] === "admin") {
    header("Location: admin/dashboard.php");
    exit;
} else {
    $bankingComplete = (int)($user["banking_details_completed"] ?? 0) === 1;

    if (!$bankingComplete) {
        header("Location: users/banking_details.php");
        exit;
    }

    header("Location: users/dashboard.php");
    exit;
}
            } else {
                $error = "Incorrect login details.";
            }
        } else {
            $error = "Incorrect login details.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Stokvel Circle</title>
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
            max-width: 470px;
            background: rgba(255, 255, 255, 0.9);
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
        }

        .login-help {
            background: #f8fbf7;
            border: 1px dashed rgba(15,107,79,0.22);
            color: #35544a;
            border-radius: 18px;
            padding: 14px 16px;
            font-size: 13px;
            margin-bottom: 18px;
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

        @media (max-width: 520px) {
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

        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">
            Login to manage your savings, returns, withdrawals, and group chat.
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

       

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Username </label>
                <input 
                    type="text" 
                    name="login_identifier" 
                    class="form-control" 
                    placeholder="Example: AB12345"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input 
                    type="password" 
                    name="password" 
                    class="form-control" 
                    placeholder="Enter your password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-stokvel w-100">
                Login 
            </button>

           
        </form>

    </div>
</div>

</body>
</html>