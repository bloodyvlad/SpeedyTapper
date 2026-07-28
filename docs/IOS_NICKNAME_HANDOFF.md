# iOS task — unique player-name availability

Use the existing authenticated PimPoPom cookie session and CSRF token. Do not derive availability locally or from Game Center/Apple display names.

## Validation

- Permit non-space Unicode names, including Cyrillic and `_`.
- Reject every whitespace character before making a request.
- Maximum length remains 20 characters.
- Display the backend's normalized `nickname` after a successful save.

## Availability request

Debounce input by 300–500 ms and cancel, or ignore, stale responses:

```http
POST /api/profile/nickname/availability
Content-Type: application/json
X-SpeedyTapper-CSRF: <session csrfToken>

{"nickname":"Player9551"}
```

Available:

```json
{"nickname":"Player9551","available":true}
```

Already claimed:

```json
{"nickname":"Player9551","available":false}
```

Invalid whitespace:

```http
HTTP/1.1 400
```

```json
{"error":"Player names cannot contain spaces."}
```

The player's unchanged current name is available to that player. Name comparison is case- and accent-insensitive on the server, so `Player9551` and `player9551` cannot belong to different profiles.

## UI behavior

- While checking, show a neutral progress state and do not claim availability.
- On `available: false`, show **This player name is already taken.** in red directly below the player-name field and disable Save.
- Clear that notice when the text changes.
- A network/server failure means **unknown**, not available. Keep Save disabled or let the final save provide the authoritative error.
- Never show who owns a taken name.

## Authoritative save

Keep the existing save request:

```http
PATCH /api/profile
Content-Type: application/json
X-SpeedyTapper-CSRF: <session csrfToken>

{"nickname":"Player9551"}
```

Availability can race. If the final save returns:

```http
HTTP/1.1 409
```

```json
{"error":"This player name is already taken."}
```

show the same red inline notice and keep the editing screen open. Do not retry automatically with a modified name.
