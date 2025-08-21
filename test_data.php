<?php
require_once 'config/database.php';

try {
    // Insert test user if not exists
    $conn->query("INSERT IGNORE INTO users (username, email, password, theme) 
                 VALUES ('test', 'test@test.com', '" . password_hash('test123', PASSWORD_DEFAULT) . "', 'strawberry')");
    
    $user_id = $conn->lastInsertId() ?: 1;

    // Insert test data
    $conn->query("INSERT INTO todos (user_id, title, status) VALUES 
                 ($user_id, 'Test Todo 1', 'pending'),
                 ($user_id, 'Test Todo 2', 'completed')");
                 
    $conn->query("INSERT INTO notes (user_id, title, content) VALUES 
                 ($user_id, 'Test Note 1', 'Content 1'),
                 ($user_id, 'Test Note 2', 'Content 2')");
                 
    $conn->query("INSERT INTO habits (user_id, name) VALUES 
                 ($user_id, 'Morning Exercise'),
                 ($user_id, 'Read Books')");
                 
    $conn->query("INSERT INTO expenses (user_id, amount, date) VALUES 
                 ($user_id, 50.00, CURRENT_DATE),
                 ($user_id, 75.00, CURRENT_DATE)");

    echo "Test data inserted successfully!";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
