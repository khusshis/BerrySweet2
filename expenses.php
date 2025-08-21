<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_month = date('Y-m');

try {
    // Get current budget and stats
    $budgetStmt = $conn->prepare("
        INSERT IGNORE INTO user_preferences (user_id, budget_amount) 
        VALUES (?, 0)
    ");
    $budgetStmt->execute([$user_id]);

    $budgetStmt = $conn->prepare("
        SELECT budget_amount,
               COALESCE((
                   SELECT SUM(amount)
                   FROM expenses
                   WHERE user_id = ?
                   AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
               ), 0) as monthly_spent
        FROM user_preferences
        WHERE user_id = ?
    ");
    $budgetStmt->execute([$user_id, $user_id]);
    $budgetData = $budgetStmt->fetch(PDO::FETCH_ASSOC);
    
    $currentBudget = $budgetData['budget_amount'] ?? 0;
    $monthlySpent = $budgetData['monthly_spent'] ?? 0;
    $remainingBudget = max(0, $currentBudget - $monthlySpent);
    $percentUsed = $currentBudget > 0 ? ($monthlySpent / $currentBudget * 100) : 0;

    // Calculate total savings first
    $savingsStmt = $conn->prepare("SELECT COALESCE(SUM(current_amount), 0) as total FROM savings_goals WHERE user_id = ?");
    $savingsStmt->execute([$user_id]);
    $totalSavings = $savingsStmt->fetchColumn() ?? 0;

    // Get savings goals statistics
    $goalStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_goals,
            COALESCE(SUM(target_amount), 0) as total_target,
            COALESCE(SUM(CASE WHEN current_amount >= target_amount THEN 1 ELSE 0 END), 0) as completed_goals
        FROM savings_goals 
        WHERE user_id = ?
    ");
    $goalStmt->execute([$user_id]);
    $goalStats = $goalStmt->fetch() ?? [
        'total_goals' => 0,
        'total_target' => 0,
        'completed_goals' => 0
    ];

    // Fetch expenses
    $stmt = $conn->prepare("SELECT * FROM expenses WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? ORDER BY date DESC");
    $stmt->execute([$user_id, $current_month]);
    $expenses = $stmt->fetchAll();

    // Calculate total
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
    $stmt->execute([$user_id, $current_month]);
    $total = $stmt->fetchColumn();

    // Get categories
    $stmt = $conn->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? GROUP BY category");
    $stmt->execute([$user_id, $current_month]);
    $categories = $stmt->fetchAll();

    // Get budget data
    $budgetStmt = $conn->prepare("
        SELECT budget_amount 
        FROM user_preferences 
        WHERE user_id = ? 
        LIMIT 1
    ");
    $budgetStmt->execute([$user_id]);
    $currentBudget = $budgetStmt->fetchColumn() ?: 0;

    // Calculate budget progress
    $monthlySpent = $total; // Use the total from expenses
    $remainingBudget = max(0, $currentBudget - $monthlySpent);
    $percentUsed = $currentBudget > 0 ? ($monthlySpent / $currentBudget * 100) : 0;

    // Calculate overall progress
    $overallProgress = ($goalStats['total_target'] ?? 0) > 0 
        ? ($totalSavings / $goalStats['total_target'] * 100) 
        : 0;

} catch(PDOException $e) {
    // Check if error is due to missing table
    if ($e->getCode() == '42S02') {
        error_log("Missing table in expenses.php: " . $e->getMessage());
        // Set default values
        $budgetData = ['budget_amount' => 0, 'monthly_spent' => 0];
        $currentBudget = 0;
        $monthlySpent = 0;
        $remainingBudget = 0;
        $percentUsed = 0;
    } else {
        throw $e;
    }
}

// Calculate overall progress
$overallProgress = $goalStats['total_target'] > 0 
    ? ($totalSavings / $goalStats['total_target'] * 100) 
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">

    <!-- Add this for profile menu -->
    <script src="assets/js/profile-menu.js" defer></script>
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-72 p-8 animate-fade-in">
        <!-- Header Section -->
        <div class=" p-8 mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-primary mb-2">Expense Tracker</h1>
                    <p class="text-gray-600">Track and manage your expenses</p>
                </div>
                <div class="flex items-center gap-4">
                    <select id="monthFilter" class="px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                        <?php
                        for ($i = 0; $i < 12; $i++) {
                            $date = date('Y-m', strtotime("-$i months"));
                            $selected = $date === $current_month ? 'selected' : '';
                            echo "<option value='$date' $selected>" . date('F Y', strtotime($date)) . "</option>";
                        }
                        ?>
                    </select>
                    <button id="addExpenseBtn" 
                            class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        <span>Add Expense</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="responsive-grid mb-8">
            <div class="bg-white p-6 rounded-lg shadow-md">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 mb-1">Total Expenses</h3>
                        <p class="text-3xl font-bold text-primary">₹<?php echo number_format($total, 2); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center">
                        <i class="fas fa-wallet text-primary text-xl"></i>
                    </div>
                </div>

                <?php
                // Calculate additional expense metrics
                $stmt = $conn->prepare("
                    SELECT 
                        COALESCE(AVG(amount), 0) as avg_expense,
                        COALESCE(MAX(amount), 0) as highest_expense,
                        COUNT(*) as transaction_count,
                        (SELECT category FROM expenses 
                         WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
                         GROUP BY category 
                         ORDER BY SUM(amount) DESC LIMIT 1) as top_category
                    FROM expenses 
                    WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                ");
                $stmt->execute([$user_id, $current_month, $user_id, $current_month]);
                $metrics = $stmt->fetch();

                // Set default values for metrics
                $metrics['avg_expense'] = $metrics['avg_expense'] ?? 0;
                $metrics['highest_expense'] = $metrics['highest_expense'] ?? 0;
                $metrics['transaction_count'] = $metrics['transaction_count'] ?? 0;

                // Get daily average and budget comparison with null handling
                $days_in_month = date('t');
                $daily_avg = $days_in_month > 0 ? ($total / $days_in_month) : 0;
                $budget = $goalStats['budget_amount'] ?? 0;
                $remaining = max(0, $budget - ($total ?? 0));
                $percent_used = $budget > 0 ? (($total ?? 0) / $budget * 100) : 0;
                ?>

                <div class="space-y-4 mt-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-3 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">Avg Transaction</p>
                            <p class="text-lg font-bold text-gray-700">₹<?php echo number_format($metrics['avg_expense'] ?? 0, 2); ?></p>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">Daily Average</p>
                            <p class="text-lg font-bold text-gray-700">₹<?php echo number_format($daily_avg, 2); ?></p>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <div class="flex justify-between mb-2">
                            <span class="text-sm text-gray-600">Monthly Budget</span>
                            <span class="text-sm font-medium">₹<?php echo number_format($currentBudget, 2); ?></span>
                        </div>
                        <?php
                        // Calculate budget percentages and status
                        $budget_used_percent = $currentBudget > 0 ? min(100, ($monthlySpent / $currentBudget) * 100) : 0;
                        $budget_status_color = 'bg-green-500';
                        
                        if ($budget_used_percent >= 90) {
                            $budget_status_color = 'bg-red-500';
                        } elseif ($budget_used_percent >= 75) {
                            $budget_status_color = 'bg-orange-500';
                        } elseif ($budget_used_percent >= 50) {
                            $budget_status_color = 'bg-yellow-500';
                        }
                        ?>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full transition-all duration-500 <?php echo $budget_status_color; ?>"
                                 style="width: <?php echo $budget_used_percent; ?>%">
                            </div>
                        </div>
                        <div class="flex justify-between items-center mt-2 text-xs">
                            <span class="text-gray-500">
                                Used: ₹<?php echo number_format($monthlySpent, 2); ?>
                            </span>
                            <span class="<?php echo $budget_used_percent >= 90 ? 'text-red-500' : 'text-green-500'; ?> font-medium">
                                Remaining: ₹<?php echo number_format(max(0, $currentBudget - $monthlySpent), 2); ?>
                            </span>
                        </div>
                        <div class="flex justify-between mt-1 text-xs text-gray-400">
                            <?php 
                            $daily_budget = $currentBudget > 0 ? $currentBudget / date('t') : 0;
                            $days_remaining = date('t') - date('j');
                            $remaining_daily_budget = $currentBudget > $monthlySpent && $days_remaining > 0 ? 
                                ($currentBudget - $monthlySpent) / $days_remaining : 0;
                            ?>
                            <span>Daily Budget: ₹<?php echo number_format($daily_budget, 2); ?></span>
                            <span>Available/Day: ₹<?php echo number_format($remaining_daily_budget, 2); ?></span>
                        </div>
                    </div>

                    <div class="flex justify-between text-sm text-gray-600">
                        <span>Transactions</span>
                        <span><?php echo $metrics['transaction_count']; ?></span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-600">
                        <span>Highest Expense</span>
                        <span>₹<?php echo number_format($metrics['highest_expense'] ?? 0, 2); ?></span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-600">
                        <span>Top Category</span>
                        <span class="font-medium"><?php echo ucfirst($metrics['top_category'] ?? 'N/A'); ?></span>
                    </div>
                    <?php if ($budget > 0): ?>
                    <div class="flex justify-between text-sm text-gray-600">
                        <span>Remaining Budget</span>
                        <span class="<?php echo $remaining < ($budget * 0.1) ? 'text-red-500' : 'text-green-500'; ?> font-medium">
                            ₹<?php echo number_format($remaining, 2); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Savings Goals Card -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-medium text-gray-500">Savings Goals</h3>
                    <button id="addSavingGoalBtn" class="text-primary hover:text-primary-dark">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <?php
                $goalStmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY deadline ASC");
                $goalStmt->execute([$user_id]);
                $goals = $goalStmt->fetchAll();
                
                if (empty($goals)): ?>
                    <div class="text-center py-4 text-gray-500">
                        <p>No savings goals yet</p>
                        <button id="createFirstGoalBtn" class="text-primary hover:text-primary-dark mt-2">
                            Create your first goal
                        </button>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach($goals as $goal): 
                            $progress = ($goal['current_amount'] / $goal['target_amount']) * 100;
                        ?>
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <div class="flex justify-between text-sm mb-2">
                                    <span class="font-medium"><?php echo htmlspecialchars($goal['name']); ?></span>
                                    <span class="text-gray-500">
                                        ₹<?php echo number_format($goal['current_amount'], 0); ?> / 
                                        ₹<?php echo number_format($goal['target_amount'], 0); ?>
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                                    <div class="bg-primary h-2.5 rounded-full transition-all duration-500"
                                         style="width: <?php echo min(100, $progress); ?>%">
                                    </div>
                                </div>
                                <div class="flex justify-between items-center text-xs">
                                    <span class="text-gray-500">
                                        Due: <?php echo date('M j, Y', strtotime($goal['deadline'])); ?>
                                    </span>
                                    <div class="flex gap-2">
                                        <button class="update-savings px-2 py-1 bg-primary/10 text-primary rounded-lg hover:bg-primary/20"
                                                data-goal-id="<?php echo $goal['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($goal['name']); ?>"
                                                data-current="<?php echo $goal['current_amount']; ?>">
                                            <i class="fas fa-plus"></i> Add
                                        </button>
                                        <button class="delete-goal px-2 py-1 bg-red-50 text-red-500 rounded-lg hover:bg-red-100"
                                                data-goal-id="<?php echo $goal['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Total Savings Card -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Total Savings</h3>
                        <p class="text-3xl font-bold text-green-500 mt-1">₹<?php echo number_format($totalSavings, 2); ?></p>
                    </div>
                    <div class="bg-green-100 rounded-full p-2">
                        <i class="fas fa-piggy-bank text-green-500 text-xl"></i>
                    </div>
                </div>

                <div class="space-y-3 mt-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Overall Progress</span>
                        <span class="text-gray-900"><?php echo number_format($overallProgress ?? 0, 1); ?>%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full transition-all duration-500"
                             style="width: <?php echo min(100, $overallProgress); ?>%">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <div class="text-center p-2 bg-gray-50 rounded-lg">
                            <p class="text-xl font-bold text-primary"><?php echo $goalStats['total_goals']; ?></p>
                            <p class="text-xs text-gray-500">Active Goals</p>
                        </div>
                        <div class="text-center p-2 bg-gray-50 rounded-lg">
                            <p class="text-xl font-bold text-green-500"><?php echo $goalStats['completed_goals']; ?></p>
                            <p class="text-xs text-gray-500">Completed</p>
                        </div>
                    </div>

                    <div class="flex justify-between text-sm text-gray-500 mt-2">
                        <span>Target Total:</span>
                        <span class="font-medium">₹<?php echo number_format($goalStats['total_target'], 2); ?></span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-500">
                        <span>Remaining:</span>
                        <span class="font-medium">₹<?php echo number_format(max(0, $goalStats['total_target'] - $totalSavings), 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Expenses Overview -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-medium text-gray-900">Weekly Overview</h3>
                <select id="weekFilter" class="px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20">
                    <?php
                    // Get start date (January 1st of current year)
                    $year_start = date('Y-01-01');
                    $current_date = date('Y-m-d');
                    $next_year = date('Y-12-31');
                    
                    // Loop through weeks for the entire year
                    $week_start = $year_start;
                    while (strtotime($week_start) <= strtotime($next_year)) {
                        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
                        $selected = $week_start === date('Y-m-d', strtotime('monday this week')) ? 'selected' : '';
                        
                        // Format the dates
                        $start_format = date('M j', strtotime($week_start));
                        $end_format = date('M j', strtotime($week_end));
                        $year_format = date('Y', strtotime($week_start));
                        
                        // Add year only if it changes
                        if ($week_start === $year_start || date('Y', strtotime($week_start)) !== date('Y', strtotime('-7 days', strtotime($week_start)))) {
                            echo "<option disabled>──── $year_format ────</option>";
                        }
                        
                        echo "<option value='$week_start' $selected>$start_format - $end_format</option>";
                        
                        // Move to next week
                        $week_start = date('Y-m-d', strtotime($week_start . ' +7 days'));
                    }
                    ?>
                </select>
            </div>
            
            <div class="space-y-6">
                <!-- Daily Breakdown -->
                <div class="grid grid-cols-7 gap-4">
                    <?php
                    $weekly_total = 0;
                    for ($i = 0; $i < 7; $i++) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $stmt = $conn->prepare("
                            SELECT COALESCE(SUM(amount), 0) as total,
                                   GROUP_CONCAT(CONCAT(category, ': ₹', amount) SEPARATOR '\n') as details
                            FROM expenses 
                            WHERE user_id = ? AND DATE(date) = ?
                        ");
                        $stmt->execute([$user_id, $date]);
                        $day_data = $stmt->fetch();
                        $weekly_total += $day_data['total'];
                    ?>
                        <div class="p-4 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors"
                             title="<?php echo $day_data['details']; ?>">
                            <p class="text-sm text-gray-500 mb-1"><?php echo date('D', strtotime($date)); ?></p>
                            <p class="text-lg font-bold text-primary">₹<?php echo number_format($day_data['total'], 0); ?></p>
                            <p class="text-xs text-gray-400"><?php echo date('M j', strtotime($date)); ?></p>
                        </div>
                    <?php } ?>
                </div>

                <!-- Weekly Summary -->
                <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-500">Weekly Total</p>
                        <p class="text-2xl font-bold text-primary">₹<?php echo number_format($weekly_total, 2); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Daily Average</p>
                        <p class="text-lg font-medium text-gray-700">₹<?php echo number_format($weekly_total / 7, 2); ?></p>
                    </div>
                </div>

                <!-- Weekly Category Breakdown -->
                <div>
                    <h4 class="text-sm font-medium text-gray-700 mb-3">Category Breakdown</h4>
                    <?php
                    // Get categories with proper totals and percentages
                    $stmt = $conn->prepare("
                        SELECT 
                            category,
                            SUM(amount) as total,
                            (SUM(amount) / (
                                SELECT NULLIF(SUM(amount), 0) 
                                FROM expenses 
                                WHERE user_id = ? 
                                AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
                            ) * 100) as percentage
                        FROM expenses 
                        WHERE user_id = ? 
                        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
                        GROUP BY category
                        ORDER BY total DESC
                    ");
                    $stmt->execute([$user_id, $user_id]);
                    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Weekly Category Breakdown section
                    echo '<div>';
                    echo '<h4 class="text-sm font-medium text-gray-700 mb-3">Category Breakdown</h4>';
                    
                    if (empty($categories)) {
                        echo '<div class="text-center py-4 text-gray-500">No expenses recorded for this week</div>';
                    } else {
                        foreach ($categories as $category) {
                            $percentage = round($category['percentage'] ?? 0, 1);
                            ?>
                            <div class="mb-2">
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600"><?php echo htmlspecialchars($category['category']); ?></span>
                                    <span class="text-gray-900">₹<?php echo number_format($category['total'], 2); ?> 
                                        <span class="text-gray-500">(<?php echo $percentage; ?>%)</span>
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-primary h-2 rounded-full" 
                                         style="width: <?php echo min(100, $percentage); ?>%">
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    echo '</div>';
                    ?>
                </div>
            </div>
        </div>

        <!-- Charts and Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Expense Chart -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Expense Breakdown</h3>
                <div class="relative h-[300px] flex items-center justify-center">
                    <?php if (empty($categories)): ?>
                        <div class="text-gray-500 text-center">
                            <i class="fas fa-chart-pie text-4xl mb-2"></i>
                            <p>No expenses recorded yet</p>
                        </div>
                    <?php else: ?>
                        <canvas id="expenseChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monthly Budget Settings -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-medium text-gray-900">Monthly Budget Settings</h3>
                    <button id="updateBudgetBtn" class="text-primary hover:text-primary-dark">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
                <?php
                // Get current budget
                $budgetStmt = $conn->prepare("SELECT budget_amount FROM user_preferences WHERE user_id = ?");
                $budgetStmt->execute([$user_id]);
                $currentBudget = $budgetStmt->fetchColumn() ?: 0;

                // Calculate remaining budget
                $remainingBudget = max(0, $currentBudget - $total);
                $percentUsed = $currentBudget > 0 ? ($total / $currentBudget) * 100 : 0;
                ?>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Current Monthly Budget:</span>
                        <span class="font-medium">₹<?php echo number_format($currentBudget, 2); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full transition-all duration-500 
                            <?php echo $percentUsed > 90 ? 'bg-red-500' : 'bg-green-500'; ?>"
                             style="width: <?php echo min(100, $percentUsed); ?>%">
                        </div>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Used: ₹<?php echo number_format($total, 2); ?></span>
                        <span class="<?php echo $remainingBudget < ($currentBudget * 0.1) ? 'text-red-500' : 'text-green-500'; ?>">
                            Remaining: ₹<?php echo number_format($remainingBudget, 2); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Recent Transactions</h3>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-sm font-medium text-gray-500">
                                <th class="pb-3">Date</th>
                                <th class="pb-3">Description</th>
                                <th class="pb-3">Category</th>
                                <th class="pb-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($expenses as $expense): ?>
                            <tr class="text-sm">
                                <td class="py-3"><?php echo date('M j', strtotime($expense['date'])); ?></td>
                                <td class="py-3"><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td class="py-3">
                                    <span class="px-2 py-1 rounded-full text-xs 
                                        <?php echo "bg-" . strtolower($expense['category']) . "-100 text-" . strtolower($expense['category']) . "-800"; ?>">
                                        <?php echo ucfirst($expense['category']); ?>
                                    </span>
                                </td>
                                <td class="py-3 text-right">₹<?php echo number_format($expense['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <?php include 'components/chatbox.php'; ?>

    <!-- Add Expense Modal -->
    <div id="expenseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white w-full max-w-md rounded-xl p-6 shadow-xl" onclick="event.stopPropagation();">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Add New Expense</h3>
                    <button type="button" class="close-modal text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="expenseForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="description" required
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (₹)</label>
                        <input type="number" name="amount" step="0.01" required min="0"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                               placeholder="0.00">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="category" required
                                class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                            <option value="Food">Food</option>
                            <option value="Transport">Transport</option>
                            <option value="Entertainment">Entertainment</option>
                            <option value="Shopping">Shopping</option>
                            <option value="Bills">Bills</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" class="close-modal px-4 py-2 text-gray-500 hover:text-gray-700">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
                            Add Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Savings Goal Modal -->
    <div id="savingsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white w-full max-w-md rounded-xl p-6 shadow-xl">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Add Savings Goal</h3>
                    <button type="button" class="close-modal text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="savingsForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Goal Name</label>
                        <input type="text" name="name" required class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Target Amount (₹)</label>
                        <input type="number" name="target_amount" required min="0" step="any" class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Current Amount (₹)</label>
                        <input type="number" name="current_amount" required min="0" step="any" class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Target Date</label>
                        <input type="date" name="deadline" required class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" class="close-modal px-4 py-2 text-gray-500 hover:text-gray-700">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">Add Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Update Savings Modal -->
    <div id="updateSavingsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white w-full max-w-md rounded-xl p-6 shadow-xl">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Update Savings</h3>
                    <button type="button" class="close-modal text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="updateSavingsForm" class="space-y-4">
                    <input type="hidden" name="goal_id" id="goalId">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount to Add (₹)</label>
                        <input type="number" name="amount" required min="0" step="any"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20">
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" class="close-modal px-4 py-2 text-gray-500 hover:text-gray-700">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Budget Modal -->
    <div id="budgetModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white w-full max-w-md rounded-xl p-6 shadow-xl">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Update Monthly Budget</h3>
                    <button type="button" class="close-modal text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="budgetForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Budget Amount (₹)</label>
                        <input type="number" name="budget_amount" required min="0" step="any"
                               value="<?php echo $currentBudget; ?>"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" class="close-modal px-4 py-2 text-gray-500 hover:text-gray-700">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">Update Budget</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const chartData = {
            labels: <?php echo json_encode(array_column($categories, 'category')); ?>,
            values: <?php echo json_encode(array_column($categories, 'total')); ?>
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/expenses.js"></script>
</body>
</html>
