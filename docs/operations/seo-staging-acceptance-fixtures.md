# SEO staging acceptance fixtures

The acceptance fixtures are opt-in and are not called by `DatabaseSeeder`. They create only records with `SEO-STAGING-` names and `seo-staging-` slugs, except for required policy pages whose public slugs must remain exactly `returns-policy` and `shipping-policy`. Existing rows at those policy slugs are reused without modification.

## Configuration

Before seeding, set `APP_ENV=staging`, keep `APP_URL` configured for the Laravel API application, and provide operator-approved values through:

| Variable | Required | Use |
|---|---|---|
| `SEO_STAGING_STORE_NAME_AR` | Yes | Arabic staging store name used only if the corresponding setting is absent. |
| `SEO_STAGING_STORE_NAME_EN` | Yes | English staging store name used only if the corresponding setting is absent. |
| `SEO_STAGING_CANONICAL_BASE_URL` | Yes | Absolute HTTPS origin of the staging storefront. It may differ from the backend API's `APP_URL`. |
| `SEO_STAGING_LOGO_MEDIA_ID` | No | Existing active public image with an absolute HTTPS URL. No media is created or modified. |

The seeder refuses to run outside `staging`, verifies the staging URL and optional logo, and fails if required configuration is missing. Existing SEO settings and media are never overwritten; if an existing setting conflicts with the supplied value, the seed transaction fails and asks the operator to update it through the admin workflow. A small ownership ledger records only rows inserted by this fixture so cleanup can distinguish them from existing staging content.

## Commands

Run the fixture ownership-ledger migration first in the intended staging environment:

```sh
php artisan migrate --force
```

Create the default fixture set (one bilingual product, one single-locale product, two additional bilingual products, a category, a supported content page, policy pages when absent, and missing configured SEO settings):

```sh
php artisan seo:staging-fixtures
```

Create additional eligible products until at least 25 products are available in both Arabic and English, allowing the storefront's 24-item pagination to show a second page:

```sh
php artisan seo:staging-fixtures --with-pagination
```

Verify the staging guard, repeatability, fixture counts, locale availability, public visibility, pagination count, and cleanup scope:

```sh
php artisan test tests/Feature/Console/SeoStagingAcceptanceFixturesTest.php
```

Remove only ledger-owned fixture records:

```sh
php artisan seo:staging-fixtures:clean
```

Cleanup retains records if a fixture product has order or conversion history, SEO records, media attachments, options, offers, or review images; if a fixture category has attached products, children, SEO records, or media attachments; if a fixture page was edited or gained SEO records; or if a fixture-owned setting's value changed after seeding. It never removes media, orders, customers, payments, authentication records, or untracked content.
