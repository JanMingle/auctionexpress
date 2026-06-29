<?php

function getTenantPackageRules($conn, $tenant_id) {
    $stmt = $conn->prepare("
        SELECT
            p.id AS package_id,
            p.package_name,
            p.package_type,

            p.minimum_saving_amount,
            p.admin_fee_amount,
            p.return_rate_percent,
            p.return_calculation_type,
            p.daily_return_percent,
            p.maturity_days,
            p.withdraw_after_days,
            p.show_daily_returns,

            p.auction_return_percent,
            p.auction_maturity_days,
            p.auction_min_coins,
            p.auction_max_coins,

            p.recruitment_bonus_percent,
            p.bonus_claim_minimum,
            p.enable_referrals,
            p.enable_bonus_claims,
            p.enable_auction,
            p.enable_group_chat,
            p.enable_guest_chat,
            p.require_banking_details,
            p.require_proof_of_payment,
            p.status AS package_status
        FROM tenants t
        LEFT JOIN packages p ON p.id = t.package_id
        WHERE t.id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $rules = $stmt->get_result()->fetch_assoc();

    if (!$rules || empty($rules["package_id"])) {
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

            "auction_return_percent" => 3.00,
            "auction_maturity_days" => 3,
            "auction_min_coins" => 0.00,
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

    $packageType = $rules["package_type"] ?? "savings";

    if (!in_array($packageType, ["savings", "auction"], true)) {
        $packageType = "savings";
    }

    return [
        "package_id" => (int)$rules["package_id"],
        "package_name" => $rules["package_name"] ?? "Package",
        "package_type" => $packageType,

        "minimum_saving_amount" => (float)$rules["minimum_saving_amount"],
        "admin_fee_amount" => (float)$rules["admin_fee_amount"],
        "return_rate_percent" => (float)$rules["return_rate_percent"],
        "return_calculation_type" => $rules["return_calculation_type"] ?? "once_off",
        "daily_return_percent" => (float)$rules["daily_return_percent"],
        "maturity_days" => (int)$rules["maturity_days"],
        "withdraw_after_days" => (int)$rules["withdraw_after_days"],
        "show_daily_returns" => (int)$rules["show_daily_returns"],

        "auction_return_percent" => (float)$rules["auction_return_percent"],
        "auction_maturity_days" => (int)$rules["auction_maturity_days"],
        "auction_min_coins" => (float)$rules["auction_min_coins"],
        "auction_max_coins" => (float)$rules["auction_max_coins"],

        "recruitment_bonus_percent" => (float)$rules["recruitment_bonus_percent"],
        "bonus_claim_minimum" => (float)$rules["bonus_claim_minimum"],
        "enable_referrals" => (int)$rules["enable_referrals"],
        "enable_bonus_claims" => (int)$rules["enable_bonus_claims"],
        "enable_auction" => (int)$rules["enable_auction"],
        "enable_group_chat" => (int)$rules["enable_group_chat"],
        "enable_guest_chat" => (int)$rules["enable_guest_chat"],
        "require_banking_details" => (int)$rules["require_banking_details"],
        "require_proof_of_payment" => (int)$rules["require_proof_of_payment"],
        "package_status" => $rules["package_status"] ?? "active"
    ];
}

function packageMoney($amount) {
    return "R" . number_format((float)$amount, 2);
}

function packageCoins($amount) {
    return number_format((float)$amount, 2) . " coins";
}

function packageIsAuction($rules) {
    return ($rules["package_type"] ?? "savings") === "auction"
        || (int)($rules["enable_auction"] ?? 0) === 1;
}

function packageIsSavings($rules) {
    return !packageIsAuction($rules);
}

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

function calculateAuctionReturn($coins, $rules) {
    $coins = (float)$coins;
    $rate = (float)($rules["auction_return_percent"] ?? 3);
    $days = (int)($rules["auction_maturity_days"] ?? 3);

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

function canWithdrawByPackage($approved_at, $rules) {
    $withdrawAfterDays = (int)($rules["withdraw_after_days"] ?? $rules["maturity_days"] ?? 30);
    $elapsedDays = approvedElapsedDays($approved_at);

    return $elapsedDays >= $withdrawAfterDays;
}

function packageReturnLabel($rules) {
    if (packageIsAuction($rules)) {
        $rate = number_format((float)($rules["auction_return_percent"] ?? 3), 2);
        $days = (int)($rules["auction_maturity_days"] ?? 3);

        return $rate . "% in " . $days . " days";
    }

    $type = $rules["return_calculation_type"] ?? "once_off";

    if ($type === "daily_simple") {
        return "Daily simple return";
    }

    if ($type === "daily_compound") {
        return "Daily compound return";
    }

    return "Once-off return";
}