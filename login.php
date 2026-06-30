<?php
session_start();
require_once "config/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login_identifier = trim($_POST["login_identifier"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($login_identifier === "" || $password === "") {
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

            if (($user["status"] ?? "") === "pending") {
                $error = "Your account is waiting for admin approval.";
            } elseif (($user["status"] ?? "") === "suspended") {
                $error = "Your account has been suspended.";
            } elseif (($user["status"] ?? "") !== "active") {
                $error = "Your account is not active.";
            } elseif (
                ($user["subscription_status"] ?? "") === "cancelled" ||
                ($user["subscription_status"] ?? "") === "suspended"
            ) {
                $error = "This account is currently not active.";
            } elseif (password_verify($password, $user["password"])) {
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["tenant_id"] = $user["tenant_id"];
                $_SESSION["role"] = $user["role"];
                $_SESSION["name"] = trim($user["first_name"] . " " . $user["last_name"]);
                $_SESSION["stokvel_name"] = $user["stokvel_name"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["member_code"] = $user["member_code"];

                if ($user["role"] === "owner" || $user["role"] === "admin") {
                    header("Location: admin/dashboard.php");
                    exit;
                }

                $bankingComplete = (int)($user["banking_details_completed"] ?? 0) === 1;

                if (!$bankingComplete) {
                    header("Location: users/banking_details.php");
                    exit;
                }

                header("Location: users/dashboard.php");
                exit;
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
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at 20% 0%, rgba(69, 90, 145, 0.18), transparent 34%),
                radial-gradient(circle at 90% 10%, rgba(168, 59, 216, 0.10), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 52%, #0b1424 100%);
            color: rgba(255,255,255,0.82);
            font-size: 12px;
            overflow-x: hidden;
        }

        .login-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px 14px;
        }

        .login-card {
            width: 100%;
            max-width: 360px;
            background: rgba(25, 39, 64, 0.88);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 6px;
            padding: 22px;
            box-shadow: 0 24px 46px rgba(0,0,0,0.22);
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #a83bd8, #11a7d8);
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 7px;
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 14px 24px rgba(168,59,216,0.18);
        }

        .brand-title {
            font-size: 13px;
            font-weight: 800;
            color: rgba(255,255,255,0.82);
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 10px;
            color: rgba(255,255,255,0.38);
            line-height: 1.2;
            margin-top: 2px;
        }

        .login-title {
            font-size: 22px;
            font-weight: 300;
            color: rgba(255,255,255,0.72);
            margin-bottom: 6px;
        }

        .login-text {
            color: rgba(255,255,255,0.36);
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .form-label {
            color: rgba(255,255,255,0.58);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-control {
            background: rgba(13,24,41,0.72);
            border: 1px solid rgba(255,255,255,0.10);
            color: rgba(255,255,255,0.86);
            border-radius: 5px;
            padding: 11px 12px;
            font-size: 12px;
        }

        .form-control::placeholder {
            color: rgba(255,255,255,0.30);
        }

        .form-control:focus {
            background: rgba(13,24,41,0.82);
            color: #ffffff;
            border-color: rgba(168,59,216,0.70);
            box-shadow: 0 0 0 4px rgba(168,59,216,0.12);
        }

        .btn-login {
            width: 100%;
            border: 0;
            border-radius: 999px;
            padding: 11px 18px;
            background: linear-gradient(135deg, #16a085, #1abc9c);
            color: #ffffff;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            box-shadow: 0 14px 24px rgba(26,188,156,0.16);
        }

        .btn-login:hover {
            color: #ffffff;
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 5px;
            font-size: 12px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .login-note {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.34);
            font-size: 10px;
            line-height: 1.5;
            text-align: center;
        }

        .small-accent {
            height: 1px;
            width: 100%;
            background: linear-gradient(90deg, transparent, rgba(168,59,216,0.7), transparent);
            margin: 16px 0;
        }

        @media (max-width: 520px) {
            .login-card {
                max-width: 100%;
                padding: 20px;
            }

            .login-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

<div class="login-shell">
    <div class="login-card">

        <div class="brand-row">
            <div class="brand-mark">A</div>

            <div>
                <div class="brand-title">
                    Auction Express
                </div>
                <div class="brand-subtitle">
                    Secure member access
                </div>
            </div>
        </div>

        <div class="login-title">
            Login
        </div>

        <div class="login-text">
            Enter your username, email, or member code to continue.
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Username / Email / Member Code</label>
                <input 
                    type="text" 
                    name="login_identifier" 
                    class="form-control" 
                    placeholder="Example: AB12345"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input 
                    type="password" 
                    name="password" 
                    class="form-control" 
                    placeholder="Enter password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn-login">
                Login
            </button>
        </form>

        <div class="small-accent"></div>

        <div class="login-note">
            Access is only available to approved members and active accounts.
        </div>

    </div>
</div>

</body>
</html>