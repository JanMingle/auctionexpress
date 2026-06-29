<?php

if (!function_exists("auctionCoins")) {
    function auctionCoins($amount) {
        return number_format((float)$amount, 2) . " coins";
    }
}

if (!function_exists("auctionBadge")) {
    function auctionBadge($status) {
        if ($status === "paid") {
            return '<span class="badge badge-approved">Paid</span>';
        }

        if ($status === "matured") {
            return '<span class="badge badge-approved">Matured</span>';
        }

        if ($status === "active") {
            return '<span class="badge badge-approved">Counting Down</span>';
        }

        if ($status === "pending_seller_approval") {
            return '<span class="badge badge-pending">Pending Approval</span>';
        }

        if ($status === "rejected") {
            return '<span class="badge badge-rejected">Rejected</span>';
        }

        if ($status === "cancelled") {
            return '<span class="badge badge-rejected">Cancelled</span>';
        }

        return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status ?: "Unknown")) . '</span>';
    }
}

if (!function_exists("auctionResaleBadge")) {
    function auctionResaleBadge($status) {
        if ($status === "listed") {
            return '<span class="badge badge-pending">Scheduled for Next Auction</span>';
        }

        if ($status === "sold") {
            return '<span class="badge badge-approved">Sold</span>';
        }

        return '<span class="badge bg-secondary">Not Listed</span>';
    }
}

if (!function_exists("auctionEnsureWallet")) {
    function auctionEnsureWallet(mysqli $conn, int $tenant_id, int $user_id): void {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO member_coin_wallets
            (tenant_id, user_id, available_coins, locked_coins, total_earned)
            VALUES (?, ?, 0, 0, 0)
        ");
        $stmt->bind_param("ii", $tenant_id, $user_id);
        $stmt->execute();
    }
}

if (!function_exists("auctionAddLedger")) {
    function auctionAddLedger(
        mysqli $conn,
        int $tenant_id,
        int $user_id,
        ?int $related_user_id,
        ?int $auction_lot_id,
        ?int $auction_claim_id,
        string $type,
        float $amount,
        float $balance_after,
        string $note,
        ?int $created_by
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO coin_ledger
            (
                tenant_id, user_id, related_user_id, auction_lot_id, auction_claim_id,
                type, amount, balance_after, note, created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "iiiiisddsi",
            $tenant_id,
            $user_id,
            $related_user_id,
            $auction_lot_id,
            $auction_claim_id,
            $type,
            $amount,
            $balance_after,
            $note,
            $created_by
        );

        $stmt->execute();
    }
}

if (!function_exists("auctionSellerLabel")) {
    function auctionSellerLabel($row) {
        if (!empty($row["username"])) {
            return $row["username"];
        }

        if (!empty($row["member_code"])) {
            return $row["member_code"];
        }

        return trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
    }
}

if (!function_exists("auctionBuyerLabel")) {
    function auctionBuyerLabel($row) {
        if (!empty($row["buyer_username"])) {
            return $row["buyer_username"];
        }

        if (!empty($row["buyer_member_code"])) {
            return $row["buyer_member_code"];
        }

        return trim(($row["buyer_first_name"] ?? "") . " " . ($row["buyer_last_name"] ?? ""));
    }
}

if (!function_exists("auctionPurchaseSellerLabel")) {
    function auctionPurchaseSellerLabel($row) {
        if (!empty($row["seller_username"])) {
            return $row["seller_username"];
        }

        if (!empty($row["seller_member_code"])) {
            return $row["seller_member_code"];
        }

        return trim(($row["seller_first_name"] ?? "") . " " . ($row["seller_last_name"] ?? ""));
    }
}

if (!function_exists("auctionDisplayDateOrWaiting")) {
    function auctionDisplayDateOrWaiting($dateValue) {
        if (empty($dateValue) || $dateValue === "0000-00-00 00:00:00") {
            return "Starts after seller approves";
        }

        return date("d M Y H:i", strtotime($dateValue));
    }
}

if (!function_exists("auctionTableColumnExists")) {
    function auctionTableColumnExists(mysqli $conn, string $table, string $column): bool {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return (int)($row["total"] ?? 0) > 0;
    }
}

if (!function_exists("auctionBankSelectSql")) {
    function auctionBankSelectSql(mysqli $conn): string {
        $bankColumn = "";

        foreach (["bank_name", "bank", "account_bank"] as $possibleColumn) {
            if (auctionTableColumnExists($conn, "users", $possibleColumn)) {
                $bankColumn = $possibleColumn;
                break;
            }
        }

        if ($bankColumn === "") {
            return "'' AS bank_name";
        }

        $safeBankColumn = "`" . str_replace("`", "", $bankColumn) . "`";

        return "users.$safeBankColumn AS bank_name";
    }
}

if (!function_exists("auctionProcessMaturedPurchases")) {
    function auctionProcessMaturedPurchases(mysqli $conn, int $tenant_id, int $user_id): void {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM auction_claims
                WHERE tenant_id = ?
                AND buyer_user_id = ?
                AND status = 'active'
                AND matures_at IS NOT NULL
                AND matures_at <= NOW()
                FOR UPDATE
            ");
            $stmt->bind_param("ii", $tenant_id, $user_id);
            $stmt->execute();
            $claims = $stmt->get_result();

            while ($claim = $claims->fetch_assoc()) {
                $claim_id = (int)$claim["id"];

                $updateClaim = $conn->prepare("
                    UPDATE auction_claims
                    SET status = 'matured'
                    WHERE id = ?
                    AND tenant_id = ?
                    AND status = 'active'
                ");
                $updateClaim->bind_param("ii", $claim_id, $tenant_id);
                $updateClaim->execute();

                auctionAddLedger(
                    $conn,
                    $tenant_id,
                    $user_id,
                    null,
                    null,
                    $claim_id,
                    "coin_purchase_matured",
                    0,
                    0,
                    "Coin purchase matured and is ready to be sold.",
                    null
                );
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
        }
    }
}

if (!function_exists("auctionGetWallet")) {
    function auctionGetWallet(mysqli $conn, int $tenant_id, int $user_id): array {
        auctionEnsureWallet($conn, $tenant_id, $user_id);

        $walletStmt = $conn->prepare("
            SELECT available_coins, locked_coins, total_earned
            FROM member_coin_wallets
            WHERE tenant_id = ?
            AND user_id = ?
            LIMIT 1
        ");
        $walletStmt->bind_param("ii", $tenant_id, $user_id);
        $walletStmt->execute();

        $wallet = $walletStmt->get_result()->fetch_assoc();

        return [
            "available_coins" => (float)($wallet["available_coins"] ?? 0),
            "locked_coins" => (float)($wallet["locked_coins"] ?? 0),
            "total_earned" => (float)($wallet["total_earned"] ?? 0),
        ];
    }
}

if (!function_exists("auctionGetStatus")) {
    function auctionGetStatus(mysqli $conn, int $tenant_id): string {
        $stmt = $conn->prepare("
            SELECT auction_status
            FROM tenants
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        return $row["auction_status"] ?? "closed";
    }
}

if (!function_exists("auctionHasPendingBuyerPurchase")) {
    function auctionHasPendingBuyerPurchase(mysqli $conn, int $tenant_id, int $user_id): bool {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM auction_claims
            WHERE tenant_id = ?
            AND buyer_user_id = ?
            AND status = 'pending_seller_approval'
        ");
        $stmt->bind_param("ii", $tenant_id, $user_id);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        return (int)($row["total"] ?? 0) > 0;
    }
}

if (!function_exists("auctionTabs")) {
    function auctionTabs($activePage) {
        $tabs = [
            "auction.php" => "Actions",
            "auction_pending_approval.php" => "Pending Approval",
            "auction_purchases.php" => "My Coin Purchases"
        ];
        ?>
        <div class="auction-tabs mb-4">
            <?php foreach ($tabs as $file => $label): ?>
                <a href="<?php echo htmlspecialchars($file); ?>" class="auction-tab <?php echo $activePage === $file ? "active" : ""; ?>">
                    <?php echo htmlspecialchars($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists("auctionCommonStyles")) {
    function auctionCommonStyles() {
        ?>
        <style>
            .auction-hero {
                background:
                    radial-gradient(circle at top right, rgba(216,169,40,0.34), transparent 34%),
                    linear-gradient(135deg, #0f6b4f, #073f2f);
                color: #fff;
                border-radius: 30px;
                padding: 28px;
                margin-bottom: 24px;
                box-shadow: 0 22px 50px rgba(16,36,31,0.16);
            }

            .card-box {
                background: rgba(255,255,255,0.88);
                border: 1px solid rgba(255,255,255,0.78);
                border-radius: 24px;
                padding: 22px;
                box-shadow: 0 20px 45px rgba(16,36,31,0.10);
            }

            .quick-card-title {
                font-weight: 900;
                letter-spacing: -0.03em;
            }

            .stat-card {
                border-radius: 22px;
                padding: 20px;
                background: #fff;
                box-shadow: 0 18px 38px rgba(16,36,31,0.10);
            }

            .stat-label {
                color: #6c757d;
                font-size: 13px;
                font-weight: 700;
            }

            .stat-value {
                font-size: 24px;
                font-weight: 900;
                margin-top: 4px;
            }

            .auction-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }

            .auction-tab {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 11px 15px;
                border-radius: 999px;
                background: #fff;
                border: 1px solid rgba(16,36,31,0.12);
                color: #10241f;
                text-decoration: none;
                font-weight: 800;
                box-shadow: 0 10px 25px rgba(16,36,31,0.06);
            }

            .auction-tab.active {
                background: #10241f;
                color: #fff;
            }

            .table thead th {
                font-size: 12px;
                text-transform: uppercase;
                color: #6c757d;
            }
        </style>
        <?php
    }
}