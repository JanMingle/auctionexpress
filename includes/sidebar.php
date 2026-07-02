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

if (!function_exists("sidebarLink")) {
    function sidebarLink($href, $fileName, $label, $icon) {
        $active = isActivePage($fileName);

        echo '
            <a href="' . htmlspecialchars($href) . '" class="sidebar-link ' . htmlspecialchars($active) . '">
                <span class="sidebar-icon">' . $icon . '</span>
                <span class="sidebar-label">' . htmlspecialchars($label) . '</span>
            </a>
        ';
    }
}

$tenant_id = (int)($_SESSION["tenant_id"] ?? 0);

$packageRules = [
    "package_type" => "savings",
    "enable_auction" => 0,
    "enable_referrals" => 0,
    "enable_group_chat" => 1
];

$packageRulesPath = __DIR__ . "/package_rules.php";

if ($tenant_id > 0 && isset($conn) && $conn instanceof mysqli && file_exists($packageRulesPath)) {
    require_once $packageRulesPath;
    $packageRules = getTenantPackageRules($conn, $tenant_id);
}

$isAuctionPackage = function_exists("packageIsAuction")
    ? packageIsAuction($packageRules)
    : (($packageRules["package_type"] ?? "savings") === "auction");

$isSavingsPackage = !$isAuctionPackage;

$showGroupChat = (int)($packageRules["enable_group_chat"] ?? 1) === 1;

/*
    Referrals must show for auction packages as well.
    For savings, it still follows the package setting.
*/
$showReferrals = $isAuctionPackage || ((int)($packageRules["enable_referrals"] ?? 0) === 1);
?>

<button type="button" class="mobile-menu-button" onclick="toggleSidebar()" aria-label="Open menu">
    <span></span>
    <span></span>
    <span></span>
</button>

<div class="sidebar-backdrop" onclick="closeSidebar()"></div>

<aside class="app-sidebar crypto-sidebar compact-sidebar" id="appSidebar">

    <div class="sidebar-close-row">
        <button type="button" class="sidebar-close-btn" onclick="closeSidebar()" aria-label="Close menu">
            ×
        </button>
    </div>

    <div class="sidebar-account-card compact-account-card">
        <div class="sidebar-account-avatar">
            <?php echo strtoupper(substr($displayUser, 0, 1)); ?>
        </div>

        <div>
            <div class="sidebar-account-label">Account</div>
            <div class="sidebar-account-name">
                <?php echo htmlspecialchars($displayUser); ?>
            </div>
            <div class="sidebar-account-role">
                <?php echo ucfirst(htmlspecialchars($role ?: "user")); ?>
            </div>
        </div>
    </div>

    <?php if ($role === "owner" || $role === "admin"): ?>

        <div class="sidebar-section">
            <div class="sidebar-section-title">
                Admin
            </div>
<?php
    sidebarLink($appBase . "/admin/dashboard.php", "dashboard.php", "Dashboard", "▦");
    sidebarLink($appBase . "/admin/members.php", "members.php", "Members", "●");
    sidebarLink($appBase . "/admin/admins.php", "admins.php", "Admin Users", "♟");
    sidebarLink($appBase . "/admin/referrals.php", "referrals.php", "Referrals", "⌖");
?>
            <?php if ($isSavingsPackage): ?>
                <?php
                    sidebarLink($appBase . "/admin/savings_requests.php", "savings_requests.php", "Saving Requests", "▣");
                    sidebarLink($appBase . "/admin/withdrawals.php", "withdrawals.php", "Withdrawals", "⇄");
                    sidebarLink($appBase . "/admin/ledger.php", "ledger.php", "Ledger", "▤");
                ?>
            <?php endif; ?>

            <?php if ($isAuctionPackage): ?>
                <?php
                    sidebarLink($appBase . "/admin/auction.php", "auction.php", "Auction", "◈");
                    sidebarLink($appBase . "/admin/auction_pending_approval.php", "auction_pending_approval.php", "Pending Approval", "▣");
                    sidebarLink($appBase . "/admin/auction_purchase_history.php", "auction_purchase_history.php", "Auction History", "▤");
                ?>
            <?php endif; ?>

            <?php if ($showGroupChat): ?>
                <?php sidebarLink($appBase . "/group_chat.php", "group_chat.php", "Group Chat", "☰"); ?>
            <?php endif; ?>

            <?php sidebarLink($appBase . "/admin/settings.php", "settings.php", "Settings", "⚙"); ?>
        </div>

    <?php else: ?>

        <div class="sidebar-section">
            <div class="sidebar-section-title">
                Menu
            </div>

            <?php
                sidebarLink($appBase . "/" . $memberFolder . "/dashboard.php", "dashboard.php", "Dashboard", "▦");
                sidebarLink($appBase . "/" . $memberFolder . "/profile.php", "profile.php", "User Profile", "●");
            ?>

            <?php if ($isSavingsPackage): ?>
                <?php
                    sidebarLink($appBase . "/" . $memberFolder . "/savings.php", "savings.php", "My Savings", "▣");
                    sidebarLink($appBase . "/" . $memberFolder . "/withdrawals.php", "withdrawals.php", "My Withdrawals", "⇄");
                    sidebarLink($appBase . "/" . $memberFolder . "/statements.php", "statements.php", "My Statement", "▤");
                ?>
            <?php endif; ?>

            <?php if ($isAuctionPackage): ?>
                <?php
                    sidebarLink($appBase . "/" . $memberFolder . "/auction.php", "auction.php", "Auction", "◈");
                    sidebarLink($appBase . "/" . $memberFolder . "/auction_pending_approval.php", "auction_pending_approval.php", "Pending Shares", "▣");
                    sidebarLink($appBase . "/" . $memberFolder . "/auction_purchases.php", "auction_purchases.php", "Shares I Bought", "✣");
                    sidebarLink($appBase . "/" . $memberFolder . "/sell_shares.php", "sell_shares.php", "Sell Shares", "▤");
                    sidebarLink($appBase . "/" . $memberFolder . "/auction_history.php", "auction_history.php", "Auction History", "☷");
                ?>
            <?php endif; ?>

            <?php if ($showReferrals): ?>
                <?php sidebarLink($appBase . "/" . $memberFolder . "/referrals.php", "referrals.php", "My Referrals", "⌖"); ?>
            <?php endif; ?>

            <?php if ($showGroupChat): ?>
                <?php sidebarLink($appBase . "/group_chat.php", "group_chat.php", "Group Chat", "☰"); ?>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <div class="sidebar-footer">
        <?php sidebarLink($appBase . "/logout.php", "logout.php", "Logout", "▰"); ?>
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