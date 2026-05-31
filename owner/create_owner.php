<?php
session_start();
require_once "../config/db.php";

$error = "";
$success = "";

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM system_owners");
$countStmt->execute();
$countData = $countStmt->get_result()->fetch_assoc();
$totalOwners = (int)($countData["total"] ?? 0);

if ($totalOwners > 0) {
    die("System owner already exists. Please delete this file: owner/create_owner.php");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "" || $email === "" || $password === "" || $confirm_password === "") {
        $error = "Please complete all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 4) {
        $error = "Password must be at least 4 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $status = "active";

        $stmt = $conn->prepare("
            INSERT INTO system_owners
            (
                full_name,
                email,
                password,
                status
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssss",
            $full_name,
            $email,
            $hashed_password,
            $status
        );

        if ($stmt->execute()) {
            $success = "System owner created successfully. You can now log in.";
        } else {
            $error = "Could not create system owner.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create System Owner</title>
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

        .setup-card {
            width: 100%;
            max-width: 520px;
            background: rgba(255,255,255,0.94);
            border-radius: 30px;
            padding: 30px;
            box-shadow: 0 30px 90px rgba(16,36,31,0.18);
        }

        .setup-title {
            font-size: 32px;
            font-weight: 900;
            letter-spacing: -0.05em;
            color: #10241f;
            margin-bottom: 8px;
        }

        .setup-text {
            color: #667085;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .form-control {
            border-radius: 14px;
            padding: 13px 14px;
            font-size: 16px;
        }

        .btn-dark {
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 900;
        }
    </style>
</head>
<body>

<div class="setup-card">
    <h1 class="setup-title">Create System Owner</h1>

    <p class="setup-text">
        This creates the main platform owner account. After creating it, delete this file.
    </p>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>

        <a href="login.php" class="btn btn-dark w-100">
            Go to System Owner Login
        </a>
    <?php else: ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" minlength="4" required>
            </div>

            <div class="mb-4">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" minlength="4" required>
            </div>

            <button type="submit" class="btn btn-dark w-100">
                Create System Owner
            </button>
        </form>

    <?php endif; ?>
</div>

</body>
</html>