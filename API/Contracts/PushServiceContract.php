<?php

declare(strict_types=1);

namespace Plugins\Push\API\Contracts;

use Plugins\Push\Domain\DeliveryReport;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\PushEnvironment;

/**
 * Published contract for `push.notifications`.
 *
 * Device-token registration and push delivery over APNs and FCM HTTP v1.
 *
 * Consumers declare `"requires": ["push.notifications"]` and resolve THIS
 * interface. Nothing outside this plugin may touch its internals — those are
 * bound with `bindInternal()` and throw `ScopeViolationException` cross-scope.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ONE RULE A CONSUMER MUST FOLLOW
 * ────────────────────────────────────────────────────────────────────────────
 * **Call {@see self::forgetDevice()} when a user signs out.**
 *
 * A device token belongs to a DEVICE, and neither push network changes it when
 * somebody signs out of your app. {@see self::registerDevice()} handles the
 * case where a new user signs IN on a shared handset — the token is reassigned,
 * never duplicated. But a device that is signed out and then left alone keeps
 * its old owner, and will keep receiving that person's notifications on a
 * screen somebody else may be holding.
 *
 * Registration is the easy half and every tutorial covers it. Forgetting is the
 * half that makes the difference between a notification system and a privacy
 * incident, and nothing in this plugin can do it for you: only the application
 * knows a session ended.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * DELIVERY IS QUEUED, AND "SENT" NEVER MEANS "SEEN"
 * ────────────────────────────────────────────────────────────────────────────
 * {@see self::notify()} returns once the notifications are QUEUED, not once
 * they are delivered. That is deliberate: HTTP v1 has no multicast, so a user
 * with four devices is four HTTPS requests to a third party, and doing that
 * inside a web request makes a page's latency depend on Apple's.
 *
 * Even {@see DeliveryReport::isDelivered()} only means the PROVIDER accepted
 * it. No push network reports that a human saw anything. Any feature built on
 * "they were notified" is building on acceptance, and should say so.
 */
interface PushServiceContract
{
    /**
     * Register, or re-register, one device for one user.
     *
     * Idempotent by token: calling it on every app launch is the intended
     * usage, and is how `last_seen_at` stays current for pruning. If the token
     * was registered to a DIFFERENT user, it is reassigned to this one and the
     * change is logged — that is a shared device changing hands.
     *
     * @param string $token       the provider's device token
     * @param string $userId      who the device belongs to now; '' for an
     *                            anonymous install
     * @param string $fid         the Firebase Installation ID, when the client
     *                            has one. FCM has deprecated the token field
     *                            in favour of it, so supplying it future-proofs
     *                            the registration
     * @param string $label       free-form, for a status command
     * @return bool               true when this call CHANGED the device's owner
     */
    public function registerDevice(
        string $token,
        Platform $platform,
        string $userId = '',
        ?PushEnvironment $environment = null,
        string $fid = '',
        string $label = '',
    ): bool;

    /**
     * Forget one device token. Call this on sign-out.
     *
     * Also called internally when a provider reports the token dead.
     *
     * @return bool whether a registration was removed
     */
    public function forgetDevice(string $token): bool;

    /**
     * Forget every device registered to a user.
     *
     * The right call for "sign out everywhere" and for account deletion. Note
     * it does NOT reach devices that were never signed in as this user.
     *
     * @return int how many registrations were removed
     */
    public function forgetUser(string $userId): int;

    /**
     * Queue a notification for every device these users have registered.
     *
     * Returns the number of devices QUEUED — not delivered, and not the number
     * of users. A user with no registered device contributes zero, which is why
     * the return value is worth checking: zero means nobody could be reached,
     * and that is indistinguishable from success in every log you will read
     * later.
     *
     * @param list<string> $userIds
     * @return int how many devices the notification was queued for
     */
    public function notify(array $userIds, Notification $notification): int;

    /**
     * Send to ONE device, right now, without the outbox.
     *
     * For the cases where the caller must know the outcome before returning: a
     * test send, a status command, a sign-in confirmation. It makes a blocking
     * HTTPS request to a third party, so it does not belong on a page a user is
     * waiting for — {@see self::notify()} is that.
     */
    public function sendNow(string $token, Notification $notification): DeliveryReport;

    /**
     * Deliver up to $limit queued notifications.
     *
     * Called by the `push.deliver` job and by `push:drain`. Returns a count per
     * outcome, so a caller can log one line instead of one per notification.
     *
     * @return array{queued: int, delivered: int, retried: int, pruned: int, rejected: int}
     */
    public function drain(int $limit = 0): array;
}
