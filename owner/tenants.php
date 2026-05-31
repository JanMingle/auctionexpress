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

$search = trim($_GET["search"] ?? "");
$searchLike = "%" . $search . "%";

function money($amount) {
    return "R" . number_format((float)$amount, 2);
}

function statusBadge($status) {
    if ($status === "active") {
        return '<span class="badge badge-approved">Active</span>';
    }

    if ($status === "trial") {
        return '<span class="badge badge-pending">Trial</span>';
    }

    if ($status === "suspended") {
        return '<span class="badge badge-rejected">Suspended</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
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

function safeDate($date) {
    if (empty($date)) {
        return "-";
    }

    return date("d M Y", strtotime($date));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "assign_package") {
        $tenant_id = (int)($_POST["tenant_id"] ?? 0);
        $package_id = (int)($_POST["package_id"] ?? 0);

        if ($tenant_id <= 0 || $package_id <= 0) {
            $error = "Please select a valid tenant and package.";
        } else {
            $packageCheck = $conn->prepare("
                SELECT id
                FROM packages
                WHERE id = ?
                LIMIT 1
            ");
            $packageCheck->bind_param("i", $package_id);
            $packageCheck->execute();
            $packageExists = $packageCheck->get_result()->fetch_assoc();

            if (!$packageExists) {
                $error = "Selected package does not exist.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE tenants
                    SET package_id = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ii", $package_id, $tenant_id);

                if ($stmt->execute()) {
                    $success = "Tenant package updated successfully.";
                } else {
                    $error = "Could not update tenant package.";
                }
            }
        }
    }

    if ($action === "update_status") {
        $tenant_id = (int)($_POST["tenant_id"] ?? 0);
        $subscription_status = $_POST["subscription_status"] ?? "";

        $allowedStatuses = ["trial", "active", "suspended"];

        if ($tenant_id <= 0 || !in_array($subscription_status, $allowedStatuses, true)) {
            $error = "Invalid tenant status selected.";
        } else {
            $stmt = $conn->prepare("
                UPDATE tenants
                SET subscription_status = ?
                WHERE id = ?
            ");
            $stmt->bind_param("si", $subscription_status, $tenant_id);

            if ($stmt->execute()) {
                $success = "Tenant status updated successfully.";
            } else {
                $error = "Could not update tenant status.";
            }
        }
    }
}

$packagesStmt = $conn->prepare("
    SELECT
        id,
        package_name,
        monthly_price,
        status
    FROM packages
    ORDER BY 
        CASE WHEN status = 'active' THEN 1 ELSE 2 END,
        package_name ASC
");
$packagesStmt->execute();
$packagesResult = $packagesStmt->get_result();

$packages = [];
while ($package = $packagesResult->fetch_assoc()) {
    $packages[] = $package;
}

$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_tenants,
        SUM(CASE WHEN package_id IS NOT NULL THEN 1 ELSE 0 END) AS tenants_with_package,
        SUM(CASE WHEN package_id IS NULL THEN 1 ELSE 0 END) AS tenants_without_package,
        SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) AS active_tenants,
        SUM(CASE WHEN subscription_status = 'trial' THEN 1 ELSE 0 END) AS trial_tenants,
        SUM(CASE WHEN subscription_status = 'suspended' THEN 1 ELSE 0 END) AS suspended_tenants
    FROM tenants
");
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_tenants = (int)($stats["total_tenants"] ?? 0);
$tenants_with_package = (int)($stats["tenants_with_package"] ?? 0);
$tenants_without_package = (int)($stats["tenants_without_package"] ?? 0);
$active_tenants = (int)($stats["active_tenants"] ?? 0);
$trial_tenants = (int)($stats["trial_tenants"] ?? 0);
$suspended_tenants = (int)($stats["suspended_tenants"] ?? 0);

$baseSql = "
    SELECT
        t.id,
        t.stokvel_name,
        t.tenant_code,
        t.subscription_status,
        t.trial_ends_at,
        t.package_id,

        p.package_name,
        p.monthly_price,
        p.status AS package_status,
        p.minimum_saving_amount,
        p.admin_fee_amount,
        p.return_rate_percent,
        p.return_calculation_type,
        p.daily_return_percent,
        p.maturity_days,
        p.withdraw_after_days,
        p.enable_referrals,
        p.enable_bonus_claims,
        p.enable_group_chat,

        COUNT(u.id) AS total_users,
        SUM(CASE WHEN u.role = 'owner' THEN 1 ELSE 0 END) AS total_owners,
        SUM(CASE WHEN u.role = 'admin' THEN 1 ELSE 0 END) AS total_admins,
        SUM(CASE WHEN u.role = 'member' THEN 1 ELSE 0 END) AS total_members
    FROM tenants t
    LEFT JOIN packages p ON p.id = t.package_id
    LEFT JOIN users u ON u.tenant_id = t.id
";

if ($search !== "") {
    $baseSql .= "
        WHERE 
            t.stokvel_name LIKE ?
            OR t.tenant_code LIKE ?
            OR t.subscription_status LIKE ?
            OR p.package_name LIKE ?
    ";
}

$baseSql .= "
    GROUP BY
        t.id,
        t.stokvel_name,
        t.tenant_code,
        t.subscription_status,
        t.trial_ends_at,
        t.package_id,
        p.package_name,
        p.monthly_price,
        p.status,
        p.minimum_saving_amount,
        p.admin_fee_amount,
        p.return_rate_percent,
        p.return_calculation_type,
        p.daily_return_percent,
        p.maturity_days,
        p.withdraw_after_days,
        p.enable_referrals,
        p.enable_bonus_claims,
        p.enable_group_chat
    ORDER BY t.id DESC
";

$tenantsStmt = $conn->prepare($baseSql);

if ($search !== "") {
    $tenantsStmt->bind_param(
        "ssss",
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
}

$tenantsStmt->execute();
$tenants = $tenantsStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tenants</title>
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

        .tenant-name {
            font-weight: 900;
            color: #10241f;
        }

        .tenant-sub {
            font-size: 12px;
            color: #667085;
            margin-top: 2px;
        }

        .rules-box {
            font-size: 13px;
            line-height: 1.7;
            min-width: 220px;
        }

        .inline-form {
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 280px;
        }

        .inline-form select {
            min-width: 150px;
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

            .inline-form {
                flex-direction: column;
                align-items: stretch;
                min-width: 220px;
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
                <a href="packages.php">Packages</a>
                <a href="tenants.php" class="active">Tenants</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="owner-content">

        <div class="owner-hero">
            <div class="owner-kicker">Phase 3 · Tenant Packages</div>

            <div class="owner-title">
                Assign tenants to packages
            </div>

            <p class="owner-text">
                Welcome, <?php echo htmlspecialchars($system_owner_name); ?>.
                Use this page to decide which package each tenant belongs to. In the next step,
                the tenant’s savings rules will start reading from the selected package.
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
                    <div class="stat-label">Total Tenants</div>
                    <div class="stat-value"><?php echo $total_tenants; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-gold">
                    <div class="stat-label">With Package</div>
                    <div class="stat-value"><?php echo $tenants_with_package; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-blue">
                    <div class="stat-label">Active Tenants</div>
                    <div class="stat-value"><?php echo $active_tenants; ?></div>
                    <div class="text-muted" style="font-size: 13px; position: relative; z-index: 2;">
                        Trial: <?php echo $trial_tenants; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-red">
                    <div class="stat-label">Suspended</div>
                    <div class="stat-value"><?php echo $suspended_tenants; ?></div>
                </div>
            </div>
        </div>

        <div class="owner-card">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                <div>
                    <div class="section-title">Tenant Package Assignment</div>
                    <p class="section-subtitle mb-0">
                        Search tenants and assign the package they should use.
                    </p>
                </div>

                <a href="packages.php" class="btn btn-outline-dark btn-sm">
                    Manage Packages
                </a>
            </div>

            <form method="GET" class="mb-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-9">
                        <input 
                            type="text"
                            name="search"
                            class="form-control"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search by stokvel name, tenant code, status or package..."
                        >
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100">
                            Search
                        </button>

                        <?php if ($search !== ""): ?>
                            <a href="tenants.php" class="btn btn-outline-dark">
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Current Package</th>
                            <th>Package Rules</th>
                            <th>Status</th>
                            <th>Users</th>
                            <th>Assign Package</th>
                            <th>Tenant Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($tenants->num_rows > 0): ?>
                            <?php while ($tenant = $tenants->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="tenant-name">
                                            <?php echo htmlspecialchars($tenant["stokvel_name"] ?: "-"); ?>
                                        </div>

                                        <div class="tenant-sub">
                                            Code: <?php echo htmlspecialchars($tenant["tenant_code"] ?: "-"); ?>
                                        </div>

                                        <div class="tenant-sub">
                                            Trial ends: <?php echo safeDate($tenant["trial_ends_at"] ?? null); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?php if (!empty($tenant["package_name"])): ?>
                                            <strong><?php echo htmlspecialchars($tenant["package_name"]); ?></strong>
                                            <div class="tenant-sub">
                                                <?php echo money($tenant["monthly_price"]); ?>/month
                                            </div>
                                            <div class="mt-1">
                                                <?php echo packageStatusBadge($tenant["package_status"]); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-rejected">No Package</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($tenant["package_name"])): ?>
                                            <div class="rules-box">
                                                <strong>Min:</strong> <?php echo money($tenant["minimum_saving_amount"]); ?><br>
                                                <strong>Fee:</strong> <?php echo money($tenant["admin_fee_amount"]); ?><br>
                                                <strong>Type:</strong> <?php echo htmlspecialchars(str_replace("_", " ", $tenant["return_calculation_type"])); ?><br>
                                                <strong>Once-off:</strong> <?php echo number_format((float)$tenant["return_rate_percent"], 2); ?>%<br>
                                                <strong>Daily:</strong> <?php echo number_format((float)$tenant["daily_return_percent"], 2); ?>%<br>
                                                <strong>Maturity:</strong> <?php echo (int)$tenant["maturity_days"]; ?> days<br>
                                                <strong>Withdraw:</strong> <?php echo (int)$tenant["withdraw_after_days"]; ?> days
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No rules assigned</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php echo statusBadge($tenant["subscription_status"]); ?>
                                    </td>

                                    <td>
                                        <strong><?php echo (int)$tenant["total_users"]; ?></strong>
                                        <div class="tenant-sub">
                                            Owners: <?php echo (int)$tenant["total_owners"]; ?>
                                        </div>
                                        <div class="tenant-sub">
                                            Admins: <?php echo (int)$tenant["total_admins"]; ?>
                                        </div>
                                        <div class="tenant-sub">
                                            Members: <?php echo (int)$tenant["total_members"]; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="assign_package">
                                            <input type="hidden" name="tenant_id" value="<?php echo (int)$tenant["id"]; ?>">

                                            <select name="package_id" class="form-control" required>
                                                <option value="">Select package</option>

                                                <?php foreach ($packages as $package): ?>
                                                    <option 
                                                        value="<?php echo (int)$package["id"]; ?>"
                                                        <?php echo (int)$tenant["package_id"] === (int)$package["id"] ? "selected" : ""; ?>
                                                    >
                                                        <?php echo htmlspecialchars($package["package_name"]); ?>
                                                        <?php echo $package["status"] === "inactive" ? " (inactive)" : ""; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button type="submit" class="btn btn-dark btn-sm">
                                                Save
                                            </button>
                                        </form>
                                    </td>

                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="tenant_id" value="<?php echo (int)$tenant["id"]; ?>">

                                            <select name="subscription_status" class="form-control">
                                                <option value="trial" <?php echo $tenant["subscription_status"] === "trial" ? "selected" : ""; ?>>
                                                    Trial
                                                </option>
                                                <option value="active" <?php echo $tenant["subscription_status"] === "active" ? "selected" : ""; ?>>
                                                    Active
                                                </option>
                                                <option value="suspended" <?php echo $tenant["subscription_status"] === "suspended" ? "selected" : ""; ?>>
                                                    Suspended
                                                </option>
                                            </select>

                                            <button type="submit" class="btn btn-outline-dark btn-sm">
                                                Update
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No tenants found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>

    </main>

</div>

</body>
</html>