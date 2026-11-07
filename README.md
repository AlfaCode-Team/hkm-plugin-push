# hkm-plugin-push

> Solves **`push.notifications`** · namespace **`Plugins\Push\`** · on-demand GDA module
> Family: Generic — reusable beyond Elimu

Device-token registration and push delivery (FCM), with per-recipient fan-out and a delivery outbox.

## Status

**Scaffold.** The domain is declared and the contract is published, but no use
case is implemented yet. `PushServiceContract` is deliberately empty — methods are added
one per ported use case.

## Where it comes from

| | |
|---|---|
| Legacy source | `api/services/NotificationDispatcher.php, AdminNotifier.php, AdminWatcher.php, admin_notification_*.php, firebase/` |
| Legacy tables | `admin_devices, family_notifications, admin_watch_state, notification_seen` |

The port plan, the reconstructed domain model and the per-plugin recipe live in
the **ElimuSystem** repository: `PORT-PLAN.md` and `PLUGIN-PLAN.md`.

## Requires

- `database.management`
- `logging.application`
- `http.client`

Declared as module **domains**, never port or contract class names. An unknown
domain fails the boot.

## Using it

```jsonc
// the consumer's module.json / proj.json
{ "requires": ["push.notifications"] }
```

```php
$svc = $container->make(\Plugins\Push\API\Contracts\PushServiceContract::class);
```

## Installing

```bash
hkm plugins enable Push <project>
```
