<?php

namespace App\Enums;

/** Status deal (SRS 4.3). Stage hanya berlaku untuk deal Open. */
enum DealStatus: string
{
    case Open = 'Open';
    case Won = 'Won';
    case Lost = 'Lost';
    case OnHold = 'On Hold';

    /** Deal yang masih berjalan dihitung dari tanggal dibuat, sisanya dari tanggal closing. */
    public function isRunning(): bool
    {
        return $this === self::Open || $this === self::OnHold;
    }
}
