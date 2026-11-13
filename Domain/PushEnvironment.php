<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * Which APNs environment a device token belongs to.
 *
 * THIS IS THE FAILURE THAT WASTES A WHOLE AFTERNOON
 * -------------------------------------------------
 * An APNs device token is issued by ONE environment and is meaningless in the
 * other. A token from a debug build sent to the production host comes back
 * `400 BadDeviceToken` — Apple's own words: "The specified device token is
 * invalid. Verify that the request contains a valid token and that the token
 * matches the environment."
 *
 * So the symptom of a mismatch is identical to the symptom of a corrupt token,
 * and the obvious conclusion — "the token is bad, prune it" — is wrong and
 * destroys a perfectly good registration. That is why this is stored PER
 * DEVICE rather than configured once per deployment: a single build of a
 * server routinely talks to both, because TestFlight builds carry production
 * tokens while Xcode builds carry sandbox ones, from the same app, at the same
 * time.
 *
 * `PUSH_APNS_ENVIRONMENT` is only the default for a registration that did not
 * say. It is not the answer for the deployment.
 */
enum PushEnvironment: string
{
    case Production = 'production';
    case Sandbox    = 'sandbox';

    /**
     * The APNs host, verbatim from Apple's "Create a POST request to APNs":
     * development `https://api.sandbox.push.apple.com:443`, production
     * `https://api.push.apple.com:443`.
     */
    public function host(): string
    {
        return match ($this) {
            self::Production => 'api.push.apple.com',
            self::Sandbox    => 'api.sandbox.push.apple.com',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'production',
            self::Sandbox    => 'sandbox (development)',
        };
    }

    /**
     * Parse a configured or stored value.
     *
     * Accepts the spellings a developer actually writes — `dev`,
     * `development`, `prod` — because the alternative is a deployment that
     * reads `PUSH_APNS_ENVIRONMENT=dev` and silently sends every iOS
     * notification to the production host.
     */
    public static function parse(?string $value, self $default = self::Production): self
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            ''                                              => $default,
            'production', 'prod', 'live'                    => self::Production,
            'sandbox', 'dev', 'development', 'debug', 'test' => self::Sandbox,
            default                                         => $default,
        };
    }

    /** Whether $value is a spelling {@see self::parse()} understands. */
    public static function understands(string $value): bool
    {
        $value = strtolower(trim($value));

        return \in_array($value, [
            'production', 'prod', 'live',
            'sandbox', 'dev', 'development', 'debug', 'test',
        ], true);
    }
}
