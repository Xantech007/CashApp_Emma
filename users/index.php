<?php
session_start();
include('inc/header.php');
include('inc/navbar.php');
include('../config/dbcon.php');  // Ensure DB connection

// Check authentication
if (!isset($_SESSION['auth']) || !isset($_SESSION['email'])) {
    $_SESSION['error'] = "Login to access dashboard!";
    header("Location: ../signin");
    exit(0);
}

$email = $_SESSION['email'];
$name = 'Guest';
$balance = 0.00;
$verify = 0;
$user_country = null;
$currency_symbol = '$';     // Default fallback
$currency_code   = 'USD';

if ($email) {
    // Fetch user data
    $user_query = "SELECT name, balance, verify, country 
                   FROM users 
                   WHERE email = ? 
                   LIMIT 1";
    $stmt = $con->prepare($user_query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user_result = $stmt->get_result();

    if ($user_result && $user_result->num_rows > 0) {
        $user_data    = $user_result->fetch_assoc();
        $name         = $user_data['name'] ?? 'Guest';
        $balance      = (float)($user_data['balance'] ?? 0.00);
        $verify       = (int)($user_data['verify'] ?? 0);
        $user_country = $user_data['country'] ?? null;
    }
    $stmt->close();

    // Fetch rate + currency info from region_settings
    $rate = 1.0;
    if (!empty($user_country)) {
        $settings_query = "SELECT rate, currency, alt_currency, crypto 
                           FROM region_settings 
                           WHERE country = ? 
                           LIMIT 1";
        $settings_stmt = $con->prepare($settings_query);
        $settings_stmt->bind_param("s", $user_country);
        $settings_stmt->execute();
        $settings_result = $settings_stmt->get_result();

        if ($settings_row = $settings_result->fetch_assoc()) {
            $rate = (float)($settings_row['rate'] ?? 1.0);
            if ($rate <= 0) $rate = 1.0;

            $is_crypto = (int)($settings_row['crypto'] ?? 0);
            $base_currency = $settings_row['currency']     ?? 'USD';
            $alt_currency  = $settings_row['alt_currency'] ?? 'USD';

            $display_currency = $is_crypto ? $alt_currency : $base_currency;

            // Map common codes to nice symbols (add more as needed)
            $symbol_map = [
                'GHS' => '₵',      // Ghanaian cedi
                'NGN' => '₦',      // Nigerian naira
                'XOF' => 'CFA',    // West African CFA
                'XAF' => 'FCFA',   // Central African CFA
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                // Add others: ZAR => 'R', KES => 'KSh', etc.
            ];

            $currency_symbol = $symbol_map[$display_currency] ?? $display_currency;
            $currency_code   = $display_currency;
        }
        $settings_stmt->close();
    }

    // Apply multiplication only for verify 2 or 3
    $display_balance = in_array($verify, [2, 3]) ? round($balance * $rate, 2) : $balance;
} else {
    $display_balance = 0.00;
}

// Format with commas for large numbers
$formatted_balance = number_format($display_balance, 2, '.', $display_balance >= 1000 ? ',' : '');

// Fetch CashTags
$cashtag_query = "SELECT cashtag FROM packages WHERE dashboard = 'enabled' ORDER BY cashtag";
$cashtag_result = mysqli_query($con, $cashtag_query);
$cashtags = [];
if ($cashtag_result && mysqli_num_rows($cashtag_result) > 0) {
    while ($row = mysqli_fetch_assoc($cashtag_result)) {
        $cashtags[] = $row['cashtag'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Your existing styles here – unchanged */
        .balance-hint {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .card-amount {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Cash Balance Card -->
        <div class="card">
            <div class="card-title">Cash balance</div>
            <div class="card-amount">
                <?php echo htmlspecialchars($currency_symbol); ?>
                <?php echo htmlspecialchars($formatted_balance); ?>
            </div>

            <?php if (in_array($verify, [2, 3]) && $rate != 1.0): ?>
                <div class="balance-hint">
                    (Base: <?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($balance, 2); ?> × <?php echo number_format($rate, 4); ?>)
                </div>
            <?php endif; ?>

            <div class="card-title" style="margin-top: 12px;">
                Hello <?php echo htmlspecialchars($name); ?>, Scan CashTags to Add Funds into Your Account
            </div>
        </div>

        <!-- Action Buttons (unchanged) -->
        <div class="action-buttons">
            <a href="scan.php" class="btn btn-add">Scan</a>
            <a href="withdrawals.php" class="btn btn-withdraw">Withdraw</a>
        </div>

        <!-- Available CashTag(s) Card (unchanged) -->
        <div class="card">
            <div class="card-title">Available CashTag(s):</div>
            <?php if (!empty($cashtags)): ?>
                <?php foreach ($cashtags as $index => $cashtag): ?>
                    <div class="cashtag-item">
                        <div class="card-amount"><?php echo htmlspecialchars($cashtag); ?></div>
                        <button class="copy-btn" data-cashtag="<?php echo htmlspecialchars($cashtag); ?>" id="copyButton<?php echo $index; ?>">
                            <i class="bi bi-front"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card-amount">No CashTags available</div>
            <?php endif; ?>
        </div>

        <!-- Used CashTags Button (unchanged) -->
        <div class="action-buttons">
            <a href="used-cashtag.php" class="btn btn-used-cashtags">View Used CashTags</a>
        </div>

        <!-- Explore Card (unchanged) -->
        <div class="card">
            <div class="card-title">Explore</div>
        </div>
    </div>

    <script>
        // Your existing copy button script – unchanged
        document.querySelectorAll('.copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const cashtag = this.getAttribute('data-cashtag');
                const tempInput = document.createElement('input');
                tempInput.value = cashtag;
                document.body.appendChild(tempInput);
                tempInput.select();
                tempInput.setSelectionRange(0, 99999);
                try {
                    document.execCommand('copy');
                    this.innerHTML = 'copied!';
                    setTimeout(() => {
                        this.innerHTML = '<i class="bi bi-front"></i>';
                    }, 2000);
                } catch (e) {
                    console.error('Copy failed:', e);
                    alert('Copy to clipboard failed. Please try manually.');
                }
                document.body.removeChild(tempInput);
            });
        });
    </script>
</body>
</html>
<?php include('inc/footer.php'); ?>
