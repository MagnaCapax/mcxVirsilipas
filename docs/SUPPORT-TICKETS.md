# Support tickets with a key

A client (an agent harness, a script, curl) can open **one new support ticket** for the account its key belongs to,
by presenting the key plus an email address of that account. Nothing else: no account data is returned, no service or
account is changed, existing tickets cannot be read or answered.

**The proxy does not serve this.** It is part of a deployment's billing integration, which holds the mapping from a
key to its account; this repository only documents the client contract so harnesses can implement it. The deployment
publishes the URL. Use it over HTTPS only: a key sent over plain HTTP is exposed whatever the server answers.

## Request

```
POST <ticket URL>
Authorization: Bearer vsk-...          (or X-API-Key: vsk-...)
Content-Type: application/json

{
  "email":       "address of the account owner, or of a user of the account who may open tickets",
  "subject":     "one line, 1-200 characters",
  "message":     "1-20000 characters of text, about 40 KB at most once HTML-encoded",
  "priority":    "Low | Medium | High",            (optional, default Medium)
  "service":     "the service number or the service username",   (optional)
  "attachments": [ {"name": "log.txt", "data": "<base64>"} ]      (optional)
}
```

- No query string. JSON only; form uploads are refused.
- The whole request may be at most 32 MiB.
- `email` is the second factor: it must be the account's own address, or the address of a user who is the owner of
  the account or holds the permission to open tickets. Contacts without a login are not accepted.
- `service`: name the service the ticket is about. When it is not a service of this account the ticket is still
  opened, without a service, and the ticket says so; the answer is the same either way, so the field cannot be used
  to discover services.
- `attachments`: any number, each at most 8 MiB decoded, within the 32 MiB request; names of letters, digits, `.`,
  `_`, `-` and spaces, starting with a letter or digit; types txt, log, png, jpg, jpeg, pdf. A `.log` file is stored
  as `<name>.log.txt`. A type the support system does not accept is refused with a 400 that names the file, never
  dropped silently.
- Fields not listed here are ignored.

## Answers

| Status | Body | Meaning |
|---|---|---|
| 201 | `{"ticket": "<number>"}` | Ticket opened; the account gets the usual new-ticket email. |
| 400 | `{"error": "<what is wrong>"}` | Malformed request (it says nothing about accounts). |
| 401 | `{"error": "not accepted"}` | Key, email or permission not accepted. Always this same answer, whatever failed. |
| 405 / 413 / 415 | `{"error": ...}` | Not POST / too large / not JSON. |
| 429 | `{"error": "too many requests, try again later"}` | A limit below was reached. |
| 503 | `{"error": "temporarily unavailable"}` | Switched off, or a check could not be completed; nothing was opened. |

## Limits

- 100 refused attempts per hour per source address (an IPv6 /64 counts as one source; set high because many
  customers can share one server address).
- 10 attempts per hour per account with a valid key, refused or not, however many addresses they come from.
- 5 tickets per hour and 20 per day per account.
- Limits are counted before the request is acted on, so parallel requests cannot slip past them.

## What the ticket shows

The message is followed by a short block written by the integration: a line saying the ticket was opened with a key
and an account email (not a client-area login), the time, the source address, the TLS version, the names of all
request headers, and the values of a few harmless ones (User-Agent, Accept, Accept-Language, Content-Type,
Content-Length, Via, X-Forwarded-For). A value that looks like a key or token is replaced by `[removed]`. The key
itself never appears.

Because a key can be stored on a server (a seedbox, a CI runner), a ticket opened this way carries no authority for
account changes: support answers questions and fixes services as usual, but asks for a client-area login before any
cancellation, refund, transfer, deletion or change of the account email or owner.

## How the key is checked

The integration stores no key for this purpose, only a PBKDF2-HMAC-SHA256 verifier of it (many SHA rounds with a
deployment salt), looks the verifier up, and then asks the proxy with the presented key whether it is still valid
(`GET /v1/usage`). A key that was rotated, disabled, expired or removed is refused there.
