<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Push — `push_devices` and `push_outbox`.
 *
 * Shipped IDENTICALLY in database/migrations (central) and
 * database/tenant-template, so the plugin works on a project with or without
 * Tenancy: the rows live on whichever connection the request is routed to.
 * Keep the two copies byte-identical.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `uniq_push_device_token` IS A PRIVACY CONTROL, NOT A TIDINESS ONE
 * ────────────────────────────────────────────────────────────────────────────
 * A device token identifies a DEVICE, and neither APNs nor FCM changes it when
 * somebody signs out. So on a shared handset, registering a token for a second
 * user must REPLACE the first mapping, not add to it. Without this unique
 * index, `push_devices` ends up holding one token against two users and every
 * notification for the first is delivered to the phone the second is holding —
 * successfully, with no error, and invisibly to anyone testing on their own
 * device.
 *
 * The index is what makes `DeviceRepository::register()` an upsert rather than
 * an insert, and it is the reason the column is `varchar(512)` with an explicit
 * ascii collation rather than `text`: MySQL cannot put a unique index on an
 * unbounded TEXT column, and a token is ascii by construction (hex for APNs,
 * base64url-ish for FCM).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THE OUTBOX HOLDS A RENDERED PAYLOAD AND A TOKEN, NOT A DEVICE ID
 * ────────────────────────────────────────────────────────────────────────────
 * Because the device row may be GONE by the time the row is drained — pruned
 * for being dead, or reassigned to another user on a shared handset. A row that
 * joined to `push_devices` at delivery time would then either fail or, far
 * worse, resolve to whoever owns that device NOW. The outbox therefore captures
 * the address and the recipient at the moment of queueing, which is the moment
 * the authorization decision was made.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('push_devices')) {
            $schema->create('push_devices', static function ($t) {
                $t->id();
                $t->ulid('public_id')->charset('ascii')->collation('ascii_bin')
                    ->comment('public identifier; never expose the internal id');

                // See the class docblock: this column carries a unique index,
                // which is the whole defence against cross-user delivery.
                $t->string('token', 512)->charset('ascii')->collation('ascii_bin')
                    ->comment('the provider address; unique across the table ON PURPOSE');
                $t->string('fid', 255)->charset('ascii')->collation('ascii_bin')->default('')
                    ->comment('Firebase Installation ID; FCM deprecated the token field in favour of it');

                $t->string('platform', 10)->comment('ios | android | web');
                $t->string('provider', 10)->comment('apns | fcm — stored, because an iOS app may use either');
                $t->string('environment', 12)->default('production')
                    ->comment('apns: production | sandbox. A mismatch reads as BadDeviceToken');

                $t->string('user_id', 64)->default('')
                    ->comment('soft reference to a user id; no FK. Empty = an anonymous install');
                $t->string('label', 160)->default('')->comment('free-form, for a status command');

                $t->unsignedInteger('failure_count')->default(0)
                    ->comment('consecutive transient failures; reset on success');
                $t->dateTime('last_seen_at')->nullable()->comment('UTC; last re-registration');
                $t->dateTime('last_delivered_at')->nullable()->comment('UTC; last accepted notification');

                $t->timestamps();

                $t->unique(['public_id'], 'uniq_push_device_public_id');
                $t->unique(['token'], 'uniq_push_device_token');
                // Fan-out to one user: the only read on the hot path.
                $t->index(['user_id', 'provider'], 'idx_push_device_user');
                // `push:prune` walks devices untouched for N days.
                $t->index(['last_seen_at'], 'idx_push_device_last_seen');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }

        if (!$schema->hasTable('push_outbox')) {
            $schema->create('push_outbox', static function ($t) {
                $t->id();
                $t->ulid('public_id')->charset('ascii')->collation('ascii_bin');

                // Captured at QUEUE time, not resolved at delivery time — see
                // the class docblock.
                $t->string('token', 512)->charset('ascii')->collation('ascii_bin');
                $t->string('fid', 255)->charset('ascii')->collation('ascii_bin')->default('');
                $t->string('platform', 10);
                $t->string('provider', 10);
                $t->string('environment', 12)->default('production');
                $t->string('user_id', 64)->default('');

                $t->text('notification')->comment('JSON: the Notification this row renders');

                $t->string('status', 12)->default('pending')
                    ->comment('pending | delivered | failed | dead');
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->dateTime('available_at')->comment('UTC; not before this');
                $t->dateTime('delivered_at')->nullable()->comment('UTC');

                $t->string('last_reason', 64)->default('')->comment('the provider reason code, verbatim');
                $t->string('last_error', 1000)->default('')->comment('one line; never a credential');
                $t->string('provider_message_id', 128)->default('')
                    ->comment('apns-id or FCM message name — the only handle a support request has');

                $t->timestamps();

                $t->unique(['public_id'], 'uniq_push_outbox_public_id');
                // The drain query: pending rows that are due, oldest first.
                $t->index(['status', 'available_at'], 'idx_push_outbox_due');
                // Housekeeping: delivered rows older than N days.
                $t->index(['status', 'delivered_at'], 'idx_push_outbox_delivered');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('push_outbox');
        $schema->dropIfExists('push_devices');
    }
};
