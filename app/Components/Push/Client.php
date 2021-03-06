<?php

namespace App\Components\Push;

use App\Components\Push\Contracts\Client as ClientContract;
use App\Components\Push\Contracts\Driver;

class Client implements ClientContract
{
    /**
     * Driver to use.
     *
     * @var Driver
     */
    private $driver;

    /**
     * Array of failed recipients.
     *
     * @var array
     */
    private $failedRecipients = [];

    /**
     * Messenger constructor.
     * @param Driver $driver
     */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * @inheritDoc
     */
    public function send($recipients, $payload)
    {
        $this->driver->send($recipients, $payload, $this->failedRecipients);
    }

    /**
     * @inheritDoc
     */
    public function failures()
    {
        return $this->failedRecipients;
    }
}
