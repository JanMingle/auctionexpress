<?php
session_start();

$isLoggedIn = isset($_SESSION["user_id"]);
$role = $_SESSION["role"] ?? "";

$dashboardLink = "login.php";

if ($isLoggedIn) {
    if ($role === "owner" || $role === "admin") {
        $dashboardLink = "admin/dashboard.php";
    } else {
        $dashboardLink = "users/dashboard.php";
    }
}

/*
    Add your WhatsApp numbers here later.
    Example: 27821234567
*/
$whatsappOne = "";
$whatsappTwo = "";

function whatsappLink($number) {
    $number = preg_replace("/[^0-9]/", "", (string)$number);

    if ($number === "") {
        return "#contact";
    }

    return "https://wa.me/" . $number;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auction Express</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at 20% 0%, rgba(69, 90, 145, 0.20), transparent 34%),
                radial-gradient(circle at 90% 10%, rgba(168, 59, 216, 0.13), transparent 30%),
                linear-gradient(180deg, #0d1829 0%, #101a2c 50%, #0b1424 100%);
            color: rgba(255,255,255,0.82);
            font-size: 12px;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        .site-nav {
            position: sticky;
            top: 0;
            z-index: 50;
            background:
                linear-gradient(rgba(13,24,41,0.88), rgba(13,24,41,0.94)),
                radial-gradient(circle at top right, rgba(59,130,246,0.12), transparent 34%);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            backdrop-filter: blur(16px);
        }

        .nav-inner {
            max-width: 1080px;
            margin: 0 auto;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 7px;
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 14px 24px rgba(168,59,216,0.20);
        }

        .brand-title {
            font-size: 13px;
            font-weight: 900;
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 10px;
            color: rgba(255,255,255,0.38);
            line-height: 1.2;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .nav-links a {
            color: rgba(255,255,255,0.56);
            font-size: 11px;
            font-weight: 800;
        }

        .nav-links a:hover {
            color: #ffffff;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-main {
            border: 0;
            border-radius: 999px;
            padding: 10px 16px;
            background: linear-gradient(135deg, #16a085, #1abc9c);
            color: #ffffff;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            box-shadow: 0 14px 24px rgba(26,188,156,0.16);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-main:hover {
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-soft {
            border-radius: 999px;
            padding: 9px 15px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.045);
            color: rgba(255,255,255,0.76);
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-soft:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.08);
        }

        .page-shell {
            max-width: 1080px;
            margin: 0 auto;
            padding: 26px 16px 50px;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.12fr 0.88fr;
            gap: 22px;
            align-items: stretch;
            margin-top: 14px;
        }

        .hero-card {
            background:
                linear-gradient(rgba(25,39,64,0.88), rgba(25,39,64,0.92)),
                radial-gradient(circle at top right, rgba(168,59,216,0.18), transparent 34%);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 6px;
            padding: 26px;
            box-shadow: 0 24px 48px rgba(0,0,0,0.20);
            position: relative;
            overflow: hidden;
        }

        .hero-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #a83bd8, #11a7d8);
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.20);
            color: #ffb74d;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .hero-title {
            font-size: 34px;
            line-height: 1.08;
            font-weight: 300;
            color: rgba(255,255,255,0.84);
            margin-bottom: 14px;
            letter-spacing: -0.04em;
        }

        .hero-title strong {
            color: #ffffff;
            font-weight: 900;
        }

        .hero-text {
            color: rgba(255,255,255,0.45);
            font-size: 13px;
            line-height: 1.75;
            max-width: 580px;
            margin-bottom: 18px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        .quick-panel {
            background:
                linear-gradient(rgba(22,34,57,0.88), rgba(22,34,57,0.94)),
                radial-gradient(circle at right top, rgba(16,185,129,0.12), transparent 30%);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 6px;
            padding: 22px;
            box-shadow: 0 24px 48px rgba(0,0,0,0.18);
        }

        .quick-title {
            font-size: 19px;
            font-weight: 300;
            color: rgba(255,255,255,0.70);
            margin-bottom: 14px;
        }

        .rate-box {
            background: linear-gradient(135deg, #ff9800, #ff7a00);
            color: #ffffff;
            border-radius: 6px;
            padding: 18px;
            margin-bottom: 14px;
            box-shadow: 0 18px 36px rgba(255,122,0,0.16);
        }

        .rate-label {
            font-size: 11px;
            color: rgba(255,255,255,0.76);
            margin-bottom: 3px;
            font-weight: 800;
        }

        .rate-value {
            font-size: 34px;
            line-height: 1;
            font-weight: 300;
        }

        .rate-note {
            margin-top: 8px;
            font-size: 11px;
            color: rgba(255,255,255,0.75);
        }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .mini-card {
            background: rgba(13,24,41,0.58);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 6px;
            padding: 12px;
        }

        .mini-label {
            font-size: 10px;
            color: rgba(255,255,255,0.34);
            margin-bottom: 4px;
        }

        .mini-value {
            font-size: 15px;
            color: rgba(255,255,255,0.74);
            font-weight: 800;
        }

        .section {
            margin-top: 24px;
        }

        .section-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .section-title {
            font-size: 20px;
            color: rgba(255,255,255,0.70);
            font-weight: 300;
            margin: 0;
        }

        .section-text {
            color: rgba(255,255,255,0.36);
            font-size: 12px;
            margin: 5px 0 0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .info-card {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 6px;
            padding: 16px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
            position: relative;
            overflow: hidden;
        }

        .info-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #a83bd8, #11a7d8);
            opacity: 0.9;
        }

        .info-icon {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            background: linear-gradient(135deg, #a83bd8, #c447f0);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            margin-bottom: 12px;
        }

        .info-title {
            color: rgba(255,255,255,0.74);
            font-size: 14px;
            font-weight: 900;
            margin-bottom: 7px;
        }

        .info-text {
            color: rgba(255,255,255,0.38);
            font-size: 12px;
            line-height: 1.6;
            margin: 0;
        }

        .sessions-panel {
            background: rgba(25, 39, 64, 0.86);
            border: 1px solid rgba(255,255,255,0.045);
            border-radius: 6px;
            padding: 18px;
            box-shadow: 0 18px 34px rgba(0,0,0,0.14);
        }

        .session-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .session-card {
            background: rgba(13,24,41,0.58);
            border: 1px solid rgba(255,255,255,0.055);
            border-radius: 6px;
            padding: 15px;
        }

        .session-name {
            color: rgba(255,255,255,0.38);
            font-size: 11px;
            margin-bottom: 5px;
        }

        .session-time {
            color: rgba(255,255,255,0.78);
            font-size: 19px;
            font-weight: 300;
        }

        .rules-list {
            display: grid;
            gap: 10px;
        }

        .rule-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: rgba(13,24,41,0.50);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 6px;
            padding: 12px;
        }

        .rule-mark {
            width: 24px;
            height: 24px;
            min-width: 24px;
            border-radius: 5px;
            background: linear-gradient(135deg, #16a085, #1abc9c);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 900;
        }

        .rule-text {
            color: rgba(255,255,255,0.50);
            font-size: 12px;
            line-height: 1.5;
        }

        .rule-text strong {
            color: rgba(255,255,255,0.82);
        }

        .contact-panel {
            background:
                linear-gradient(135deg, rgba(255,152,0,0.95), rgba(255,122,0,0.95));
            color: #ffffff;
            border-radius: 6px;
            padding: 20px;
            box-shadow: 0 18px 36px rgba(255,122,0,0.16);
            margin-top: 24px;
        }

        .contact-title {
            font-size: 19px;
            font-weight: 300;
            margin-bottom: 8px;
        }

        .contact-text {
            color: rgba(255,255,255,0.76);
            font-size: 12px;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .contact-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-whatsapp {
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.25);
            color: #ffffff;
            border-radius: 999px;
            padding: 10px 15px;
            font-size: 11px;
            font-weight: 900;
        }

        .btn-whatsapp:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.22);
        }

        .footer {
            color: rgba(255,255,255,0.34);
            font-size: 11px;
            text-align: center;
            padding: 28px 16px 32px;
        }

        .footer strong {
            color: rgba(255,255,255,0.66);
        }

        @media (max-width: 920px) {
            .hero {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .nav-links {
                display: none;
            }
        }

        @media (max-width: 620px) {
            .nav-inner {
                align-items: flex-start;
            }

            .nav-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-main,
            .btn-soft {
                padding: 9px 12px;
                font-size: 10px;
            }

            .hero-card,
            .quick-panel {
                padding: 20px;
            }

            .hero-title {
                font-size: 28px;
            }

            .rate-value {
                font-size: 30px;
            }

            .mini-grid,
            .session-list {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<nav class="site-nav">
    <div class="nav-inner">
        <a href="index.php" class="brand">
            <div class="brand-mark">A</div>
            <div>
                <div class="brand-title">Auction Express</div>
                <div class="brand-subtitle">Peer to peer online platform</div>
            </div>
        </a>

        <div class="nav-links">
            <a href="#sessions">Sessions</a>
            <a href="#rules">Rules</a>
            <a href="#support">Support</a>
            <a href="#contact">Contact</a>
        </div>

        <div class="nav-actions">
            <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="btn-soft">
                <?php echo $isLoggedIn ? "Dashboard" : "Login"; ?>
            </a>

            <?php if (!$isLoggedIn): ?>
                <a href="register.php" class="btn-main">
                    Register
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="page-shell">

    <section class="hero">
        <div class="hero-card">
            <div class="hero-kicker">
                🔹🔸 Auction Express 🔸🔹
            </div>

            <h1 class="hero-title">
                Where lives are transformed, <strong>digitally.</strong>
            </h1>

            <p class="hero-text">
                Auction Express is a peer to peer online platform built for daily auction sessions,
                member bidding, automated coin reallocations, and online support.
            </p>

            <div class="hero-actions">
                <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="btn-main">
                    <?php echo $isLoggedIn ? "Open Dashboard" : "Login To Account"; ?>
                </a>

                <?php if (!$isLoggedIn): ?>
                    <a href="register.php" class="btn-soft">
                        Create Account
                    </a>
                <?php endif; ?>

                <a href="#contact" class="btn-soft">
                    Get In Touch
                </a>
            </div>
        </div>

        <div class="quick-panel">
            <div class="quick-title">
                Auction Package
            </div>

            <div class="rate-box">
                <div class="rate-label">Return</div>
                <div class="rate-value">50%</div>
                <div class="rate-note">in 3 days</div>
            </div>

            <div class="mini-grid">
                <div class="mini-card">
                    <div class="mini-label">Minimum</div>
                    <div class="mini-value">R300.00</div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Maximum</div>
                    <div class="mini-value">R3000</div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Pay After Bidding</div>
                    <div class="mini-value">6 hours</div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Referral Bonus</div>
                    <div class="mini-value">5%</div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="sessions">
        <div class="section-head">
            <div>
                <h2 class="section-title">Daily Auction Sessions</h2>
                <p class="section-text">Two sessions per day, every day.</p>
            </div>
        </div>

        <div class="sessions-panel">
            <div class="session-list">
                <div class="session-card">
                    <div class="session-name">Session 1</div>
                    <div class="session-time">9:00 AM</div>
                </div>

                <div class="session-card">
                    <div class="session-name">Session 2</div>
                    <div class="session-time">6:00 PM</div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="features">
        <div class="section-head">
            <div>
                <h2 class="section-title">Platform Highlights</h2>
                <p class="section-text">Simple online auction flow for members.</p>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-card">
                <div class="info-icon">🏦</div>
                <div class="info-title">All SA Banks Accepted</div>
                <p class="info-text">
                    Members can participate using South African bank payments.
                </p>
            </div>

            <div class="info-card">
                <div class="info-icon">🔁</div>
                <div class="info-title">Automated Reallocation</div>
                <p class="info-text">
                    Reallocations of coins are within 24 hours and automated.
                </p>
            </div>

            <div class="info-card">
                <div class="info-icon">💻</div>
                <div class="info-title">Online Support</div>
                <p class="info-text">
                    Online support is available between 8am and 6pm.
                </p>
            </div>
        </div>
    </section>

    <section class="section" id="rules">
        <div class="section-head">
            <div>
                <h2 class="section-title">Auction Rules</h2>
                <p class="section-text">Important member participation details.</p>
            </div>
        </div>

        <div class="sessions-panel">
            <div class="rules-list">
                <div class="rule-row">
                    <div class="rule-mark">1</div>
                    <div class="rule-text">
                        <strong>50% in 3 days</strong> on the auction package.
                    </div>
                </div>

                <div class="rule-row">
                    <div class="rule-mark">2</div>
                    <div class="rule-text">
                        Minimum bid is <strong>R300.00</strong> and maximum bid is <strong>R3000</strong>.
                    </div>
                </div>

                <div class="rule-row">
                    <div class="rule-mark">3</div>
                    <div class="rule-text">
                        Members have <strong>6 hours</strong> to pay after bidding.
                    </div>
                </div>

                <div class="rule-row">
                    <div class="rule-mark">4</div>
                    <div class="rule-text">
                        Multiple bidding is allowed only after the first one is paid.
                    </div>
                </div>

                <div class="rule-row">
                    <div class="rule-mark">5</div>
                    <div class="rule-text">
                        Get <strong>5% unlimited referral bonus</strong>.
                    </div>
                </div>

                <div class="rule-row">
                    <div class="rule-mark">6</div>
                    <div class="rule-text">
                        Groups will be open between <strong>8am and 7pm daily</strong>.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="contact-panel" id="contact">
        <div class="contact-title">
            Get in touch with us
        </div>

        <p class="contact-text">
            For help, registration, bidding support, or account assistance, contact the Auction Express support team.
        </p>

        <div class="contact-actions">
            <a href="<?php echo htmlspecialchars(whatsappLink($whatsappOne)); ?>" class="btn-whatsapp">
                WhatsApp 1
            </a>

            <a href="<?php echo htmlspecialchars(whatsappLink($whatsappTwo)); ?>" class="btn-whatsapp">
                WhatsApp 2
            </a>

            <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="btn-whatsapp">
                <?php echo $isLoggedIn ? "Dashboard" : "Login"; ?>
            </a>
        </div>
    </section>

</div>

<footer class="footer" id="support">
    <strong>Auction Express</strong><br>
    Where Lives Are Transformed, Digitally.
</footer>

</body>
</html>