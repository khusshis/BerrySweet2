<?php
session_start();
require_once "db.php"; // include DB connection

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - BerrySweet Productivity</title>
    <link rel="stylesheet" href="https://cdn.tailwindcss.com">
    <!-- ...theme and style imports... -->
</head>
<body class="theme-strawberry bg-bg text-text min-h-screen flex items-center justify-center">
    <div class="bg-white rounded-lg p-8 w-full max-w-md shadow-lg">
        <h2 class="text-2xl font-bold text-primary mb-6">Welcome to BerrySweet</h2>
        <?php if ($error): ?>
            <div class="mb-4 text-red-500"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="login.php">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
            </div>
            <button type="submit" class="w-full bg-primary text-white py-2 px-4 rounded-md hover:bg-primary-dark">Login</button>
        </form>
        <p class="text-center mt-4 text-sm text-gray-600">Don't have an account? <a href="register.php" class="text-primary hover:underline">Register</a></p>
    </div>
</body>
</html>
