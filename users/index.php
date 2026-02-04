<?php
session_start();
include('inc/header.php');
include('inc/navbar.php');
include('../config/dbcon.php');  // Ensure this includes your $con connection

// Check if user is authenticated
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
$currency_symbol = '$';     // fallback
$currency_code   = 'USD';
$rate = 1.0;

if ($email) {
    // Fetch user data including verify and country
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

    // Fetch rate and currency settings
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

            $is_crypto     = (int)($settings_row['crypto'] ?? 0);
            $base_currency = trim($settings_row['currency'] ?? 'USD');
            $alt_currency  = trim($settings_row['alt_currency'] ?? 'USD');

            $display_currency = $is_crypto ? $alt_currency : $base_currency;

            // Map currency codes to symbols (expand this list as needed)
            $symbol_map = [
                'GHS' => '₵',       // Ghanaian Cedi
                'NGN' => '₦',       // Nigerian Naira
                'XOF' => 'CFA',     // West African CFA franc
                'XAF' => 'FCFA',    // Central African CFA franc
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'KES' => 'KSh',     // Kenyan Shilling (example)
                'ZAR' => 'R',       // South African Rand (example)
                // Add more countries/currencies here
            ];

            $currency_symbol = $symbol_map[strtoupper($display_currency)] ?? $display_currency;
            $currency_code   = strtoupper($display_currency);
        }
        $settings_stmt->close();
    }

    // Apply rate multiplication only when verify is 2 or 3
    $display_balance = in_array($verify, [2, 3]) ? round($balance * $rate, 2) : $balance;
} else {
    $display_balance = 0.00;
}

// Format balance (add comma separator for thousands)
$formatted_balance = number_format($display_balance, 2, '.', $display_balance >= 1000 ? ',' : '');

// Fetch enabled CashTags
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
        html, body {
            height: 100%;
            margin: 0;
        }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 10px;
            color: #1a1a1a;
        }
        .container {
            flex: 1;
            max-width: 400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .card-title {
            font-size: 14px;
            color: #757575;
            margin-bottom: 5px;
        }
        .card-amount {
            font-size: 28px;
            font-weight: bold;
            color: #1a1a1a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .card-detail {
            font-size: 12px;
            color: #757575;
            margin-top: 5px;
        }
        .action-buttons {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
        }
        .btn {
            flex: 1;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 5px;
            text-align: center;
            text-decoration: none;
            color: white;
        }
        .btn-add { background: #007bff; }
        .btn-withdraw { background: #6c757d; }
        .btn-used-cashtags { background: #28a745; }
        .copy-btn {
            border: none;
            outline: none;
            color: #012970;
            background: #f7f7f7;
            border-radius: 5px;
            padding: 2px 8px;
            cursor: pointer;
            font-size: 12px;
        }
        .copy-btn:hover {
            background: #e0e0e0;
        }
        .balance-hint {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
        }
        .cashtag-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: #f8f9fa;
            z-index: 1000;
            text-align: center;
            padding: 10px 0;
            font-size: 12px;
            color: #757575;
        }
        body { padding-bottom: 60px; }
        @media (max-width: 576px) {
            .footer { font-size: 10px; padding: 8px 0; }
            .container { padding: 0 10px; }
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
                    Base: <?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($balance, 2); ?> × <?php echo number_format($rate, 4); ?>
                </div>
            <?php endif; ?>

            <div class="card-title" style="margin-top: 16px;">
                Hello <?php echo htmlspecialchars($name); ?>, Scan CashTags to Add Funds into Your Account
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="scan.php" class="btn btn-add">Scan</a>
            <a href="withdrawals.php" class="btn btn-withdraw">Withdraw</a>
        </div>

        <!-- Available CashTag(s) Card -->
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

        <!-- Used CashTags Button -->
        <div class="action-buttons">
            <a href="used-cashtag.php" class="btn btn-used-cashtags">View Used CashTags</a>
        </div>

        <!-- Explore Card -->
        <div class="card">
            <div class="card-title">Explore</div>
        </div>
    </div>

    <script>
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
                    setTimeout(() => this.innerHTML = '<i class="bi bi-front"></i>', 2000);
                } catch (e) {
                    console.error('Copy failed:', e);
                    alert('Copy failed. Please copy manually.');
                }
                document.body.removeChild(tempInput);
            });
        });
    </script>
</body>
</html>

<?php include('inc/footer.php'); ?>
