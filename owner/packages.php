<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["system_owner_id"])) {
    header("Location: login.php");
    exit;
}

$system_owner_name = $_SESSION["system_owner_name"] ?? "System Owner";

$success = "";
$error = "";

$edit_id = (int)($_GET["edit"] ?? 0);
$editingPackage = null;

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function checkedValue($key) {
    return isset($_POST[$key]) ? 1 : 0;
}

function packageStatusBadge($status) {
    if ($status === "active") {
        return '<span class="badge badge-approved">Active</span>';
    }

    if ($status === "inactive") {
        return '<span class="badge badge-rejected">Inactive</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
}

function yesNoBadge($value) {
    if ((int)$value === 1) {
        return '<span class="badge badge-approved">Yes</span>';
    }

    return '<span class="badge badge-rejected">No</span>';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "save_package") {
        $package_id = (int)($_POST["package_id"] ?? 0);

        $package_name = trim($_POST["package_name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $monthly_price = trim($_POST["monthly_price"] ?? "0");

        $minimum_saving_amount = trim($_POST["minimum_saving_amount"] ?? "0");
        $admin_fee_amount = trim($_POST["admin_fee_amount"] ?? "0");
        $return_rate_percent = trim($_POST["return_rate_percent"] ?? "0");
$return_calculation_type = $_POST["return_calculation_type"] ?? "once_off";
$daily_return_percent = trim($_POST["daily_return_percent"] ?? "0");
$maturity_days = trim($_POST["maturity_days"] ?? "0");
$withdraw_after_days = trim($_POST["withdraw_after_days"] ?? "0");
$show_daily_returns = checkedValue("show_daily_returns");

        $recruitment_bonus_percent = trim($_POST["recruitment_bonus_percent"] ?? "0");
        $bonus_claim_minimum = trim($_POST["bonus_claim_minimum"] ?? "0");

        $enable_referrals = checkedValue("enable_referrals");
        $enable_bonus_claims = checkedValue("enable_bonus_claims");
        $enable_group_chat = checkedValue("enable_group_chat");
        $enable_guest_chat = checkedValue("enable_guest_chat");
        $require_banking_details = checkedValue("require_banking_details");
        $require_proof_of_payment = checkedValue("require_proof_of_payment");

        $status = $_POST["status"] ?? "active";
        $allowed_statuses = ["active", "inactive"];

        if ($package_name === "") {
            $error = "Please enter a package name.";
        } elseif (!is_numeric($monthly_price) || (float)$monthly_price < 0) {
            $error = "Please enter a valid monthly price.";
        } elseif (!is_numeric($minimum_saving_amount) || (float)$minimum_saving_amount < 0) {
            $error = "Please enter a valid minimum saving amount.";
        } elseif (!is_numeric($admin_fee_amount) || (float)$admin_fee_amount < 0) {
            $error = "Please enter a valid admin fee.";
       } elseif (!is_numeric($return_rate_percent) || (float)$return_rate_percent < 0) {
    $error = "Please enter a valid once-off return percentage.";
} elseif (!in_array($return_calculation_type, ["once_off", "daily_simple", "daily_compound"], true)) {
    $error = "Please select a valid return calculation type.";
} elseif (!is_numeric($daily_return_percent) || (float)$daily_return_percent < 0) {
    $error = "Please enter a valid daily return percentage.";
} elseif (!is_numeric($maturity_days) || (int)$maturity_days <= 0) {
    $error = "Please enter valid maturity days.";
} elseif (!is_numeric($withdraw_after_days) || (int)$withdraw_after_days < 0) {
    $error = "Please enter valid withdrawal availability days.";
} elseif ((int)$withdraw_after_days > (int)$maturity_days) {
    $error = "Withdraw after days cannot be greater than maturity days.";
        } elseif (!is_numeric($recruitment_bonus_percent) || (float)$recruitment_bonus_percent < 0) {
            $error = "Please enter a valid recruitment bonus percentage.";
        } elseif (!is_numeric($bonus_claim_minimum) || (float)$bonus_claim_minimum < 0) {
            $error = "Please enter a valid bonus claim minimum.";
        } elseif (!in_array($status, $allowed_statuses, true)) {
            $error = "Invalid package status selected.";
        } else {
            $monthly_price = (float)$monthly_price;
            $minimum_saving_amount = (float)$minimum_saving_amount;
            $admin_fee_amount = (float)$admin_fee_amount;
            $return_rate_percent = (float)$return_rate_percent;
$daily_return_percent = (float)$daily_return_percent;
$maturity_days = (int)$maturity_days;
$withdraw_after_days = (int)$withdraw_after_days;
            $recruitment_bonus_percent = (float)$recruitment_bonus_percent;
            $bonus_claim_minimum = (float)$bonus_claim_minimum;

            $duplicateSql = "
                SELECT id
                FROM packages
                WHERE package_name = ?
            ";

            if ($package_id > 0) {
                $duplicateSql .= " AND id <> ?";
                $duplicateStmt = $conn->prepare($duplicateSql);
                $duplicateStmt->bind_param("si", $package_name, $package_id);
            } else {
                $duplicateStmt = $conn->prepare($duplicateSql);
                $duplicateStmt->bind_param("s", $package_name);
            }

            $duplicateStmt->execute();
            $duplicate = $duplicateStmt->get_result()->fetch_assoc();

            if ($duplicate) {
                $error = "A package with this name already exists.";
            } else {
                if ($package_id > 0) {
                    $stmt = $conn->prepare("
                        UPDATE packages
                        SET
                            package_name = ?,
                            description = ?,
                            monthly_price = ?,
                            minimum_saving_amount = ?,
                            admin_fee_amount = ?,
                           return_rate_percent = ?,
return_calculation_type = ?,
daily_return_percent = ?,
maturity_days = ?,
withdraw_after_days = ?,
show_daily_returns = ?,
                            recruitment_bonus_percent = ?,
                            bonus_claim_minimum = ?,
                            enable_referrals = ?,
                            enable_bonus_claims = ?,
                            enable_group_chat = ?,
                            enable_guest_chat = ?,
                            require_banking_details = ?,
                            require_proof_of_payment = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");

         $stmt->bind_param(
    "ssddddsdiidiiiiiisi",
    $package_name,
    $description,
    $monthly_price,
    $minimum_saving_amount,
    $admin_fee_amount,
    $return_rate_percent,
    $return_calculation_type,
    $daily_return_percent,
    $maturity_days,
    $withdraw_after_days,
    $show_daily_returns,
    $recruitment_bonus_percent,
    $bonus_claim_minimum,
    $enable_referrals,
    $enable_bonus_claims,
    $enable_group_chat,
    $enable_guest_chat,
    $require_banking_details,
    $require_proof_of_payment,
    $status,
    $package_id
);

                    if ($stmt->execute()) {
                        $success = "Package updated successfully.";
                        $edit_id = 0;
                    } else {
                        $error = "Could not update package.";
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO packages
                        (
                            package_name,
                            description,
                            monthly_price,
                            minimum_saving_amount,
                            admin_fee_amount,
                           return_rate_percent,
return_calculation_type,
daily_return_percent,
maturity_days,
withdraw_after_days,
show_daily_returns,
                            recruitment_bonus_percent,
                            bonus_claim_minimum,
                            enable_referrals,
                            enable_bonus_claims,
                            enable_group_chat,
                            enable_guest_chat,
                            require_banking_details,
                            require_proof_of_payment,
                            status
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

            $stmt->bind_param(
    "ssddddsdiidiiiiiis",
    $package_name,
    $description,
    $monthly_price,
    $minimum_saving_amount,
    $admin_fee_amount,
    $return_rate_percent,
    $return_calculation_type,
    $daily_return_percent,
    $maturity_days,
    $withdraw_after_days,
    $show_daily_returns,
    $recruitment_bonus_percent,
    $bonus_claim_minimum,
    $enable_referrals,
    $enable_bonus_claims,
    $enable_group_chat,
    $enable_guest_chat,
    $require_banking_details,
    $require_proof_of_payment,
    $status
);

                    if ($stmt->execute()) {
                        $success = "Package created successfully.";
                    } else {
                        $error = "Could not create package.";
                    }
                }
            }
        }
    }

    if ($action === "toggle_status") {
        $package_id = (int)($_POST["package_id"] ?? 0);
        $new_status = $_POST["new_status"] ?? "";

        if ($package_id <= 0 || !in_array($new_status, ["active", "inactive"], true)) {
            $error = "Invalid package status update.";
        } else {
            $stmt = $conn->prepare("
                UPDATE packages
                SET status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("si", $new_status, $package_id);

            if ($stmt->execute()) {
                $success = "Package status updated.";
            } else {
                $error = "Could not update package status.";
            }
        }
    }
}

if ($edit_id > 0) {
    $editStmt = $conn->prepare("
        SELECT *
        FROM packages
        WHERE id = ?
        LIMIT 1
    ");
    $editStmt->bind_param("i", $edit_id);
    $editStmt->execute();
    $editingPackage = $editStmt->get_result()->fetch_assoc();

    if (!$editingPackage) {
        $edit_id = 0;
    }
}

$packagesStmt = $conn->prepare("
    SELECT *
    FROM packages
    ORDER BY 
        CASE WHEN status = 'active' THEN 1 ELSE 2 END,
        created_at DESC
");
$packagesStmt->execute();
$packages = $packagesStmt->get_result();

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_packages,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_packages,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_packages,
        AVG(monthly_price) AS average_price
    FROM packages
");
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_packages = (int)($stats["total_packages"] ?? 0);
$active_packages = (int)($stats["active_packages"] ?? 0);
$inactive_packages = (int)($stats["inactive_packages"] ?? 0);
$average_price = (float)($stats["average_price"] ?? 0);

$formPackage = [
    "id" => $editingPackage["id"] ?? 0,
    "package_name" => $editingPackage["package_name"] ?? "",
    "description" => $editingPackage["description"] ?? "",
    "monthly_price" => $editingPackage["monthly_price"] ?? "450.00",
    "minimum_saving_amount" => $editingPackage["minimum_saving_amount"] ?? "200.00",
    "admin_fee_amount" => $editingPackage["admin_fee_amount"] ?? "20.00",
    "return_rate_percent" => $editingPackage["return_rate_percent"] ?? "10.00",
"return_calculation_type" => $editingPackage["return_calculation_type"] ?? "once_off",
"daily_return_percent" => $editingPackage["daily_return_percent"] ?? "0.00",
"maturity_days" => $editingPackage["maturity_days"] ?? "30",
"withdraw_after_days" => $editingPackage["withdraw_after_days"] ?? "30",
"show_daily_returns" => $editingPackage["show_daily_returns"] ?? 0,
    "recruitment_bonus_percent" => $editingPackage["recruitment_bonus_percent"] ?? "0.00",
    "bonus_claim_minimum" => $editingPackage["bonus_claim_minimum"] ?? "100.00",
    "enable_referrals" => $editingPackage["enable_referrals"] ?? 0,
    "enable_bonus_claims" => $editingPackage["enable_bonus_claims"] ?? 0,
    "enable_group_chat" => $editingPackage["enable_group_chat"] ?? 1,
    "enable_guest_chat" => $editingPackage["enable_guest_chat"] ?? 0,
    "require_banking_details" => $editingPackage["require_banking_details"] ?? 1,
    "require_proof_of_payment" => $editingPackage["require_proof_of_payment"] ?? 1,
    "status" => $editingPackage["status"] ?? "active"
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Packages</title>
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

        .owner-shell {
            min-height: 100vh;
        }

        .owner-topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(251, 247, 237, 0.82);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(16,36,31,0.08);
            padding: 16px 20px;
        }

        .owner-topbar-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
        }

        .owner-brand {
            font-weight: 900;
            color: #10241f;
            font-size: 18px;
        }

        .owner-nav {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .owner-nav a {
            color: #10241f;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            padding: 9px 12px;
            border-radius: 12px;
        }

        .owner-nav a.active,
        .owner-nav a:hover {
            background: #ffffff;
        }

        .owner-content {
            max-width: 1180px;
            margin: 0 auto;
            padding: 30px 18px;
        }

        .owner-hero {
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

        .owner-hero::after {
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

        .owner-kicker {
            display: inline-flex;
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

        .owner-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .owner-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 760px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .owner-card {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.24), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,0.94), rgba(232,247,239,0.9));
            border: 1px solid rgba(255,255,255,0.88);
            border-radius: 26px;
            padding: 24px;
            box-shadow: 0 22px 55px rgba(16,36,31,0.12);
        }

        .owner-card-green {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
        }

        .owner-card-gold {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.30), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #fff1b8 100%) !important;
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

        .switch-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .switch-box {
            background: rgba(255,255,255,0.76);
            border: 1px solid rgba(16,36,31,0.08);
            border-radius: 16px;
            padding: 10px 12px;
        }

        .switch-box label {
            font-size: 13px;
            font-weight: 800;
            color: #10241f;
            display: flex;
            gap: 8px;
            align-items: center;
            cursor: pointer;
        }

        .feature-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        @media (max-width: 700px) {
            .owner-topbar-inner {
                flex-direction: column;
                align-items: flex-start;
            }

            .owner-title {
                font-size: 28px;
            }

            .owner-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .switch-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="owner-shell">

    <header class="owner-topbar">
        <div class="owner-topbar-inner">
            <div class="owner-brand">
                Platform Owner
            </div>

            <nav class="owner-nav">
                <a href="dashboard.php">Dashboard</a>
                <a href="packages.php" class="active">Packages</a>
                <a href="#">Tenants</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="owner-content">

        <div class="owner-hero">
            <div class="owner-kicker">Phase 2 · Packages</div>

            <div class="owner-title">
                Create package rules for tenants
            </div>

            <p class="owner-text">
                Welcome, <?php echo htmlspecialchars($system_owner_name); ?>.
                Packages define how different stokvel tenants will behave. Later, tenants will be assigned
                to one of these packages and their system rules will follow that package.
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
                    <div class="stat-label">Total Packages</div>
                    <div class="stat-value"><?php echo $total_packages; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-gold">
                    <div class="stat-label">Active Packages</div>
                    <div class="stat-value"><?php echo $active_packages; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-blue">
                    <div class="stat-label">Inactive Packages</div>
                    <div class="stat-value"><?php echo $inactive_packages; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-red">
                    <div class="stat-label">Average Price</div>
                    <div class="stat-value"><?php echo money($average_price); ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="owner-card owner-card-green">
                    <div class="section-title">
                        <?php echo $edit_id > 0 ? "Edit Package" : "Create Package"; ?>
                    </div>

                    <p class="section-subtitle">
                        Define package rules and feature access.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="action" value="save_package">
                        <input type="hidden" name="package_id" value="<?php echo (int)$formPackage["id"]; ?>">

                        <div class="mb-3">
                            <label class="form-label">Package Name *</label>
                            <input 
                                type="text"
                                name="package_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($formPackage["package_name"]); ?>"
                                placeholder="Example: Recruitment Stokvel"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea 
                                name="description"
                                class="form-control"
                                rows="3"
                                placeholder="What this package is for"
                            ><?php echo htmlspecialchars($formPackage["description"]); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Monthly Price</label>
                            <input 
                                type="number"
                                step="0.01"
                                min="0"
                                name="monthly_price"
                                class="form-control"
                                value="<?php echo htmlspecialchars($formPackage["monthly_price"]); ?>"
                            >
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Minimum Saving</label>
                                <input 
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="minimum_saving_amount"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($formPackage["minimum_saving_amount"]); ?>"
                                >
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Admin Fee</label>
                                <input 
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="admin_fee_amount"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($formPackage["admin_fee_amount"]); ?>"
                                >
                            </div>
                        </div>

                        <div class="mb-3">
    <label class="form-label">Return Calculation Type</label>
    <select name="return_calculation_type" class="form-control">
        <option value="once_off" <?php echo $formPackage["return_calculation_type"] === "once_off" ? "selected" : ""; ?>>
            Once-off return
        </option>
        <option value="daily_simple" <?php echo $formPackage["return_calculation_type"] === "daily_simple" ? "selected" : ""; ?>>
            Daily simple return
        </option>
        <option value="daily_compound" <?php echo $formPackage["return_calculation_type"] === "daily_compound" ? "selected" : ""; ?>>
            Daily compound return
        </option>
    </select>
    <div class="text-muted mt-1" style="font-size: 12px;">
        Once-off uses the normal return %. Daily returns grow every 24 hours after approval.
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Once-off Return %</label>
        <input 
            type="number"
            step="0.01"
            min="0"
            name="return_rate_percent"
            class="form-control"
            value="<?php echo htmlspecialchars($formPackage["return_rate_percent"]); ?>"
        >
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Daily Return %</label>
        <input 
            type="number"
            step="0.01"
            min="0"
            name="daily_return_percent"
            class="form-control"
            value="<?php echo htmlspecialchars($formPackage["daily_return_percent"]); ?>"
        >
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Maturity Days</label>
        <input 
            type="number"
            min="1"
            name="maturity_days"
            class="form-control"
            value="<?php echo htmlspecialchars($formPackage["maturity_days"]); ?>"
        >
        <div class="text-muted mt-1" style="font-size: 12px;">
            The full saving cycle period.
        </div>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Withdraw Allowed After Days</label>
        <input 
            type="number"
            min="0"
            name="withdraw_after_days"
            class="form-control"
            value="<?php echo htmlspecialchars($formPackage["withdraw_after_days"]); ?>"
        >
        <div class="text-muted mt-1" style="font-size: 12px;">
            Example: maturity is 30 days, but withdrawal allowed after 7 days.
        </div>
    </div>
</div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Recruitment Bonus %</label>
                                <input 
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="recruitment_bonus_percent"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($formPackage["recruitment_bonus_percent"]); ?>"
                                >
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bonus Claim Minimum</label>
                                <input 
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="bonus_claim_minimum"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($formPackage["bonus_claim_minimum"]); ?>"
                                >
                            </div>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label class="form-label">Package Features</label>

                            <div class="switch-grid">
                                <div class="switch-box">
                                    <label>
                                        <input type="checkbox" name="enable_referrals" <?php echo (int)$formPackage["enable_referrals"] === 1 ? "checked" : ""; ?>>
                                        Enable Referrals
                                    </label>
                                </div>

                                <div class="switch-box">
    <label>
        <input type="checkbox" name="show_daily_returns" <?php echo (int)$formPackage["show_daily_returns"] === 1 ? "checked" : ""; ?>>
        Show Daily Returns
    </label>
</div>

                                <div class="switch-box">
                                    <label>
                                        <input type="checkbox" name="enable_bonus_claims" <?php echo (int)$formPackage["enable_bonus_claims"] === 1 ? "checked" : ""; ?>>
                                        Bonus Claims
                                    </label>
                                </div>

                                <div class="switch-box">
                                    <label>
                                        <input type="checkbox" name="enable_group_chat" <?php echo (int)$formPackage["enable_group_chat"] === 1 ? "checked" : ""; ?>>
                                        Group Chat
                                    </label>
                                </div>

                                <div class="switch-box">
                                    <label>
                                        <input type="checkbox" name="enable_guest_chat" <?php echo (int)$formPackage["enable_guest_chat"] === 1 ? "checked" : ""; ?>>
                                        Guest Chat
                                    </label>
                                </div>

                                <div class="switch-box">
                                    <label>
                                        <input type="checkbox" name="require_banking_details" <?php echo (int)$formPackage["require_banking_details"] === 1 ? "checked" : ""; ?>>
                                        Require Banking
                                    </label>
                                </div>

                                <div class="switch-box">
                                    <label>
                                        <input type="checkbox" name="require_proof_of_payment" <?php echo (int)$formPackage["require_proof_of_payment"] === 1 ? "checked" : ""; ?>>
                                        Require Proof
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo $formPackage["status"] === "active" ? "selected" : ""; ?>>Active</option>
                                <option value="inactive" <?php echo $formPackage["status"] === "inactive" ? "selected" : ""; ?>>Inactive</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-dark w-100">
                            <?php echo $edit_id > 0 ? "Save Package Changes" : "Create Package"; ?>
                        </button>

                        <?php if ($edit_id > 0): ?>
                            <a href="packages.php" class="btn btn-outline-dark w-100 mt-2">
                                Cancel Edit
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="owner-card owner-card-gold">
                    <div class="section-title">Existing Packages</div>
                    <p class="section-subtitle">
                        Packages are not assigned to tenants yet. That comes in Phase 3.
                    </p>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Package</th>
                                    <th>Rules</th>
                                    <th>Features</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($packages->num_rows > 0): ?>
                                    <?php while ($package = $packages->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($package["package_name"]); ?></strong>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    <?php echo money($package["monthly_price"]); ?>/month
                                                </div>

                                                <?php if (!empty($package["description"])): ?>
                                                    <div class="text-muted mt-1" style="font-size: 12px; max-width: 230px;">
                                                        <?php echo htmlspecialchars($package["description"]); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <div style="font-size: 13px;">
                                                    <strong>Min:</strong> <?php echo money($package["minimum_saving_amount"]); ?><br>
                                                    <strong>Fee:</strong> <?php echo money($package["admin_fee_amount"]); ?><br>
                                                   <strong>Return Type:</strong> <?php echo htmlspecialchars(str_replace("_", " ", ucfirst($package["return_calculation_type"]))); ?><br>
<strong>Once-off:</strong> <?php echo number_format((float)$package["return_rate_percent"], 2); ?>%<br>
<strong>Daily:</strong> <?php echo number_format((float)$package["daily_return_percent"], 2); ?>%<br>
<strong>Maturity:</strong> <?php echo (int)$package["maturity_days"]; ?> days<br>
<strong>Withdraw After:</strong> <?php echo (int)$package["withdraw_after_days"]; ?> days<br>
                                                    <strong>Recruit:</strong> <?php echo number_format((float)$package["recruitment_bonus_percent"], 2); ?>%
                                                </div>
                                            </td>

                                            <td>
                                                <div class="feature-list">
                                                    <?php if ((int)$package["enable_referrals"] === 1): ?>
                                                        <span class="badge badge-approved">Referrals</span>
                                                    <?php endif; ?>

                                                    <?php if ((int)$package["show_daily_returns"] === 1): ?>
    <span class="badge badge-approved">Daily Returns</span>
<?php endif; ?>

                                                    <?php if ((int)$package["enable_bonus_claims"] === 1): ?>
                                                        <span class="badge badge-approved">Bonus Claims</span>
                                                    <?php endif; ?>

                                                    <?php if ((int)$package["enable_group_chat"] === 1): ?>
                                                        <span class="badge badge-approved">Chat</span>
                                                    <?php endif; ?>

                                                    <?php if ((int)$package["enable_guest_chat"] === 1): ?>
                                                        <span class="badge badge-approved">Guest Chat</span>
                                                    <?php endif; ?>

                                                    <?php if ((int)$package["require_banking_details"] === 1): ?>
                                                        <span class="badge badge-pending">Banking Required</span>
                                                    <?php endif; ?>

                                                    <?php if ((int)$package["require_proof_of_payment"] === 1): ?>
                                                        <span class="badge badge-pending">Proof Required</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php echo packageStatusBadge($package["status"]); ?>
                                            </td>

                                            <td class="text-end">
                                                <a href="packages.php?edit=<?php echo (int)$package["id"]; ?>" class="btn btn-outline-dark btn-sm">
                                                    Edit
                                                </a>

                                                <?php if ($package["status"] === "active"): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="package_id" value="<?php echo (int)$package["id"]; ?>">
                                                        <input type="hidden" name="new_status" value="inactive">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            Deactivate
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="package_id" value="<?php echo (int)$package["id"]; ?>">
                                                        <input type="hidden" name="new_status" value="active">
                                                        <button type="submit" class="btn btn-success btn-sm">
                                                            Activate
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No packages have been created yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>

                        </table>
                    </div>
                </div>
            </div>
        </div>

    </main>

</div>

</body>
</html>