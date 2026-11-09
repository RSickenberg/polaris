<?php

declare(strict_types=1);

namespace Polaris\Shared\Service\Enum;

/**
 * The look of a toast, and the flash type that asks for it: `$this->addFlash(ToastVariant::Success->value, ...)`.
 * `Shared:ToastRegion` shows each flash type as the toast of the same name (and the `error` type of Symfony as
 * {@see self::Danger}); `Shared:Toast` and `Shared:ToastAnnounce` take the value as their `variant`.
 */
enum ToastVariant: string
{
    /**
     * Something the user asked for is done. Leaves after 6 seconds.
     */
    case Success = 'success';

    /**
     * Neutral news. Leaves after 6 seconds.
     */
    case Info = 'info';

    /**
     * Done only in part, or not done for a reason the user can wait out. Leaves after 6 seconds.
     */
    case Warning = 'warning';

    /**
     * Needs an action of the user, or went wrong. Stays until it is dismissed.
     */
    case Danger = 'danger';
}
