-- Add the missing 'title' column to the journals table

ALTER TABLE journals
ADD COLUMN title VARCHAR(255) NOT NULL AFTER id;
