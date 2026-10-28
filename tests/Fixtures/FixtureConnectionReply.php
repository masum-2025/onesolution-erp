<?php

namespace Tests\Fixtures;

use App\Platform\Connections\Models\ConnectionMessage;

/**
 * The client's answer to the provider (to_provider), stored like FixtureConnectionNote.
 */
class FixtureConnectionReply extends FixtureConnectionNote
{
    public function __construct()
    {
        parent::__construct(ConnectionMessage::TO_PROVIDER);
    }
}
