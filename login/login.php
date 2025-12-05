<?php
session_start();

// Include database connection ($conn - MySQLi)
require_once '../db_connect.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
	header('Location: ../dashboard.php');
	exit;
}

// Initialize tracking
if (!isset($_SESSION['login_attempts'])) {
	$_SESSION['login_attempts'] = 0;
	$_SESSION['last_attempt'] = time();
}

$lockout = false;
$remaining = 0;

if ($_SESSION['login_attempts'] >= 5) {
	$elapsed = time() - $_SESSION['last_attempt'];

	if ($elapsed < 600) { // 600 seconds = 10 minutes
		$lockout = true;
		$remaining = 600 - $elapsed; // seconds remaining in lockout
	} else {
		// Reset attempts after timeout
		$_SESSION['login_attempts'] = 0;
	}
}

// Initialize error message
$error = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$lockout) {
	// Get inputs
	$username = trim($_POST['username'] ?? '');
	$password = trim($_POST['password'] ?? '');

	if ($username !== '' && $password !== '') {
		// Try to find user by email first, then by username
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

		// If not found by email, try by username
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
			$storedHash = (string)$user['password'];

			// Secure password verification
			if (password_verify($password, $storedHash)) {
				// Reset attempts on successful login
				$_SESSION['login_attempts'] = 0;

				// Optional: Automatically rehash passwords if needed
				if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
					$newHash = password_hash($password, PASSWORD_DEFAULT);

					$u = $conn->prepare("UPDATE USER_TAB SET password = ? WHERE id = ?");
					$u->bind_param("si", $newHash, $user['id']);
					$u->execute();
					$u->close();
				}

				// Secure session handling - regenerate session ID to prevent fixation
				session_regenerate_id(true);
				
				// Store user data in session
				$_SESSION['user_id'] = (int)$user['id'];
				$_SESSION['username'] = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
				$_SESSION['role'] = isset($user['role']) && $user['role'] !== null ? $user['role'] : 'Reader';

				// Redirect to dashboard
				header("Location: ../dashboard.php");
				exit;
			} else {
				// Record failed attempt
				$_SESSION['login_attempts']++;
				$_SESSION['last_attempt'] = time();
				$error = "Wrong username or password.";
			}
		} else {
			// User not found → also record attempt
			$_SESSION['login_attempts']++;
			$_SESSION['last_attempt'] = time();
			$error = "Wrong username or password.";
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
    <style>
        .lockout-message {
            background: #ffe3e3;
            color: #b30000;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 6px;
            text-align: center;
            font-weight: 600;
        }

        #login-form input[disabled],
        #login-form button[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
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

          <?php if ($lockout): ?>
                <div class="lockout-message">
                    <p><strong>Too many attempts.</strong></p>
                    <p>Please wait <span id="lockout-timer"></span> before trying again.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="login-form">
				<div class="input-group">
					<input type="text" name="username" placeholder="Username or Email" required
						<?php if ($lockout) echo 'disabled'; ?>>
				</div>
                <div class="input-group password-group">
                    <input type="password" name="password" placeholder="Password" required
						<?php if ($lockout) echo 'disabled'; ?>>
                    <span class="toggle-password">
                        <img src="eye.png" alt="Show password">
                    </span>
                </div>
                <button type="submit" class="btn"
					<?php if ($lockout) echo 'disabled'; ?>>
					LOG IN
				</button>
            </form>
        </div>
    </div>

<script>
// toggle password visibility
document.querySelector(".toggle-password").addEventListener("click", function(){
    const passField = document.querySelector("input[name='password']");
    passField.type = passField.type === "password" ? "text" : "password";
});

<?php if ($lockout): ?>
    let remaining = <?php echo $remaining; ?>;
    let timerElem = document.getElementById("lockout-timer");

    function updateTimer() {
        let m = Math.floor(remaining / 60);
        let s = remaining % 60;
        if (s < 10) s = "0" + s;

        timerElem.textContent = m + ":" + s;

        if (remaining <= 0) {
            location.reload();
        } else {
            remaining--;
            setTimeout(updateTimer, 1000);
        }
    }

    updateTimer();
<?php endif; ?>
</script>
</body>
</html>
