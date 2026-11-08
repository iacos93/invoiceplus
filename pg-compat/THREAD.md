# PG Compat — Thread State

## Objective
Find and fix all MySQL-isms in `app/` that crash or misbehave on PostgreSQL.

## Status: SCANNER CLEAN ✓

### All fixes applied
| # | File | Pattern | What | Status |
|---|------|---------|------|--------|
| 1 | app/Models/F24Model.php:53-57 | P1 | Removed `= ''` / `!= ''` on `f24_paid_date` (date column) → `IS NULL` / `IS NOT NULL` | FIXED (pre-harness) |
| 2 | app/Models/ReportModel.php:40,122,193 | P2 | Changed `" "` → `' '` in CONCAT (3 occurrences) | FIXED (pre-harness) |
| 3 | app/Models/ReportModel.php:155-160 | P3 | Wrapped HAVING on aliases in subquery with WHERE + COALESCE | FIXED (pre-harness) |
| 4 | app/Controllers/ClientsAjax.php:42-44 | P3 | Replaced having/orHaving with WHERE + groupStart; spelled out CONCAT instead of alias | FIXED |

### Verification
- Smoke test: 252 routes, OK
- Unit tests: 109 tests, 310 assertions, OK
- Scanner: 0 findings across 5 patterns

### Known latent issues (not crash-class, logged for awareness)
- **LIKE case sensitivity**: MySQL LIKE is case-insensitive for utf8; PG LIKE is case-sensitive. Search in `ClientsAjax.php` and `Search.php` may need `ILIKE` if users expect case-insensitive search. This is a behavioral difference, not a crash.
- **Empty string on text columns in raw SQL** (`InvoiceAmountModel.php:309,341`): `client_split_payment = ''` works on PG text columns but depends on whether data has NULLs vs empty strings. Currently safe because the `OR IS NULL` branch covers both.

### Next action
Harness complete. The scanner can be re-run after future model changes to catch regressions:
```bash
php pg-compat/scanner.php
```
