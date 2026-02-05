<?php
session_start();
include('../config/dbcon.php');
include('inc/header.php');
include('inc/navbar.php');
?>
<!-- ======= Sidebar ======= -->
<main id="main" class="main">
    <div class="pagetitle">
        <?php
        $email = mysqli_real_escape_string($con, $_SESSION['email']);

        // Fetch user data
        $query = "SELECT balance, verify, message, country, verify_time, convert_currency
                  FROM users
                  WHERE email = ?
                  LIMIT 1";
        $stmt = mysqli_prepare($con, $query);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $query_run = mysqli_stmt_get_result($stmt);

        if ($query_run && mysqli_num_rows($query_run) > 0) {
            $row = mysqli_fetch_assoc($query_run);
            $balance          = (float)$row['balance'];
            $verify           = (int)($row['verify'] ?? 0);
            $message          = $row['message'] ?? '';
            $user_country     = $row['country'] ?? '';
            $verify_time      = $row['verify_time'] ?? null;
            $convert_currency = (int)($row['convert_currency'] ?? 0);

            // Handle verify timeout (315 minutes ≈ 5.25 hours)
            if ($verify == 1 && !empty($verify_time)) {
                $current_time = new DateTime('now', new DateTimeZone('Africa/Lagos'));
                $verify_time_dt = new DateTime($verify_time, new DateTimeZone('Africa/Lagos'));
                $interval = $current_time->diff($verify_time_dt);
                $total_minutes_passed = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                if ($total_minutes_passed >= 315) {
                    $update_query = "UPDATE users SET verify = 0 WHERE email = ?";
                    $update_stmt = mysqli_prepare($con, $update_query);
                    mysqli_stmt_bind_param($update_stmt, "s", $email);
                    mysqli_stmt_execute($update_stmt);
                    mysqli_stmt_close($update_stmt);
                    $verify = 0;
                }
            }
        } else {
            $_SESSION['error'] = "User not found.";
            error_log("withdrawals.php - User not found for email: $email");
            header("Location: ../signin.php");
            exit(0);
        }
        mysqli_stmt_close($stmt);

        // Default values
        $rate             = 1.0;
        $display_balance  = $balance;
        $currency_symbol  = '$';   // Always fallback to $ when not converting
        $currency         = 'USD'; // Always fallback to USD when not converting

        // Fetch region settings ONLY if convert_currency === 1
        if ($convert_currency === 1 && !empty($user_country)) {
            $region_query = "SELECT rate, currency, crypto, Channel, Channel_name, Channel_number,
                                    alt_channel, alt_ch_name, alt_ch_number, alt_currency
                             FROM region_settings
                             WHERE country = ?
                             LIMIT 1";
            $region_stmt = mysqli_prepare($con, $region_query);
            mysqli_stmt_bind_param($region_stmt, "s", $user_country);
            mysqli_stmt_execute($region_stmt);
            $region_result = mysqli_stmt_get_result($region_stmt);

            if ($region_data = mysqli_fetch_assoc($region_result)) {
                $rate = (float)($region_data['rate'] ?? 1.0);
                if ($rate <= 0) $rate = 1.0;

                // Apply conversion
                $display_balance = round($balance * $rate, 2);

                // Use region currency
                $currency = $region_data['currency'] ?? 'USD';
                $currency_symbol = $currency; // e.g. ₦, GHS, etc.

                // Payment channel logic
                if ($region_data['crypto'] == 1) {
                    $channel_label       = $region_data['alt_channel']    ?? 'Crypto Channel';
                    $channel_name_label  = $region_data['alt_ch_name']    ?? 'Crypto Name';
                    $channel_number_label= $region_data['alt_ch_number']  ?? 'Crypto Address';
                    $currency_symbol     = $region_data['alt_currency']   ?? $currency_symbol;
                    $currency            = $region_data['alt_currency']   ?? $currency;
                } else {
                    $channel_label       = $region_data['Channel']        ?? 'Bank';
                    $channel_name_label  = $region_data['Channel_name']   ?? 'Account Name';
                    $channel_number_label= $region_data['Channel_number'] ?? 'Account Number';
                    $currency_symbol     = $region_data['currency']       ?? $currency_symbol;
                    $currency            = $region_data['currency']       ?? $currency;
                }
            } else {
                error_log("withdrawals.php - No region settings found for country: $user_country");
                $channel_label = 'Bank';
                $channel_name_label = 'Account Name';
                $channel_number_label = 'Account Number';
            }
            mysqli_stmt_close($region_stmt);
        } else {
            // No conversion → pure fallback to USD / $
            $channel_label = 'Bank';
            $channel_name_label = 'Account Name';
            $channel_number_label = 'Account Number';
        }

        // Minimum withdrawal amount (shown in current currency/symbol)
        $min_withdrawal = 50;
        $min_display = $currency_symbol . number_format($min_withdrawal, 0);
        ?>

        <h1>Available Balance: <?= htmlspecialchars($currency_symbol) ?><?= number_format($display_balance, 2) ?></h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index">Home</a></li>
                <li class="breadcrumb-item">Users</li>
                <li class="breadcrumb-item active">Withdrawals</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <!-- Display user message if exists -->
    <?php if (!empty(trim($message))): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin-top: 20px;">
            <i class="bi bi-exclamation-triangle me-2"></i><strong><?= htmlspecialchars($message) ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Session error/success modals -->
    <?php
    if (isset($_SESSION['error'])) {
        echo '<div class="modal fade show" id="errorModal" tabindex="-1" style="display:block;" aria-modal="true" role="dialog">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Error</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">' . htmlspecialchars($_SESSION['error']) . '</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="window.location.reload();">Ok</button>
                        </div>
                    </div>
                </div>
              </div>
              <div class="modal-backdrop fade show"></div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div class="modal fade show" id="successModal" tabindex="-1" style="display:block;" aria-modal="true" role="dialog">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Success</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">' . htmlspecialchars($_SESSION['success']) . '</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="window.location.reload();">Ok</button>
                        </div>
                    </div>
                </div>
              </div>
              <div class="modal-backdrop fade show"></div>';
        unset($_SESSION['success']);
    }
    ?>

    <style>
        .form1 { padding: 10px 10px; width: 300px; background: white; display: flex; justify-content: space-between; opacity: 0.85; border-radius: 10px; }
        input { border: none; outline: none; }
        #button { border: none; outline: none; color: #012970; background: #f7f7f7; border-radius: 5px; }
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        @media (max-width: 500px) { .form { width: 100%; margin: auto; } }
        .action-buttons { display: flex; justify-content: space-between; margin: 15px 0; }
        .btn-verify { background: #ffc107; flex: 1; padding: 12px; font-size: 16px; font-weight: bold; border: none; border-radius: 5px; cursor: pointer; margin: 0 5px; text-align: center; text-decoration: none; color: white; }
    </style>

    <div class="card" style="margin-top:20px">
        <div class="card-body">
            <h5 class="card-title">Withdrawal Request</h5>
            <p>Fill in amount to be withdrawn, <?= htmlspecialchars($channel_label) ?>, <?= htmlspecialchars($channel_name_label) ?>, and <?= htmlspecialchars($channel_number_label) ?>, then submit form to complete your request</p>
            <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#verticalycentered">
                Request Withdrawal
            </button>

            <div class="modal fade" id="verticalycentered" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Minimum withdrawal is <?= $min_display ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="form" data-aos="fade-up">
                                <form action="../codes/withdrawals.php" method="POST" class="F" id="form" enctype="multipart/form-data">
                                    <div class="error"></div>
                                    <div class="inputbox">
                                        <input class="input" type="number" name="amount" autocomplete="off"
                                               required="required" min="<?= $min_withdrawal ?>" step="0.01" />
                                        <span>Amount in <?= htmlspecialchars($currency) ?></span>
                                    </div>
                                    <div class="inputbox">
                                        <input class="input" type="text" name="channel" autocomplete="off" required="required" />
                                        <span><?= htmlspecialchars($channel_label) ?></span>
                                    </div>
                                    <div class="inputbox">
                                        <input class="input" type="text" name="channel_name" autocomplete="off" required="required" />
                                        <span><?= htmlspecialchars($channel_name_label) ?></span>
                                    </div>
                                    <div class="inputbox">
                                        <input class="input" type="text" name="channel_number" autocomplete="off" required="required" />
                                        <span><?= htmlspecialchars($channel_number_label) ?></span>
                                    </div>
                                    <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['email']) ?>">
                                    <input type="hidden" name="balance" value="<?= htmlspecialchars($balance) ?>">
                                    <input type="hidden" name="display_currency" value="<?= htmlspecialchars($currency) ?>">
                                    <input type="hidden" name="convert_currency" value="<?= $convert_currency ?>">
                                    <input type="hidden" name="currency_symbol" value="<?= htmlspecialchars($currency_symbol) ?>">
                                </form>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                            <button type="submit" form="form" class="btn btn-secondary" name="withdraw">Submit Request</button>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                #form { margin: auto; width: 80%; }
                .form .inputbox { position: relative; width: 100%; margin-top: 20px; }
                .form .inputbox input, .form .inputbox textarea { width: 100%; padding: 5px 0; font-size: 12px; border: none; outline: none; background: transparent; border-bottom: 2px solid #ccc; margin: 10px 0; }
                .form .inputbox span { position: absolute; left: 0; padding: 5px 0; font-size: 12px; margin: 10px 0; }
                .form .inputbox input:focus ~ span, .form .inputbox textarea:focus ~ span { color: #0dcefd; font-size: 12px; transform: translateY(-20px); transition: 0.4s ease-in-out; }
                .form .inputbox input:valid ~ span, .form .inputbox textarea:valid ~ span { color: #0dcefd; font-size: 12px; transform: translateY(-20px); }
                .B { color: #ccm; margin-top: 20px; background: transparent; padding: 12px; font-weight: 400; transition: 0.8s ease-in-out; letter-spacing: 1px; border: 2px solid #0d6efd; }
                .B:hover { background: #186; }
                .error { margin-bottom: 10px; padding: 0px; background: #d3ad7f; text-align: center; font-size: 12px; transition: all 0.5s ease; color: white; border-radius: 3px; }
            </style>
        </div>
    </div>

    <div class="pagetitle">
        <h1>Withdrawal History</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-borderless">
                    <thead>
                        <tr>
                            <th scope="col">Amount</th>
                            <th scope="col"><?= htmlspecialchars($channel_label) ?></th>
                            <th scope="col"><?= htmlspecialchars($channel_name_label) ?></th>
                            <th scope="col"><?= htmlspecialchars($channel_number_label) ?></th>
                            <th scope="col">Status</th>
                            <th scope="col">Date</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT id, amount, channel, channel_name, channel_number, status, created_at
                                  FROM withdrawals
                                  WHERE email = ?";
                        $stmt = mysqli_prepare($con, $query);
                        mysqli_stmt_bind_param($stmt, "s", $email);
                        mysqli_stmt_execute($stmt);
                        $query_run = mysqli_stmt_get_result($stmt);

                        if (mysqli_num_rows($query_run) > 0) {
                            while ($data = mysqli_fetch_assoc($query_run)) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($currency_symbol) ?><?= number_format($data['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($data['channel']) ?></td>
                                    <td><?= htmlspecialchars($data['channel_name']) ?></td>
                                    <td><?= htmlspecialchars($data['channel_number']) ?></td>
                                    <td>
                                        <?php if ($data['status'] == 0): ?>
                                            <span class="badge bg-warning text-light">Pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-success text-light">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d-M-Y', strtotime($data['created_at'])) ?></td>
                                    <td>
                                        <form action="../codes/withdrawals.php" method="POST">
                                            <button class="btn btn-light" name="delete" value="<?= $data['id'] ?>">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php }
                        } else { ?>
                            <tr><td colspan="7">No withdrawals found.</td></tr>
                        <?php }
                        mysqli_stmt_close($stmt);
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($verify === 0 || $verify === 1): ?>
        <div class="action-buttons">
            <a href="verify.php" class="btn btn-verify">Verify Account</a>
        </div>
    <?php endif; ?>

</main>

<script>
    let input = document.querySelector("#text");
    let inputbutton = document.querySelector("#button");
    if (input && inputbutton) {
        inputbutton.addEventListener('click', copytext);
        function copytext() {
            input.select();
            document.execCommand('copy');
            inputbutton.innerHTML = 'copied!';
        }
    }
</script>

<?php include('inc/footer.php'); ?>
</html>
