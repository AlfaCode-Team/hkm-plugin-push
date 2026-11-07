<?php

declare(strict_types=1);

namespace Plugins\Push\API\Contracts;

/**
 * Published contract for `push.notifications`.
 *
 * Device-token registration and push delivery (FCM), with per-recipient fan-out and a delivery outbox.
 *
 * Legacy source : api/services/NotificationDispatcher.php, AdminNotifier.php, AdminWatcher.php, admin_notification_*.php, firebase/
 * Legacy tables : admin_devices, family_notifications, admin_watch_state, notification_seen
 *
 * Consumers declare `"requires": ["push.notifications"]` and resolve THIS interface.
 * Nothing outside this plugin may touch its internals — those are bound with
 * `bindInternal()` and throw `ScopeViolationException` cross-scope.
 *
 * @see docs/PORT.md in this repository for what still has to be ported.
 */
interface PushServiceContract
{
    // Methods are added as each legacy use case is ported. Keep this surface
    // minimal: one method per use case the OUTSIDE world needs, never a
    // pass-through to a repository.
}
