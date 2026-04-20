<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Health\HealthSnapshot;

interface HealthReporterInterface
{
    /**
     * Return a point-in-time health snapshot.
     * This is always safe to call and never throws.
     */
    public function snapshot(): HealthSnapshot;

    /**
     * Whether the socket is currently connected (at or above Connected state).
     */
    public function isConnected(): bool;

    /**
     * Whether the session is currently authenticated.
     */
    public function isAuthenticated(): bool;

    /**
     * Whether the heartbeat monitor considers the connection alive.
     */
    public function isLive(): bool;
}
