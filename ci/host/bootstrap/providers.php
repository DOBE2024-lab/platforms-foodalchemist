<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // 13.10.2026: echtes platform-activity-log statt Stub (Paket-Discovery kennt den Provider nicht, weil composer.lock noch auf den Stub zeigt)
    Platform\ActivityLog\ActivityLogServiceProvider::class,
];
