-- Add the missing 'date' column to the journals table

ALTER TABLE journals
ADD COLUMN date DATE NOT NULL AFTER title;
