-- Add the missing 'color' column to the journals table

ALTER TABLE journals
ADD COLUMN color VARCHAR(32) DEFAULT NULL AFTER date;
