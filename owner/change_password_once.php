<?php
session_start();
require_once "../config/db.php";

$error = "";
$success = "";

/*
|--------------------------------------------------------------------------
| ONE-TIME PASSWORD CHANGE PAGE
|--------------------------------------------------------------------------
| Use this page once, then delete it after changing the password.
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($email === "" || $new_password === "" || $confirm_password === "") {
        $error = "Please complete all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($new_password) < 4) {
        $error = "Password must be at least 4 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $checkStmt = $conn->prepare("
            SELECT id, full_name, email
            FROM system_owners
            WHERE email = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $owner = $checkStmt->get_result()->fetch_assoc();

        if (!$owner) {
            $error = "No system owner found with that email address.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $updateStmt = $conn->prepare("
                UPDATE system_owners
                SET password = ?
                WHERE id = ?
                LIMIT 1
            ");
            $updateStmt->bind_param("si", $hashed_password, $owner["id"]);

            if ($updateStmt->execute()) {
                $success = "Password changed successfully. You can now log in with your new password. Please delete this file now: owner/change_password_once.php";
            } else {
                $error = "Could not change password. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change System Owner Password</title>
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

        .reset-card {
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

        .reset-card::after {
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

        .reset-kicker {
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

        .reset-title {
            font-size: 32px;
            font-weight: 900;
            letter-spacing: -0.05em;
            color: #10241f;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .reset-text {
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

        .btn-outline-dark {
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 900;
        }
    </style>
</head>
<body>

<div class="reset-card">
    <div class="reset-kicker">One-Time Password Change</div>

    <h1 class="reset-title">Change Password</h1>

    <p class="reset-text">
        Use this page once to change the System Owner password. After the password is changed,
        delete this page for safety.
    </p>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>

        <a href="login.php" class="btn btn-owner">
            Go to Login
        </a>
    <?php else: ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">System Owner Email</label>
                <input 
                    type="email"
                    name="email"
                    class="form-control"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input 
                    type="password"
                    name="new_password"
                    class="form-control"
                    minlength="4"
                    required
                >
            </div>

            <div class="mb-4">
                <label class="form-label">Confirm New Password</label>
                <input 
                    type="password"
                    name="confirm_password"
                    class="form-control"
                    minlength="4"
                    required
                >
            </div>

            <button type="submit" class="btn-owner">
                Change Password Once
            </button>
        </form>

    <?php endif; ?>
</div>

</body>
</html>