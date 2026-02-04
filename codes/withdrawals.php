<?php
session_start();
include('../config/dbcon.php');

if (isset($_POST['withdraw'])) {
    // Sanitize and type-correct inputs
    $email          = mysqli_real_escape_string($con, $_POST['email']);
    $amount         = floatval($_POST['amount']);           // displayed / requested amount
    $balance        = floatval($_POST['balance']);          // original stored balance from form
    $channel        = mysqli_real_escape_string($con, $_POST['channel']);
    $channel_name   = mysqli_real_escape_string($con, $_POST['channel_name']);
    $channel_number = mysqli_real_escape_string($con, $_POST['channel_number']);

    // === VERIFY USER STATUS ===
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
        // Only verify == 2 continues
    } else {
        $_SESSION['error'] = "User not found.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();

    // === INPUT VALIDATION ===
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

    // === FETCH CURRENCY & RATE ===
    $payment_query = "SELECT currency, alt_currency, crypto, rate, alt_rate 
                      FROM region_settings 
                      WHERE country = ? 
                      LIMIT 1";
    $stmt = $con->prepare($payment_query);
    $stmt->bind_param("s", $user_country);
    $stmt->execute();
    $payment_result = $stmt->get_result();

    if ($payment_result && $payment_result->num_rows > 0) {
        $payment     = $payment_result->fetch_assoc();
        $base_currency = $payment['currency']     ?? 'USD';
        $alt_currency  = $payment['alt_currency'] ?? 'USD';
        $is_crypto     = (int)($payment['crypto'] ?? 0);
        $rate          = (float)($payment['rate'] ?? 1.0);
        $alt_rate      = (float)($payment['alt_rate'] ?? 1.0);

        if ($rate <= 0)     $rate     = 1.0;
        if ($alt_rate <= 0) $alt_rate = 1.0;
    } else {
        $_SESSION['error'] = "Failed to fetch payment details for your region.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();

    // Decide which rate applies to the displayed amount
    $display_rate = $is_crypto ? $alt_rate : $rate;

    // When verify = 2 → displayed amount is already multiplied → convert back to base units
    $base_amount_to_deduct = ($verify_status === 2) ? ($amount / $display_rate) : $amount;

    // Safety: don't allow withdrawal larger than real stored balance
    if ($base_amount_to_deduct > $balance) {
        $_SESSION['error'] = "Insufficient balance after rate adjustment.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }

    // Amount the user will actually receive in their payout method
    $received_amount   = $is_crypto ? ($amount * $alt_rate) : ($amount * $rate);
    $received_currency = $is_crypto ? $alt_currency : $base_currency;

    // Currency shown to user in frontend & success message
    $display_currency = $is_crypto ? $alt_currency : $base_currency;

    // Format amount nicely (no decimals if whole number)
    $formatted_amount = number_format($amount, ($amount == floor($amount) ? 0 : 2));

    // === INSERT WITHDRAWAL REQUEST ===
    $query = "INSERT INTO withdrawals (email, amount, currency, channel, channel_name, channel_number, status, created_at)
              VALUES (?, ?, ?, ?, ?, ?, '0', NOW())";
    $stmt = $con->prepare($query);
    $stmt->bind_param("sdssss", $email, $amount, $display_currency, $channel, $channel_name, $channel_number);

    if ($stmt->execute()) {
        // === UPDATE USER BALANCE ===
        $new_balance = $balance - $base_amount_to_deduct;

        $update_query = "UPDATE users SET balance = ? WHERE email = ?";
        $update_stmt = $con->prepare($update_query);
        $update_stmt->bind_param("ds", $new_balance, $email);

        if ($update_stmt->execute()) {
            // Requested success message format
            $_SESSION['success'] = "Request to withdraw " . $display_currency . $formatted_amount . " has been submitted successfully.";
            header("Location: ../users/withdrawals.php");
            exit(0);
        } else {
            $_SESSION['error'] = "Failed to update balance.";
            header("Location: ../users/withdrawals.php");
            exit(0);
        }
        $update_stmt->close();
    } else {
        $_SESSION['error'] = "Failed to submit withdrawal request.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();
}

// === DELETE WITHDRAWAL REQUEST ===
if (isset($_POST['delete'])) {
    $id = mysqli_real_escape_string($con, $_POST['delete']);
    $delete_query = "DELETE FROM withdrawals WHERE id = ? LIMIT 1";
    $stmt = $con->prepare($delete_query);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Withdrawal request deleted successfully.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    } else {
        $_SESSION['error'] = "Failed to delete withdrawal request.";
        header("Location: ../users/withdrawals.php");
        exit(0);
    }
    $stmt->close();
}

$con->close();
?>
