<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $conn->prepare("SELECT * FROM habits WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $habits = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Error in habits.php: " . $e->getMessage());
    $habits = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Habits - BerrySweet</title>
    
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
                    <h1 class="text-4xl font-bold text-primary mb-2">Habit Tracker</h1>
                    <p class="text-gray-600">Build and maintain positive habits</p>
                </div>
                <button id="addHabitBtn" 
                        class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>Add Habit</span>
                </button>
            </div>
        </div>

        <!-- Habits Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($habits)): ?>
                <div class="col-span-full card p-8 text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full mx-auto flex items-center justify-center mb-4">
                        <i class="fas fa-seedling text-primary text-xl"></i>
                    </div>
                    <p class="text-gray-500">No habits yet. Click 'Add Habit' to start building better habits!</p>
                </div>
            <?php else: foreach($habits as $habit): ?>
                <div class="card p-6">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="font-medium text-lg text-gray-900"><?php echo htmlspecialchars($habit['name']); ?></h3>
                        <div class="flex gap-2">
                            <button class="edit-habit p-2 text-gray-400 hover:text-primary rounded-lg hover:bg-gray-100 transition-all" 
                                    data-id="<?php echo $habit['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($habit['name']); ?>"
                                    data-description="<?php echo htmlspecialchars($habit['description']); ?>"
                                    data-frequency="<?php echo htmlspecialchars($habit['frequency']); ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="delete-habit p-2 text-gray-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-all"
                                    data-id="<?php echo $habit['id']; ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <p class="text-gray-600 text-sm mb-4"><?php echo htmlspecialchars($habit['description']); ?></p>
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="flex flex-col justify-center">
                            <div class="grid grid-cols-2 gap-2">
                                <div class="text-center">
                                    <p class="text-xs text-gray-500">Streak</p>
                                    <p class="text-xl font-bold text-orange-500"><?php echo $habit['streak'] ?? 0; ?></p>
                                    <p class="text-xs text-gray-500">days</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-xs text-gray-500">Frequency</p>
                                    <p class="text-xl font-bold text-blue-500"><?php echo ucfirst($habit['frequency']); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="relative">
                            <canvas class="completion-chart w-full" 
                                   data-completion="<?php echo $habit['completion_rate'] ?? 0; ?>"
                                   height="100"></canvas>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-lg font-bold text-primary"><?php echo round($habit['completion_rate'] ?? 0); ?>%</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Started <?php echo date('M j', strtotime($habit['created_at'])); ?></span>
                        <div class="flex gap-2">
                            <button class="log-habit px-3 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-all"
                                    data-id="<?php echo $habit['id']; ?>">
                                Log Today
                            </button>
                            <button class="skip-habit px-3 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-all"
                                    data-id="<?php echo $habit['id']; ?>">
                                Skip
                            </button>
                        </div>
                    </div>
                    <!-- Add Skip Reasons Section -->
                    <?php
                    $skipStmt = $conn->prepare("
                        SELECT reason, skip_date 
                        FROM habit_skips 
                        WHERE habit_id = ? 
                        ORDER BY skip_date DESC 
                        LIMIT 3
                    ");
                    $skipStmt->execute([$habit['id']]);
                    $skips = $skipStmt->fetchAll();
                    
                    if (!empty($skips)): ?>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500 mb-2">Recent Skips:</p>
                        <?php foreach($skips as $skip): ?>
                        <div class="text-sm text-gray-600 mb-1">
                            <span class="text-gray-400"><?php echo date('M j', strtotime($skip['skip_date'])); ?></span>
                            - <?php echo htmlspecialchars($skip['reason']); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </main>

    <?php include 'components/chatbox.php'; ?>

    <!-- Add Habit Modal -->
    <div id="habitModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white w-full max-w-md rounded-xl p-6 shadow-xl relative">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Add New Habit</h3>
                    <button type="button" class="close-modal text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="habitForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Habit Name</label>
                        <input type="text" name="name" required 
                               class="w-full px-4 py-2 rounded-lg border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="3" 
                                  class="w-full px-4 py-2 rounded-lg border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Frequency</label>
                        <select name="frequency" required 
                                class="w-full px-4 py-2 rounded-lg border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Select frequency</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" class="close-modal px-4 py-2 text-gray-500 hover:text-gray-700">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
                            Add Habit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Skip Habit Modal after existing modals -->
    <div id="skipModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white w-full max-w-md rounded-xl p-6 shadow-xl relative">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Skip Habit</h3>
                    <button type="button" class="close-modal text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="skipForm" class="space-y-4">
                    <input type="hidden" name="habit_id" id="skipHabitId">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reason for skipping</label>
                        <textarea name="reason" required rows="3" 
                                  class="w-full px-4 py-2 rounded-lg border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20"
                                  placeholder="What prevented you from completing this habit today?"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" class="close-modal px-4 py-2 text-gray-500 hover:text-gray-700">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
                            Submit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/habits.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>
