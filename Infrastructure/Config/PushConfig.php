<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Config;

use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\PushEnvironment;

/**
 * The resolved configuration — the ONE place `env()` is read.
 *
 * WHY THE CREDENTIALS ARE OPTIONAL IN module.json AND CHECKED HERE
 * ----------------------------------------------------------------
 * `ValidateConfigStage` is static: it cannot know that `PUSH_APNS_TOPIC` only
 * matters when `PUSH_APNS_ENABLED` is true. Declaring the APNs keys required
 * would therefore fail the boot of every project that uses FCM alone, and
 * declaring them optional means the boot cannot catch a half-configured
 * provider at all. So they are declared optional and validated HERE, where
 * `enabled` is known — and the error names the missing key.
 *
 * A KEY IS NEVER PUT IN A MESSAGE, A LOG OR A STATUS LINE
 * ------------------------------------------------------
 * `PUSH_APNS_KEY` and `PUSH_FCM_CREDENTIALS` are private keys. Everything here
 * that reports configuration reports the SHAPE of the credential — present,
 * how long, which key id — and never the material. {@see self::describe()} is
 * what a status command prints, and it is the only renderer.
 */
final readonly class PushConfig
{
    private function __construct(
        public bool $enabled,
        public bool $dryRun,
        public int $timeout,
        public int $connectTimeout,
        public int $outboxBatch,
        public int $outboxMaxAttempts,
        public int $pruneAfterDays,
        // ── FCM ──────────────────────────────────────────────────────────────
        public bool $fcmEnabled,
        public string $fcmProjectId,
        /** The service-account JSON, already resolved from a path if it was one. */
        public string $fcmCredentials,
        public string $fcmEndpoint,
        public string $fcmOauthEndpoint,
        // ── APNs ─────────────────────────────────────────────────────────────
        public bool $apnsEnabled,
        public string $apnsKeyId,
        public string $apnsTeamId,
        /** The .p8 PEM text, already resolved from a path if it was one. */
        public string $apnsKey,
        public string $apnsTopic,
        public PushEnvironment $apnsEnvironment,
        public string $apnsEndpoint,
        public int $apnsPort,
    ) {
    }

    public static function fromEnv(): self
    {
        $apnsEnabled = self::flag(env('PUSH_APNS_ENABLED'), false);
        $fcmEnabled  = self::flag(env('PUSH_FCM_ENABLED'), false);

        $config = new self(
            enabled:           self::flag(env('PUSH_ENABLED'), true),
            dryRun:            self::flag(env('PUSH_DRY_RUN'), false),
            timeout:           self::int(env('PUSH_TIMEOUT'), 10, 1),
            connectTimeout:    self::int(env('PUSH_CONNECT_TIMEOUT'), 5, 1),
            outboxBatch:       self::int(env('PUSH_OUTBOX_BATCH'), 100, 1),
            outboxMaxAttempts: self::int(env('PUSH_OUTBOX_MAX_ATTEMPTS'), 5, 1),
            pruneAfterDays:    self::int(env('PUSH_PRUNE_AFTER_DAYS'), 30, 1),

            fcmEnabled:       $fcmEnabled,
            fcmProjectId:     self::str(env('PUSH_FCM_PROJECT_ID')),
            fcmCredentials:   self::material(self::str(env('PUSH_FCM_CREDENTIALS'))),
            fcmEndpoint:      rtrim(self::str(env('PUSH_FCM_ENDPOINT'), 'https://fcm.googleapis.com'), '/'),
            fcmOauthEndpoint: self::str(env('PUSH_FCM_OAUTH_ENDPOINT'), 'https://oauth2.googleapis.com/token'),

            apnsEnabled:     $apnsEnabled,
            apnsKeyId:       self::str(env('PUSH_APNS_KEY_ID')),
            apnsTeamId:      self::str(env('PUSH_APNS_TEAM_ID')),
            apnsKey:         self::material(self::str(env('PUSH_APNS_KEY'))),
            apnsTopic:       self::str(env('PUSH_APNS_TOPIC')),
            apnsEnvironment: PushEnvironment::parse(self::str(env('PUSH_APNS_ENVIRONMENT'), 'production')),
            apnsEndpoint:    rtrim(self::str(env('PUSH_APNS_ENDPOINT')), '/'),
            apnsPort:        self::int(env('PUSH_APNS_PORT'), 443, 1),
        );

        return $config;
    }

    /**
     * Refuse a half-configured FCM.
     *
     * @throws PushException naming the missing key
     */
    public function assertFcmUsable(): void
    {
        if (!$this->fcmEnabled) {
            throw PushException::misconfigured('PUSH_FCM_ENABLED', 'is false, so FCM cannot be used.');
        }

        if ($this->fcmProjectId === '') {
            throw PushException::misconfigured(
                'PUSH_FCM_PROJECT_ID',
                'is empty. It goes into the send URL itself '
                . '(/v1/projects/{project_id}/messages:send), so without it there is no endpoint to call.',
            );
        }

        if ($this->fcmCredentials === '') {
            throw PushException::misconfigured(
                'PUSH_FCM_CREDENTIALS',
                'is empty. It must be the service-account JSON, or a path to it — the file you download '
                . 'from the Firebase console under Project settings → Service accounts.',
            );
        }

        $account = $this->serviceAccount();

        foreach (['client_email', 'private_key'] as $field) {
            if (($account[$field] ?? '') === '') {
                throw PushException::misconfigured(
                    'PUSH_FCM_CREDENTIALS',
                    'is valid JSON but has no "' . $field . '". That field is required to mint the '
                    . 'assertion Google exchanges for an access token, so this is probably the wrong '
                    . 'file — an API key or a client config rather than a SERVICE ACCOUNT key.',
                );
            }
        }
    }

    /**
     * Refuse a half-configured APNs.
     *
     * @throws PushException naming the missing key
     */
    public function assertApnsUsable(): void
    {
        if (!$this->apnsEnabled) {
            throw PushException::misconfigured('PUSH_APNS_ENABLED', 'is false, so APNs cannot be used.');
        }

        $missing = [];

        foreach ([
            'PUSH_APNS_KEY_ID' => $this->apnsKeyId,
            'PUSH_APNS_TEAM_ID' => $this->apnsTeamId,
            'PUSH_APNS_KEY'    => $this->apnsKey,
            'PUSH_APNS_TOPIC'  => $this->apnsTopic,
        ] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw PushException::misconfigured(
                implode(', ', $missing),
                \count($missing) === 1 ? 'is empty.' : 'are empty.',
            );
        }

        // Apple states both lengths. A mix-up between the two is the single
        // most common APNs misconfiguration, and its only symptom is
        // 403 InvalidProviderToken — which reads as "bad key".
        if (\strlen($this->apnsKeyId) !== 10) {
            throw PushException::misconfigured(
                'PUSH_APNS_KEY_ID',
                \sprintf(
                    'is %d characters. Apple: "The 10-character Key ID you obtained from your developer '
                    . 'account." A value of another length is not a key id — most often it is the team id '
                    . 'or the filename of the .p8.',
                    \strlen($this->apnsKeyId),
                ),
            );
        }

        if (\strlen($this->apnsTeamId) !== 10) {
            throw PushException::misconfigured(
                'PUSH_APNS_TEAM_ID',
                \sprintf(
                    'is %d characters. Apple: "the value for which is the 10-character Team ID you use '
                    . 'for developing your company\'s apps."',
                    \strlen($this->apnsTeamId),
                ),
            );
        }
    }

    /**
     * The decoded service-account JSON.
     *
     * @return array<string, string>
     */
    public function serviceAccount(): array
    {
        if ($this->fcmCredentials === '') {
            return [];
        }

        $decoded = json_decode($this->fcmCredentials, true);

        if (!\is_array($decoded)) {
            throw PushException::misconfigured(
                'PUSH_FCM_CREDENTIALS',
                'is not valid JSON. It must be the service-account key file\'s contents, or a path to it. '
                . '(No part of it is shown here — it holds a private key.)',
            );
        }

        $account = [];

        foreach ($decoded as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $account[$key] = $value;
            }
        }

        return $account;
    }

    /** The FCM send URL for this project. */
    public function fcmSendUrl(): string
    {
        return \sprintf('%s/v1/projects/%s/messages:send', $this->fcmEndpoint, $this->fcmProjectId);
    }

    /** The APNs base URL for an environment, honouring a test override. */
    public function apnsBaseUrl(PushEnvironment $environment): string
    {
        if ($this->apnsEndpoint !== '') {
            return $this->apnsEndpoint;
        }

        return 'https://' . $environment->host() . ':' . $this->apnsPort;
    }

    /**
     * What a status command prints. NEVER any key material.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        // Guarded: serviceAccount() throws on malformed JSON, and a status
        // command whose job is to diagnose a broken configuration must not be
        // the thing that dies of it. A bad credential is REPORTED below.
        try {
            $account = $this->fcmCredentials !== '' ? $this->serviceAccount() : [];
        } catch (PushException) {
            $account = ['client_email' => '(PUSH_FCM_CREDENTIALS is not valid JSON)'];
        }

        return [
            'Push enabled'   => $this->enabled ? 'yes' : 'NO — every send is a no-op',
            'Dry run'        => $this->dryRun ? 'YES — nothing is delivered' : 'no',
            'FCM'            => $this->fcmEnabled
                ? 'enabled, project ' . ($this->fcmProjectId !== '' ? $this->fcmProjectId : '(unset)')
                : 'disabled',
            'FCM credential' => $this->fcmCredentials === ''
                ? '(none)'
                : 'service account ' . ($account['client_email'] ?? '(no client_email)')
                  . ', private key ' . (($account['private_key'] ?? '') !== '' ? 'present' : 'MISSING'),
            'APNs'           => $this->apnsEnabled ? 'enabled' : 'disabled',
            'APNs topic'     => $this->apnsTopic !== '' ? $this->apnsTopic : '(unset)',
            'APNs key'       => $this->apnsKey === ''
                ? '(none)'
                : 'key id ' . ($this->apnsKeyId !== '' ? $this->apnsKeyId : '(unset)')
                  . ', team ' . ($this->apnsTeamId !== '' ? $this->apnsTeamId : '(unset)')
                  . ', PEM ' . \strlen($this->apnsKey) . ' bytes',
            'APNs default env' => $this->apnsEnvironment->label(),
            'APNs host'      => $this->apnsBaseUrl($this->apnsEnvironment),
        ];
    }

    // ── env readers ─────────────────────────────────────────────────────────
    //
    // Each takes the VALUE, not the key, so `ground check` can still see a
    // literal env('KEY') at the call site above — a helper that read the key
    // itself would hide every variable this module uses from the tool whose
    // job is to find them.

    /**
     * `env()` returns null for an absent key and the raw '' for a
     * present-but-empty one, and for every string this class reads the two are
     * treated the SAME.
     *
     * That is a deliberate exception to the usual rule that '' is a value
     * rather than an absence. None of these settings has a meaningful empty
     * value: there is no such thing as an empty endpoint, project id or topic.
     * So `PUSH_APNS_TOPIC=` means "unset", and the alternative — an empty topic
     * carried all the way to Apple as a `MissingTopic` — tells the operator
     * less than the configuration check does.
     */
    private static function str(mixed $value, string $default = ''): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    private static function int(mixed $value, int $default, int $min): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, (int) $value);
    }

    private static function flag(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    /**
     * A credential given either inline or as a path to a file.
     *
     * Both are supported because deployments genuinely differ: a container
     * injects the JSON as an environment variable, while a server mounts the
     * .p8 as a file with 0600 on it. Refusing one would force an operator to
     * paste a private key into a place it does not belong.
     *
     * A path is detected by being readable, not by looking like a path — a PEM
     * contains newlines and a JSON blob starts with `{`, so neither can be
     * mistaken for a filename, while a filename that happens not to exist must
     * NOT be silently treated as key material.
     */
    private static function material(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $looksInline = str_contains($value, "\n") || str_starts_with(ltrim($value), '{');

        if ($looksInline) {
            return $value;
        }

        if (is_file($value) && is_readable($value)) {
            $contents = file_get_contents($value);

            if ($contents === false) {
                throw PushException::misconfigured(
                    'a credential path',
                    'exists but could not be read: ' . $value . '. Check the file\'s permissions — a '
                    . 'key mounted 0600 and owned by another user is readable by nobody here.',
                );
            }

            return trim($contents);
        }

        // Not inline and not a readable file. Returning it as key material
        // would hand openssl a filename and produce "could not read the
        // private key", which sends the operator to look at the key rather
        // than at the path.
        if (!str_contains($value, ' ') && (str_contains($value, '/') || str_ends_with($value, '.p8')
            || str_ends_with($value, '.json') || str_ends_with($value, '.pem'))) {
            throw PushException::misconfigured(
                'a credential path',
                'looks like a file path but there is no readable file there: ' . $value,
            );
        }

        return $value;
    }
}
