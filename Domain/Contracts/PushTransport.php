<?php

declare(strict_types=1);

namespace Plugins\Push\Domain\Contracts;

use Plugins\Push\Domain\DeliveryReport;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Notification;

/**
 * Hands one notification to one push network.
 *
 * WHY THIS EXISTS RATHER THAN THE SERVICE CALLING THE GATEWAYS DIRECTLY
 * --------------------------------------------------------------------
 * {@see \Plugins\Push\Application\Services\PushService} used to `match` on the
 * provider enum and call `ApnsGateway` or `FcmGateway` by their concrete types.
 * That worked, and it made the service untestable without either a live
 * provider or a scripted HTTP client — so the outbox's retry and pruning
 * behaviour could only be exercised through a full APNs request, which is a lot
 * of unrelated machinery to stand up in order to assert that a row's attempt
 * count went up.
 *
 * It was also the wrong shape for the layer rules: a Service may call a Gateway,
 * but through a published contract rather than a concrete vendor class. A third
 * provider — Web Push with VAPID, Huawei, Amazon ADM — is now an implementation
 * of this and no change to the service.
 *
 * IMPLEMENTATIONS MUST NOT THROW for a delivery failure. Every outcome,
 * including "the provider is not configured" and "the connection failed", comes
 * back as a {@see DeliveryReport} — because the caller is draining a batch, and
 * one unreachable device must not abandon the other ninety-nine.
 */
interface PushTransport
{
    public function send(DeviceToken $device, Notification $notification): DeliveryReport;
}
