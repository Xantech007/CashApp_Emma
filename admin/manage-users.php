<?php
session_start();
include('inc/header.php');
include('inc/navbar.php');
include('inc/sidebar.php');
?>
<main id="main" class="main">
    <div class="pagetitle">
        <h1>Manage Users</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard">Home</a></li>
                <li class="breadcrumb-item">Users</li>
                <li class="breadcrumb-item active">Manage Users</li>
            </ol>
        </nav>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-borderless">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Referred By</th>
                            <th>Profile</th>
                            <th>Verification Status</th>
                            <th>Convert Currency</th>
                            <th>Edit</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // === PAGINATION SETUP ===
                        $limit = 25;
                        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                        $offset = ($page - 1) * $limit;

                        // Count total users
                        $count_query = "SELECT COUNT(*) as total FROM users";
                        $count_result = mysqli_query($con, $count_query);
                        $total_users = mysqli_fetch_assoc($count_result)['total'];
                        $total_pages = ceil($total_users / $limit);

                        // Fetch users for current page
                        $query = "SELECT id, name, email, refered_by, image, verify, convert_currency
                                  FROM users
                                  ORDER BY id DESC
                                  LIMIT ? OFFSET ?";
                        $stmt = $con->prepare($query);
                        $stmt->bind_param("ii", $limit, $offset);
                        $stmt->execute();
                        $query_run = $stmt->get_result();

                        if (mysqli_num_rows($query_run) > 0) {
                            foreach ($query_run as $data) {
                                $verify_status = match ((int)$data['verify']) {
                                    0 => 'Not Verified',
                                    1 => 'Under Review',
                                    2 => 'Verified',
                                    3 => 'Partial',
                                    default => 'Not Verified'
                                };

                                $badge = match ((int)$data['verify']) {
                                    0, null => 'bg-danger',
                                    1 => 'bg-warning',
                                    2 => 'bg-success',
                                    3 => 'bg-purple',
                                    default => 'bg-danger'
                                };

                                $convert_enabled = !empty($data['convert_currency']) && (int)$data['convert_currency'] === 1;
                        ?>
                                <tr>
                                    <td><?= $data['id'] ?></td>
                                    <td><?= htmlspecialchars($data['name']) ?></td>
                                    <td><?= htmlspecialchars($data['email']) ?></td>
                                    <td><?= htmlspecialchars($data['refered_by'] ?? '-') ?></td>
                                    <td>
                                        <img src="../Uploads/profile-picture/<?= htmlspecialchars($data['image']) ?>"
                                             style="width:50px;height:50px;border-radius:50%;object-fit:cover;"
                                             loading="lazy" alt="Profile">
                                    </td>
                                    <td>
                                        <span class="badge <?= $badge ?>"><?= $verify_status ?></span>
                                        <button type="button" class="btn btn-outline-primary btn-sm mt-1 verify-btn"
                                                data-id="<?= $data['id'] ?>"
                                                data-name="<?= htmlspecialchars($data['name']) ?>"
                                                data-status="<?= (int)$data['verify'] ?>">
                                            Change
                                        </button>
                                    </td>

                                    <!-- NEW COLUMN: Convert Currency -->
                                    <td>
                                        <button type="button"
                                                class="btn btn-sm convert-toggle <?= $convert_enabled ? 'btn-success' : 'btn-outline-secondary' ?>"
                                                data-id="<?= $data['id'] ?>"
                                                data-enabled="<?= $convert_enabled ? '1' : '0' ?>">
                                            <?= $convert_enabled ? 'ON' : 'OFF' ?>
                                        </button>
                                    </td>

                                    <td>
                                        <a href="edit-user?id=<?= $data['id'] ?>" class="btn btn-light">Edit</a>
                                    </td>
                                    <td>
                                        <form action="codes/users.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="profile_pic" value="<?= htmlspecialchars($data['image']) ?>">
                                            <button class="btn btn-outline-danger" name="delete_user" value="<?= $data['id'] ?>">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                        <?php
                            }
                        } else {
                            echo '<tr><td colspan="9" class="text-center">No users found.</td></tr>';
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>

                <!-- === PAGINATION CONTROLS === -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mt-4">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>" tabindex="-1">Previous</a>
                        </li>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- === SINGLE SHARED MODAL FOR VERIFICATION === -->
    <div class="modal fade" id="verifyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Status for <span id="modalUserName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="verifyForm" action="codes/users.php" method="POST">
                        <input type="hidden" name="user_id" id="modalUserId">
                        <div class="mb-3">
                            <label class="form-label">Verification Status</label>
                            <select name="verify_status" class="form-control" required>
                                <option value="0">Not Verified</option>
                                <option value="1">Under Review</option>
                                <option value="2">Verified</option>
                                <option value="3">Partial</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-secondary" name="update_verify_status">Save</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- Custom Purple Badge -->
<style>
    .bg-purple {
        background-color: #6f42c1 !important;
        color: white !important;
    }
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    // Verification status modal
    document.querySelectorAll('.verify-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const status = this.dataset.status;

            document.getElementById('modalUserId').value = id;
            document.getElementById('modalUserName').textContent = name;
            document.querySelector('#verifyModal select').value = status;

            const modal = new bootstrap.Modal(document.getElementById('verifyModal'));
            modal.show();
        });
    });

    // Convert Currency Toggle
    document.querySelectorAll('.convert-toggle').forEach(btn => {
        btn.addEventListener('click', function () {
            const userId = this.dataset.id;
            const isCurrentlyOn = this.dataset.enabled === '1';
            const newValue = isCurrentlyOn ? 0 : 1;

            if (!confirm(`Turn currency conversion ${newValue === 1 ? 'ON' : 'OFF'} for this user?`)) {
                return;
            }

            fetch('codes/users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `toggle_convert_currency=1&user_id=${userId}&new_value=${newValue}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI
                    btn.dataset.enabled = newValue;
                    btn.textContent = newValue === 1 ? 'ON' : 'OFF';
                    btn.classList.remove('btn-success', 'btn-outline-secondary');
                    btn.classList.add(newValue === 1 ? 'btn-success' : 'btn-outline-secondary');
                } else {
                    alert('Failed to update: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Toggle error:', err);
                alert('Error communicating with server');
            });
        });
    });

});
</script>

<?php include('inc/footer.php'); ?>
</body>
</html>
