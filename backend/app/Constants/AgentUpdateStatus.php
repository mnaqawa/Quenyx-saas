<?php

namespace App\Constants;

final class AgentUpdateStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DOWNLOADING = 'downloading';

    public const VERIFYING = 'verifying';

    public const INSTALLING = 'installing';

    public const RESTARTING = 'restarting';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const ROLLED_BACK = 'rolled_back';

    public const SKIPPED = 'skipped';

    /**
     * @return array<int, string>
     */
    public static function inProgress(): array
    {
        return [self::DOWNLOADING, self::VERIFYING, self::INSTALLING, self::RESTARTING];
    }
}
