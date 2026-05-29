<?php
session_start();
require_once "config/db.php";

$error = "";
$success = "";
$generated_username = "";
$generated_member_code = "";

$tenant_code = trim($_GET["tenant"] ?? "");
$ref_code = trim($_GET["ref"] ?? "");
$upline_user_id = null;
$upline_display = "";

if (empty($tenant_code)) {
    die("Invalid registration link. Tenant code is missing.");
}

$tenantStmt = $conn->prepare("
    SELECT id, stokvel_name, subscription_status
    FROM tenants
    WHERE tenant_code = ?
    LIMIT 1
");
$tenantStmt->bind_param("s", $tenant_code);
$tenantStmt->execute();
$tenantResult = $tenantStmt->get_result();

if ($tenantResult->num_rows !== 1) {
    die("Invalid registration link. This stokvel account was not found.");
}

$tenant = $tenantResult->fetch_assoc();

if ($tenant["subscription_status"] === "cancelled" || $tenant["subscription_status"] === "suspended") {
    die("This stokvel account is currently not accepting new registrations.");
}

$tenant_id = (int)$tenant["id"];
$stokvel_name = $tenant["stokvel_name"];
if (!empty($ref_code)) {
    $refStmt = $conn->prepare("
        SELECT id, username, member_code
        FROM users
        WHERE tenant_id = ?
        AND role = 'member'
        AND status = 'active'
        AND (
            username = ?
            OR member_code = ?
        )
        LIMIT 1
    ");
    $refStmt->bind_param("iss", $tenant_id, $ref_code, $ref_code);
    $refStmt->execute();
    $refUser = $refStmt->get_result()->fetch_assoc();

    if ($refUser) {
        $upline_user_id = (int)$refUser["id"];
        $upline_display = $refUser["username"] ?: $refUser["member_code"];
    }
}

function cleanNamePart($value) {
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-z0-9]/", "", $value);
    return $value ?: "user";
}

function generateMemberCode($conn) {
    do {
        $letters = chr(random_int(65, 90)) . chr(random_int(65, 90));
        $digits = str_pad((string)random_int(0, 99999), 5, "0", STR_PAD_LEFT);
        $code = $letters . $digits;

        $stmt = $conn->prepare("SELECT id FROM users WHERE member_code = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
    } while ($exists);

    return $code;
}

function generateUsername($conn, $firstName, $memberCode) {
    $base = cleanNamePart($firstName);
    $digits = substr($memberCode, 2);
    $username = $base . $digits;

    $counter = 1;

    while (true) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();

        if ($stmt->get_result()->num_rows === 0) {
            return $username;
        }

        $username = $base . $digits . $counter;
        $counter++;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (
        empty($first_name) ||
        empty($last_name) ||
        empty($phone) ||
        empty($password) ||
        empty($confirm_password)
    ) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($password) < 4) {
        $error = "Password must be at least 4 characters or digits.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $member_code = generateMemberCode($conn);
        $username = generateUsername($conn, $first_name, $member_code);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $role = "member";
        $status = "pending";
        $email = null;

        $stmt = $conn->prepare("
      INSERT INTO users 
(
    tenant_id,
    upline_user_id,
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
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

$stmt->bind_param(
    "iisssssssss",
    $tenant_id,
    $upline_user_id,
    $first_name,
    $last_name,
    $email,
    $phone,
    $username,
    $member_code,
    $hashed_password,
    $role,
    $status
);

        if ($stmt->execute()) {
            $generated_username = $username;
            $generated_member_code = $member_code;
            $success = "Your registration has been submitted. Please keep your login details below and wait for the stokvel admin to approve your account.";
        } else {
            $error = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Join <?php echo htmlspecialchars($stokvel_name); ?> - Stokvel Circle</title>
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
            max-width: 640px;
            background: rgba(255, 255, 255, 0.92);
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
            margin-bottom: 18px;
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
            margin-bottom: 18px;
        }

        .stokvel-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #f8fbf7;
            border: 1px dashed rgba(15,107,79,0.22);
            color: #35544a;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .stokvel-badge::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
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

        .btn-soft {
            border-radius: 18px;
            padding: 12px 18px;
            border: 1px solid var(--border);
            background: #ffffff;
            color: var(--ink);
            font-weight: 800;
        }

        .btn-soft:hover {
            background: #f8fbf7;
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

        .login-box {
            background: #f8fbf7;
            border: 1px dashed rgba(15,107,79,0.28);
            border-radius: 24px;
            padding: 20px;
            margin-top: 16px;
        }

        .login-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .code-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 16px;
        }

        .code-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
            font-weight: 700;
        }

        .login-code {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 0.08em;
            color: var(--green-dark);
        }

        .login-username {
            font-size: 18px;
            font-weight: 900;
            color: var(--ink);
            word-break: break-word;
        }

        .copy-hint {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.55;
            margin-top: 14px;
        }

        @media (max-width: 620px) {
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

            .login-grid {
                grid-template-columns: 1fr;
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

        <h1 class="auth-title">Join the circle</h1>
        <p class="auth-subtitle">
            Create your member profile for this stokvel. Your login code and username will be generated automatically.
        </p>

        <div class="stokvel-badge">
            <?php echo htmlspecialchars($stokvel_name); ?>
        </div>
        <?php if (!empty($upline_display)): ?>
    <div class="stokvel-badge" style="background:#fff8df; border-color:rgba(216,169,40,0.35); color:#7a5a09;">
        Invited by <?php echo htmlspecialchars($upline_display); ?>
    </div>
<?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>

            <div class="login-box">
                <div class="login-grid">
                    <div class="code-card">
                        <div class="code-label">Member Code</div>
                        <div class="login-code">
                            <?php echo htmlspecialchars($generated_member_code); ?>
                        </div>
                    </div>

                    <div class="code-card">
                        <div class="code-label">Username</div>
                        <div class="login-username">
                            <?php echo htmlspecialchars($generated_username); ?>
                        </div>
                    </div>
                </div>

                <p class="copy-hint mb-0">
                    Keep these details safe. Use either your member code or username to login after the admin approves your account.
                </p>
            </div>

            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-stokvel">
                    Go to Login
                </a>
            </div>
        <?php else: ?>

            <form method="POST" action="signup.php?tenant=<?php echo urlencode($tenant_code); ?><?php echo !empty($ref_code) ? '&ref=' . urlencode($ref_code) : ''; ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">First Name *</label>
                        <input 
                            type="text" 
                            name="first_name" 
                            class="form-control" 
                            placeholder="Your first name"
                            required
                        >
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Last Name *</label>
                        <input 
                            type="text" 
                            name="last_name" 
                            class="form-control" 
                            placeholder="Your last name"
                            required
                        >
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Phone Number *</label>
                    <input 
                        type="text" 
                        name="phone" 
                        class="form-control"
                        placeholder="Example: 0712345678"
                        required
                    >
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password *</label>
                        <input 
                            type="password" 
                            name="password" 
                            class="form-control" 
                            minlength="4"
                            placeholder="Minimum 4 digits/characters"
                            required
                        >
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Confirm Password *</label>
                        <input 
                            type="password" 
                            name="confirm_password" 
                            class="form-control" 
                            minlength="4"
                            placeholder="Repeat password"
                            required
                        >
                    </div>
                </div>

                <button type="submit" class="btn btn-stokvel w-100">
                    Submit Member Registration
                </button>

                <div class="mini-note">
                    Already approved?
                    <a href="login.php" class="auth-link">Login here</a>
                </div>

            </form>

        <?php endif; ?>

    </div>
</div>

</body>
</html>