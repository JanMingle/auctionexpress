<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["system_owner_id"])) {
    header("Location: login.php");
    exit;
}

$system_owner_name = $_SESSION["system_owner_name"] ?? "System Owner";

$tenantStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_tenants,
        SUM(CASE WHEN subscription_status = 'trial' THEN 1 ELSE 0 END) AS trial_tenants,
        SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) AS active_tenants,
        SUM(CASE WHEN subscription_status = 'suspended' THEN 1 ELSE 0 END) AS suspended_tenants
    FROM tenants
");
$tenantStatsStmt->execute();
$tenantStats = $tenantStatsStmt->get_result()->fetch_assoc();

$total_tenants = (int)($tenantStats["total_tenants"] ?? 0);
$trial_tenants = (int)($tenantStats["trial_tenants"] ?? 0);
$active_tenants = (int)($tenantStats["active_tenants"] ?? 0);
$suspended_tenants = (int)($tenantStats["suspended_tenants"] ?? 0);

$userStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) AS stokvel_owners,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS stokvel_admins,
        SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) AS members
    FROM users
");
$userStatsStmt->execute();
$userStats = $userStatsStmt->get_result()->fetch_assoc();

$total_users = (int)($userStats["total_users"] ?? 0);
$stokvel_owners = (int)($userStats["stokvel_owners"] ?? 0);
$stokvel_admins = (int)($userStats["stokvel_admins"] ?? 0);
$members = (int)($userStats["members"] ?? 0);

$tenantsStmt = $conn->prepare("
    SELECT
        t.id,
        t.stokvel_name,
        t.tenant_code,
        t.subscription_status,
        t.trial_ends_at,
        COUNT(u.id) AS total_users,
        SUM(CASE WHEN u.role = 'member' THEN 1 ELSE 0 END) AS total_members
    FROM tenants t
    LEFT JOIN users u ON u.tenant_id = t.id
    GROUP BY 
        t.id,
        t.stokvel_name,
        t.tenant_code,
        t.subscription_status,
        t.trial_ends_at
    ORDER BY t.id DESC
    LIMIT 10
");
$tenantsStmt->execute();
$tenants = $tenantsStmt->get_result();

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

function safeDate($date) {
    if (empty($date)) {
        return "-";
    }

    return date("d M Y", strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Owner Dashboard</title>
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
            max-width: 720px;
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

        @media (max-width: 700px) {
            .owner-topbar-inner {
                flex-direction: column;
                align-items: flex-start;
            }

            .owner-nav {
                flex-wrap: wrap;
            }

            .owner-title {
                font-size: 28px;
            }

            .owner-hero {
                border-radius: 24px;
                padding: 24px;
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
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="packages.php">Packages</a>
                <a href="tenants.php">Tenants</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="owner-content">

        <div class="owner-hero">
            <div class="owner-kicker">System Owner Area</div>

            <div class="owner-title">
                Welcome, <?php echo htmlspecialchars($system_owner_name); ?>
            </div>

            <p class="owner-text">
                This is your platform-level control area. From here, you will manage tenants,
                packages, subscriptions, and system-wide rules.
            </p>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card stat-card-green">
                    <div class="stat-label">Total Tenants</div>
                    <div class="stat-value"><?php echo $total_tenants; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-gold">
                    <div class="stat-label">Trial Tenants</div>
                    <div class="stat-value"><?php echo $trial_tenants; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-blue">
                    <div class="stat-label">Active Tenants</div>
                    <div class="stat-value"><?php echo $active_tenants; ?></div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card stat-card-red">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="owner-card">
                    <div class="section-title">Stokvel Owners</div>
                    <div class="stat-value"><?php echo $stokvel_owners; ?></div>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        Tenant owners registered on the platform.
                    </p>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="owner-card">
                    <div class="section-title">Stokvel Admins</div>
                    <div class="stat-value"><?php echo $stokvel_admins; ?></div>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        Admin users added under tenants.
                    </p>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="owner-card">
                    <div class="section-title">Members</div>
                    <div class="stat-value"><?php echo $members; ?></div>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        Members across all tenants.
                    </p>
                </div>
            </div>
        </div>

        <div class="owner-card">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                <div>
                    <div class="section-title">Latest Tenants</div>
                    <p class="section-subtitle mb-0">
                        Recent stokvels registered on the platform.
                    </p>
                </div>

                <span class="badge badge-pending">
                    Phase 1 View Only
                </span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Stokvel</th>
                            <th>Tenant Code</th>
                            <th>Status</th>
                            <th>Trial Ends</th>
                            <th>Users</th>
                            <th>Members</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($tenants->num_rows > 0): ?>
                            <?php while ($tenant = $tenants->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php echo htmlspecialchars($tenant["stokvel_name"] ?: "-"); ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($tenant["tenant_code"] ?: "-"); ?>
                                    </td>

                                    <td>
                                        <?php echo statusBadge($tenant["subscription_status"] ?? ""); ?>
                                    </td>

                                    <td>
                                        <?php echo safeDate($tenant["trial_ends_at"] ?? null); ?>
                                    </td>

                                    <td>
                                        <?php echo (int)$tenant["total_users"]; ?>
                                    </td>

                                    <td>
                                        <?php echo (int)$tenant["total_members"]; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No tenants found yet.
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