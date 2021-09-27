<?php


namespace Omines\DirectAdmin\Objects;


use Omines\DirectAdmin\Context\UserContext;
use Omines\DirectAdmin\DirectAdminException;
use Omines\DirectAdmin\Objects\Users\User;

class Ftp extends BaseObject
{
    const CACHE_ACCESS_HOSTS = 'ftp';

    const TYPE_SYSTEM = 'system';
    const TYPE_DOMAIN = 'domain';
    const TYPE_FTP    = 'ftp';
    const TYPE_USER   = 'user';
    const TYPE_CUSTOM = 'custom';

    private static $typesAllowed = [
        self::TYPE_SYSTEM,
        self::TYPE_DOMAIN,
        self::TYPE_FTP,
        self::TYPE_USER,
        self::TYPE_CUSTOM,
    ];

    /** @var User */
    private $owner;

    /** @var Domain */
    private $domain;

    private $path;

    /**
     * Ftp constructor.
     *
     * @param string      $username
     * @param Domain      $domain
     * @param UserContext $context
     * @param string      $path
     */
    public function __construct($username, Domain $domain, UserContext $context, $path = null)
    {
        parent::__construct($username, $context);
        $this->owner  = $context->getContextUser();
        $this->domain = $domain;
        $this->path   = $path;
    }

    /**
     * @return mixed
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @param mixed $path
     */
    public function setPath($path)
    {
        $this->path = $path;
    }

    /**
     * @return Domain
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @param bool $withoutDomain If true return username without domain
     * @return string
     */
    public function getName($withoutDomain = false)
    {
        $userName = parent::getName();
        if ($withoutDomain) {
            $pos = stripos($userName, '@');
            if ($pos !== false) {
                $userName = substr($userName, 0, $pos);
            }
        }

        return $userName;
    }

    /**
     * Create ftp account
     *
     * @param string      $username
     * @param string      $type
     * @param string      $password
     * @param string      $domainName
     * @param UserContext $context
     * @param string|null $path
     * @return Ftp
     */
    public static function create($username, $type, $password, Domain $domain, UserContext $context, $path = null)
    {
        if (!in_array($type, Ftp::$typesAllowed)) {
            throw new DirectAdminException("Invalid ftp type $type");
        }

        if ($type == self::TYPE_DOMAIN && trim($domain) == null) {
            throw new DirectAdminException("If ftp type is $type, domainName is required");
        } elseif ($type == self::TYPE_DOMAIN) {
            $founded = false;
            foreach ($context->getContextUser()->getDomains() as $domain) {
                if ($domain->getDomainName() === $domain->getDomainName()) {
                    $founded = true;
                    break;
                }
            }

            if (!$founded) {
                throw new DirectAdminException("Domain " . $domain->getDomainName() . " not owned by user");
            }
        }

        $data = [
            'action'  => 'create',
            'user'    => $username,
            'type'    => $type,
            'passwd'  => $password,
            'passwd2' => $password,
        ];

        if ($type == self::TYPE_DOMAIN) {
            $data['domain'] = $domain->getDomainName();
        } elseif ($type == self::TYPE_CUSTOM) {
            $data['custom_val'] = $path;
        }

        $context->getContextUser()->getContext()->invokeApiPost('FTP', $data);
        $context->getContextUser()->clearCache();
        return new self($username, $domain, $context);
    }

    /**
     * Deletes this ftp from the user.
     */
    public function delete()
    {
        $this->getContext()->invokeApiPost('FTP', [
            'action'  => 'delete',
            'domain'  => $this->domain->getDomainName(),
            'select0' => $this->getName(true),
        ]);
        $this->getContext()->getContextUser()->clearCache();
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->getContext()->invokeApiPost('FTP_SHOW', [
            'user'    => $this->getName(true),
            'domain'  => $this->domain->getDomainName(),
        ]);
    }


    /**
     * Reset the password for this ftp account.
     *
     * @param string $newPassword
     * @return bool
     */
    public function setPassword($newPassword)
    {
        $res = $this->getData();
        if (!isset($res['type'])) {
            throw new DirectAdminException("Cannot get type of ftp user ".$this->getName(true));
        }

        $result = $this->getContext()->invokeApiPost('FTP', [
            'action'  => 'modify',
            'user'    => $this->getName(true),
            'domain'  => $this->domain->getDomainName(),
            'type'    => $res['type'],
            'passwd'  => $newPassword,
            'passwd2' => $newPassword,
        ]);

        if (isset($result['error']) && $result['error'] == 0) {
            return true;
        }

        return false;
    }

    /**
     * Change type of this ftp account
     *
     * @param string      $type   (system|domainName|ftp|user|custom)
     * @param Domain|null $domain required if type is domainName
     * @param string|null $folder required if type is custom
     */
    /*public function changeType($type, $domain = null, $folder = null)
    {
        if (!in_array($type, Ftp::$typesAllowed)) {
            throw new DirectAdminException("Invalid ftp type $type");
        }

        if ($type == self::TYPE_DOMAIN && !$domain instanceof Domain) {
            throw new DirectAdminException("If ftp type is $type, domainName is required");
        }

        $data = [
            'action' => 'modify',
            'user'   => $this->getName(true),
            'type'   => $type
        ];

        if ($type == self::TYPE_DOMAIN) {
            $data['domain'] = $domain->getDomainName();
        } elseif ($type == self::TYPE_CUSTOM) {
            $data['custom_val'] = $folder;
        }

        $this->getContext()->invokeApiPost('FTP', $data);
        $this->getContext()->getContextUser()->clearCache();
    }*/
}