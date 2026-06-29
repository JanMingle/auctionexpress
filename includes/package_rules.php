<?php

if (!function_exists("dbColumnExists")) {
    function dbColumnExists(mysqli $conn, string $table, string $column): bool {
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

if (!function_exists("packageDefaultRules")) {
    function packageDefaultRules(): array {
        return [
            "package_id" => null,
            "package_name" => "Default Savings Package",
            "package_type" => "savings",

            "minimum_saving_amount" => 200.00,
            "admin_fee_amount" => 20.00,
            "return_rate_percent" => 10.00,
            "return_calculation_type" => "once_off",
            "daily_return_percent" => 0.00,
            "maturity_days" => 30,
            "withdraw_after_days" => 30,
            "show_daily_returns" => 0,

            "auction_return_percent" => 10.00,
            "auction_maturity_days" => 30,
            "auction_min_coins" => 200.00,
            "auction_max_coins" => 0.00,

            "recruitment_bonus_percent" => 0.00,
            "bonus_claim_minimum" => 100.00,
            "enable_referrals" => 0,
            "enable_bonus_claims" => 0,
            "enable_auction" => 0,
            "enable_group_chat" => 1,
            "enable_guest_chat" => 0,
            "require_banking_details" => 1,
            "require_proof_of_payment" => 1,
            "package_status" => "active"
        ];
    }
}

if (!function_exists("packageRowToRules")) {
    function packageRowToRules(?array $package): array {
        $default = packageDefaultRules();

        if (!$package || empty($package["id"])) {
            return $default;
        }

        $packageName = $package["package_name"] ?? "Package";

        $enableAuction = (int)($package["enable_auction"] ?? 0);

        $packageType = $package["package_type"] ?? "";
        if ($packageType === "") {
            $packageType = $enableAuction === 1 ? "auction" : "savings";
        }

        if (!in_array($packageType, ["savings", "auction"], true)) {
            $packageType = $enableAuction === 1 ? "auction" : "savings";
        }

        $minimumSavingAmount = (float)($package["minimum_saving_amount"] ?? $default["minimum_saving_amount"]);
        $adminFeeAmount = (float)($package["admin_fee_amount"] ?? $default["admin_fee_amount"]);

        $returnRatePercent = (float)($package["return_rate_percent"] ?? $default["return_rate_percent"]);
        $returnCalculationType = $package["return_calculation_type"] ?? "once_off";

        if (!in_array($returnCalculationType, ["once_off", "daily_simple", "daily_compound"], true)) {
            $returnCalculationType = "once_off";
        }

        $dailyReturnPercent = (float)($package["daily_return_percent"] ?? 0);
        $maturityDays = (int)($package["maturity_days"] ?? 30);
        if ($maturityDays <= 0) {
            $maturityDays = 30;
        }

        $withdrawAfterDays = (int)($package["withdraw_after_days"] ?? $maturityDays);
        if ($withdrawAfterDays <= 0) {
            $withdrawAfterDays = $maturityDays;
        }

        /*
            Important:
            Auction must now follow the owner package values.
            So auction return and maturity come from:
            - return_rate_percent
            - maturity_days
            - minimum_saving_amount
        */
        $auctionReturnPercent = $returnRatePercent;
        $auctionMaturityDays = $maturityDays;
        $auctionMinCoins = $minimumSavingAmount;
        $auctionMaxCoins = (float)($package["auction_max_coins"] ?? 0);

        return [
            "package_id" => (int)$package["id"],
            "package_name" => $packageName,
            "package_type" => $packageType,

            "minimum_saving_amount" => $minimumSavingAmount,
            "admin_fee_amount" => $adminFeeAmount,
            "return_rate_percent" => $returnRatePercent,
            "return_calculation_type" => $returnCalculationType,
            "daily_return_percent" => $dailyReturnPercent,
            "maturity_days" => $maturityDays,
            "withdraw_after_days" => $withdrawAfterDays,
            "show_daily_returns" => (int)($package["show_daily_returns"] ?? 0),

            "auction_return_percent" => $auctionReturnPercent,
            "auction_maturity_days" => $auctionMaturityDays,
            "auction_min_coins" => $auctionMinCoins,
            "auction_max_coins" => $auctionMaxCoins,

            "recruitment_bonus_percent" => (float)($package["recruitment_bonus_percent"] ?? 0),
            "bonus_claim_minimum" => (float)($package["bonus_claim_minimum"] ?? 100),

            "enable_referrals" => (int)($package["enable_referrals"] ?? 0),
            "enable_bonus_claims" => (int)($package["enable_bonus_claims"] ?? 0),

            "enable_auction" => (
                $packageType === "auction" || $enableAuction === 1
            ) ? 1 : 0,

            "enable_group_chat" => (int)($package["enable_group_chat"] ?? 1),
            "enable_guest_chat" => (int)($package["enable_guest_chat"] ?? 0),
            "require_banking_details" => (int)($package["require_banking_details"] ?? 1),
            "require_proof_of_payment" => (int)($package["require_proof_of_payment"] ?? 1),

            "package_status" => $package["status"] ?? "active"
        ];
    }
}

if (!function_exists("getTenantPackageRules")) {
    function getTenantPackageRules(mysqli $conn, int $tenant_id): array {
        if (!dbColumnExists($conn, "tenants", "package_id")) {
            return packageDefaultRules();
        }

        $stmt = $conn->prepare("
            SELECT p.*
            FROM tenants t
            LEFT JOIN packages p ON p.id = t.package_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();

        $package = $stmt->get_result()->fetch_assoc();

        return packageRowToRules($package ?: null);
    }
}

if (!function_exists("syncTenantSettingsFromPackage")) {
    function syncTenantSettingsFromPackage(mysqli $conn, int $tenant_id, array $rules): void {
        $tenant_id = (int)$tenant_id;

        $return_rate_percent = (float)($rules["return_rate_percent"] ?? 10);
        $maturity_days = (int)($rules["maturity_days"] ?? 30);
        $recruitment_bonus_percent = (float)($rules["recruitment_bonus_percent"] ?? 0);

        $check = $conn->prepare("
            SELECT id
            FROM stokvel_settings
            WHERE tenant_id = ?
            LIMIT 1
        ");
        $check->bind_param("i", $tenant_id);
        $check->execute();

        $existing = $check->get_result()->fetch_assoc();

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE stokvel_settings
                SET
                    return_rate_percent = ?,
                    maturity_days = ?,
                    recruitment_bonus_percent = ?
                WHERE tenant_id = ?
            ");
            $stmt->bind_param(
                "didi",
                $return_rate_percent,
                $maturity_days,
                $recruitment_bonus_percent,
                $tenant_id
            );
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO stokvel_settings
                (
                    tenant_id,
                    return_rate_percent,
                    maturity_days,
                    recruitment_bonus_percent
                )
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "idid",
                $tenant_id,
                $return_rate_percent,
                $maturity_days,
                $recruitment_bonus_percent
            );
            $stmt->execute();
        }
    }
}

if (!function_exists("syncPackageSettingsForTenant")) {
    function syncPackageSettingsForTenant(mysqli $conn, int $tenant_id): array {
        $rules = getTenantPackageRules($conn, $tenant_id);
        syncTenantSettingsFromPackage($conn, $tenant_id, $rules);

        return $rules;
    }
}

if (!function_exists("packageMoney")) {
    function packageMoney($amount) {
        return "R" . number_format((float)$amount, 2);
    }
}

if (!function_exists("packageCoins")) {
    function packageCoins($amount) {
        return number_format((float)$amount, 2) . " coins";
    }
}

if (!function_exists("packageIsAuction")) {
    function packageIsAuction($rules) {
        return ($rules["package_type"] ?? "savings") === "auction"
            || (int)($rules["enable_auction"] ?? 0) === 1;
    }
}

if (!function_exists("packageIsSavings")) {
    function packageIsSavings($rules) {
        return !packageIsAuction($rules);
    }
}

if (!function_exists("calculatePackageReturn")) {
    function calculatePackageReturn($amount, $rules, $days = null) {
        $amount = (float)$amount;

        $returnType = $rules["return_calculation_type"] ?? "once_off";
        $onceOffRate = (float)($rules["return_rate_percent"] ?? 0);
        $dailyRate = (float)($rules["daily_return_percent"] ?? 0);
        $maturityDays = (int)($rules["maturity_days"] ?? 30);

        if ($days === null) {
            $days = $maturityDays;
        }

        $days = max(0, min((int)$days, $maturityDays));

        if ($returnType === "daily_simple") {
            $returnAmount = ($amount * $dailyRate / 100) * $days;
            $totalAmount = $amount + $returnAmount;

            return [
                "return_amount" => $returnAmount,
                "total_amount" => $totalAmount,
                "days_used" => $days,
                "rate_used" => $dailyRate,
                "type" => "daily_simple"
            ];
        }

        if ($returnType === "daily_compound") {
            $totalAmount = $amount * pow((1 + ($dailyRate / 100)), $days);
            $returnAmount = $totalAmount - $amount;

            return [
                "return_amount" => $returnAmount,
                "total_amount" => $totalAmount,
                "days_used" => $days,
                "rate_used" => $dailyRate,
                "type" => "daily_compound"
            ];
        }

        $returnAmount = ($amount * $onceOffRate) / 100;
        $totalAmount = $amount + $returnAmount;

        return [
            "return_amount" => $returnAmount,
            "total_amount" => $totalAmount,
            "days_used" => $maturityDays,
            "rate_used" => $onceOffRate,
            "type" => "once_off"
        ];
    }
}

if (!function_exists("calculateAuctionReturn")) {
    function calculateAuctionReturn($coins, $rules) {
        $coins = (float)$coins;
        $rate = (float)($rules["auction_return_percent"] ?? $rules["return_rate_percent"] ?? 3);
        $days = (int)($rules["auction_maturity_days"] ?? $rules["maturity_days"] ?? 3);

        $returnCoins = round(($coins * $rate) / 100, 2);
        $totalCoins = $coins + $returnCoins;

        return [
            "principal_coins" => $coins,
            "return_coins" => $returnCoins,
            "total_due_coins" => $totalCoins,
            "rate_used" => $rate,
            "days_used" => $days
        ];
    }
}

if (!function_exists("approvedElapsedDays")) {
    function approvedElapsedDays($approved_at) {
        if (empty($approved_at)) {
            return 0;
        }

        $approvedTime = strtotime($approved_at);

        if (!$approvedTime) {
            return 0;
        }

        $seconds = time() - $approvedTime;

        if ($seconds <= 0) {
            return 0;
        }

        return (int)floor($seconds / 86400);
    }
}

if (!function_exists("canWithdrawByPackage")) {
    function canWithdrawByPackage($approved_at, $rules) {
        $withdrawAfterDays = (int)($rules["withdraw_after_days"] ?? $rules["maturity_days"] ?? 30);
        $elapsedDays = approvedElapsedDays($approved_at);

        return $elapsedDays >= $withdrawAfterDays;
    }
}

if (!function_exists("packageReturnLabel")) {
    function packageReturnLabel($rules) {
        if (packageIsAuction($rules)) {
            $rate = number_format((float)($rules["auction_return_percent"] ?? $rules["return_rate_percent"] ?? 3), 2);
            $days = (int)($rules["auction_maturity_days"] ?? $rules["maturity_days"] ?? 3);

            return $rate . "% in " . $days . " days";
        }

        $type = $rules["return_calculation_type"] ?? "once_off";

        if ($type === "daily_simple") {
            return "Daily simple return";
        }

        if ($type === "daily_compound") {
            return "Daily compound return";
        }

        $rate = number_format((float)($rules["return_rate_percent"] ?? 0), 2);
        $days = (int)($rules["maturity_days"] ?? 30);

        return $rate . "% in " . $days . " days";
    }
}