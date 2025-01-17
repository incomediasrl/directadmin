<?php

/*
 * DirectAdmin API Client
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Omines\DirectAdmin\Objects;

use Omines\DirectAdmin\Context\UserContext;
use Omines\DirectAdmin\DirectAdminException;
use Omines\DirectAdmin\Objects\Domains\Subdomain;
use Omines\DirectAdmin\Objects\Email\Forwarder;
use Omines\DirectAdmin\Objects\Email\Mailbox;
use Omines\DirectAdmin\Objects\Users\User;
use Omines\DirectAdmin\Utility\Conversion;
use phpDocumentor\Reflection\Types\Boolean;

/**
 * Encapsulates a domain and its derived objects, like aliases, pointers and mailboxes.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class Domain extends BaseObject
{
    const CACHE_FORWARDERS = 'forwarders';
    const CACHE_MAILBOXES  = 'mailboxes';
    const CACHE_SUBDOMAINS = 'subdomains';

    const CATCHALL_BLACKHOLE = ':blackhole:';
    const CATCHALL_FAIL      = ':fail:';

    /** @var string */
    private $domainName;

    /** @var User */
    private $owner;

    /** @var string[] */
    private $aliases;

    /** @var string[] */
    private $phpVersions;

    /** @var string */
    private $selectedPhpVersion;

    /** @var string[] */
    private $pointers;

    /** @var float */
    private $bandwidthUsed;

    /** @var float|null */
    private $bandwidthLimit;

    /** @var float */
    private $diskUsage;

    /** @var float|null */
    private $diskLimit;

    /** @var bool */
    private $forceSSL = false;

    /**
     * Construct the object.
     *
     * @param string       $name    The domain name
     * @param UserContext  $context The owning user context
     * @param string|array $config  The basic config string as returned by CMD_API_ADDITIONAL_DOMAINS
     */
    public function __construct($name, UserContext $context, $config)
    {
        parent::__construct($name, $context);
        $this->setConfig($context, is_array($config) ? $config : \GuzzleHttp\Psr7\parse_query($config));
    }

    /**
     * Creates a new domain under the specified user.
     *
     * @param User       $user           Owner of the domain
     * @param string     $domainName     Domain name to create
     * @param float|null $bandwidthLimit Bandwidth limit in MB, or NULL to share with account
     * @param float|null $diskLimit      Disk limit in MB, or NULL to share with account
     * @param bool|null  $ssl            Whether SSL is to be enabled, or NULL to fallback to account default
     * @param bool|null  $php            Whether PHP is to be enabled, or NULL to fallback to account default
     * @param bool|null  $cgi            Whether CGI is to be enabled, or NULL to fallback to account default
     * @return Domain The newly created domain
     */
    public static function create(User $user, $domainName, $bandwidthLimit = null, $diskLimit = null, $ssl = null, $php = null, $cgi = null)
    {
        $options = [
            'action'                                              => 'create',
            'domain'                                              => $domainName,
            (isset($bandwidthLimit) ? 'bandwidth' : 'ubandwidth') => $bandwidthLimit,
            (isset($diskLimit) ? 'quota' : 'uquota')              => $diskLimit,
            'ssl'                                                 => Conversion::onOff($ssl, $user->hasSSL()),
            'php'                                                 => Conversion::onOff($php, $user->hasPHP()),
            'cgi'                                                 => Conversion::onOff($cgi, $user->hasCGI()),
        ];
        $user->getContext()->invokeApiPost('DOMAIN', $options);
        $config = $user->getContext()->invokeApiGet('ADDITIONAL_DOMAINS');
        return new self($domainName, $user->getContext(), $config[$domainName]);
    }

    /**
     * @param string $newDomainName
     * @see https://www.directadmin.com/features.php?id=694
     */
    public function rename($newDomainName)
    {
        $result = $this->getContext()->invokeApiPost('CHANGE_DOMAIN', ['old_domain' => $this->domainName, 'new_domain' => $newDomainName]);
        if (isset($result['error']) && $result['error'] == 0) {
            $this->owner->clearCache();
            return true;
        }

        return false;
    }

    /**
     * Set parameter force_ssl
     *
     * @param bool $forceSSL
     * @return bool
     */
    public function forceSSL(bool $forceSSL)
    {
        /**
         * If state of force_ssl is same that desired
         */
        if (($forceSSL === true && $this->isForceSSL()) || ($forceSSL === false && !$this->forceSSL)) {
            $this->owner->clearCache();
            return true;
        }

        $parameters = [
            'action'     => 'modify',
            'domain'     => $this->domainName,
            'force_ssl'  => $forceSSL ? 'yes' : 'no',
            'ssl'        => Conversion::onOff($this->owner->hasSSL()),
            'php'        => Conversion::onOff($this->owner->hasPHP()),
            'cgi'        => Conversion::onOff($this->owner->hasCGI()),
        ];

        if ($this->bandwidthLimit != null) {
            $parameters['bandwidth'] = $this->bandwidthLimit;
        } else {
            $parameters['ubandwidth'] = 'unlimited';
        }

        if ($this->diskLimit != null) {
            $parameters['quota'] = $this->diskLimit;
        } else {
            $parameters['uquota'] = 'unlimited';
        }

        $result = $this->getContext()->invokeApiPost('DOMAIN', $parameters);
        if (isset($result['error']) && $result['error'] == 0) {
            $this->owner->clearCache();
            return true;
        }

        return false;
    }

    /**
     * Creates a new email forwarder.
     *
     * @param string          $prefix     Part of the email address before the @
     * @param string|string[] $recipients One or more recipients
     * @return Forwarder The newly created forwarder
     */
    public function createForwarder($prefix, $recipients)
    {
        return Forwarder::create($this, $prefix, $recipients);
    }

    /**
     * Creates a new mailbox.
     *
     * @param string   $prefix    Prefix for the account
     * @param string   $password  Password for the account
     * @param int|null $quota     Quota in megabytes, or zero/null for unlimited
     * @param int|null $sendLimit Send limit, or 0 for unlimited, or null for system default
     * @return Mailbox The newly created mailbox
     */
    public function createMailbox($prefix, $password, $quota = null, $sendLimit = null)
    {
        return Mailbox::create($this, $prefix, $password, $quota, $sendLimit);
    }

    /**
     * Creates a pointer or alias.
     *
     * @param string $domain
     * @param bool   $alias
     * @return bool
     */
    public function createPointer($domain, $alias = false)
    {
        $parameters = [
            'domain' => $this->domainName,
            'from'   => $domain,
            'action' => 'add',
        ];
        if ($alias) {
            $parameters['alias'] = 'yes';
            $list                = &$this->aliases;
        } else {
            $list = &$this->pointers;
        }

        $result = $this->getContext()->invokeApiPost('DOMAIN_POINTER', $parameters);
        if (isset($result['error']) && $result['error'] == 0) {
            $list[] = $domain;
            $list   = array_unique($list);
            $this->owner->clearCache();
            return true;
        }

        return false;
    }

    /**
     * Delete a pointer.
     *
     * @param string $domain
     * @param bool   $alias
     * @return bool
     */
    public function deletePointer($domain)
    {
        $parameters = [
            'domain'  => $this->domainName,
            'select0' => $domain,
            'action'  => 'delete',
        ];

        $result = $this->getContext()->invokeApiPost('DOMAIN_POINTER', $parameters);
        if (isset($result['error']) && $result['error'] == 0) {
            $this->owner->clearCache();
            return true;
        }

        return false;
    }

    /**
     * Creates a new subdomain.
     *
     * @param string $prefix Prefix to add before the domain name
     * @return Subdomain The newly created subdomain
     */
    public function createSubdomain($prefix)
    {
        return Subdomain::create($this, $prefix);
    }

    /**
     * Deletes this domain from the user.
     */
    public function delete()
    {
        $this->getContext()->invokeApiPost('DOMAIN', [
            'delete'    => true,
            'confirmed' => true,
            'select0'   => $this->domainName,
        ]);
        $this->owner->clearCache();
    }

    /**
     * Create LetsEncryptCertificate
     *
     * @param array  $entries (es. [www.domain, email.domain, ecc..]
     * @param string $keySize Valid values: 2048, 4096, prime256v1, secp384r1, secp521r1
     * @param string $encryption
     * @return bool
     * @throws DirectAdminException
     */
    public function createLetsEncryptCertificate($entries = [], $keySize = '4096', $encryption = 'SHA256')
    {
        $data = [
            'domain'     => $this->domainName,
            'action'     => 'save',
            'submit'     => 'save',
            'type'       => 'create',
            'request'    => 'letsencrypt',
            'name'       => $this->domainName,
            'keysize'    => $keySize,
            'encryption' => $encryption,
            'le_select0' => $this->domainName,
        ];

        if (is_array($entries) && count($entries) > 0) {
            $n = 1;
            foreach ($entries as $entry) {
                $data['le_select' . $n] = $entry;
                $n++;
            }
        }

        $res = $this->getContext()->invokeApiPost('SSL', $data);
        $this->owner->clearCache();

        if ($res['error'] == 1) {
            throw new DirectAdminException("Cannot create LetsEncrypt certificate for domain " . $this->domainName . " Error message: " . $res['details']);
        }

        return true;
    }

    /**
     * Create certificate as paste certificate and key
     *
     * @param string $certificateAndKey
     * @return bool
     */
    public function pasteCertificateAndKey($certificateAndKey)
    {
        $data = [
            'domain'      => $this->domainName,
            'action'      => 'save',
            'type'        => 'paste',
            'certificate' => $certificateAndKey,
        ];

        $res = $this->getContext()->invokeApiPost('SSL', $data);
        $this->owner->clearCache();

        if ($res['error'] == 1) {
            throw new DirectAdminException("Cannot paste certificate and key for domain " . $this->domainName . " Error message: " . $res['details']);
        }

        return true;
    }

    public function getCertificate()
    {
        $data = [
            'domain' => $this->domainName,
            'type'   => 'cacert',
        ];

        $res = $this->getContext()->invokeApiGet('SSL', $data);
        $this->owner->clearCache();

        return $res;
    }

    /**
     * @return string[] List of aliases for this domain
     */
    public function getAliases()
    {
        return $this->aliases;
    }

    /**
     * @return float Bandwidth used in megabytes
     */
    public function getBandwidthUsed()
    {
        return $this->bandwidthUsed;
    }

    /**
     * @return float|null Bandwidth quotum in megabytes, or NULL for unlimited
     */
    public function getBandwidthLimit()
    {
        return $this->bandwidthLimit;
    }

    /**
     * @return string|null Currently configured catch-all configuration
     */
    public function getCatchall()
    {
        $value = $this->getContext()->invokeApiGet('EMAIL_CATCH_ALL', ['domain' => $this->domainName]);
        return isset($value['value']) ? $value['value'] : null;
    }

    /**
     * @return float Disk usage in megabytes
     */
    public function getDiskUsage()
    {
        return $this->diskUsage;
    }

    /**
     * @return string The real domain name
     */
    public function getDomainName()
    {
        return $this->domainName;
    }

    /**
     * Returns unified sorted list of main domain name, aliases and pointers.
     *
     * @return string[]
     */
    public function getDomainNames()
    {
        return $this->getCache('domainNames', function () {
            $list = array_merge($this->aliases, $this->pointers, [$this->getDomainName()]);
            sort($list);
            return $list;
        });
    }

    /**
     * @return Forwarder[] Associative array of forwarders
     */
    public function getForwarders()
    {
        return $this->getCache(self::CACHE_FORWARDERS, function () {
            $forwarders = $this->getContext()->invokeApiGet('EMAIL_FORWARDERS', [
                'domain' => $this->getDomainName(),
            ]);
            return DomainObject::toDomainObjectArray($forwarders, Forwarder::class, $this);
        });
    }

    /**
     * @return Mailbox[] Associative array of mailboxes
     */
    public function getMailboxes()
    {
        return $this->getCache(self::CACHE_MAILBOXES, function () {
            $boxes = $this->getContext()->invokeApiGet('POP', [
                'domain' => $this->getDomainName(),
                'action' => 'full_list',
            ]);
            return DomainObject::toDomainObjectArray($boxes, Mailbox::class, $this);
        });
    }

    /**
     * @return User
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * @return string[] List of domain pointers for this domain
     */
    public function getPointers()
    {
        return $this->pointers;
    }

    /**
     * @return bool
     */
    public function isForceSSL()
    {
        return $this->forceSSL;
    }

    /**
     * get PHP info
     * @return mixed The value of the domain item
     * @see https://www.directadmin.com/features.php?id=1738 and https://www.directadmin.com/features.php?id=2427
     * @return array array with php versions and the selected one
     */
    public function getPhpInfo() {

        $rs = $this->getContext()->invokeApiGet('ADDITIONAL_DOMAINS', ['action' => 'view', 'domain' => $this->domainName]);

        $selectedPhpVersionKey = null;
        foreach ($rs as $key => $value) {
            if (preg_match('/php(.)_ver/', $key) != false) {
                $this->phpVersions[$key] = $value;
            } else if ($key === 'php1_select') {
                $this->selectedPhpVersion = "php{$value}_ver";
            }
        }

        $currentVersion = !empty($this->selectedPhpVersion)
            ? $this->phpVersions[$this->selectedPhpVersion]
            : array_values($this->phpVersions)[0]; // if no selected version -> return the first one (the server default)

        return [array_values($this->phpVersions), $currentVersion];
    }

    /**
     * Check if Php version was selected (if false means that is using the server default one)
     * @return bool
     */
    public function isPhpVersionSelected() {
        if (empty($this->phpVersions)) {
            $this->getPhpInfo();
        }

        return !empty($this->selectedPhpVersion) ? true : false;
    }

    /**
     * Set php version specified
     * @param string $version // php version
     * @return array
     */
    public function setPhpVersion($version) {

        $versionToSet = null;
        foreach ($this->phpVersions as $key => $value) {
            if ($value === $version) {
                $versionToSet = substr($key, 3, 1);
            }
        }

        if ($versionToSet !== null) {
            $ret = $this->getContext()->invokeApiPost('DOMAIN', ['action' => 'php_selector', 'php1_select' => $versionToSet, 'domain' => $this->domainName]);
        } else {
            throw new DirectAdminException('Could not set php version: ' . $version . " for domain " . $this->domainName . ". Version not available");

        }

        return $ret;
    }

    /**
     * @return Subdomain[] Associative array of subdomains
     */
    public function getSubdomains()
    {
        return $this->getCache(self::CACHE_SUBDOMAINS, function () {
            $subs = $this->getContext()->invokeApiGet('SUBDOMAINS', ['domain' => $this->getDomainName()]);
            $subs = array_combine($subs, $subs);
            return DomainObject::toDomainObjectArray($subs, Subdomain::class, $this);
        });
    }

    /**
     * Invokes a POST command on a domain object.
     *
     * @param string $command    Command to invoke
     * @param string $action     Action to execute
     * @param array  $parameters Additional options for the command
     * @param bool   $clearCache Whether to clear the domain cache on success
     * @return array Response from the API
     */
    public function invokePost($command, $action, $parameters = [], $clearCache = true)
    {
        $response = $this->getContext()->invokeApiPost($command, array_merge([
                                                                                 'action' => $action,
                                                                                 'domain' => $this->domainName,
                                                                             ], $parameters));
        if ($clearCache) {
            $this->clearCache();
        }
        return $response;
    }

    /**
     * @param string $newValue New address for the catch-all, or one of the CATCHALL_ constants
     */
    public function setCatchall($newValue)
    {
        $parameters = array_merge(['domain' => $this->domainName, 'update' => 'Update'],
                                  (empty($newValue) || $newValue[0] == ':') ? ['catch' => $newValue] : ['catch' => 'address', 'value' => $newValue]);
        $this->getContext()->invokeApiPost('EMAIL_CATCH_ALL', $parameters);
    }

    /**
     * Allows Domain object to be passed as a string with its domain name.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getDomainName();
    }

    /**
     * Sets configuration options from raw DirectAdmin data.
     *
     * @param UserContext $context Owning user context
     * @param array       $config  An array of settings
     */
    private function setConfig(UserContext $context, array $config)
    {
        $this->domainName = $config['domain'];

        // Determine owner
        if ($config['username'] === $context->getUsername()) {
            $this->owner = $context->getContextUser();
        } else {
            throw new DirectAdminException('Could not determine relationship between context user and domain');
        }

        // Parse plain options
        $bandwidths           = array_map('trim', explode('/', $config['bandwidth']));
        $this->bandwidthUsed  = floatval($bandwidths[0]);
        $this->bandwidthLimit = !isset($bandwidths[1]) || ctype_alpha($bandwidths[1]) ? null : floatval($bandwidths[1]);
        $this->diskUsage      = floatval($config['quota']);
        $this->diskLimit      = !isset($config['quota_limit']) || ctype_alpha($config['quota_limit']) ? null : floatval($config['quota_limit']);

        if (isset($config['force_ssl']) && $config['force_ssl'] == 'yes') {
            $this->forceSSL = true;
        }

        $this->aliases  = array_filter(explode('|', $config['alias_pointers']));
        $this->pointers = array_filter(explode('|', $config['pointers']));
    }
}
