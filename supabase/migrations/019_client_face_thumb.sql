-- Durable Face ID thumbnail stored in DB so profiles always show the photo
-- even if the full file is temporarily unavailable.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS face_photo_thumb TEXT DEFAULT NULL;
