# Deploy NTP ke Supabase dan Render

## Yang sudah disiapkan di project ini

- Koneksi database sekarang bisa memakai MySQL lokal atau PostgreSQL Supabase lewat environment variable.
- `Dockerfile` dan `render.yaml` sudah ditambahkan supaya Render bisa menjalankan app PHP ini.
- `supabase/schema.sql` sudah disiapkan untuk membuat tabel yang dibutuhkan di Supabase.
- `.env.example` disiapkan sebagai contoh konfigurasi.

## Langkah di Supabase

1. Buat project baru di Supabase.
2. Buka SQL Editor.
3. Jalankan isi file `supabase/schema.sql`.
4. Ambil `Host`, `Database`, `Port`, `User`, dan `Password` dari menu `Project Settings -> Database`.

## Langkah di Render

1. Upload project ini ke GitHub terlebih dahulu.
2. Di Render, pilih `New + -> Web Service`.
3. Hubungkan repo GitHub yang berisi project ini.
4. Render akan membaca `Dockerfile`.
5. Tambahkan environment variable berikut:
   - `DB_CONNECTION=pgsql`
   - `DB_HOST=...`
   - `DB_PORT=5432`
   - `DB_DATABASE=postgres`
   - `DB_USERNAME=postgres`
   - `DB_PASSWORD=...`
   - `DB_SSLMODE=require`
   - atau cukup `DATABASE_URL=postgresql://...`
   - `GEMINI_API_KEY=...` jika fitur AI ingin tetap aktif
   - `GEMINI_MODEL=gemini-3-flash-preview`

## Data awal

- Setelah schema dibuat, buka aplikasi yang sudah ter-deploy.
- Masuk ke menu upload CSV.
- Upload file NTP dan Andil dari antarmuka aplikasi untuk mengisi data Supabase.

## Catatan penting

- File `.env` lokal saat ini berisi API key nyata. Sebaiknya pindahkan key itu ke environment variable Render dan rotasi key lama.
- Saya belum bisa melakukan deploy langsung dari sini karena butuh akses ke akun Supabase, GitHub, dan Render Anda.
