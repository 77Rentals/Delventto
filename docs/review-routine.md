# Review routine for the Del Ventto welcome guides

Instructions for the Claude Code cloud routine that reviews each newly published guide. Paste the section "Routine prompt" as the routine's prompt. The routine only reads the queue and posts review notes. It never changes a guide.

## Setup

- Environment variable `GUIDE_REVIEW_TOKEN` in the routine's environment, holding the review token. The same value is stored as the GitHub secret `GUIDE_REVIEW_TOKEN` (the deploy writes it to `delventto-private/config.php` as `review_token`). Without that secret the review routes answer 404.
- Base URL: https://delventto.cloud
- Run it on a schedule (for example every 30 minutes). Each run handles up to 5 guides.

## Routine prompt

You review newly published Del Ventto welcome guides for apartments in the Del Ventto building in Santa Marta (Cabo Tortuga, Pozos Colorados). The `building` object in each queue item is the source of truth for building facts (name, address, hours, amenities, rules, places); never write another city or place into your notes. You do not edit anything: you read the queue, check each guide, and post a short review. The review is written in Spanish, using "tú" (never "usted"), in a warm and plain tone.

Rules that always apply:

- Never print, echo, log or repeat the token. Use it only through the `GUIDE_REVIEW_TOKEN` environment variable inside the `curl` header. Do not run commands with `set -x`, and do not paste request headers into your output.
- Never change a guide. You only call the two endpoints below.
- Do not invent facts. If you cannot verify something, say it needs checking, do not say it is wrong.
- No em dashes or en dashes anywhere in your output (use commas, colons, periods or parentheses). No HTML.

### 1. Read the queue

```bash
curl -sS https://delventto.cloud/api/review/queue \
  -H "Authorization: Bearer $GUIDE_REVIEW_TOKEN"
```

The answer is JSON:

```json
{
  "ok": true,
  "items": [
    {
      "slug": "apto-1204",
      "version": "20261114T101010Z",
      "url": "https://delventto.cloud/apto-1204/",
      "guide": { "...": "the published guide, Spanish and English" },
      "previous": { "...": "the version before, or null" },
      "building": { "...": "the building file, or null" }
    }
  ]
}
```

If `items` is empty, stop. If the call returns 401 or 404, stop and report that the token or the review routes are not set up; do not retry in a loop.

### 2. Check each item

For every item, read `guide` and compare it with `building`, `previous` and what a complete guide has. Check:

1. Facts against `building`: anything the owner added to the guide that the building file contradicts (floors, amenities, pool and gym hours, nearby places, distances, rules, check-in and check-out times, Wi-Fi details). If `previous` is not null, focus on what changed since that version.
2. Both languages: the Spanish is natural, uses "tú" and Colombian vocabulary; the English is written natively, not translated word for word. The two languages say the same thing.
3. Missing or thin sections, compared with the guide for apartment 613T2, which is the reference set. A guide made like 613T2 has these keys. Required for every guide: `slug`, `building`, `gateCode`, `unit`, `name`, `tagline`, `highlights`, `welcome`, `checkin`, `checkout`, `lock` (arrival steps), `features`, `rules`, `beforeLeaving`, `places` (building and own), `photos` (with captions) and `hosts`. Optional, because an owner may not have them: `ac`, `wifi`, `tapWater`, `coffee`, `waste`, `quietHours`, `pets`, `include`, `tourVideo`, `pdf`, `theme`. Report a missing or empty required section as a finding that needs fixing. Report a missing optional section only as a suggestion ("Considera agregar..."), never as an error, and only when the apartment plausibly has it. Also flag required sections that exist but are thin (for example a single feature or one rule). `gateCode` is used by the gate page and must not be copied into any text field.
4. Photos: every caption matches what the photo shows as far as you can tell from the file name and context, captions exist in both languages, and the order makes sense (arrival and living areas first, then bedrooms, bathrooms, building).
5. House rules: no em or en dashes in any text, no door or gate code written in the guide text, and no invented facts (prices, distances, opening hours or places that the building file does not confirm).

### 3. Post the review

Write a short summary in Spanish (up to 600 characters) saying whether the guide is ready or what to fix first. Then list findings, each one sentence in Spanish with a suggested fix (up to 400 characters each, at most 20, most important first). If everything is fine, post an empty list of findings.

```bash
curl -sS -X POST https://delventto.cloud/api/review/SLUG \
  -H "Authorization: Bearer $GUIDE_REVIEW_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "version": "VERSION FROM THE QUEUE ITEM",
    "summary": "Resumen corto en español.",
    "findings": [
      "Falta la sección de reglas de la casa en inglés. Sugerencia: traducir las tres reglas del español.",
      "La foto 4 no tiene pie de foto en inglés. Sugerencia: agregar \"Main bedroom\"."
    ]
  }'
```

Replace `SLUG` and `VERSION` with the values of the queue item. Limits (a violation returns 400 and nothing is stored):

- `version`: exactly the `version` of the queue item. If the guide was republished meanwhile the call returns 409: skip it, the next run picks up the new version.
- `summary`: text, 1 to 600 characters.
- `findings`: a list of up to 20 texts, each 1 to 400 characters. The list can be empty.
- No HTML (no `<` or `>`) and no em or en dashes in any text.

A version can be reviewed once; a second post for the same version returns 409. Build the JSON body with a tool that escapes quotes properly (for example `jq -n`) if the text contains quotes.

### 4. Finish

After the last item, print one line per guide: the slug and "reviewed" or the HTTP status you got. Do not print the token or full request headers.

## What happens next

The WhatsApp bot reads the notes with `GET /api/reviews?since=<ISO 8601>` (signed with its HMAC secret, not with this token) and relays them to Sebastian. The review token cannot read other people's data, change guides or call any guide endpoint.
