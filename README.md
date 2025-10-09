# deo-hanumakonda

This repository contains the DEO Hanumakonda teacher management web app (PHP + MySQL). It is intended to run on XAMPP or any PHP+MySQL stack.

Quick start

1. Copy `includes/db_connect.php.example` to `includes/db_connect.php` and update DB credentials.
2. Ensure a MySQL database is created and import `schema.sql` if needed.
3. Place this project in your webroot (XAMPP: `C:\xampp\htdocs`).
4. Open your browser at `http://localhost/deo-hanumakonda/teacher_edit.php?treasury_code=...`.

Security notes

- `includes/db_connect.php` is not committed; keep it out of source control.
- `debug_select.php` is now gated to admin sessions; remove it if no longer needed.
- `normalize_school_list.php` and runner were used for one-off normalization; consider removing them after use.

Publishing to GitHub

1. Initialize a local repository and commit (already done).
2. Create a private repository on GitHub.
3. Add your GitHub remote and push:

   git remote add origin https://github.com/<your-user>/<repo>.git
   git branch -M main
   git push -u origin main

If you want, I can push for you if you provide a remote URL with write access (or add me as a collaborator). Otherwise, follow the steps above.
