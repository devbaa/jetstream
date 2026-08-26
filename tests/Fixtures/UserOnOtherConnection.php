<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

/**
 * A user model configured onto a connection other than the default.
 *
 * Jetstream::useUserModel() is a documented extension point, so the model
 * holding a user's authoritative state need not live on the default
 * connection. This fixture is what that looks like, and it exists so the
 * package's own transactions can be checked against it rather than against the
 * arrangement that happens to make them look correct.
 */
class UserOnOtherConnection extends User
{
    /**
     * The connection this model's state lives on.
     *
     * @var string|null
     */
    protected $connection = 'jetstream_competitor';

    /**
     * The table this model reads and writes.
     *
     * Named explicitly because the class name is not the table's: it stands
     * for the same users, reached over a different connection.
     *
     * @var string
     */
    protected $table = 'users';
}
