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
$name = $_SESSION["name"] ?? "Member";
$username = $_SESSION["username"] ?? "";
$member_code = $_SESSION["member_code"] ?? "";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";

$displayName = $username ?: ($member_code ?: $name);

$error = "";

$stmt = $conn->prepare("
    SELECT 
        bank_name,
        bank_account_holder,
        bank_account_number,
        bank_branch_code,
        bank_account_type,
        banking_details_completed
    FROM users
    WHERE id = ?
    AND tenant_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $user_id, $tenant_id);
$stmt->execute();
$bank = $stmt->get_result()->fetch_assoc();

$bank_name = $bank["bank_name"] ?? "";
$bank_account_holder = $bank["bank_account_holder"] ?? "";
$bank_account_number = $bank["bank_account_number"] ?? "";
$bank_branch_code = $bank["bank_branch_code"] ?? "";
$bank_account_type = $bank["bank_account_type"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $bank_name = trim($_POST["bank_name"] ?? "");
    $bank_account_holder = trim($_POST["bank_account_holder"] ?? "");
    $bank_account_number = trim($_POST["bank_account_number"] ?? "");
    $bank_branch_code = trim($_POST["bank_branch_code"] ?? "");
    $bank_account_type = trim($_POST["bank_account_type"] ?? "");

    if (
        $bank_name === "" ||
        $bank_account_holder === "" ||
        $bank_account_number === "" ||
        $bank_branch_code === "" ||
        $bank_account_type === ""
    ) {
        $error = "Please complete all banking details before continuing.";
    } else {
        $updateStmt = $conn->prepare("
            UPDATE users
            SET
                bank_name = ?,
                bank_account_holder = ?,
                bank_account_number = ?,
                bank_branch_code = ?,
                bank_account_type = ?,
                banking_details_completed = 1
            WHERE id = ?
            AND tenant_id = ?
        ");

        $updateStmt->bind_param(
            "sssssii",
            $bank_name,
            $bank_account_holder,
            $bank_account_number,
            $bank_branch_code,
            $bank_account_type,
            $user_id,
            $tenant_id
        );

        if ($updateStmt->execute()) {
            header("Location: dashboard.php?banking=saved");
            exit;
        } else {
            $error = "Could not save banking details. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Banking Details</title>
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
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(216, 169, 40, 0.34), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(15, 107, 79, 0.28), transparent 32%),
                linear-gradient(135deg, #fff4c7 0%, #fbf7ed 36%, #e7f7ef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            color: var(--ink);
        }

        .banking-shell {
            width: 100%;
            max-width: 640px;
            position: relative;
        }

        .banking-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.26), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,0.96), rgba(232,247,239,0.94));
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: 34px;
            padding: 30px;
            box-shadow: 0 30px 90px rgba(16,36,31,0.18);
            position: relative;
            overflow: hidden;
        }

        .banking-card::after {
            content: "R";
            position: absolute;
            right: 28px;
            top: 24px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: rgba(74,53,4,0.40);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 900;
            opacity: 0.20;
            transform: rotate(-12deg);
        }

        .banking-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .banking-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
        }

        .banking-text {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
            margin-bottom: 22px;
            position: relative;
            z-index: 2;
        }

        .form-label {
            font-size: 13px;
            font-weight: 800;
            color: #34433d;
        }

        .form-control,
        .form-select {
            border-radius: 15px;
            padding: 13px 14px;
            font-size: 16px;
            border: 1px solid rgba(16,36,31,0.14);
        }

        .form-control:focus,
        .form-select:focus {
            box-shadow: none;
            border-color: var(--green);
        }

        .btn-save {
            width: 100%;
            border: 0;
            border-radius: 16px;
            padding: 13px 16px;
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            color: #ffffff;
            font-weight: 900;
        }

        .safe-note {
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(16,36,31,0.08);
            border-radius: 18px;
            padding: 13px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
            margin-bottom: 18px;
        }

        @media (max-width: 560px) {
            .banking-card {
                border-radius: 26px;
                padding: 24px;
            }

            .banking-title {
                font-size: 28px;
            }

            .banking-card::after {
                width: 70px;
                height: 70px;
                font-size: 32px;
            }
        }
    </style>
</head>
<body>

<div class="banking-shell">
    <div class="banking-card">
        <div class="banking-kicker">
            <?php echo htmlspecialchars($stokvel_name); ?>
        </div>

        <h1 class="banking-title">
            Add your banking details
        </h1>

        <p class="banking-text">
            Hello, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
            Before you continue to your dashboard, please add the banking details where your approved withdrawals or contributions can be tracked by the stokvel admin.
        </p>

        <div class="safe-note">
            These details will only be visible to the stokvel owner/admin for payout and record keeping.
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Bank Name *</label>
                <input 
                    type="text"
                    name="bank_name"
                    class="form-control"
                    value="<?php echo htmlspecialchars($bank_name); ?>"
                    placeholder="Example: FNB, Capitec, Standard Bank"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Account Holder *</label>
                <input 
                    type="text"
                    name="bank_account_holder"
                    class="form-control"
                    value="<?php echo htmlspecialchars($bank_account_holder); ?>"
                    placeholder="Name on the bank account"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Account Number *</label>
                <input 
                    type="text"
                    name="bank_account_number"
                    class="form-control"
                    inputmode="numeric"
                    value="<?php echo htmlspecialchars($bank_account_number); ?>"
                    placeholder="Enter account number"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Branch Code *</label>
                <input 
                    type="text"
                    name="bank_branch_code"
                    class="form-control"
                    inputmode="numeric"
                    value="<?php echo htmlspecialchars($bank_branch_code); ?>"
                    placeholder="Example: 470010"
                    required
                >
            </div>

            <div class="mb-4">
                <label class="form-label">Account Type *</label>
                <select name="bank_account_type" class="form-select" required>
                    <option value="">Select account type</option>
                    <option value="Savings" <?php echo $bank_account_type === "Savings" ? "selected" : ""; ?>>Savings</option>
                    <option value="Current" <?php echo $bank_account_type === "Current" ? "selected" : ""; ?>>Current</option>
                    <option value="Cheque" <?php echo $bank_account_type === "Cheque" ? "selected" : ""; ?>>Cheque</option>
                    <option value="Transmission" <?php echo $bank_account_type === "Transmission" ? "selected" : ""; ?>>Transmission</option>
                </select>
            </div>

            <button type="submit" class="btn-save">
                Save Banking Details and Continue
            </button>
        </form>
    </div>
</div>

</body>
</html>