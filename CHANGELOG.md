# Changelog

All notable changes to `hkm-plugin-push` are documented here.

## 1.0.0

First working release. Solves `push.notifications`.

Supersedes the 0.1.0 scaffold, which declared the domain and an empty contract
and could not send anything.

### Added

- **A native APNs client** over HTTP/2: the `/3/device/<token>` request, the
  full documented header set (`apns-push-type`, `apns-priority`, `apns-topic`,
  `apns-id`, `apns-expiration`, `apns-collapse-id`), and ES256 provider tokens
  signed with `ext-openssl` alone.
- **A native FCM HTTP v1 client**: the `messages:send` call, RS256
  service-account assertions exchanged for OAuth2 access tokens, and the
  per-platform `android` and `apns` blocks.
- **`PushServiceContract`** — `registerDevice`, `forgetDevice`, `forgetUser`,
  `notify`, `sendNow`, `drain`.
- **A delivery outbox**, one row per device, with per-row attempts, exponential
  backoff honouring the provider's own `Retry-After`, and terminal states for a
  dead token, a rejection and exhausted attempts.
- **Three commands**: `push:status` (authenticates for real), `push:send`,
  `push:drain`. Plus a `push.deliver` job.
- **`push.token_invalidated`** integration event, carrying no token.
- **LetMigrate migrations** for `push_devices` and `push_outbox`, shipped
  identically for central and tenant connections.

### Deliberate decisions worth knowing

- **`push_devices.token` carries a unique index, and `registerDevice()` is an
  upsert on it.** A device token identifies a DEVICE and no push network
  changes it on sign-out, so a token re-registered for a second user must
  REPLACE the first mapping. An insert would leave one token mapped to two
  users and deliver every notification for the first to the phone the second is
  holding — successfully, with no error, and only on shared devices, so never in
  development. The reassignment is logged as `push.device.reassigned`.
  The other half cannot be done here: the application must call
  `forgetDevice()` on sign-out, and the README says so first.
- **The APNs environment is stored per device, not configured once.** One
  deployment holds both sandbox and production tokens from the same app, and
  sending to the wrong host is `400 BadDeviceToken` — which is also what a
  genuinely dead token looks like, so a single configured host would prune live
  registrations.
- **Provider tokens are cached in a shared `CachePort`, not per process.**
  Apple sets a floor as well as a ceiling — "no more than once every 20
  minutes" — and under PHP-FPM a per-process cache means one token per request
  and a `429`. There is no setting for the lifetime: the window is 20–60
  minutes, 30 is used, and every value outside it is an error.
- **`INVALID_ARGUMENT` from FCM prunes a token only when the message names it.**
  Firebase lists six causes for that one code and advises checking the token
  rather than deleting it, so the message is the discriminator. Pruning on the
  code alone loses devices because a payload field was mistyped.
- **`SENDER_ID_MISMATCH` never prunes.** The token is valid; this is the wrong
  sender for it.
- **HTTP/2 is requested explicitly** rather than relying on libcurl's default,
  because a build without nghttp2 falls back to HTTP/1.1 — which APNs refuses,
  with a failure that reads as a network fault.
- **Both `fid` and `token` are stored.** FCM's Message reference now marks the
  `token` target deprecated in favour of `fid`, accepting either during the
  transition, so a fleet can migrate app-side without a same-day server change.
- **No routes.** A registration endpoint belongs to the project: only it knows
  how clients authenticate and which user a token may be attributed to.
- **`stop()`-style idempotency is not claimed.** An outbox row is marked
  delivered after the provider answers, so a process that dies in between
  re-sends. Neither provider offers an idempotency key, and marking delivered
  first would trade a rare duplicate for a rare silent loss. `collapseId` is
  the real mitigation and the README says to use it.

### Found by running it

- **`push:status` did not exist.** The commands were declared in `module.json`
  `commands[]` and nothing else, and `DeclaredCommands` deliberately skips any
  command whose constructor takes a parameter — optional ones included. All
  three inject dependencies, so the declaration compiled and the commands were
  silently absent: "Command not found: push:status". They are registered in
  `Provider::boot()` now, deferred so only a CLI process pays for them.
- **Every credential was declared required-with-no-default.** A bare string in
  `config[]` means exactly that, which seeds an ACTIVE `KEY=` and makes
  `ValidateConfigStage` fail the boot — so any project using FCM alone would
  have stopped on the unset APNs keys. `ground probe` caught it; they are
  `required: false` with no default now, which seeds `# KEY=`.
- **The service called the gateways by concrete type**, which made the outbox's
  retry and pruning behaviour impossible to assert without standing up a live
  APNs request to do it — and was the wrong shape for the layer rules besides.
  Both gateways implement a `PushTransport` contract now, so a third provider
  is an implementation and no change to the service.
- **A bug in a shared plugin, uncovered by this one.** The first real send
  failed with `curl_setopt_array(): Argument #2 must contain only valid cURL
  options`, which turned out to be an array-unpacking fault in
  `hkm-plugin-http-client` that had ALSO been silently disabling its own SSRF
  protocol restriction. Fixed there; see that plugin's CHANGELOG 1.2.0.

### Not verified

- **No notification has been delivered to a handset.** There is no Apple
  developer account, Firebase project or physical device here. Everything up to
  the provider's acceptance is exercised against the real services — a real
  request to `api.push.apple.com` returns `403 InvalidProviderToken` with an
  `apns-id`, and a real request to Google's token endpoint returns
  `invalid_grant — account not found`, both of which are the correct answers
  for locally generated keys and both of which prove the request shape was
  accepted. The last hop is untested.
- A `200` from either provider, and the success body shapes.
- A real `410 Unregistered` with its `timestamp`. The
  "a re-registration after that timestamp means the token is live" rule is
  reasoned from Apple's two documented sentences, not observed.
- Push types beyond `alert` and `background`.
- The `apns-topic` suffixes for VoIP, complications, file providers and
  push-to-talk are deliberately NOT asserted: Apple's current reference does not
  publish the table and only `.push-type.liveactivity` is confirmed
  first-party. Configure the full topic instead.
- Any load above a single notification.
