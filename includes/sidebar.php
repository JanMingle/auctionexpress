<?php
$currentScript = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"]);
$scriptDir = str_replace("\\", "/", dirname($currentScript));

$role = $_SESSION["role"] ?? "";
$stokvelName = $_SESSION["stokvel_name"] ?? "Stokvel";

$displayUser = $_SESSION["username"] 
    ?? $_SESSION["member_code"] 
    ?? $_SESSION["name"] 
    ?? "User";

if (preg_match("#/(admin|member|users)$#", $scriptDir)) {
    $appBase = dirname($scriptDir);
} else {
    $appBase = rtrim($scriptDir, "/");
}

if ($appBase === "/" || $appBase === "\\") {
    $appBase = "";
}

$memberFolder = "member";

if (strpos($currentScript, "/users/") !== false || is_dir(__DIR__ . "/../users")) {
    $memberFolder = "users";
}

if (!function_exists("isActivePage")) {
    function isActivePage($fileName) {
        $current = basename($_SERVER["SCRIPT_NAME"]);
        return $current === $fileName ? "active" : "";
    }
}
?>

<button type="button" class="mobile-menu-button" onclick="toggleSidebar()" aria-label="Open menu">
    <span></span>
    <span></span>
    <span></span>
</button>

<div class="sidebar-backdrop" onclick="closeSidebar()"></div>

<aside class="app-sidebar" id="appSidebar">

    <div class="app-brand">
        <div class="app-brand-title">
            <?php echo htmlspecialchars($stokvelName); ?>
        </div>

        <div class="app-brand-subtitle">
            <?php echo htmlspecialchars($displayUser); ?> · <?php echo ucfirst(htmlspecialchars($role)); ?>
        </div>
    </div>

    <?php if ($role === "owner" || $role === "admin"): ?>

        <div class="sidebar-section">
            <div class="sidebar-section-title">Stokvel Admin</div>

            <a href="<?php echo $appBase; ?>/admin/dashboard.php" class="sidebar-link <?php echo isActivePage('dashboard.php'); ?>">
                <span class="sidebar-dot"></span>
                Dashboard
            </a>

            <a href="<?php echo $appBase; ?>/admin/members.php" class="sidebar-link <?php echo isActivePage('members.php'); ?>">
                <span class="sidebar-dot"></span>
                Members
            </a>

            <a href="<?php echo $appBase; ?>/admin/admins.php" class="sidebar-link <?php echo isActivePage('admins.php'); ?>">
    <span class="sidebar-dot"></span>
    Admin Users
</a>

            <a href="<?php echo $appBase; ?>/admin/savings_requests.php" class="sidebar-link <?php echo isActivePage('savings_requests.php'); ?>">
                <span class="sidebar-dot"></span>
                Saving Requests
            </a>

            <a href="<?php echo $appBase; ?>/admin/withdrawals.php" class="sidebar-link <?php echo isActivePage('withdrawals.php'); ?>">
                <span class="sidebar-dot"></span>
                Withdrawals
            </a>

            <a href="<?php echo $appBase; ?>/admin/ledger.php" class="sidebar-link <?php echo isActivePage('ledger.php'); ?>">
                <span class="sidebar-dot"></span>
                Ledger
            </a>

            <a href="<?php echo $appBase; ?>/group_chat.php" class="sidebar-link <?php echo isActivePage('group_chat.php'); ?>">
                <span class="sidebar-dot"></span>
                Group Chat
            </a>

            <a href="<?php echo $appBase; ?>/admin/settings.php" class="sidebar-link <?php echo isActivePage('settings.php'); ?>">
                <span class="sidebar-dot"></span>
                Stokvel Settings
            </a>
        </div>

    <?php else: ?>

        <div class="sidebar-section">
            <div class="sidebar-section-title">My Stokvel</div>

            <a href="<?php echo $appBase; ?>/<?php echo $memberFolder; ?>/dashboard.php" class="sidebar-link <?php echo isActivePage('dashboard.php'); ?>">
                <span class="sidebar-dot"></span>
                Dashboard
            </a>

            <a href="<?php echo $appBase; ?>/<?php echo $memberFolder; ?>/savings.php" class="sidebar-link <?php echo isActivePage('savings.php'); ?>">
                <span class="sidebar-dot"></span>
                My Savings
            </a>

            <a href="<?php echo $appBase; ?>/<?php echo $memberFolder; ?>/withdrawals.php" class="sidebar-link <?php echo isActivePage('withdrawals.php'); ?>">
                <span class="sidebar-dot"></span>
                My Withdrawals
            </a>

            <a href="<?php echo $appBase; ?>/<?php echo $memberFolder; ?>/statements.php" class="sidebar-link <?php echo isActivePage('statements.php'); ?>">
                <span class="sidebar-dot"></span>
                My Statement
            </a>

            <a href="<?php echo $appBase; ?>/<?php echo $memberFolder; ?>/referrals.php" class="sidebar-link <?php echo isActivePage('referrals.php'); ?>">
    <span class="sidebar-dot"></span>
    My Referrals
</a>

            <a href="<?php echo $appBase; ?>/group_chat.php" class="sidebar-link <?php echo isActivePage('group_chat.php'); ?>">
                <span class="sidebar-dot"></span>
                Group Chat
            </a>
        </div>

    <?php endif; ?>

    <div class="sidebar-footer">
        <a href="<?php echo $appBase; ?>/logout.php" class="sidebar-link">
            <span class="sidebar-dot"></span>
            Logout
        </a>
    </div>

</aside>

<script>
function toggleSidebar() {
    document.body.classList.toggle("sidebar-open");
}

function closeSidebar() {
    document.body.classList.remove("sidebar-open");
}

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeSidebar();
    }
});

document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("#appSidebar a").forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 900) {
                closeSidebar();
            }
        });
    });
});

window.addEventListener("resize", function () {
    if (window.innerWidth > 900) {
        closeSidebar();
    }
});
</script>