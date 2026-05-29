<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "owner" && $_SESSION["role"] !== "admin") {
    header("Location: ../users/dashboard.php");
    exit;
}

$tenant_id = (int)$_SESSION["tenant_id"];
$current_user_id = (int)$_SESSION["user_id"];
$current_role = $_SESSION["role"] ?? "";
$stokvel_name = $_SESSION["stokvel_name"] ?? "Stokvel";
$username = $_SESSION["username"] ?? "";
$name = $_SESSION["name"] ?? "Admin";
$displayName = $username ?: $name;

$success = "";
$error = "";

$canManageAdmins = ($current_role === "owner");

function cleanNamePart($value) {
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-z0-9]/", "", $value);
    return $value ?: "admin";
}

function generateAdminUsername($conn, $firstName) {
    $base = cleanNamePart($firstName);

    do {
        $username = $base . random_int(1000, 9999);

        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE username = ? 
            LIMIT 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
    } while ($exists);

    return $username;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!$canManageAdmins) {
        $error = "Only the stokvel owner can manage admin users.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "create_admin") {
            $first_name = trim($_POST["first_name"] ?? "");
            $last_name = trim($_POST["last_name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $phone = trim($_POST["phone"] ?? "");
            $password = $_POST["password"] ?? "";
            $confirm_password = $_POST["confirm_password"] ?? "";

            if (
                $first_name === "" ||
                $last_name === "" ||
                $email === "" ||
                $phone === "" ||
                $password === "" ||
                $confirm_password === ""
            ) {
                $error = "Please fill in all required fields.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } elseif (strlen($password) < 4) {
                $error = "Password must be at least 4 characters or digits.";
            } elseif ($password !== $confirm_password) {
                $error = "Passwords do not match.";
            } else {
                $checkStmt = $conn->prepare("
                    SELECT id 
                    FROM users 
                    WHERE email = ? 
                    LIMIT 1
                ");
                $checkStmt->bind_param("s", $email);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();

                if ($existing) {
                    $error = "This email address is already registered.";
                } else {
                    $admin_username = generateAdminUsername($conn, $first_name);
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $role = "admin";
                    $status = "active";

                    $stmt = $conn->prepare("
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
                        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)
                    ");

                    $stmt->bind_param(
                        "issssssss",
                        $tenant_id,
                        $first_name,
                        $last_name,
                        $email,
                        $phone,
                        $admin_username,
                        $hashed_password,
                        $role,
                        $status
                    );

                    if ($stmt->execute()) {
                        $success = "Admin user created successfully. Their username is " . $admin_username . ".";
                    } else {
                        $error = "Could not create admin user.";
                    }
                }
            }
        }

        if ($action === "suspend_admin") {
            $admin_id = (int)($_POST["admin_id"] ?? 0);

            if ($admin_id <= 0) {
                $error = "Invalid admin selected.";
            } elseif ($admin_id === $current_user_id) {
                $error = "You cannot suspend your own account.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET status = 'suspended'
                    WHERE id = ?
                    AND tenant_id = ?
                    AND role = 'admin'
                ");
                $stmt->bind_param("ii", $admin_id, $tenant_id);

                if ($stmt->execute()) {
                    $success = "Admin suspended successfully.";
                } else {
                    $error = "Could not suspend admin.";
                }
            }
        }

        if ($action === "activate_admin") {
            $admin_id = (int)($_POST["admin_id"] ?? 0);

            if ($admin_id <= 0) {
                $error = "Invalid admin selected.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET status = 'active'
                    WHERE id = ?
                    AND tenant_id = ?
                    AND role = 'admin'
                ");
                $stmt->bind_param("ii", $admin_id, $tenant_id);

                if ($stmt->execute()) {
                    $success = "Admin activated successfully.";
                } else {
                    $error = "Could not activate admin.";
                }
            }
        }

        if ($action === "delete_admin") {
            $admin_id = (int)($_POST["admin_id"] ?? 0);

            if ($admin_id <= 0) {
                $error = "Invalid admin selected.";
            } elseif ($admin_id === $current_user_id) {
                $error = "You cannot delete your own account.";
            } else {
                $stmt = $conn->prepare("
                    DELETE FROM users
                    WHERE id = ?
                    AND tenant_id = ?
                    AND role = 'admin'
                ");
                $stmt->bind_param("ii", $admin_id, $tenant_id);

                if ($stmt->execute()) {
                    $success = "Admin deleted successfully.";
                } else {
                    $error = "Could not delete admin.";
                }
            }
        }
    }
}

$adminsStmt = $conn->prepare("
    SELECT 
        id,
        first_name,
        last_name,
        email,
        phone,
        username,
        role,
        status,
        created_at
    FROM users
    WHERE tenant_id = ?
    AND role IN ('owner', 'admin')
    ORDER BY 
        CASE 
            WHEN role = 'owner' THEN 1
            WHEN status = 'active' THEN 2
            WHEN status = 'suspended' THEN 3
            ELSE 4
        END,
        created_at DESC
");
$adminsStmt->bind_param("i", $tenant_id);
$adminsStmt->execute();
$admins = $adminsStmt->get_result();

$statsStmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) AS total_owners,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS total_admins,
        SUM(CASE WHEN role = 'admin' AND status = 'active' THEN 1 ELSE 0 END) AS active_admins,
        SUM(CASE WHEN role = 'admin' AND status = 'suspended' THEN 1 ELSE 0 END) AS suspended_admins
    FROM users
    WHERE tenant_id = ?
    AND role IN ('owner', 'admin')
");
$statsStmt->bind_param("i", $tenant_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$total_owners = (int)($stats["total_owners"] ?? 0);
$total_admins = (int)($stats["total_admins"] ?? 0);
$active_admins = (int)($stats["active_admins"] ?? 0);
$suspended_admins = (int)($stats["suspended_admins"] ?? 0);

function adminBadge($role, $status) {
    if ($role === "owner") {
        return '<span class="badge badge-approved">Owner</span>';
    }

    if ($status === "active") {
        return '<span class="badge badge-approved">Active Admin</span>';
    }

    if ($status === "suspended") {
        return '<span class="badge badge-rejected">Suspended</span>';
    }

    return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

function adminDisplayName($row) {
    if (!empty($row["username"])) {
        return $row["username"];
    }

    return trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Users</title>
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

        .app-main {
            background:
                radial-gradient(circle at 20% 15%, rgba(216,169,40,0.13), transparent 30%),
                radial-gradient(circle at 88% 30%, rgba(15,107,79,0.10), transparent 34%);
        }

        .app-content {
            position: relative;
        }

        .app-content::before {
            content: "R";
            position: fixed;
            right: 40px;
            bottom: 34px;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: linear-gradient(145deg, rgba(248,216,106,0.45), rgba(216,169,40,0.24));
            color: rgba(74,53,4,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 82px;
            font-weight: 900;
            transform: rotate(-14deg);
            pointer-events: none;
            z-index: 0;
        }

        .app-content > * {
            position: relative;
            z-index: 1;
        }

        .admins-hero {
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

        .admins-hero::after {
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

        .admins-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .admins-title {
            font-size: 34px;
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .admins-text {
            color: rgba(255,255,255,0.78);
            font-size: 14px;
            line-height: 1.6;
            max-width: 720px;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }

        .admin-form-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.18), transparent 35%),
                linear-gradient(135deg, #ffffff 0%, #def5e8 100%) !important;
        }

        .admin-list-card {
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

        .admin-identity {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .admin-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0f6b4f, #073f2f);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15,107,79,0.22);
            flex: 0 0 auto;
        }

        .admin-name {
            font-weight: 900;
            color: #10241f;
        }

        .admin-sub {
            font-size: 12px;
            color: #667085;
            margin-top: 2px;
        }

        .actions-wrap {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        @media (max-width: 900px) {
            .admins-hero {
                border-radius: 24px;
                padding: 24px;
            }

            .admins-title {
                font-size: 27px;
            }

            .admins-hero::after {
                width: 72px;
                height: 72px;
                font-size: 30px;
                right: 20px;
                top: 20px;
            }
        }
    </style>
</head>
<body>

<div class="app-shell">

    <?php include "../includes/sidebar.php"; ?>

    <main class="app-main">
        <div class="app-topbar">
            <div>
                <div class="app-topbar-title">Admin Users</div>
                <div class="app-topbar-subtitle">
                    Add trusted admins to help manage this stokvel.
                </div>
            </div>
        </div>

        <div class="app-content">

            <div class="admins-hero">
                <div class="admins-kicker">
                    <?php echo htmlspecialchars($stokvel_name); ?>
                </div>

                <div class="admins-title">
                    Give trusted people admin access
                </div>

                <p class="admins-text">
                    Welcome, <strong><?php echo htmlspecialchars($displayName); ?></strong>.
                    Admin users log in under the same stokvel and get access to the same admin dashboard,
                    savings, withdrawals, ledger, settings, and group chat.
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
                        <div class="stat-label">Owners</div>
                        <div class="stat-value"><?php echo $total_owners; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-gold">
                        <div class="stat-label">Total Admins</div>
                        <div class="stat-value"><?php echo $total_admins; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-label">Active Admins</div>
                        <div class="stat-value"><?php echo $active_admins; ?></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card stat-card-red">
                        <div class="stat-label">Suspended</div>
                        <div class="stat-value"><?php echo $suspended_admins; ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card-box admin-form-card">
                        <div class="section-title">Create Admin</div>
                        <p class="text-muted" style="font-size: 13px;">
                            The admin will be linked to this same stokvel and can log in using email or username.
                        </p>

                        <?php if (!$canManageAdmins): ?>
                            <div class="alert alert-warning">
                                Only the stokvel owner can create or manage admin users.
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="action" value="create_admin">

                            <div class="mb-3">
                                <label class="form-label">First Name *</label>
                                <input 
                                    type="text" 
                                    name="first_name" 
                                    class="form-control"
                                    <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Last Name *</label>
                                <input 
                                    type="text" 
                                    name="last_name" 
                                    class="form-control"
                                    <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input 
                                    type="email" 
                                    name="email" 
                                    class="form-control"
                                    <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input 
                                    type="text" 
                                    name="phone" 
                                    class="form-control"
                                    <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <input 
                                    type="password" 
                                    name="password" 
                                    class="form-control"
                                    minlength="4"
                                    <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Confirm Password *</label>
                                <input 
                                    type="password" 
                                    name="confirm_password" 
                                    class="form-control"
                                    minlength="4"
                                    <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                                    required
                                >
                            </div>

                            <button 
                                type="submit" 
                                class="btn btn-dark w-100"
                                <?php echo !$canManageAdmins ? "disabled" : ""; ?>
                            >
                                Create Admin User
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card-box admin-list-card">
                        <div class="section-title mb-3">Current Admin Access</div>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($admins->num_rows > 0): ?>
                                        <?php while ($admin = $admins->fetch_assoc()): ?>
                                            <?php
                                                $adminName = adminDisplayName($admin);
                                                $realName = trim(($admin["first_name"] ?? "") . " " . ($admin["last_name"] ?? ""));
                                                $initials = strtoupper(substr($adminName ?: "AD", 0, 2));
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="admin-identity">
                                                        <div class="admin-avatar">
                                                            <?php echo htmlspecialchars($initials); ?>
                                                        </div>

                                                        <div>
                                                            <div class="admin-name">
                                                                <?php echo htmlspecialchars($adminName ?: "-"); ?>
                                                            </div>
                                                            <div class="admin-sub">
                                                                <?php echo htmlspecialchars($realName ?: "-"); ?>
                                                            </div>
                                                            <div class="admin-sub">
                                                                <?php echo ucfirst(htmlspecialchars($admin["role"])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?php echo htmlspecialchars($admin["email"] ?: "-"); ?>
                                                </td>

                                                <td>
                                                    <?php echo htmlspecialchars($admin["phone"] ?: "-"); ?>
                                                </td>

                                                <td>
                                                    <?php echo adminBadge($admin["role"], $admin["status"]); ?>
                                                </td>

                                                <td>
                                                    <?php echo date("d M Y", strtotime($admin["created_at"])); ?>
                                                </td>

                                                <td class="text-end">
                                                    <div class="actions-wrap">
                                                        <?php if ($admin["role"] === "owner"): ?>
                                                            <span class="text-muted" style="font-size: 13px;">
                                                                Main owner
                                                            </span>
                                                        <?php elseif (!$canManageAdmins): ?>
                                                            <span class="text-muted" style="font-size: 13px;">
                                                                Owner only
                                                            </span>
                                                        <?php elseif ($admin["status"] === "active"): ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="admin_id" value="<?php echo (int)$admin["id"]; ?>">
                                                                <input type="hidden" name="action" value="suspend_admin">
                                                                <button class="btn btn-warning btn-sm" type="submit">
                                                                    Suspend
                                                                </button>
                                                            </form>

                                                            <form 
                                                                method="POST" 
                                                                class="d-inline"
                                                                onsubmit="return confirm('Are you sure you want to delete this admin?');"
                                                            >
                                                                <input type="hidden" name="admin_id" value="<?php echo (int)$admin["id"]; ?>">
                                                                <input type="hidden" name="action" value="delete_admin">
                                                                <button class="btn btn-outline-danger btn-sm" type="submit">
                                                                    Delete
                                                                </button>
                                                            </form>
                                                        <?php elseif ($admin["status"] === "suspended"): ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="admin_id" value="<?php echo (int)$admin["id"]; ?>">
                                                                <input type="hidden" name="action" value="activate_admin">
                                                                <button class="btn btn-primary btn-sm" type="submit">
                                                                    Activate
                                                                </button>
                                                            </form>

                                                            <form 
                                                                method="POST" 
                                                                class="d-inline"
                                                                onsubmit="return confirm('Are you sure you want to delete this admin?');"
                                                            >
                                                                <input type="hidden" name="admin_id" value="<?php echo (int)$admin["id"]; ?>">
                                                                <input type="hidden" name="action" value="delete_admin">
                                                                <button class="btn btn-outline-danger btn-sm" type="submit">
                                                                    Delete
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="text-muted" style="font-size: 13px;">
                                                                No action
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                No admin users found yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>

                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

</div>

</body>
</html>