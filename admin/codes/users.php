<?php
session_start();
include('../../config/dbcon.php');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    error_log("users.php - Invalid request method");
    header("Location: ../manage-users.php");
    exit(0);
}

// Return JSON for AJAX requests (we detect by checking specific flags)
header('Content-Type: application/json');

// === TOGGLE CONVERT CURRENCY (AJAX) ===
if (isset($_POST['toggle_convert_currency'])) {
    $user_id   = isset($_POST['user_id'])   ? (int)$_POST['user_id']   : 0;
    $new_value = isset($_POST['new_value']) ? (int)$_POST['new_value'] : -1;

    $response = ['success' => false, 'message' => 'Invalid request'];

    if ($user_id < 1 || !in_array($new_value, [0, 1])) {
        $response['message'] = 'Invalid user ID or value';
        echo json_encode($response);
        exit;
    }

    $stmt = $con->prepare("UPDATE users SET convert_currency = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param("ii", $new_value, $user_id);

    if ($stmt->execute()) {
        $response = [
            'success' => true,
            'new_value' => $new_value
        ];
        error_log("users.php - convert_currency toggled: user_id=$user_id → $new_value");
    } else {
        $response['message'] = 'Database error: ' . $stmt->error;
        error_log("users.php - convert_currency toggle failed: " . $stmt->error);
    }

    $stmt->close();
    echo json_encode($response);
    exit;
}

// === UPDATE USER (Balance, Bonus, Email, Message) ===
if (isset($_POST['update_user'])) {
    $id = $_POST['update_user'];
    $email = trim($_POST['email']);
    $bonus = $_POST['referal_bonus'];
    $balance = $_POST['balance'];
    $message = $_POST['message'] ?? '';

    // Validate inputs
    if (empty($id) || empty($email) || !is_numeric($bonus) || !is_numeric($balance)) {
        $_SESSION['error'] = "All required fields must be valid.";
        error_log("users.php - Invalid input for update_user: ID=$id, Email=$email, Bonus=$bonus, Balance=$balance");
        header("Location: ../edit-user?id=" . urlencode($id));
        exit(0);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        error_log("users.php - Invalid email format: Email=$email");
        header("Location: ../edit-user?id=" . urlencode($id));
        exit(0);
    }

    if ($balance < 0 || $bonus < 0) {
        $_SESSION['error'] = "Balance and referral bonus cannot be negative.";
        error_log("users.php - Negative values: Balance=$balance, Bonus=$bonus");
        header("Location: ../edit-user?id=" . urlencode($id));
        exit(0);
    }

    // Use prepared statement
    $query = "UPDATE users SET balance = ?, referal_bonus = ?, email = ?, message = ? WHERE id = ? LIMIT 1";
    $stmt = $con->prepare($query);
    $stmt->bind_param("ddssi", $balance, $bonus, $email, $message, $id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "User updated successfully";
        error_log("users.php - User updated: ID=$id, Email=$email, Bonus=$bonus, Balance=$balance");
    } else {
        $_SESSION['error'] = "Failed to update user.";
        error_log("users.php - Update query error: " . $stmt->error);
    }
    $stmt->close();
    header("Location: ../edit-user?id=" . urlencode($id));
    exit(0);
}

// === DELETE USER ===
elseif (isset($_POST['delete_user'])) {
    $id = $_POST['delete_user'];
    $profile_pic = $_POST['profile_pic'] ?? '';

    if (empty($id)) {
        $_SESSION['error'] = "Invalid user ID.";
        error_log("users.php - Missing user ID for delete_user");
        header("Location: ../manage-users.php");
        exit(0);
    }

    // Delete user
    $stmt = $con->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        if (!empty($profile_pic) && file_exists("../../Uploads/profile-picture/" . $profile_pic)) {
            unlink("../../Uploads/profile-picture/" . $profile_pic);
        }
        $_SESSION['success'] = "User deleted successfully";
        error_log("users.php - User deleted: ID=$id");
    } else {
        $_SESSION['error'] = "Failed to delete user.";
        error_log("users.php - Delete query error: " . $stmt->error);
    }
    $stmt->close();
    header("Location: ../manage-users.php");
    exit(0);
}

// === UPDATE VERIFICATION STATUS ===
elseif (isset($_POST['update_verify_status'])) {
    $user_id = $_POST['user_id'];
    $verify_status = $_POST['verify_status'];

    // Validate
    if (!is_numeric($user_id) || !in_array($verify_status, ['0','1','2','3'])) {
        $_SESSION['error'] = "Invalid user ID or verification status.";
        error_log("users.php - Invalid input for update_verify_status: User ID=$user_id, Verify Status=$verify_status");
        header("Location: ../manage-users.php");
        exit(0);
    }

    $user_id = (int)$user_id;
    $verify_status = (int)$verify_status;

    $stmt = $con->prepare("UPDATE users SET verify = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param("ii", $verify_status, $user_id);

    if ($stmt->execute()) {
        $status_text = match ($verify_status) {
            0 => 'Not Verified',
            1 => 'Under Review',
            2 => 'Verified',
            3 => 'Partial',
            default => 'Unknown'
        };

        $_SESSION['success'] = "Verification status updated to '$status_text'.";
        error_log("users.php - Verification updated: user_id=$user_id → $verify_status ($status_text)");
    } else {
        $_SESSION['error'] = "Failed to update verification status.";
        error_log("users.php - Verify update failed: " . $stmt->error);
    }
    $stmt->close();
    header("Location: ../manage-users.php");
    exit(0);
}

// === FALLBACK ===
else {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        // AJAX request without valid action
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    } else {
        $_SESSION['error'] = "Invalid action.";
        error_log("users.php - No valid POST action detected");
        header("Location: ../manage-users.php");
    }
    exit(0);
}

$con->close();
