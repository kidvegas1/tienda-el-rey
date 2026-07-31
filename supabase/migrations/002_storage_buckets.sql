-- Tienda Hispana El Rey — Supabase Storage buckets (Phase 3)
-- Private buckets; access via service role upload + signed URLs / PHP proxy (api/files.php)

INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES
  ('barri-reports', 'barri-reports', false, 10485760, ARRAY['application/pdf']::text[]),
  ('client-ids', 'client-ids', false, 10485760, NULL),
  ('imports', 'imports', false, 10485760, ARRAY[
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel'
  ]::text[])
ON CONFLICT (id) DO UPDATE SET
  public = EXCLUDED.public,
  file_size_limit = EXCLUDED.file_size_limit,
  allowed_mime_types = EXCLUDED.allowed_mime_types;

-- Logical upload subdirs mapped to buckets (via includes/storage.php):
--   barri-reports  → bucket barri-reports
--   imports        → bucket imports
--   client-ids, receiver-ids, income-docs, clock-in → bucket client-ids (path prefix = subdir)
