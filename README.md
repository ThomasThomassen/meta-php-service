# Meta PHP Service

Small PHP 8.1 service to interact with Meta Graph API (Instagram) and expose a minimal endpoint to fetch posts by hashtag. Intended to be hosted on a subdomain and consumed from your main domain (and other whitelisted domains) via CORS.

## Features
- GET /instagram/hashtag?tag=yourtag&type=recent|top&limit=12
- GET /instagram/self/media?limit=12 (your account's posts)
- GET /instagram/self/tags?limit=12 (posts where your account is tagged)
- GET /instagram/self/merged?limit=12 (merged of both, newest first, de-duplicated)
 - GET /auth/token/debug?admin_token=... (debug current token; optional token=...)
 - GET /auth/token/refresh?admin_token=... (refresh long-lived token)
 - GET /auth/token/auto-refresh?admin_token=... (refresh only if token is near expiry)
 - GET /diagnostics (when DIAGNOSTICS_ENABLED=1)
- CORS whitelist via `ALLOWED_ORIGINS`
- Simple file cache to reduce API calls
- Optional per-IP rate limiting for public Instagram endpoints
- Health check at `/health`

## Requirements
- PHP >= 8.1 with curl and openssl
- Composer
- A Meta App and an Instagram Business Account connected to a Facebook Page
- Long-lived Instagram Graph API Access Token

Permissions generally required: `instagram_basic`, `pages_read_engagement`; depending on content access, you may need `instagram_manage_insights` and app review for `public_content_access` to read hashtagged media.

## Setup
1. Copy `.env.example` to `.env` and fill in values:
   - `ALLOWED_ORIGINS` set to your main domain(s)
   - `IG_BUSINESS_ACCOUNT_ID`
   - `IG_ACCESS_TOKEN` (long-lived)
   - Optional: `META_APP_ID` and `META_APP_SECRET` to enable token debug/refresh endpoints
   - Optional: `IG_TOKEN_STORAGE` file path to persist refreshed tokens
   - Optional: `ADMIN_TOKEN` or `WHITELISTED_IPS` to restrict admin endpoints
   - Optional: `DIAGNOSTICS_ENABLED=1` to enable `/diagnostics`
   - `GRAPH_API_VERSION` (default: `v24.0`)
   - Optional (local dev SSL help): `HTTP_VERIFY_SSL=1` and/or `CA_BUNDLE_PATH`
   - Optional (rate limiting):
     - `RATE_LIMIT_MAX_REQUESTS` (default: 60)
     - `RATE_LIMIT_WINDOW_SECONDS` (default: 60)
     - `RATE_LIMIT_TRUST_PROXY` (0/1; enable only behind a trusted proxy)
     - `RATE_LIMIT_STORAGE` (custom counters directory; default: var/ratelimit)
   - Optional (token auto-refresh):
     - `REFRESH_THRESHOLD_DAYS` (e.g., 45; refresh when token expires within this window)
     - `AUTO_REFRESH_ENABLED=1` to allow opportunistic background refresh
     - `AUTO_REFRESH_WINDOW_SECONDS` (default: 86400; run at most once per day)
2. Install dependencies:

```cmd
composer install
```

3. Run locally:

```cmd
composer start
```

Visit:
- http://127.0.0.1:8080/health
- http://127.0.0.1:8080/instagram/hashtag?tag=acme&type=recent&limit=6
 - http://127.0.0.1:8080/instagram/self/media?limit=6
 - http://127.0.0.1:8080/instagram/self/tags?limit=6
 - http://127.0.0.1:8080/instagram/self/merged?limit=6

## Endpoint
GET `/instagram/hashtag`

Query params:
- `tag` (required): hashtag without `#`
- `type` (optional): `recent` or `top` (default: `recent`)
- `limit` (optional): 1..50 (default: 12)
- `fields` (optional): custom Graph API fields (advanced)

Response shape:
```
{
  "tag": "acme",
  "type": "recent",
  "count": 6,
  "data": [
    {
      "id": "...",
      "username": "...",
      "caption": "...",
      "media_type": "IMAGE|VIDEO|CAROUSEL_ALBUM",
      "media_url": "https://...",
      "permalink": "https://...",
      "timestamp": "2024-01-01T00:00:00+0000",
      "children": [ { "media_type": "IMAGE", "media_url": "https://..." } ]
    }
  ]
}
```

## Endpoints (self media and tags)

GET `/instagram/self/media?limit=12&fields=...`
GET `/instagram/self/tags?limit=12&fields=...`

Response shape is the same as above (`data` array of normalized media items).

## CORS
Set `ALLOWED_ORIGINS` to a comma-separated list of origins (scheme + host[:port]). Use `*` to allow all (not recommended in production). Preflight OPTIONS requests are handled automatically.

## Rate limiting
This service can enforce a simple fixed-window per-IP rate limit for all `/instagram/*` endpoints.

Environment variables:
- `RATE_LIMIT_MAX_REQUESTS` — max requests per IP per window (default: 60)
- `RATE_LIMIT_WINDOW_SECONDS` — window size in seconds (default: 60)
- `RATE_LIMIT_TRUST_PROXY` — set to `1` to respect `X-Forwarded-For` when behind a trusted proxy/load balancer

On each request, the service adds `RateLimit-Limit` and `RateLimit-Remaining` headers. When exceeded, it returns HTTP 429 with `Retry-After`.

## Diagnostics
Set `DIAGNOSTICS_ENABLED=1` to enable `GET /diagnostics` for a quick environment check (PHP version, key extensions, and vendor/autoload presence). Disable in production when not needed.

## SSL on Windows/local dev
If you see `cURL error 60: SSL certificate problem: unable to get local issuer certificate` when calling the Graph API:

- Recommended: install a CA bundle and point PHP to it
  - Download cacert.pem from https://curl.se/docs/caextract.html
  - Update your php.ini (the one used to run the built-in server):
    - `curl.cainfo = C:\\path\\to\\cacert.pem`
    - `openssl.cafile = C:\\path\\to\\cacert.pem`
  - Or set `CA_BUNDLE_PATH` in `.env` to the full path; the service will pass it to Guzzle.

- Temporary (local only): disable verification by setting `HTTP_VERIFY_SSL=0` in `.env`.
  - Do not use this in production.

## Deployment
- Preferred: point your subdomain's DocumentRoot at the `public/` folder. The `public/.htaccess` will route requests to `public/index.php`.
- If you cannot change DocumentRoot, a root-level `.htaccess` is included to rewrite all requests into `public/`.
- For Nginx, route all requests under the subdomain to `public/index.php`.
- Ensure `var/cache` and `var/log` are writable by the web server user.

Robots and indexing:
- A `public/robots.txt` is provided with `Disallow: /` and `X-Robots-Tag: noindex, nofollow, noarchive` headers are set (via PHP and Apache) to discourage indexing.
- If you prefer indexing, remove or adjust these directives.

## Token management
- Long-lived Instagram Graph API user access tokens typically expire in ~60 days. You can refresh them using your App ID and App Secret.
- Endpoints (restrict with `ADMIN_TOKEN` or `WHITELISTED_IPS`):
  - `/auth/token/debug` → proxies Graph `debug_token` to show scopes and expiry
  - `/auth/token/refresh` → calls Graph `oauth/access_token` with `grant_type=fb_exchange_token` to obtain a new long-lived token
  - `/auth/token/auto-refresh` → refreshes only when expiry is within `REFRESH_THRESHOLD_DAYS`
- If `IG_TOKEN_STORAGE` is set, refreshed tokens are written there and then used by the service; otherwise, update `IG_ACCESS_TOKEN` in `.env` manually after refresh.

Opportunistic background auto-refresh:
- If `AUTO_REFRESH_ENABLED=1`, the service will attempt a background token refresh at most once per `AUTO_REFRESH_WINDOW_SECONDS` (default: daily) when handling requests.
- Alternatively, you can run the CLI refresher periodically:

```cmd
composer token:refresh
```

## Notes
- API rate limits and app review may apply when using hashtag search. Handle token refresh and permissions as per Meta docs.
- This service intentionally returns normalized media fields; adjust `fields` if you need more.