<?php
/**
 * DirectAdmin
 * (c) Omines Internetbureau B.V.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Omines\DirectAdmin\Context;

use Omines\DirectAdmin\DirectAdmin;
use Omines\DirectAdmin\DirectAdminException;
use Omines\DirectAdmin\Objects\Users\Admin;
use Omines\DirectAdmin\Objects\Users\Reseller;
use Omines\DirectAdmin\Objects\Users\User;

/**
 * Context for user functions.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com
 */
class UserContext extends BaseContext
{
    private $user;

    /**
     * @param DirectAdmin $connection A prepared connection.
     * @param bool $validate Whether to check if the connection matches the context.
     */
    public function __construct(DirectAdmin $connection, $validate = false)
    {
        parent::__construct($connection);
    }

    /**
     * @return string One of the DirectAdmin::USERTYPE_ constants describing the type of underlying user.
     */
    public function getType()
    {
        return $this->getContextUser()->getType();
    }

    /**
     * @return Admin|Reseller|User The user object behind the context.
     */
    public function getContextUser()
    {
        if(!isset($this->user))
            $this->user = User::fromConfig($this->invokeGet('SHOW_USER_CONFIG'), $this);
        return $this->user;
    }
}