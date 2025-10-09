teacher_view.php

Purpose

This script loads a CSV/JSON mapping that defines how to display columns from the `teacherdata` table, then fetches a teacher row by an identifier and renders the fields grouped by section in a responsive 3-column layout.

How to use

1. Put `teacher_view.php` in your project root (already done).
2. Ensure one of these mapping files exists:
   - teacher_structure.csv (preferred)
   - Book1.csv (your attached CSV)
   - config/label_map.json (optional JSON mapping)
3. Ensure database connection is available via `includes/db_connect.php` (the script will reuse it). If not, edit the mysqli fallback in the file.
4. Open in browser with an id:

   http://localhost/deo-hanumakonda/teacher_view.php?id=12345

   To use a different column for lookup (validated against mapping):

   http://localhost/deo-hanumakonda/teacher_view.php?by=SchCode&id=98765

Notes

- Missing values are displayed as an en-dash (–).
- The page includes a Print button and print-friendly CSS.
- If you want Bootstrap styling, let me know and I will enable it.
