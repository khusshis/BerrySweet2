<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch today's goal with proper error handling
try {
    // Fetch today's goal
    $todayStmt = $conn->prepare("
        INSERT INTO focus_goals (user_id, target_sessions, completed_sessions, created_at)
        VALUES (?, 1, 0, CURRENT_DATE)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $todayStmt->execute([$user_id]);
    
    $todayStmt = $conn->prepare("
        SELECT * FROM focus_goals 
        WHERE user_id = ? 
        AND DATE(created_at) = CURRENT_DATE()
    ");
    $todayStmt->execute([$user_id]);
    $today = $todayStmt->fetch(PDO::FETCH_ASSOC);

    // Initialize today if not exists
    if (!$today) {
        $today = [
            'target_sessions' => 1,
            'completed_sessions' => 0
        ];
    }
} catch(PDOException $e) {
    error_log("Error in Pomodoro page: " . $e->getMessage());
    $today = [
        'target_sessions' => 1,
        'completed_sessions' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Focus Mode - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-72 p-8 animate-fade-in">
        <!-- Header Section -->
        <div class=" p-8 mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-primary mb-2">Focus Mode</h1>
                    <p class="text-gray-600">Stay focused and productive with Pomodoro Timer</p>
                </div>
            </div>
        </div>

        <!-- Timer Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="card p-8">
                <div class="text-center">
                    <div class="text-8xl font-bold text-primary mb-8" id="timer">25:00</div>
                    <div class="flex justify-center gap-4">
                        <button id="startTimer" class="px-6 py-3 bg-primary text-white rounded-xl hover:bg-primary-dark transition-all">
                            <i class="fas fa-play mr-2"></i> Start
                        </button>
                        <button id="resetTimer" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </button>
                    </div>
                </div>
            </div>

            <div class="card p-8 relative overflow-hidden backdrop-blur-sm bg-white/90">
                <div class="absolute top-0 right-0 w-64 h-64 bg-primary/5 rounded-full -mr-32 -mt-32"></div>
                <div class="relative">
                    <div class="flex items-center justify-between mb-8">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center gap-2">
                            <i class="fas fa-sliders-h text-primary"></i>
                            Timer Settings
                        </h3>
                       
                    </div>

                    <div class="grid grid-cols-2 gap-8">
                        <!-- Work Duration -->
                        <div class="relative">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-sm font-medium text-gray-700">
                                    <i class="fas fa-briefcase text-primary mr-2"></i>
                                    Work Duration
                                </label>
                                <span class="text-2xl font-bold text-primary" id="workValue">25</span>
                            </div>
                            <input type="range" 
                                   id="workDuration" 
                                   min="1" 
                                   max="60" 
                                   value="25" 
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-primary">
                            <div class="flex justify-between text-xs text-gray-500 mt-1">
                                <span>1m</span>
                                <span>30m</span>
                                <span>60m</span>
                            </div>
                        </div>

                        <!-- Break Duration -->
                        <div class="relative">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-sm font-medium text-gray-700">
                                    <i class="fas fa-coffee text-primary mr-2"></i>
                                    Break Duration
                                </label>
                                <span class="text-2xl font-bold text-primary" id="breakValue">5</span>
                            </div>
                            <input type="range" 
                                   id="breakDuration" 
                                   min="1" 
                                   max="30" 
                                   value="5" 
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-primary">
                            <div class="flex justify-between text-xs text-gray-500 mt-1">
                                <span>1m</span>
                                <span>15m</span>
                                <span>30m</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-6 pt-6 border-t">
                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <div class="relative">
                                    <input type="checkbox" id="notifications" class="sr-only">
                                    <div class="w-10 h-5 bg-gray-200 rounded-full"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-3 h-3 rounded-full transition"></div>
                                </div>
                                <span class="text-sm text-gray-600">Sound Alert</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <div class="relative">
                                    <input type="checkbox" id="autoStart" class="sr-only">
                                    <div class="w-10 h-5 bg-gray-200 rounded-full"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-3 h-3 rounded-full transition"></div>
                                </div>
                                <span class="text-sm text-gray-600">Auto-start Breaks</span>
                            </label>
                        </div>
                        <button type="button" id="resetSettings" class="text-sm text-gray-500 hover:text-primary transition-colors">
                            <i class="fas fa-undo-alt mr-1"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Goals Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
            <div class="card p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-calendar-check text-primary mr-2"></i>
                    Yesterday's Goal
                </h3>
                <?php
                    $yesterdayGoal = $conn->prepare("
                        SELECT * FROM focus_goals 
                        WHERE user_id = ? 
                        AND DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)
                    ");
                    $yesterdayGoal->execute([$user_id]);
                    $yesterday = $yesterdayGoal->fetch();
                ?>
                <div class="bg-gray-50 rounded-xl p-4">
                    <?php if ($yesterday): ?>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-600">Focus Sessions</span>
                            <span class="text-lg font-bold text-primary">
                                <?php echo $yesterday['completed_sessions']; ?>/<?php echo $yesterday['target_sessions']; ?>
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-primary h-2.5 rounded-full transition-all" style="width: <?php 
                                echo ($yesterday['completed_sessions'] / $yesterday['target_sessions']) * 100; 
                            ?>%"></div>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500 italic">No sessions were planned</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-bullseye text-primary mr-2"></i>
                    Today's Goal
                </h3>
                <?php if (!$today): ?>
                    <form id="goalForm" class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                            <span class="text-gray-600">Focus Sessions</span>
                            <div class="flex items-center gap-3">
                                <button type="button" id="decrementSessions" class="w-8 h-8 rounded-lg bg-gray-200 hover:bg-gray-300 flex items-center justify-center">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span id="sessionCount" class="text-xl font-bold text-primary min-w-[2ch] text-center">1</span>
                                <input type="hidden" id="targetSessions" name="targetSessions" value="1">
                                <button type="button" id="incrementSessions" class="w-8 h-8 rounded-lg bg-gray-200 hover:bg-gray-300 flex items-center justify-center">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div id="goalProgress" class="bg-primary h-2.5 rounded-full transition-all" style="width: 0%"></div>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-primary text-white rounded-xl hover:bg-primary-dark transition-all">
                            Set Today's Target
                        </button>
                    </form>
                <?php else: ?>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-gray-600">Progress</span>
                            <div class="flex items-center gap-3">
                                <button type="button" class="goal-update-btn w-8 h-8 rounded-lg bg-gray-200 hover:bg-gray-300 flex items-center justify-center" data-direction="-1">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <div class="flex items-center gap-2">
                                    <span id="currentSessions" class="text-lg font-bold text-primary"><?php echo $today['completed_sessions']; ?></span>
                                    <span class="text-lg font-bold text-gray-400">/</span>
                                    <span id="targetSessions" class="text-lg font-bold text-primary"><?php echo $today['target_sessions']; ?></span>
                                </div>
                                <button type="button" class="goal-update-btn w-8 h-8 rounded-lg bg-gray-200 hover:bg-gray-300 flex items-center justify-center" data-direction="1">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5 mb-4">
                            <div id="goalProgress" class="bg-primary h-2.5 rounded-full transition-all duration-500" 
                                 style="width: <?php echo min(100, ($today['completed_sessions'] / max(1, $today['target_sessions']) * 100)); ?>%">
                            </div>
                        </div>
                        <div class="text-center">
                            <?php if ($today['completed_sessions'] < $today['target_sessions']): ?>
                                <p class="text-sm text-gray-500">
                                    <span id="remainingSessions"><?php echo $today['target_sessions'] - $today['completed_sessions']; ?></span> sessions remaining
                                </p>
                            <?php else: ?>
                                <p class="text-sm text-green-600 font-medium">
                                    <i class="fas fa-check-circle mr-1"></i> Daily target achieved!
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-fire text-primary mr-2"></i>
                    Current Streak
                </h3>
                <?php
                    $streak = $conn->prepare("
                        SELECT COUNT(*) as streak
                        FROM (
                            SELECT DATE(created_at) as date
                            FROM focus_goals
                            WHERE user_id = ? 
                            AND completed_sessions >= target_sessions
                            AND DATE(created_at) <= CURRENT_DATE()
                            GROUP BY DATE(created_at)
                            HAVING date >= (
                                SELECT MAX(date) 
                                FROM (
                                    SELECT DATE(created_at) as date
                                    FROM focus_goals
                                    WHERE user_id = ?
                                    AND (completed_sessions < target_sessions OR completed_sessions IS NULL)
                                ) as breaks
                            )
                        ) as continuous_days
                    ");
                    $streak->execute([$user_id, $user_id]);
                    $streakCount = $streak->fetchColumn();
                ?>
                <div class="text-center">
                    <div class="text-5xl font-bold text-primary mb-2"><?php echo $streakCount; ?></div>
                    <p class="text-gray-600">days in a row</p>
                </div>
            </div>
        </div>
    </main>

    <?php include 'components/chatbox.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/pomodoro.js"></script>
</body>
</html>
