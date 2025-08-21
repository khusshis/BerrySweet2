<?php
session_start();
require_once 'config/database.php';

// Add error logging
ini_set('display_errors', 1);
ini_set('error_reporting', E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

// Fetch user data and statistics
$user_id = $_SESSION['user_id'];

// Debug data fetching
try {
    // Get user info
    $userStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        throw new Exception("User not found: $user_id");
    }

    // Get stats with better error handling
    $stats = [];
    $queries = [
        'active_todos' => "SELECT COUNT(*) FROM todos WHERE user_id = ? AND status = 'pending'",
        'total_notes' => "SELECT COUNT(*) FROM notes WHERE user_id = ?",
        'active_habits' => "SELECT COUNT(*) FROM habits WHERE user_id = ?",
        'monthly_expenses' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ? AND MONTH(date) = MONTH(CURRENT_DATE())",
        'budget' => "SELECT COALESCE(budget_amount, 0) FROM user_preferences WHERE user_id = ?",
        'completed_today' => "SELECT COUNT(*) FROM todos WHERE user_id = ? AND status = 'completed' AND DATE(completed_at) = CURRENT_DATE",
        'due_today' => "SELECT COUNT(*) FROM todos WHERE user_id = ? AND DATE(due_date) = CURRENT_DATE",
        'habits_completed' => "SELECT COUNT(*) FROM habit_logs WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE",
        'notes_today' => "SELECT COUNT(*) FROM notes WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE",
        'notes_week' => "SELECT COUNT(*) FROM notes WHERE user_id = ? AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)",
        'habit_success_rate' => "SELECT ROUND(AVG(IF(completed=1, 100, 0)), 1) FROM habit_logs WHERE user_id = ? AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)",
        'recent_tasks' => "SELECT title, COALESCE(due_date, '') as due_date, status FROM todos WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
        'recent_journals' => "SELECT title, mood, created_at FROM journals WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
        'recent_notes' => "SELECT title, content, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
        'recent_expenses' => "SELECT amount, category, date FROM expenses WHERE user_id = ? ORDER BY date DESC LIMIT 5",
        'habit_stats' => "SELECT h.name, COUNT(hl.id) as completed FROM habits h LEFT JOIN habit_logs hl ON h.id = hl.habit_id 
                          WHERE h.user_id = ? GROUP BY h.id ORDER BY completed DESC LIMIT 5"
    ];

    // Add debug logging for stats
    foreach ($queries as $key => $query) {
        try {
            $stmt = $conn->prepare($query);
            $stmt->execute([$user_id]);
            
            // For recent items and habit stats, fetch as arrays
            if (strpos($key, 'recent_') === 0 || $key === 'habit_stats') {
                $stats[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("DEBUG: Stats fetch - $key = " . count($stats[$key]) . " items"); // Log count for arrays
            } else {
                $stats[$key] = $stmt->fetchColumn();
                error_log("DEBUG: Stats fetch - $key = " . ($stats[$key] ?? 'null')); // Log scalar values safely
            }
        } catch(PDOException $e) {
            error_log("ERROR: Stats fetch failed for $key - " . $e->getMessage());
            $stats[$key] = strpos($key, 'recent_') === 0 ? [] : 0;
        }
    }

    // Add this new query for today's tasks
    $taskStmt = $conn->prepare("
        SELECT 
            id, title, priority, created_at, status, due_date 
        FROM todos 
        WHERE user_id = ? 
        AND (DATE(due_date) = CURRENT_DATE OR due_date IS NULL)
        AND status = 'pending'
        ORDER BY created_at DESC
    ");
    $taskStmt->execute([$user_id]);
    $todaysTasks = $taskStmt->fetchAll();

    // Add query for recent notes
    $notesStmt = $conn->prepare("
        SELECT id, title, content, color, created_at 
        FROM notes 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $notesStmt->execute([$user_id]);
    $recentNotes = $notesStmt->fetchAll();
} catch(Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $stats = array_fill_keys(array_keys($queries), 0);
    $todaysTasks = [];
    $recentNotes = [];
}

// Update the activities query
try {
    // Fetch all recent tasks (not just 5)
    $stmt = $conn->prepare("
        SELECT 'task' as type, id, title, created_at, status, NULL as color
        FROM todos 
        WHERE user_id = ? 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug logging
    error_log("Activities Query Debug:");
    error_log("User ID: " . $user_id);
    error_log("Total activities found: " . count($activities));
    foreach ($activities as $activity) {
        error_log("Activity: {$activity['type']} - {$activity['title']} - {$activity['created_at']}");
    }

} catch(Exception $e) {
    error_log("Activities Error: " . $e->getMessage());
    error_log("SQL State: " . $e->errorInfo[0]);
    error_log("Error Code: " . $e->errorInfo[1]);
    error_log("Error Message: " . $e->errorInfo[2]);
    $activities = [];
}

// Get progress data for chart
try {
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as completed,
            DATE_FORMAT(created_at, '%a') as day_name
        FROM todos 
        WHERE user_id = ? 
        AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$user_id]);
    $progress_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fill in missing days with zero values
    $full_week_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $day_name = date('D', strtotime($date));
        $found = false;
        
        foreach ($progress_data as $day) {
            if ($day['date'] === $date) {
                $full_week_data[] = [
                    'date' => $date,
                    'completed' => (int)$day['completed'],
                    'day_name' => $day_name
                ];
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $full_week_data[] = [
                'date' => $date,
                'completed' => 0,
                'day_name' => $day_name
            ];
        }
    }
    $progress_data = $full_week_data;
} catch(Exception $e) {
    error_log("Progress Error: " . $e->getMessage());
    $progress_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-80 p-8 relative z-0 theme-aware-dashboard">
        <!-- Header Card -->
        <div class="card p-8 mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-primary mb-2">Welcome Back, <?= htmlspecialchars($_SESSION['username']) ?></h1>
                    <p class="text-gray-600">Here's your productivity overview</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Overall Progress</p>
                        <p class="text-2xl font-bold text-primary"><?= $stats['completion_rate'] ?? 0 ?>%</p>
                    </div>
                    <button onclick="quickAddTask()" 
                            class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl
                                   transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        <span>Quick Add</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Add this function before the cards array definition -->
        <?php
        // Add this function before any usage
        function generateGraphData($type) {
            global $conn, $user_id;
            
            try {
                $data = [0, 0, 0];  // [completed, in_progress, pending]
                
                switch($type) {
                    case 'completed':
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM todos WHERE user_id = ? AND status = 'completed' AND DATE(completed_at) = CURRENT_DATE");
                        $stmt->execute([$user_id]);
                        $data[0] = (int)$stmt->fetchColumn();
                        break;
                        
                    case 'due':
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM todos WHERE user_id = ? AND DATE(due_date) = CURRENT_DATE");
                        $stmt->execute([$user_id]);
                        $data[1] = (int)$stmt->fetchColumn();
                        break;
                        
                    case 'habits':
                    case 'notes':
                    case 'expenses':
                        // Add specific queries for each type
                        break;
                }
                
                return $data;
                
            } catch(PDOException $e) {
                error_log("Error in generateGraphData: " . $e->getMessage());
                return [0, 0, 0];
            }
        }
        ?>

        <!-- Define the cards array with updated structure -->
        <?php
        $cards = [
            [
                'title' => 'Active Tasks',
                'value' => $stats['active_todos'] ?? 0,
                'icon' => 'tasks',
                'color' => 'rose', // Changed from primary to rose
                'metrics' => [
                    [
                        'label' => 'Completed Today',
                        'value' => $stats['completed_today'] ?? 0,
                        'graph' => generateGraphData('completed') // Remove days parameter
                    ],
                    [
                        'label' => 'Due Today',
                        'value' => $stats['due_today'] ?? 0,
                        'graph' => generateGraphData('due') // Remove days parameter
                    ]
                ]
            ],
            [
                'title' => 'Habits Streak',
                'value' => $stats['active_habits'] ?? 0,
                'icon' => 'chart-line',
                'color' => 'blue',
                'metrics' => [
                    [
                        'label' => 'Completed',
                        'value' => $stats['habits_completed'] ?? 0,
                        'graph' => generateGraphData(7, 'habits')
                    ],
                    [
                        'label' => 'Success Rate',
                        'value' => ($stats['habit_success_rate'] ?? 0) . '%',
                        'graph' => generateGraphData(7, 'success')
                    ]
                ]
            ],
            [
                'title' => 'Recent Notes',
                'value' => $stats['total_notes'] ?? 0,
                'icon' => 'sticky-note',
                'color' => 'indigo',
                'metrics' => [
                    [
                        'label' => 'Created Today',
                        'value' => $stats['notes_today'] ?? 0,
                        'graph' => generateGraphData(7, 'notes')
                    ],
                    [
                        'label' => 'This Week',
                        'value' => $stats['notes_week'] ?? 0,
                        'graph' => generateGraphData(7, 'notes_weekly')
                    ]
                ]
            ],
            [
                'title' => 'Monthly Expenses',
                'value' => '₹' . number_format($stats['monthly_expenses'] ?? 0),
                'icon' => 'wallet',
                'color' => 'teal', // Changed from emerald to teal
                'metrics' => [
                    [
                        'label' => 'Budget',
                        'value' => '₹' . number_format($stats['budget'] ?? 0),
                        'graph' => generateGraphData(7, 'expenses')
                    ],
                    [
                        'label' => 'Remaining',
                        'value' => '₹' . number_format(($stats['budget'] ?? 0) - ($stats['monthly_expenses'] ?? 0)),
                        'graph' => generateGraphData(7, 'remaining')
                    ]
                ]
            ]
        ];
        ?>

        <!-- Updated stats grid and card structure -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-9">
            <?php foreach($cards as $index => $card): ?>
                <div class="bg-white/95 backdrop-blur-sm rounded-xl p-3 shadow hover:shadow-md transition-all duration-300">
                    <!-- Card Header -->
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-10 h-10 bg-<?= $card['color'] ?>-50 rounded-lg flex items-center justify-center">
                            <i class="fas fa-<?= $card['icon'] ?> text-base text-<?= $card['color'] ?>-500"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-medium text-gray-500"><?= $card['title'] ?></h3>
                            <p class="text-lg font-bold text-<?= $card['color'] ?>-500"><?= $card['value'] ?></p>
                        </div>
                    </div>
                    
                    <!-- Metrics Grid -->
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach($card['metrics'] as $metric): ?>
                            <div class="bg-gray-50 rounded-lg p-2 relative overflow-hidden">
                                <span class="text-xs font-medium text-gray-500"><?= $metric['label'] ?></span>
                                <div class="text-sm font-bold text-<?= $card['color'] ?>-600"><?= $metric['value'] ?></div>
                                <!-- Removed the metric-chart graph canvas -->
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Tasks Overview -->
            <div class="card p-6 lg:col-span-2">
                <h3 class="text-lg font-medium text-gray-900 mb-6">Weekly Progress</h3>
                <div class="grid grid-cols-7 gap-4">
                    <?php foreach ($progress_data as $day): ?>
                        <div class="p-4 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                            <p class="text-sm text-gray-500 mb-1"><?= $day['day_name'] ?></p>
                            <p class="text-lg font-bold text-primary"><?= $day['completed'] ?></p>
                            <p class="text-xs text-gray-400"><?= date('M j', strtotime($day['date'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Activity Feed -->
            <div class="card p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-6">Recent Activity</h3>
                <!-- Show all recent tasks in the activity feed, scrollable if too many -->
                <div class="space-y-4 max-h-80 overflow-y-auto pr-1">
                    <?php if (empty($activities)): ?>
                        <div class="text-center text-gray-500 py-4">
                            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-history text-gray-400 text-xl"></i>
                            </div>
                            <p>No recent activity</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($activities as $activity): ?>
                            <div class="flex items-center gap-4 p-3 rounded-lg hover:bg-gray-50 transition-all">
                                <?php
                                $iconClass = match($activity['type']) {
                                    'task' => $activity['status'] === 'completed' ? 'text-green-500' : 'text-rose-500',
                                    'note' => 'text-indigo-500',
                                    'habit' => 'text-blue-500'
                                };
                                
                                $icon = match($activity['type']) {
                                    'task' => $activity['status'] === 'completed' ? 'check-circle' : 'tasks',
                                    'note' => 'sticky-note',
                                    'habit' => 'chart-line'
                                };

                                $timeAgo = time_elapsed_string($activity['created_at']);
                                ?>
                                
                                <div class="w-10 h-10 rounded-lg bg-gray-50 flex items-center justify-center">
                                    <i class="fas fa-<?= $icon ?> <?= $iconClass ?>"></i>
                                </div>
                                
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">
                                        <?= htmlspecialchars($activity['title']) ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?= $timeAgo ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Replace Charts Section with Stats Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
            <div class="card p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-6">Task Progress</h3>
                <div class="grid grid-cols-2 gap-6">
                    <!-- Task Stats -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Completed Today</p>
                        <p class="text-2xl font-bold text-primary"><?= $stats['completed_today'] ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Due Today</p>
                        <p class="text-2xl font-bold text-blue-500"><?= $stats['due_today'] ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Weekly Total</p>
                        <p class="text-2xl font-bold text-green-500"><?= $stats['weekly_completed'] ?? 0 ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Completion Rate</p>
                        <p class="text-2xl font-bold text-indigo-500"><?= round(($stats['completed_today'] / max(1, $stats['due_today'])) * 100) ?>%</p>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-6">Activity Overview</h3>
                <div class="grid grid-cols-2 gap-6">
                    <!-- Activity Stats -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Total Tasks</p>
                        <p class="text-2xl font-bold text-primary"><?= $stats['active_todos'] ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Total Notes</p>
                        <p class="text-2xl font-bold text-blue-500"><?= $stats['total_notes'] ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Active Habits</p>
                        <p class="text-2xl font-bold text-green-500"><?= $stats['active_habits'] ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-sm text-gray-500 mb-1">Monthly Expenses</p>
                        <p class="text-2xl font-bold text-indigo-500">₹<?= number_format($stats['monthly_expenses']) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Overview Grid after Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
            <!-- Tasks Overview -->
            <div class="card p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center gap-2">
                        <i class="fas fa-tasks text-rose-500"></i> Recent Tasks
                    </h3>
                    <a href="tasks.php" class="text-rose-500 hover:underline text-sm font-semibold flex items-center gap-1">
                        View All <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                </div>
                <!-- For Tasks Overview -->
                <ul class="divide-y divide-gray-200 max-h-56 overflow-y-auto pr-1">
                    <?php if (empty($stats['recent_tasks'])): ?>
                        <li class="py-6 text-center text-gray-400">No recent tasks</li>
                    <?php else: ?>
                        <?php foreach (array_slice($stats['recent_tasks'], 0, 3) as $task): ?>
                            <li class="flex items-center justify-between py-4 group hover:bg-rose-50 px-3 rounded-lg transition">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-rose-100 text-rose-500">
                                        <i class="fas fa-<?= $task['status'] === 'completed' ? 'check-circle' : 'circle' ?>"></i>
                                    </span>
                                    <div>
                                        <p class="font-medium text-gray-900"><?= htmlspecialchars($task['title']) ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php
                                            if (!empty($task['due_date']) && $task['due_date'] !== '0000-00-00' && $task['due_date'] !== '0000-00-00 00:00:00') {
                                                echo 'Due: ' . date('M j', strtotime($task['due_date']));
                                            } else {
                                                echo 'No due date';
                                            }
                                            ?>
                                        </p>
                                    </div>
                                </div>
                                <!-- No time_elapsed_string here -->
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Journal & Notes Overview -->
            <div class="flex flex-col gap-6">
                <!-- Journal Overview -->
                <!-- <div class="card p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center gap-2">
                            <i class="fas fa-book text-indigo-500"></i> Recent Journal Entries
                        </h3>
                        <a href="journals.php" class="text-indigo-500 hover:underline text-sm font-semibold flex items-center gap-1">
                            View All <i class="fas fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                    <ul class="divide-y divide-gray-200 max-h-56 overflow-y-auto pr-1">
                        <?php if (empty($stats['recent_journals'])): ?>
                            <li class="py-6 text-center text-gray-400">No recent journals</li>
                        <?php else: ?>
                            <?php foreach (array_slice($stats['recent_journals'], 0, 3) as $journal): ?>
                                <li class="flex items-center gap-3 py-4 group hover:bg-indigo-50 px-3 rounded-lg transition">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-500 text-xl">
                                        <?= getMoodEmoji($journal['mood']) ?>
                                    </span>
                                    <div>
                                        <p class="font-medium text-gray-900"><?= htmlspecialchars($journal['title']) ?></p>
                                        <p class="text-xs text-gray-500"><?= date('M j, Y', strtotime($journal['created_at'])) ?></p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div> -->
                <!-- Notes Overview -->
                <div class="card p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center gap-2">
                            <i class="fas fa-sticky-note text-blue-500"></i> Recent Notes
                        </h3>
                        <a href="notes.php" class="text-blue-500 hover:underline text-sm font-semibold flex items-center gap-1">
                            View All <i class="fas fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                    <ul class="divide-y divide-gray-200 max-h-56 overflow-y-auto pr-1">
                        <?php if (empty($stats['recent_notes'])): ?>
                            <li class="py-6 text-center text-gray-400">No recent notes</li>
                        <?php else: ?>
                            <?php foreach (array_slice($stats['recent_notes'], 0, 3) as $note): ?>
                                <li class="flex items-start gap-3 py-4 group hover:bg-blue-50 px-3 rounded-lg transition">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-500">
                                        <i class="fas fa-sticky-note"></i>
                                    </span>
                                    <div>
                                        <p class="font-medium text-gray-900"><?= htmlspecialchars($note['title']) ?></p>
                                        <p class="text-xs text-gray-500"><?= date('M j, Y', strtotime($note['created_at'])) ?></p>
                                        <?php if (!empty($note['content'])): ?>
                                            <p class="text-xs text-gray-400 line-clamp-2"><?= htmlspecialchars(mb_strimwidth(strip_tags($note['content']), 0, 60, '...')) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </main>

    <?php include 'components/chatbox.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/chat-init.js"></script>
    <script src="assets/js/chat.js"></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>

<?php
function getMoodEmoji($mood) {
    return match($mood) {
        'happy' => '😊',
        'neutral' => '😐',
        'sad' => '😔',
        'excited' => '🤩',
        'tired' => '😴',
        default => '📝'
    };
}

function time_elapsed_string($datetime) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00' || $datetime === '0000-00-00') {
        return 'No date available';
    }

    try {
        $now = new DateTime('now', new DateTimeZone('Asia/Kolkata')); // Use your timezone
        $ago = new DateTime($datetime, new DateTimeZone('Asia/Kolkata'));
        $diff = $now->diff($ago);

        // For future dates
        if ($now < $ago) {
            if ($diff->days == 0) {
                $hours = $diff->h;
                $minutes = $diff->i;
                if ($hours == 0) {
                    if ($minutes == 0) {
                        return "Due soon";
                    }
                    return "Due in $minutes min";
                }
                return "Due in $hours hr";
            }
            if ($diff->days == 1) {
                return "Due tomorrow";
            }
            if ($diff->days < 7) {
                return "Due in " . $diff->days . " days";
            }
            return "Due " . $ago->format('M j');
        }

        // For past dates
        if ($diff->days == 0) {
            $hours = $diff->h;
            $minutes = $diff->i;
            if ($hours == 0) {
                if ($minutes == 0) {
                    return "Just now";
                }
                return "$minutes min ago";
            }
            return "$hours hr ago";
        }
        if ($diff->days == 1) {
            return "Yesterday";
        }
        if ($diff->days < 7) {
            return $diff->days . " days ago";
        }
        return $ago->format('M j');
    } catch (Exception $e) {
        error_log("Date parsing error: " . $e->getMessage());
        return 'Invalid date';
    }
}
?>
