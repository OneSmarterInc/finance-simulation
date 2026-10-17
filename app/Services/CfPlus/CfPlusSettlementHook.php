<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusAuctionResult;

class CfPlusSettlementHook
{
    public function afterResultPersisted(CfPlusAuctionResult $result): void
    {
        // Test seam for proving transaction rollback after a result write.
    }
}
