<?php
session_start();
include('../config/dbcon.php');

if (isset($_POST['withdraw'])) {
    // ────────────────────────────────────────────────
    // Sanitize inputs
    $email         = mysqli_real_escape_string($con, $_POST['email']);
    $amount        = floatval($_POST['amount']);           // ← use float
    $balance       = floatval($_POST['balance']);          // original stored balance
    $channel       = mysqli_real_escape_string($con, $_POST['channel']);
    $channel_name  = mysqli_real_escape_string($con, $_POST['channel_name']);
    $channel_number= mysqli_real_escape_string($con, $_POST['channel_number']);

    // ────────────────────────────────────────────────
    // VERIFY USER STATUS
    $verify_query = "SELECT verify, country FROM users WHERE email = ? LIMIT 1";
    $stmt = $con->prepare($verify_query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $verify_result = $stmt->get_result();

    if ($verify_result && $verify_result->num_rows > 0) {
        $user = $verify_result->fetch_assoc();
        $user_country  = $user['country'];
        $verify_status = (int)$user['verify'];

        // BLOCK WITHDRAWAL BASED ON verify VALUE
        if ($verify_status == 0) {
            $_SESSION['error'] = "Verify Your Account and Try Again.";
            header("Location: ../users/withdrawals.php");
            exit(0);
        } elseif ($verify_status == 1) {
            $_SESSION['error'] = "Verification Under Review, Try Again Later.";
            header("Location: ../users/withdrawals.php");
            exit(0);
        } elseif ($verify_status == 3) {
            $_SESSION['error'] = "An error occurred while converting to your local currency.";
            header("Location: ../users/withdrawals.php");
            exit(0);
        } elseif ($verify_status != 2) {
            $_SESSION['error'] = "Invalid verification status.";
            header("Location: ../users/withdrawals.php");
            exit(0);
        }
        // Only verify == 2 continues here
    } else {
        $_SESSION['error'] = "User not found.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();

    // ────────────────────────────────────────────────
    // INPUT VALIDATION
    if (empty($channel) || empty($channel_name) || empty($channel_number)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }

    if ($amount < 50) {
        $_SESSION['error'] = "Minimum withdrawal is set at $50";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }

    // ────────────────────────────────────────────────
    // FETCH RATE & CURRENCY (used for both conversion and display)
    $payment_query = "SELECT currency, alt_currency, crypto, rate, alt_rate 
                      FROM region_settings 
                      WHERE country = ? 
                      LIMIT 1";
    $stmt = $con->prepare($payment_query);
    $stmt->bind_param("s", $user_country);
    $stmt->execute();
    $payment_result = $stmt->get_result();

    if ($payment_result && $payment_result->num_rows > 0) {
        $payment = $payment_result->fetch_assoc();
        $base_currency   = $payment['currency']     ?? 'USD';
        $alt_currency    = $payment['alt_currency'] ?? 'USD';
        $is_crypto       = (int)($payment['crypto'] ?? 0);
        $rate            = (float)($payment['rate'] ?? 1.0);
        $alt_rate        = (float)($payment['alt_rate'] ?? 1.0);

        // Safeguard invalid rates
        if ($rate <= 0)     $rate     = 1.0;
        if ($alt_rate <= 0) $alt_rate = 1.0;
    } else {
        $_SESSION['error'] = "Failed to fetch region settings.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();

    // ────────────────────────────────────────────────
    // Decide which rate to use (consistent with frontend logic)
    $display_rate = $is_crypto ? $alt_rate : $rate;

    // When verify = 2 → displayed amount is already multiplied → divide back to base
    // When verify ≠ 2 → displayed amount = real amount → no change
    $base_amount_to_deduct = ($verify_status === 2) ? ($amount / $display_rate) : $amount;

    // Safety check: after conversion, should not exceed real balance
    if ($base_amount_to_deduct > $balance) {
        $_SESSION['error'] = "Insufficient balance after rate adjustment.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }

    // ────────────────────────────────────────────────
    // Amount user will actually receive (in their payout currency)
    $received_amount = $is_crypto ? ($amount * $alt_rate) : ($amount * $rate);
    $received_currency = $is_crypto ? $alt_currency : $base_currency;

    // ────────────────────────────────────────────────
    // INSERT WITHDRAWAL REQUEST (store the DISPLAYED / requested amount)
    // Most systems store what the user requested/approved
    $insert_query = "INSERT INTO withdrawals 
                     (email, amount, currency, channel, channel_name, channel_number, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, '0', NOW())";
    $stmt = $con->prepare($insert_query);
    $display_currency = $is_crypto ? $alt_currency : $base_currency; // or always use displayed one
    $stmt->bind_param("sdssss", $email, $amount, $display_currency, $channel, $channel_name, $channel_number);

    if (!$stmt->execute()) {
        $_SESSION['error'] = "Failed to submit withdrawal request.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();

    // ────────────────────────────────────────────────
    // UPDATE USER BALANCE (subtract base amount)
    $new_balance = $balance - $base_amount_to_deduct;

    $update_query = "UPDATE users SET balance = ? WHERE email = ?";
    $update_stmt = $con->prepare($update_query);
    $update_stmt->bind_param("ds", $new_balance, $email);

    if ($update_stmt->execute()) {
        $_SESSION['success'] = $display_currency . " " . number_format($amount, 2) . "  withdrawal request submitted successfully.
        header("Location: ../users/withdrawals.php");
        exit(0);
    } else {
        $_SESSION['error'] = "Failed to update balance.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $update_stmt->close();
}

// ────────────────────────────────────────────────
// DELETE LOGIC (unchanged)
if (isset($_POST['delete'])) {
    $id = (int)$_POST['delete'];
    $delete_query = "DELETE FROM withdrawals WHERE id = ? LIMIT 1";
    $stmt = $con->prepare($delete_query);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Withdrawal request deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete withdrawal request.";
    }
    $stmt->close();
    header("Location: ../users/withdrawals.php");
    exit(0);
}

$con->close();
?>
