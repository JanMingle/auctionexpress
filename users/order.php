<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "member") {
    header("Location: ../admin/dashboard.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$tenant_id = (int)$_SESSION["tenant_id"];
$name = $_SESSION["name"];
$stokvel_name = $_SESSION["stokvel_name"];

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $amount = trim($_POST["amount"] ?? "");
    $note = trim($_POST["note"] ?? "");

    if ($amount === "") {
        $error = "Please enter how much you want to save.";
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error = "Please enter a valid amount.";
    } else {
        $amount = number_format((float)$amount, 2, ".", "");

        $stmt = $conn->prepare("
            INSERT INTO savings_requests
            (tenant_id, user_id, amount, note, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");

        $stmt->bind_param("iids", $tenant_id, $user_id, $amount, $note);

        if ($stmt->execute()) {
            $success = "Your saving request has been submitted successfully.";
        } else {
            $error = "Could not submit your saving request. Please try again.";
        }
    }
}

$historyStmt = $conn->prepare("
    SELECT amount, note, status, created_at
    FROM savings_requests
    WHERE tenant_id = ?
    AND user_id = ?
    ORDER BY created_at DESC
");
$historyStmt->bind_param("ii", $tenant_id, $user_id);
$historyStmt->execute();
$history = $historyStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Saving Request</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f7fb;
            font-family: Arial, sans-serif;
        }

        .topbar {
            background: #111827;
            color: white;
            padding: 18px 28px;
        }

        .topbar a {
            color: white;
            text-decoration: none;
            font-size: 14px;
            margin-left: 18px;
        }

        .page-wrapper {
            padding: 30px;
        }

        .card-box {
            background: white;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
        }

        .form-control:focus {
            box-shadow: none;
            border-color: #111827;
        }

        .btn-dark {
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 600;
        }

        .table {
            font-size: 14px;
        }

        .badge {
            border-radius: 999px;
            padding: 7px 10px;
            font-size: 12px;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-approved {
            background: #dcfce7;
            color: #166534;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>

<div class="topbar d-flex justify-content-between align-items-center">
    <div>
        <strong><?php echo htmlspecialchars($stokvel_name); ?></strong>
        <div style="font-size: 13px; color: #d1d5db;">
            Member Saving Request
        </div>
    </div>

    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="../logout.php">Logout</a>
    </div>
</div>

<div class="page-wrapper">

    <div class="mb-4">
        <h3>Hello, <?php echo htmlspecialchars($name); ?></h3>
        <p class="text-muted mb-0">
            Submit how much you want to save in this stokvel.
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

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card-box">
                <h5 class="mb-3">Submit Saving Amount</h5>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label">Amount You Want to Save *</label>
                        <input 
                            type="number" 
                            name="amount" 
                            class="form-control" 
                            step="0.01"
                            min="1"
                            placeholder="Example: 500"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Note</label>
                        <textarea 
                            name="note" 
                            class="form-control" 
                            rows="4"
                            placeholder="Optional note for the admin"
                        ></textarea>
                    </div>

                    <button type="submit" class="btn btn-dark w-100">
                        Submit Request
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card-box">
                <h5 class="mb-3">My Previous Requests</h5>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Amount</th>
                                <th>Note</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history->num_rows > 0): ?>
                                <?php while ($row = $history->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                R<?php echo number_format((float)$row["amount"], 2); ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($row["note"] ?: "-"); ?>
                                        </td>

                                        <td>
                                            <?php if ($row["status"] === "pending"): ?>
                                                <span class="badge badge-pending">Pending</span>
                                            <?php elseif ($row["status"] === "approved"): ?>
                                                <span class="badge badge-approved">Approved</span>
                                            <?php elseif ($row["status"] === "rejected"): ?>
                                                <span class="badge badge-rejected">Rejected</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php echo date("d M Y H:i", strtotime($row["created_at"])); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        You have not submitted any saving request yet.
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

</body>
</html>