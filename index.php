<?php
// landing.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stokvel Circle - Digital Stokvel Platform</title>
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
            --gold-soft: #fff3bd;
            --cream: #fbf7ed;
            --ink: #10241f;
            --muted: #667085;
            --border: rgba(16, 36, 31, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 8% 10%, rgba(216, 169, 40, 0.32), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(15, 107, 79, 0.24), transparent 32%),
                linear-gradient(135deg, #fff4c7 0%, #fbf7ed 36%, #e7f7ef 74%, #dff5e9 100%);
            background-attachment: fixed;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        .site-shell {
            position: relative;
            overflow: hidden;
        }

        .money-bg-symbol {
            position: fixed;
            right: 34px;
            bottom: 28px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: linear-gradient(145deg, rgba(248,216,106,0.42), rgba(216,169,40,0.24));
            color: rgba(74,53,4,0.16);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 86px;
            font-weight: 900;
            transform: rotate(-14deg);
            pointer-events: none;
            z-index: 0;
        }

        .site-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 16px 0;
            background: rgba(251, 247, 237, 0.78);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(16,36,31,0.08);
        }

        .nav-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 900;
            color: var(--ink);
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: #4a3504;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(216,169,40,0.22);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 18px;
            font-size: 14px;
            font-weight: 800;
        }

        .nav-links a {
            color: #35544a;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-stokvel {
            border: 0;
            border-radius: 16px;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            color: #ffffff;
            font-weight: 900;
            box-shadow: 0 16px 30px rgba(15,107,79,0.20);
        }

        .btn-stokvel:hover {
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-soft {
            border-radius: 16px;
            padding: 11px 16px;
            border: 1px solid rgba(16,36,31,0.12);
            background: rgba(255,255,255,0.72);
            color: var(--ink);
            font-weight: 900;
        }

        .btn-soft:hover {
            background: #ffffff;
            color: var(--green-dark);
        }

        .hero {
            position: relative;
            z-index: 1;
            max-width: 1180px;
            margin: 0 auto;
            padding: 78px 18px 46px;
            display: grid;
            grid-template-columns: 1fr 0.95fr;
            gap: 36px;
            align-items: center;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: rgba(255,255,255,0.74);
            border: 1px solid rgba(255,255,255,0.88);
            color: #7a5a09;
            border-radius: 999px;
            padding: 9px 13px;
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 20px;
            box-shadow: 0 12px 28px rgba(16,36,31,0.08);
        }

        .hero-kicker::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--green);
        }

        .hero-title {
            font-size: clamp(42px, 6vw, 76px);
            line-height: 0.94;
            font-weight: 900;
            letter-spacing: -0.075em;
            margin-bottom: 22px;
            color: var(--ink);
        }

        .hero-title span {
            color: var(--green);
        }

        .hero-text {
            color: var(--muted);
            font-size: 16px;
            line-height: 1.7;
            max-width: 650px;
            margin-bottom: 26px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }

        .trust-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            max-width: 640px;
        }

        .trust-card {
            background: rgba(255,255,255,0.74);
            border: 1px solid rgba(255,255,255,0.86);
            border-radius: 22px;
            padding: 16px;
            box-shadow: 0 16px 35px rgba(16,36,31,0.08);
            backdrop-filter: blur(16px);
        }

        .trust-value {
            font-size: 18px;
            font-weight: 900;
            color: var(--green-dark);
        }

        .trust-label {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .trading-panel {
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.25), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,0.92), rgba(232,247,239,0.88));
            border: 1px solid rgba(255,255,255,0.88);
            border-radius: 34px;
            padding: 22px;
            box-shadow: 0 30px 80px rgba(16,36,31,0.16);
            backdrop-filter: blur(18px);
            position: relative;
            overflow: hidden;
        }

        .trading-panel::after {
            content: "R";
            position: absolute;
            right: 22px;
            top: 18px;
            width: 74px;
            height: 74px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f8d86a, #d8a928);
            color: rgba(74,53,4,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            opacity: 0.22;
            transform: rotate(-13deg);
        }

        .panel-top {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 18px;
            position: relative;
            z-index: 2;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
            margin-bottom: 4px;
        }

        .panel-subtitle {
            color: var(--muted);
            font-size: 13px;
        }

        .live-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #fff8df;
            border: 1px solid rgba(216,169,40,0.35);
            color: #7a5a09;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .live-pill::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 rgba(16,185,129,0.6);
            animation: pulseDot 1.4s infinite;
        }

        @keyframes pulseDot {
            0% {
                box-shadow: 0 0 0 0 rgba(16,185,129,0.6);
            }
            70% {
                box-shadow: 0 0 0 8px rgba(16,185,129,0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(16,185,129,0);
            }
        }

        .chart-card {
            background: #081f18;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 26px;
            padding: 16px;
            overflow: hidden;
            position: relative;
            z-index: 2;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.03);
        }

        .chart-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 10px;
            color: rgba(255,255,255,0.78);
            font-size: 13px;
        }

        .chart-price {
            font-size: 25px;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: -0.04em;
        }

        .chart-change {
            color: #82f2b8;
            font-weight: 900;
        }

        #liveTradingChart {
            width: 100%;
            height: 260px;
            display: block;
        }

        .mini-chart-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 14px;
            position: relative;
            z-index: 2;
        }

        .mini-card {
            background: rgba(255,255,255,0.86);
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: 20px;
            padding: 14px;
        }

        .mini-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .mini-value {
            margin-top: 4px;
            font-size: 18px;
            font-weight: 900;
            color: var(--green-dark);
        }

        .section {
            position: relative;
            z-index: 1;
            max-width: 1180px;
            margin: 0 auto;
            padding: 44px 18px;
        }

        .section-head {
            text-align: center;
            max-width: 720px;
            margin: 0 auto 28px;
        }

        .section-kicker {
            color: var(--green);
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            margin-bottom: 8px;
        }

        .section-title {
            font-size: clamp(30px, 4vw, 46px);
            line-height: 1.04;
            letter-spacing: -0.055em;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .section-text {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .feature-card {
            background: rgba(255,255,255,0.82);
            border: 1px solid rgba(255,255,255,0.88);
            border-radius: 28px;
            padding: 24px;
            box-shadow: 0 20px 50px rgba(16,36,31,0.10);
            backdrop-filter: blur(16px);
            min-height: 220px;
            position: relative;
            overflow: hidden;
        }

        .feature-card::after {
            content: "";
            position: absolute;
            right: -48px;
            bottom: -54px;
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: rgba(216,169,40,0.16);
        }

        .feature-icon {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            margin-bottom: 16px;
            box-shadow: 0 14px 28px rgba(15,107,79,0.2);
        }

        .feature-title {
            font-size: 17px;
            font-weight: 900;
            margin-bottom: 8px;
            letter-spacing: -0.03em;
        }

        .feature-text {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
            margin-bottom: 0;
        }

        .growth-section {
            display: grid;
            grid-template-columns: 0.85fr 1.15fr;
            gap: 22px;
            align-items: stretch;
        }

        .growth-card {
            background:
                radial-gradient(circle at top right, rgba(15,107,79,0.16), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,0.92), rgba(255,248,223,0.9));
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: 30px;
            padding: 24px;
            box-shadow: 0 22px 55px rgba(16,36,31,0.12);
            backdrop-filter: blur(18px);
        }

        #growthLineChart {
            width: 100%;
            height: 280px;
            display: block;
        }

        .pricing-card {
            max-width: 760px;
            margin: 0 auto;
            background:
                radial-gradient(circle at top right, rgba(216,169,40,0.26), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,0.94), rgba(232,247,239,0.9));
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: 32px;
            padding: 28px;
            box-shadow: 0 24px 65px rgba(16,36,31,0.14);
            text-align: center;
        }

        .pricing-price {
            font-size: 48px;
            font-weight: 900;
            letter-spacing: -0.06em;
            color: var(--green-dark);
            margin: 10px 0 4px;
        }

        .pricing-price span {
            font-size: 15px;
            color: var(--muted);
            letter-spacing: 0;
        }

        .pricing-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            text-align: left;
            margin: 22px 0;
        }

        .pricing-item {
            background: rgba(255,255,255,0.74);
            border: 1px solid rgba(16,36,31,0.08);
            border-radius: 18px;
            padding: 12px 14px;
            color: #34433d;
            font-weight: 800;
            font-size: 14px;
        }

        .disclaimer {
            margin-top: 12px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
        }

        .footer {
            position: relative;
            z-index: 1;
            padding: 34px 18px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 980px) {
            .hero {
                grid-template-columns: 1fr;
                padding-top: 52px;
            }

            .feature-grid,
            .growth-section {
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

            .btn-soft,
            .btn-stokvel {
                padding: 10px 12px;
                font-size: 13px;
            }

            .hero-title {
                font-size: 44px;
            }

            .trust-row,
            .mini-chart-grid,
            .pricing-list {
                grid-template-columns: 1fr;
            }

            .trading-panel,
            .growth-card,
            .pricing-card,
            .feature-card {
                border-radius: 24px;
            }

            #liveTradingChart {
                height: 230px;
            }
        }
    </style>
</head>
<body>

<div class="site-shell">
    <div class="money-bg-symbol">R</div>

    <header class="site-nav">
        <div class="nav-inner">
            <a href="landing.php" class="brand">
                <span class="brand-icon">S</span>
                <span>Stokvel Circle</span>
            </a>

            <nav class="nav-links">
                <a href="#features">Features</a>
                <a href="#charts">Live Charts</a>
                <a href="#pricing">Pricing</a>
            </nav>

            <div class="nav-actions">
                <a href="login.php" class="btn-soft">Login</a>
                <a href="register.php" class="btn-stokvel">Create Stokvel</a>
            </div>
        </div>
    </header>

    <section class="hero">
        <div>
            <div class="hero-kicker">
                Digital stokvel platform for trusted money circles
            </div>

            <h1 class="hero-title">
                fave together. <span>Grow together.</span>
            </h1>

            <p class="hero-text">
                Create a private stokvel, invite members with a link, approve savings,
                track returns, manage withdrawals, and keep everyone updated with a group chat.
                Built for modern savings groups that need visibility, trust, and simple money tracking.
            </p>

            <div class="hero-actions">
                <a href="register.php" class="btn-stokvel">
                    Start Your Stokvel
                </a>

                <a href="login.php" class="btn-soft">
                    Member Login
                </a>
            </div>

            <div class="trust-row">
                <div class="trust-card">
                    <div class="trust-value">Private</div>
                    <div class="trust-label">Each stokvel has its own data</div>
                </div>

                <div class="trust-card">
                    <div class="trust-value">Tracked</div>
                    <div class="trust-label">Savings, proof, returns, payouts</div>
                </div>

                <div class="trust-card">
                    <div class="trust-value">Simple</div>
                    <div class="trust-label">Member code login, no email needed</div>
                </div>
            </div>
        </div>

        <div class="trading-panel">
            <div class="panel-top">
                <div>
                    <div class="panel-title">Live Stokvel Growth View</div>
                    <div class="panel-subtitle">Demo trading-style chart for savings momentum</div>
                </div>

                <span class="live-pill">Live demo</span>
            </div>

            <div class="chart-card">
                <div class="chart-meta">
                    <div>
                        <div style="font-size: 12px; color: rgba(255,255,255,0.55);">STOKVEL / ZAR</div>
                        <div class="chart-price" id="chartPrice">R12,480.00</div>
                    </div>

                    <div class="chart-change" id="chartChange">+2.42%</div>
                </div>

                <canvas id="liveTradingChart"></canvas>
            </div>

            <div class="mini-chart-grid">
                <div class="mini-card">
                    <div class="mini-label">Active Savings</div>
                    <div class="mini-value" id="activeSavings">R84,250</div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Expected Returns</div>
                    <div class="mini-value" id="expectedReturns">R8,425</div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Matured</div>
                    <div class="mini-value" id="maturedValue">R21,700</div>
                </div>
            </div>

            <p class="disclaimer">
                This is a simulated visual chart for the landing page. Connect it to your real ledger later if you want actual platform data.
            </p>
        </div>
    </section>

    <section class="section" id="features">
        <div class="section-head">
            <div class="section-kicker">What the platform does</div>
            <h2 class="section-title">Everything your stokvel needs in one place</h2>
            <p class="section-text">
                The system gives every stokvel owner their own admin dashboard,
                while members get a simple dashboard to save, upload proof, track maturity,
                request withdrawals, and chat with the group.
            </p>
        </div>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon">01</div>
                <div class="feature-title">Multi-tenant stokvel accounts</div>
                <p class="feature-text">
                    Every owner creates their own stokvel. Members join under that owner using a private registration link.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">02</div>
                <div class="feature-title">Savings and proof tracking</div>
                <p class="feature-text">
                    Members submit saving amounts, upload proof of payment, and admins approve only verified payments.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">03</div>
                <div class="feature-title">Returns and maturity countdown</div>
                <p class="feature-text">
                    Return percentage and maturity days are set by the admin. Members can see countdowns until withdrawal time.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">04</div>
                <div class="feature-title">Withdrawals and closed cycles</div>
                <p class="feature-text">
                    Once savings mature, members request withdrawals. Admins approve and mark them paid to close the cycle.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">05</div>
                <div class="feature-title">Group chat</div>
                <p class="feature-text">
                    Members can communicate in one shared stokvel chat. Guests can preview and send limited messages before registering.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">06</div>
                <div class="feature-title">Ledger and statements</div>
                <p class="feature-text">
                    Admins get a full ledger view, while members get their own personal statement history.
                </p>
            </div>
        </div>
    </section>

    <section class="section" id="charts">
        <div class="growth-section">
            <div class="growth-card">
                <div class="section-kicker">Trading-style experience</div>
                <h2 class="section-title">Make savings feel alive</h2>
                <p class="section-text">
                    The landing page uses animated charts to make the platform feel modern and exciting.
                    Later, these charts can be connected to your real database totals such as active savings,
                    returns, withdrawals, and growth over time.
                </p>

                <div class="pricing-list">
                    <div class="pricing-item">Live-looking chart animation</div>
                    <div class="pricing-item">Savings growth visual</div>
                    <div class="pricing-item">Modern dashboard feel</div>
                    <div class="pricing-item">Can connect to real data later</div>
                </div>
            </div>

            <div class="growth-card">
                <div class="panel-top">
                    <div>
                        <div class="panel-title">Savings Growth Trend</div>
                        <div class="panel-subtitle">Demo line chart showing stokvel growth</div>
                    </div>

                    <span class="live-pill">Updating</span>
                </div>

                <canvas id="growthLineChart"></canvas>
            </div>
        </div>
    </section>

    <section class="section" id="pricing">
        <div class="pricing-card">
            <div class="section-kicker">Simple pricing</div>
            <h2 class="section-title">Start with one stokvel</h2>
            <p class="section-text">
                Use this as the public website to explain the product and convert stokvel owners into paying subscribers.
            </p>

            <div class="pricing-price">
                R450 <span>/ month</span>
            </div>

            <div class="pricing-list">
                <div class="pricing-item">Owner admin dashboard</div>
                <div class="pricing-item">Member registration links</div>
                <div class="pricing-item">Savings and proof tracking</div>
                <div class="pricing-item">Withdrawal management</div>
                <div class="pricing-item">Group chat</div>
                <div class="pricing-item">Ledger and statements</div>
            </div>

            <a href="register.php" class="btn-stokvel">
                Create Your Stokvel
            </a>

            <p class="disclaimer">
                Pricing can be changed later when you add subscription billing.
            </p>
        </div>
    </section>

    <footer class="footer">
        © <?php echo date("Y"); ?> Stokvel Circle. Built for trusted community savings.
    </footer>
</div>

<script>
const tradingCanvas = document.getElementById("liveTradingChart");
const tradingCtx = tradingCanvas.getContext("2d");

const growthCanvas = document.getElementById("growthLineChart");
const growthCtx = growthCanvas.getContext("2d");

let candles = [];
let growthPoints = [];
let currentPrice = 12480;

function resizeCanvas(canvas) {
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;

    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;

    const ctx = canvas.getContext("2d");
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function rand(min, max) {
    return Math.random() * (max - min) + min;
}

function seedCandles() {
    candles = [];
    let price = currentPrice;

    for (let i = 0; i < 34; i++) {
        const open = price;
        const close = open + rand(-220, 260);
        const high = Math.max(open, close) + rand(40, 170);
        const low = Math.min(open, close) - rand(40, 170);

        candles.push({ open, close, high, low });
        price = close;
    }

    currentPrice = price;
}

function addCandle() {
    const last = candles[candles.length - 1];
    const open = last ? last.close : currentPrice;
    const close = open + rand(-180, 230);
    const high = Math.max(open, close) + rand(30, 140);
    const low = Math.min(open, close) - rand(30, 140);

    candles.push({ open, close, high, low });

    if (candles.length > 34) {
        candles.shift();
    }

    currentPrice = close;

    const change = ((close - candles[0].open) / candles[0].open) * 100;

    document.getElementById("chartPrice").textContent = "R" + close.toLocaleString("en-ZA", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    document.getElementById("chartChange").textContent = (change >= 0 ? "+" : "") + change.toFixed(2) + "%";
    document.getElementById("chartChange").style.color = change >= 0 ? "#82f2b8" : "#ff9b9b";

    document.getElementById("activeSavings").textContent = "R" + Math.round(84000 + rand(-1400, 2800)).toLocaleString("en-ZA");
    document.getElementById("expectedReturns").textContent = "R" + Math.round(8400 + rand(-300, 520)).toLocaleString("en-ZA");
    document.getElementById("maturedValue").textContent = "R" + Math.round(21700 + rand(-800, 1300)).toLocaleString("en-ZA");
}

function drawTradingChart() {
    resizeCanvas(tradingCanvas);

    const rect = tradingCanvas.getBoundingClientRect();
    const w = rect.width;
    const h = rect.height;

    tradingCtx.clearRect(0, 0, w, h);

    tradingCtx.fillStyle = "#081f18";
    tradingCtx.fillRect(0, 0, w, h);

    const padding = 18;
    const chartW = w - padding * 2;
    const chartH = h - padding * 2;

    const allHighs = candles.map(c => c.high);
    const allLows = candles.map(c => c.low);
    const maxPrice = Math.max(...allHighs);
    const minPrice = Math.min(...allLows);

    function y(price) {
        return padding + ((maxPrice - price) / (maxPrice - minPrice || 1)) * chartH;
    }

    tradingCtx.strokeStyle = "rgba(255,255,255,0.06)";
    tradingCtx.lineWidth = 1;

    for (let i = 0; i <= 5; i++) {
        const yy = padding + (chartH / 5) * i;
        tradingCtx.beginPath();
        tradingCtx.moveTo(padding, yy);
        tradingCtx.lineTo(w - padding, yy);
        tradingCtx.stroke();
    }

    const candleGap = 7;
    const candleW = Math.max(7, (chartW / candles.length) - candleGap);

    candles.forEach((candle, i) => {
        const x = padding + i * (chartW / candles.length) + candleGap / 2;
        const centerX = x + candleW / 2;

        const isUp = candle.close >= candle.open;
        const color = isUp ? "#82f2b8" : "#ff9b9b";

        tradingCtx.strokeStyle = color;
        tradingCtx.fillStyle = color;
        tradingCtx.lineWidth = 2;

        tradingCtx.beginPath();
        tradingCtx.moveTo(centerX, y(candle.high));
        tradingCtx.lineTo(centerX, y(candle.low));
        tradingCtx.stroke();

        const bodyTop = y(Math.max(candle.open, candle.close));
        const bodyBottom = y(Math.min(candle.open, candle.close));
        const bodyHeight = Math.max(4, bodyBottom - bodyTop);

        tradingCtx.globalAlpha = 0.95;
        tradingCtx.fillRect(x, bodyTop, candleW, bodyHeight);
        tradingCtx.globalAlpha = 1;
    });
}

function seedGrowth() {
    growthPoints = [];
    let value = 25000;

    for (let i = 0; i < 34; i++) {
        value += rand(400, 1800);
        growthPoints.push(value);
    }
}

function addGrowthPoint() {
    const last = growthPoints[growthPoints.length - 1] || 25000;
    const next = last + rand(-500, 1600);

    growthPoints.push(next);

    if (growthPoints.length > 34) {
        growthPoints.shift();
    }
}

function drawGrowthChart() {
    resizeCanvas(growthCanvas);

    const rect = growthCanvas.getBoundingClientRect();
    const w = rect.width;
    const h = rect.height;

    growthCtx.clearRect(0, 0, w, h);

    const padding = 22;
    const chartW = w - padding * 2;
    const chartH = h - padding * 2;

    const max = Math.max(...growthPoints);
    const min = Math.min(...growthPoints);

    function x(i) {
        return padding + (chartW / (growthPoints.length - 1)) * i;
    }

    function y(value) {
        return padding + ((max - value) / (max - min || 1)) * chartH;
    }

    growthCtx.strokeStyle = "rgba(16,36,31,0.08)";
    growthCtx.lineWidth = 1;

    for (let i = 0; i <= 5; i++) {
        const yy = padding + (chartH / 5) * i;
        growthCtx.beginPath();
        growthCtx.moveTo(padding, yy);
        growthCtx.lineTo(w - padding, yy);
        growthCtx.stroke();
    }

    const gradient = growthCtx.createLinearGradient(0, padding, 0, h - padding);
    gradient.addColorStop(0, "rgba(15,107,79,0.24)");
    gradient.addColorStop(1, "rgba(15,107,79,0.00)");

    growthCtx.beginPath();
    growthPoints.forEach((point, i) => {
        if (i === 0) {
            growthCtx.moveTo(x(i), y(point));
        } else {
            growthCtx.lineTo(x(i), y(point));
        }
    });

    growthCtx.lineTo(x(growthPoints.length - 1), h - padding);
    growthCtx.lineTo(x(0), h - padding);
    growthCtx.closePath();
    growthCtx.fillStyle = gradient;
    growthCtx.fill();

    growthCtx.beginPath();
    growthPoints.forEach((point, i) => {
        if (i === 0) {
            growthCtx.moveTo(x(i), y(point));
        } else {
            growthCtx.lineTo(x(i), y(point));
        }
    });

    growthCtx.strokeStyle = "#0f6b4f";
    growthCtx.lineWidth = 4;
    growthCtx.stroke();

    const lastX = x(growthPoints.length - 1);
    const lastY = y(growthPoints[growthPoints.length - 1]);

    growthCtx.fillStyle = "#d8a928";
    growthCtx.beginPath();
    growthCtx.arc(lastX, lastY, 6, 0, Math.PI * 2);
    growthCtx.fill();
}

function animateCharts() {
    addCandle();
    addGrowthPoint();
    drawTradingChart();
    drawGrowthChart();
}

seedCandles();
seedGrowth();
drawTradingChart();
drawGrowthChart();

setInterval(animateCharts, 1300);

window.addEventListener("resize", function () {
    drawTradingChart();
    drawGrowthChart();
});
</script>

</body>
</html>