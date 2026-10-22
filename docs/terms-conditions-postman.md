# Terms and Conditions API: Postman Handoff

Use these requests to verify the `terms-conditions` content page and integrate it in the frontend.

## Postman environment

Create an environment with:

| Variable | Example | Description |
|---|---|---|
| `baseUrl` | `http://127.0.0.1:8000` | Local API or staging origin, without a trailing slash |
| `adminToken` | Your admin token | Sanctum token with admin access; keep it private |

All requests use `{{baseUrl}}/api/v1`. Send `Accept: application/json`. For admin requests, configure **Authorization → Bearer Token** as `{{adminToken}}`.

## 1. List configured content pages

**GET** `{{baseUrl}}/api/v1/admin/content-pages` (admin token required)

The response should contain an entry with `slug: "terms-conditions"`. Before its first save, its `id` and content fields are `null`.

## 2. Read the unsaved admin page

**GET** `{{baseUrl}}/api/v1/admin/content-pages/terms-conditions` (admin token required)

Before the first save, expect a successful response with this data shape:

```json
{
  "data": {
    "id": null,
    "slug": "terms-conditions",
    "titleAr": null,
    "titleEn": null,
    "bodyAr": null,
    "bodyEn": null,
    "createdAt": null,
    "updatedAt": null
  }
}
```

## 3. Save both translations

**PUT** `{{baseUrl}}/api/v1/admin/content-pages/terms-conditions` (admin token required)

Set **Body → raw → JSON** and send all four fields:

```json
{
  "titleAr": "الشروط والأحكام",
  "titleEn": "Terms and Conditions",
  "bodyAr": "النص العربي للشروط والأحكام",
  "bodyEn": "English terms and conditions"
}
```

All fields are required, must be strings, and cannot be blank. A missing or blank field returns **422 Unprocessable Content** with a validation error. The response returns the saved bilingual admin resource.

## 4. Read the public page in Arabic

**GET** `{{baseUrl}}/api/v1/content-pages/terms-conditions`

Send `Accept-Language: ar`. Expect `Content-Language: ar` and the Arabic values in `data.title` and `data.body`.

## 5. Read the public page in English

Send the same GET request with `Accept-Language: en` (or `en-US`). Expect `Content-Language: en` and the English values in `data.title` and `data.body`.

The public response also includes a `data.seo` object. The terms page canonical path is `/terms-conditions`; it is locale-free, so the frontend can use the same path for Arabic and English and apply its normal locale routing.

## 6. Confirm unsupported slugs return 404

**GET** `{{baseUrl}}/api/v1/content-pages/not-a-page`

Expect **404 Not Found**. Public GET for `terms-conditions` also returns 404 until the page has been saved once in the admin API.
