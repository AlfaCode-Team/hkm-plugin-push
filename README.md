# hkm-plugin-push

Device-token registration and push delivery over **APNs** (HTTP/2, ES256
provider tokens) and **FCM HTTP v1** (RS256 service-account tokens), with a
delivery outbox and automatic pruning of tokens the provider reports dead.

Solves `push.notifications`. Requires `database.management`,
`logging.application`, `http.client`.

---

## Read this part first

**A device token identifies a DEVICE, not a person — and no push network
changes it when somebody signs out.**

On a shared handset:

1. user A signs in. The app registers token `T` against A.
2. user A signs out. The token is still `T`; signing out of your app is not a
   push-level event, so nothing told Apple or Google anything.
3. user B signs in. The app registers `T` again.

If step 3 adds a row, **every notification for A is delivered to the phone B is
holding.** Successfully. With no error on either side. It cannot happen on your
own device, because you sign in as yourself — so it reaches production untested.

This plugin handles one half and **you must handle the other**:

- **it handles step 3.** `registerDevice()` is an upsert on the token, backed by
  a unique index, so a known token re-registered for a different user is
  *reassigned*. One token, one owner. The change is logged at `notice` as
  `push.device.reassigned`.
- **you handle step 2.** Call `forgetDevice($token)` when a user signs out.
  Nothing here can do it for you — only your application knows a session
  ended. A device signed out and then left alone keeps its old owner.

```php
// On sign-out. Not optional.
$push->forgetDevice($token);

// On account deletion, or "sign out everywhere":
$push->forgetUser($userId);
```

`push:status` warns when no CachePort is bound and reports the device count, but
it cannot tell whether you call `forgetDevice()`. That one is on you.

---

## Installing

```bash
hkm plugins enable push
hkm cli migrate            # creates push_devices and push_outbox
hkm cli push:status        # checks the configuration and authenticates
```

The migration ships identically in `database/migrations` (central) and
`database/tenant-template`, so it works with or without the Tenancy plugin.

### Configuration

Every credential is **optional in the manifest and validated at runtime**, so a
project that uses only FCM is not asked for Apple's keys. `push:status` names
whatever is missing.

| Variable | Default | What it is |
|---|---|---|
| `PUSH_ENABLED` | `true` | master switch; false makes every send a no-op |
| `PUSH_DRY_RUN` | `false` | see below — it is not symmetrical |
| `PUSH_FCM_ENABLED` | `false` | |
| `PUSH_FCM_PROJECT_ID` | — | goes into the send URL itself |
| `PUSH_FCM_CREDENTIALS` | — | the service-account JSON, **or a path to it** |
| `PUSH_APNS_ENABLED` | `false` | |
| `PUSH_APNS_KEY_ID` | — | the 10-character Key ID |
| `PUSH_APNS_TEAM_ID` | — | the 10-character Team ID |
| `PUSH_APNS_KEY` | — | the `.p8` contents, **or a path to it** |
| `PUSH_APNS_TOPIC` | — | your bundle id |
| `PUSH_APNS_ENVIRONMENT` | `production` | the default for a registration that does not state its own |
| `PUSH_APNS_PORT` | `443` | Apple also accepts 2197, for firewalls that block 443 |
| `PUSH_OUTBOX_BATCH` | `100` | per drain |
| `PUSH_OUTBOX_MAX_ATTEMPTS` | `5` | then the row is marked `dead` |

A credential may be given inline or as a file path — a container injects JSON in
an environment variable, a server mounts a `.p8` at 0600, and both are
legitimate. A path is detected by being readable, and a path that *looks* like
one but is not readable is an error rather than being treated as key material.

**No key material is ever printed, logged or put in an error message.**
`push:status` reports shapes: which service account, how many bytes of PEM,
which key id.

---

## Using it

```php
use Plugins\Push\API\Contracts\PushServiceContract;
use Plugins\Push\Domain\{Notification, Platform, PushEnvironment, PushType};

// From your own registration endpoint — this plugin ships no routes, see below.
$push->registerDevice(
    token: $request->input('token'),
    platform: Platform::Ios,
    userId: $request->identity()->userId,
    environment: PushEnvironment::Production,
    fid: $request->input('fid', ''),       // Firebase Installation ID, if the client has one
    label: $request->userAgent()->describe(),
);

// Queued, one row per device, drained by the push.deliver job or push:drain.
$push->notify([$userId], Notification::of(
    title: 'Order shipped',
    body: 'Your order is on its way',
    data: ['orderId' => '42'],             // values must be STRINGS
    badge: 3,
    collapseId: 'order-42',                // two of these replace each other on the device
    ttlSeconds: 3600,
));

// A silent data push, correct by construction (priority 5, no alert).
$push->notify([$userId], Notification::silent(['sync' => 'inbox']));

// Blocking, for when you must know the outcome before returning.
$report = $push->sendNow($token, Notification::of(title: 'Welcome'));
```

### Why `notify()` is queued

FCM HTTP v1 **has no multicast**. The legacy API took a `registration_ids`
array; v1 takes one target per request. So a user with four devices is four
HTTPS requests to a third party, and a broadcast to ten thousand is ten
thousand — which is not something to do inside a web request, because the
page's latency becomes Apple's latency.

### "Delivered" never means "seen"

No push network reports that a human saw anything. `DeliveryReport::isDelivered()`
means **the provider accepted it**. Anything built on "they were notified" is
built on acceptance; say so in your product rather than in a support ticket.

### Why there are no routes

A device-registration endpoint belongs to your project, because only your
project knows how its clients authenticate and which user a registration may be
attributed to. A route shipped here would either be unauthenticated — letting
anyone register a token against any user id, which is the
delivery-to-the-wrong-person bug by a different road — or would hard-code an
auth scheme this plugin has no business assuming.

---

## Commands

```bash
hkm cli push:status                  # configuration + authenticate against both providers
hkm cli push:send --token=… --title=… --body=…
hkm cli push:send --token=… --silent
hkm cli push:drain [--limit=50]      # deliver what is queued
```

`push:status` does real work rather than echoing settings back, because every
way push can be misconfigured produces the same symptom — nothing arrives:

- **FCM is verified end to end** using FCM's own `validate_only` flag, which
  exercises the credentials, the project, the token and the whole message
  shape, and delivers nothing. That is as close to proof as a status command
  gets.
- **APNs has no validate-only mode.** The most that can be checked without
  notifying somebody is that the provider token *signs*, which is where a wrong
  key, key id or team id actually fails. Whether Apple accepts it needs a real
  device token — that is `push:send`.

`PUSH_DRY_RUN` is asymmetrical for the same reason: FCM requests go out with
`validate_only`, and APNs requests are not sent at all.

---

## Things that will bite you

### The provider token must be cached, and the cache must be shared

Apple: *"Refresh your token no more than once every 20 minutes and no less than
once every 60 minutes."* There is a **floor** as well as a ceiling, and the
penalty for crossing it is `429 TooManyProviderTokenUpdates`.

Under PHP-FPM every request is a fresh process, so an in-process cache is no
cache at all: a hundred requests mint a hundred tokens and APNs starts refusing.
**Bind a `CachePort`** (Redis, APCu) before sending through APNs from web
requests. `push:status` warns when none is bound, and says why.

There is deliberately no setting for the token lifetime. The window is 20–60
minutes; this uses 30. Every value outside that window is a 403 or a 429, and no
value inside it is better than another.

### Sandbox and production tokens are not interchangeable

A token from a debug build sent to the production host comes back
`400 BadDeviceToken` — Apple's own words: *"Verify that the request contains a
valid token and that the token matches the environment."*

So the symptom of a mismatch is identical to the symptom of a dead token, and
the obvious conclusion — prune it — destroys a good registration. The
environment is therefore stored **per device**, not configured once, because one
deployment routinely holds both: TestFlight builds carry production tokens while
Xcode builds carry sandbox ones, from the same app, at the same time.
`PUSH_APNS_ENVIRONMENT` is only the default for a registration that did not say.

### `INVALID_ARGUMENT` from FCM is six faults wearing one name

Firebase lists it for a bad registration token, a bad package name, an oversized
payload, a bad data key, a bad TTL and bad parameter names or types. Only one is
the token's fault, and Firebase's guidance is to *check* the token format, not
delete it.

This plugin prunes on `INVALID_ARGUMENT` **only** when the message names the
registration token (Firebase's own example being `"The registration token is not
a valid FCM registration token"`). Everything else is reported for a human.
Pruning on the code alone loses live devices because a data key was mistyped.

### `SENDER_ID_MISMATCH` is not a dead token either

The token is fine; this server is the wrong sender for it. Deleting it would be
wrong twice over.

### `410` has two reasons

`Unregistered` **and** `ExpiredToken`. Code that knows only the first keeps dead
tokens forever, because the status matched and the reason did not.

### HTTP/2 is not optional for APNs

Apple: *"Use HTTP/2 and TLS 1.2 or later."* libcurl happens to default to HTTP/2
over TLS, so this works by accident on most builds — but a cURL built without
nghttp2 silently falls back to HTTP/1.1, which APNs refuses, and the failure
looks like a network problem. The request therefore asks for HTTP/2 explicitly
(via the `http_version` option added to `hkm-plugin-http-client` 1.2.0), and the
gateway refuses to start if this build has no HTTP/2 at all.

Measured: forcing HTTP/1.1 against `api.push.apple.com` fails with
`Received HTTP/0.9 when not allowed`.

### FCM deprecated the `token` target

Its Message reference now says **"Use `fid` instead"**, accepting a Firebase
Installation ID in either field during the transition. Both are stored, and
`fid` is sent when the client supplied one — so a fleet migrating app-side does
not have to migrate server-side on the same day.

### A duplicate is possible, and a collapse id makes it harmless

An outbox row is marked delivered *after* the provider answers. If the process
dies in between — the request succeeded, the UPDATE never ran — the row is still
pending and will be sent again.

That is not fixable here: neither APNs nor FCM offers an idempotency key, so
there is no way to ask "did you already take this one?". The alternative,
marking delivered *before* sending, trades a rare duplicate for a rare silent
loss, and for a notification a loss is worse.

What **is** fixable is the consequence: set `collapseId` on anything that
matters. Two notifications sharing one replace each other on the device, so a
duplicate becomes nothing at all instead of a second buzz.

### Payload size is measured on the encoded bytes

4096 bytes (5120 for VoIP), and Apple forbids compressing it, so there is no
headroom to buy. The check runs on the JSON, not on your strings, because
escaping a single quote mark or emoji adds bytes — a payload that looks like
3 KB of text can encode past the limit and be refused with `413` after the TLS
handshake has already been paid for. The usual culprit is a `data[]` map
carrying a whole record where an id would do.

---

## Events

`push.token_invalidated` — a provider reported a token dead and the
registration has been removed. Carries `userId`, `platform`, `provider`,
`reason`, `occurredAt`, and **deliberately not the token**: an integration event
crosses module boundaries and is likely to be logged, and what a listener
legitimately needs is "this user has one fewer way to be reached".

---

## What is verified, and what is not

Verified by running, not by inspection:

- **The ES256 and RS256 signing is checked against `firebase/php-jwt`** — a
  library this plugin did not write. A codec checked against its own decoder
  proves only self-consistency. The DER→JOSE conversion is swept over 400
  freshly generated keys, and the test *asserts that the padding-sensitive case
  actually occurred* (it does, about 4 times in 400) — because a parser that
  strips a fixed header is correct for most keys and wrong for roughly one in
  128, which is an intermittent `403` and the worst flavour of bug.
- **A real request reached Apple's production endpoint** over HTTP/2 and was
  answered `403 InvalidProviderToken` with an `apns-id` — the correct answer for
  a locally generated key Apple has never seen, and proof that Apple accepted
  the request shape and got as far as verifying the signature. A malformed
  request answers `BadPath` or `MissingTopic` instead.
- **A real request reached Google's token endpoint** and was answered
  `invalid_grant — account not found`, meaning Google parsed the assertion and
  looked up the service account.
- The shared-device reassignment, the outbox retry/backoff/terminal states, and
  both provider error tables are covered by 103 tests (632 assertions).
- `ground check` clean, `ground probe` clean, PHPStan level 8 clean.

**Not verified — no notification has ever been delivered to a handset.** That
needs an Apple developer account, a Firebase project and a physical device, none
of which exist here. Everything up to the provider's acceptance is exercised
against the real services; the last hop is not. In particular these remain
untested against a live account:

- a `200` from APNs, and the `apns-id` it returns on success;
- a real `410 Unregistered` with its `timestamp`, so the
  re-registration-is-newer comparison is reasoned from Apple's two documented
  sentences rather than observed;
- FCM's success body and `validate_only` acceptance;
- Live Activity, VoIP and the other push types beyond `alert` and `background`;
- any load above a single notification.

The `apns-topic` suffixes for VoIP, complications, file providers and
push-to-talk are **deliberately not asserted** by this plugin: Apple's current
reference does not publish the table, and only
`.push-type.liveactivity` is confirmed first-party (its WWDC23 session). Set
`PUSH_APNS_TOPIC` to the full topic, or pass one per notification.
