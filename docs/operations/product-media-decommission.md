# Legacy ProductMedia decommission

Run the legacy migration while `product_media` and the provenance ledger both exist:

1. Take and verify a restorable database backup.
2. Apply the unified media schema and `legacy_product_media_migrations` migrations.
3. Run `php artisan media:migrate-product-media` until it exits successfully.
4. Run `php artisan media:verify-product-media-migration`. It must exit successfully before proceeding.
5. Apply `2026_09_21_000003_drop_product_media_table.php` only after steps 1 through 4. The migration repeats the verification and refuses to drop the table if any active legacy row is unmapped or inconsistent.

The verifier checks all non-soft-deleted legacy rows, ledger status and identifiers, live media asset rows and stored files, attachment-to-asset links, Product or SellableItem ownership, and the role derived from the legacy media type. It does not delete remote Cloudinary objects. Soft-deleted legacy rows are excluded from verification and are removed with the table.

The migration's `down()` recreates only the `product_media` schema. It cannot restore the rows removed by `up()`, including soft-deleted rows. The provenance ledger remains in place and its existing rollback guard prevents it from being dropped after it contains records. Run the migration command before the table-drop migration; it cannot process legacy rows after `product_media` has been dropped.
