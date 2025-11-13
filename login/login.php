<?php
session_start();

// Include database connection ($conn - MySQLi)
require_once '../db_connect.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
	header('Location: ../dashboard.php');
	exit;
}

// Initialize error message
$error = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	// Get and sanitize inputs
	$username = trim($_POST['username'] ?? ''); // this field captures email or username
	$password = trim($_POST['password'] ?? '');
	$sanitizedUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
	$sanitizedPassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');

	if ($username !== '' && $password !== '') {
		// Try to find user by email first
		$user = null;
		$stmt = $conn->prepare("SELECT id, email, password, role FROM USER_TAB WHERE email = ? LIMIT 1");
		if ($stmt) {
			$stmt->bind_param('s', $username);
			$stmt->execute();
			$result = $stmt->get_result();
			if ($result && $result->num_rows === 1) {
				$user = $result->fetch_assoc();
			}
			$stmt->close();
		}

		// If not found by email, try by username (if such a column exists)
		if ($user === null) {
			$stmt2 = $conn->prepare("SELECT id, email, password, role FROM USER_TAB WHERE username = ? LIMIT 1");
			if ($stmt2) {
				$stmt2->bind_param('s', $username);
				$stmt2->execute();
				$result2 = $stmt2->get_result();
				if ($result2 && $result2->num_rows === 1) {
					$user = $result2->fetch_assoc();
				}
				$stmt2->close();
			}
		}

		if ($user !== null) {
			$storedPassword = (string)$user['password'];
			$authOk = false;

			// Primary: verify using password_hash
			if (password_verify($password, $storedPassword)) {
				$authOk = true;
				// Optionally rehash if algorithm parameters changed
				if (password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
					$newHash = password_hash($password, PASSWORD_DEFAULT);
					$rehashStmt = $conn->prepare("UPDATE USER_TAB SET password = ? WHERE id = ?");
					if ($rehashStmt) {
						$rehashStmt->bind_param('si', $newHash, $user['id']);
						$rehashStmt->execute();
						$rehashStmt->close();
					}
				}
			} else {
				// Fallback for legacy plaintext passwords stored in DB
				if (hash_equals($storedPassword, $password)) {
					$authOk = true;
					// Immediately upgrade to a secure hash
					$newHash = password_hash($password, PASSWORD_DEFAULT);
					$upgradeStmt = $conn->prepare("UPDATE USER_TAB SET password = ? WHERE id = ?");
					if ($upgradeStmt) {
						$upgradeStmt->bind_param('si', $newHash, $user['id']);
						$upgradeStmt->execute();
						$upgradeStmt->close();
					}
				}
			}

			if ($authOk) {
				// Authentication successful: store user data in session
				$_SESSION['user_id'] = (int)$user['id'];
				$_SESSION['username'] = $sanitizedUsername; // store sanitized identifier
				$_SESSION['role'] = isset($user['role']) && $user['role'] !== null ? $user['role'] : 'Reader';

				// Redirect to dashboard
				header('Location: ../dashboard.php');
				exit;
			}
		}

		// Authentication failed
		$error = 'Invalid username or password.';
	} else {
		$error = 'Please enter username and password.';
	}
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Shkolla Austriake</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="logo-box">
                <img src="logo_ShA.png" class="logo-icon">
                <h1>SHKOLLA<br>AUSTRIAKE</h1>
                <p>Manage school finance<br>with clarity and control</p>
            </div>
        </div>

        <!-- RIGHT SIDE -->
        <div class="right-panel">
            <h2>LOGIN</h2>

          <?php if (!empty($error)): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form method="post" action="">
				<div class="input-group">
					<input type="text" name="username" placeholder="Username or Email" required>
				</div>
                <div class="input-group password-group">
                    <input type="password" name="password" placeholder="Password" required>
                    <span class="toggle-password">
                        <img src="eye.png" alt="Show password">
                    </span>
                </div>
                <button type="submit" class="btn">LOG IN</button>
            </form>
        </div>
    </div>

<script>
// toggle password visibility
document.querySelector(".toggle-password").addEventListener("click", function(){
    const passField = document.querySelector("input[name='password']");
    passField.type = passField.type === "password" ? "text" : "password";
});
</script>
</body>
</html>
