-- Client face photo + recognition descriptor for live counter match.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS face_photo_path VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS face_descriptor TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS face_consent_at TIMESTAMPTZ DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS face_enrolled_at TIMESTAMPTZ DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_clients_face_enrolled
  ON clients (id)
  WHERE face_descriptor IS NOT NULL;
