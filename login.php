<?php
session_start();

// Include database connection ($conn - MySQLi)
require_once __DIR__ . '/db_connect.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
	header('Location: dashboard.php');
	exit;
}

// Initialize error message
$error = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	// Get and sanitize inputs
	$username = trim($_POST['username'] ?? ''); // this field captures email in current schema
	$password = trim($_POST['password'] ?? '');
	$sanitizedUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
	$sanitizedPassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');

	if ($username !== '' && $password !== '') {
		// Use prepared statement to find user by email and fetch role
		$stmt = $conn->prepare("SELECT id, email, password, role FROM USER_TAB WHERE email = ? LIMIT 1");
		if ($stmt) {
			$stmt->bind_param('s', $username);
			$stmt->execute();
			$result = $stmt->get_result();

			if ($result && $result->num_rows === 1) {
				$user = $result->fetch_assoc();
				// Verify password (password column should store password_hash)
				if (password_verify($password, $user['password'])) {
					// Authentication successful: store user data in session
					$_SESSION['user_id'] = (int)$user['id'];
					$_SESSION['username'] = $sanitizedUsername; // store sanitized email
					$_SESSION['role'] = isset($user['role']) && $user['role'] !== null ? $user['role'] : 'Reader';

					// Redirect to dashboard
					header('Location: dashboard.php');
					exit;
				}
			}

			// Authentication failed
			$error = 'Invalid username or password.';
			$stmt->close();
		} else {
			// Statement preparation failed
			$error = 'An error occurred. Please try again later.';
		}
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
                    <input type="text" name="username" placeholder="Username" required>
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
