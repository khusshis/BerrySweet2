-- Add the missing user_id column to the habit_skips table

ALTER TABLE habit_skips
ADD COLUMN user_id INT NOT NULL AFTER habit_id;
