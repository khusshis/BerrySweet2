<?php
session_start();
require_once "db.php";

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = "Username or email already exists.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hash]);
            header("Location: login.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - BerrySweet Productivity</title>
    <link rel="stylesheet" href="https://cdn.tailwindcss.com">
    <!-- ...theme and style imports... -->
</head>
<body class="theme-strawberry bg-bg text-text min-h-screen flex items-center justify-center">
    <div class="bg-white rounded-lg p-8 w-full max-w-md shadow-lg">
        <h2 class="text-2xl font-bold text-primary mb-6">Create Account</h2>
        <?php if ($error): ?>
            <div class="mb-4 text-red-500"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="register.php">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
            </div>
            <button type="submit" class="w-full bg-primary text-white py-2 px-4 rounded-md hover:bg-primary-dark">Register</button>
        </form>
        <p class="text-center mt-4 text-sm text-gray-600">Already have an account? <a href="login.php" class="text-primary hover:underline">Login</a></p>
    </div>
</body>
</html>
