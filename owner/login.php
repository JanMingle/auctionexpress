<?php
session_start();
require_once "../config/db.php";

if (isset($_SESSION["system_owner_id"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("
            SELECT 
                id,
                full_name,
                email,
                password,
                status
            FROM system_owners
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc();

        if (!$owner || !password_verify($password, $owner["password"])) {
            $error = "Invalid email or password.";
        } elseif ($owner["status"] !== "active") {
            $error = "Your system owner account is suspended.";
        } else {
            $_SESSION["system_owner_id"] = (int)$owner["id"];
            $_SESSION["system_owner_name"] = $owner["full_name"];
            $_SESSION["system_owner_email"] = $owner["email"];

            header("Location: dashboard.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Owner Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        body {
            min-height: 100vh;
            margin: 0;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(216, 169, 40, 0.34), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(15, 107, 79, 0.28), transparent 32%),
                linear-gradient(135deg, #fff4c7 0%, #fbf7ed 36%, #e7f7ef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }

        .login-card {
            width: 100%;
            max-width: 500px;
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.24), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,0.96), rgba(232,247,239,0.94));
            border-radius: 32px;
            padding: 32px;
            box-shadow: 0 30px 90px rgba(16,36,31,0.18);
            position: relative;
            overflow: hidden;
        }

        .login-card::after {
            content: "R";
            position: absolute;
            right: 26px;
            top: 24px;
            width: 86px;
            height: 86px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: rgba(74,53,4,0.40);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 900;
            opacity: 0.20;
            transform: rotate(-12deg);
        }

        .login-kicker {
            display: inline-flex;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.35);
            color: #7a5a09;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 16px;
            position: relative;
            z-index: 2;
        }

        .login-title {
            font-size: 34px;
            font-weight: 900;
            letter-spacing: -0.05em;
            color: #10241f;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .login-text {
            color: #667085;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
            position: relative;
            z-index: 2;
        }

        .form-control {
            border-radius: 14px;
            padding: 13px 14px;
            font-size: 16px;
        }

        .btn-owner {
            width: 100%;
            border: 0;
            border-radius: 14px;
            padding: 13px 16px;
            background: linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            font-weight: 900;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-kicker">Platform Control</div>

    <h1 class="login-title">System Owner Login</h1>

    <p class="login-text">
        Log in to manage tenants, packages, subscriptions, and platform-level settings.
    </p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input 
                type="email"
                name="email"
                class="form-control"
                required
            >
        </div>

        <div class="mb-4">
            <label class="form-label">Password</label>
            <input 
                type="password"
                name="password"
                class="form-control"
                required
            >
        </div>

        <button type="submit" class="btn-owner">
            Login
        </button>
    </form>
</div>

</body>
</html>