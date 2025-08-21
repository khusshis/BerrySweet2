<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch(PDOException $e) {
    error_log("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <style>
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .theme-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: center;
        }
        
        .theme-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1),
                        0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .theme-preview {
            height: 120px;
            border-radius: 0.75rem;
            position: relative;
            overflow: hidden;
        }

        .settings-section {
            background: white;
            border-radius: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 2rem;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .settings-section:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-72 p-8 bg-gradient-to-br from-gray-50 to-white">
        <div class="settings-container">
            <!-- Header with improved styling -->
            <div class="mb-12 text-center">
                <h1 class="text-4xl font-bold text-gray-900 mb-3">Personalization</h1>
                <p class="text-lg text-gray-600">Customize your BerrySweet experience</p>
            </div>

            <!-- Theme Settings Section -->
            <div class="settings-section backdrop-blur-sm bg-white/50">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Theme Selection</h2>
                        <p class="text-gray-500 mt-1">Choose a theme that matches your style</p>
                    </div>
                    <span class="px-4 py-2 bg-primary/10 text-primary rounded-full text-sm">
                        Current Theme: <?php echo ucfirst($_SESSION['theme'] ?? 'strawberry'); ?>
                    </span>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
                    <?php foreach(['strawberry', 'lychee', 'mango', 'melon', 'blueberry'] as $theme): ?>
                        <button class="theme-btn theme-card group"
                                data-theme="<?php echo $theme; ?>">
                            <div class="theme-preview bg-gradient-to-br from-<?php echo $theme; ?>-400 to-<?php echo $theme; ?>-600 mb-4">
                                <?php if ($theme === ($_SESSION['theme'] ?? 'strawberry')): ?>
                                    <div class="absolute inset-0 flex items-center justify-center bg-black/20">
                                        <i class="fas fa-check text-2xl text-white"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-all duration-300"></div>
                            </div>
                            <div class="px-4 pb-4">
                                <h3 class="text-lg font-medium capitalize text-gray-900"><?php echo $theme; ?></h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    <?php echo ucfirst($theme); ?> theme
                                </p>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Color Preferences Section -->
            <div class="settings-section backdrop-blur-sm bg-white/50">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Accent Colors</h2>
                        <p class="text-gray-500 mt-1">Pick your favorite accent colors</p>
                    </div>
                </div>

                <div class="grid grid-cols-3 md:grid-cols-6 gap-6">
                    <?php foreach(['#FF7EB9', '#7afcff', '#FEFF9C', '#fff740', '#7AFFAD', '#ff65a3'] as $color): ?>
                        <div class="aspect-square rounded-2xl transition-all duration-300 hover:scale-105 cursor-pointer 
                                   hover:shadow-lg relative overflow-hidden group"
                             style="background-color: <?php echo $color; ?>20">
                            <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 
                                      transition-all duration-300 bg-white/10 backdrop-blur-sm">
                                <div class="w-10 h-10 rounded-full shadow-lg transform group-hover:scale-110 transition-transform"
                                     style="background-color: <?php echo $color; ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <?php include 'components/chatbox.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/settings.js"></script>
</body>
</html>
