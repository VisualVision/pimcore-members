<?php

namespace DachcomBundle\Test\Helper\Browser;

use Codeception\Module;
use Codeception\Lib;
use Codeception\Exception\ModuleException;
use DachcomBundle\Test\Helper\PimcoreCore;
use DachcomBundle\Test\Helper\PimcoreUser;
use DachcomBundle\Test\Util\MembersHelper;
use MembersBundle\Adapter\User\UserInterface;
use Pimcore\Model\Document\Email;
use Pimcore\Model\User;
use Symfony\Bundle\SwiftmailerBundle\DataCollector\MessageDataCollector;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\BrowserKit\Cookie;

class PhpBrowser extends Module implements Lib\Interfaces\DependsOnModule
{
    const PIMCORE_ADMIN_CSRF_TOKEN_NAME = 'MOCK_CSRF_TOKEN';

    /**
     * @var Cookie
     */
    protected $sessionSnapShot;

    /**
     * @var PimcoreCore
     */
    protected $pimcoreCore;

    /**
     * @return array|mixed
     */
    public function _depends()
    {
        return [
            'Codeception\Module\Symfony' => 'PhpBrowser needs the pimcore core framework to work.'
        ];
    }

    /**
     * @param PimcoreCore $pimcoreCore
     */
    public function _inject($pimcoreCore)
    {
        $this->pimcoreCore = $pimcoreCore;
    }

    /**
     * @inheritDoc
     */
    public function _initialize()
    {
        $this->sessionSnapShot = [];

        parent::_initialize();
    }

    /**
     * Actor Function to see a page with enabled edit-mode
     *
     * @param string $page
     */
    public function amOnPageInEditMode(string $page)
    {
        $this->pimcoreCore->amOnPage(sprintf('%s?pimcore_editmode=true', $page));
    }

    /**
     * @param string $name
     * @param string $type
     * @param array  $options
     * @param null   $data
     * @param null   $selector
     */
    public function seeAEditableConfiguration(string $name, string $type, array $options, $data = null, $selector = null)
    {
        $this->pimcoreCore->see(MembersHelper::generateEditableConfiguration($name, $type, $options, $data), $selector);
    }

    /**
     * Actor Function to see if given email has been with specified address
     * Only works with PhpBrowser (Symfony Client)
     *
     * @param string $recipient
     * @param Email  $email
     */
    public function seeEmailIsSentTo(string $recipient, Email $email)
    {
        $collectedMessages = $this->getCollectedEmails($email);

        $recipients = [];
        foreach ($collectedMessages as $message) {
            if ($email->getSubject() !== $message->getSubject()) {
                continue;
            }
            $recipients = array_merge($recipients, $message->getTo());
        }

        $this->assertContains($recipient, array_keys($recipients));

    }

    /**
     * Actor Function to see if given email has been sent
     *
     * @param Email  $email
     * @param string $property
     * @param string $value
     */
    public function seeSentEmailHasPropertyValue(Email $email, string $property, string $value)
    {
        $collectedMessages = $this->getCollectedEmails($email);

        $getter = 'get' . ucfirst($property);
        foreach ($collectedMessages as $message) {
            $getterData = $message->$getter();
            if (is_array($getterData)) {
                $this->assertContains($value, array_keys($getterData));
            } else {
                $this->assertEquals($value, $getterData);
            }
        }
    }

    /**
     * Actor Function to login into Members FrontEnd
     *
     * @param UserInterface $membersUser
     */
    public function amLoggedInAsFrontendUser(UserInterface $membersUser)
    {
        $firewallName = 'members_fe';

        if (!$membersUser instanceof UserInterface) {
            $this->debug(sprintf('[PIMCORE BUNDLE MODULE] user needs to be a instance of %s.', UserInterface::class));
            return;
        }

        /** @var Session $session */
        $session = $this->pimcoreCore->getContainer()->get('session');

        $token = new UsernamePasswordToken($membersUser, null, $firewallName, $membersUser->getRoles());
        $this->pimcoreCore->getContainer()->get('security.token_storage')->setToken($token);

        $session->set('_security_' . $firewallName, serialize($token));
        $session->save();

        $cookie = new Cookie($session->getName(), $session->getId());

        $this->pimcoreCore->client->getCookieJar()->clear();
        $this->pimcoreCore->client->getCookieJar()->set($cookie);

    }

    /**
     * Actor Function to login into Pimcore Backend
     *
     * @param $username
     */
    public function amLoggedInAs($username)
    {
        $firewallName = 'admin';

        try {
            /** @var PimcoreUser $userModule */
            $userModule = $this->getModule('\\' . PimcoreUser::class);
        } catch (ModuleException $pimcoreModule) {
            $this->debug('[PIMCORE BUNDLE MODULE] could not load pimcore user module');
            return;
        }

        $pimcoreUser = $userModule->getUser($username);

        if (!$pimcoreUser instanceof User) {
            $this->debug(sprintf('[PIMCORE BUNDLE MODULE] could not fetch user %s.', $username));
            return;
        }

        /** @var Session $session */
        $session = $this->pimcoreCore->getContainer()->get('session');

        $user = new \Pimcore\Bundle\AdminBundle\Security\User\User($pimcoreUser);
        $token = new UsernamePasswordToken($user, null, $firewallName, $pimcoreUser->getRoles());
        $this->pimcoreCore->getContainer()->get('security.token_storage')->setToken($token);

        \Pimcore\Tool\Session::useSession(function (AttributeBagInterface $adminSession) use ($pimcoreUser, $session) {
            $session->setId(\Pimcore\Tool\Session::getSessionId());
            $adminSession->set('user', $pimcoreUser);
            $adminSession->set('csrfToken', self::PIMCORE_ADMIN_CSRF_TOKEN_NAME);
        });

        // allow re-usage of session in same cest.
        if (!empty($this->sessionSnapShot)) {
            $cookie = $this->sessionSnapShot;
        } else {
            $cookie = new Cookie($session->getName(), $session->getId());
            $this->sessionSnapShot = $cookie;
        }

        $this->pimcoreCore->client->getCookieJar()->clear();
        $this->pimcoreCore->client->getCookieJar()->set($cookie);

    }

    /**
     * Actor Function to send tokenized ajax request in backend
     *
     * @param string $url
     * @param array  $params
     */
    public function sendTokenAjaxPostRequest(string $url, array $params = [])
    {
        $params['csrfToken'] = self::PIMCORE_ADMIN_CSRF_TOKEN_NAME;
        $this->pimcoreCore->sendAjaxPostRequest($url, $params);
    }

    /**
     * @param Email $email
     *
     * @return array
     */
    protected function getCollectedEmails(Email $email)
    {
        $this->assertInstanceOf(Email::class, $email);

        /** @var Profiler $profiler */
        $profiler = $this->pimcoreCore->_getContainer()->get('profiler');

        $tokens = $profiler->find('', '', 1, 'POST', '', '');
        if (count($tokens) === 0) {
            throw new \RuntimeException('No profile found. Is the profiler data collector enabled?');
        }

        $token = $tokens[0]['token'];
        /** @var \Symfony\Component\HttpKernel\Profiler\Profile $profile */
        $profile = $profiler->loadProfile($token);

        if (!$profile instanceof Profile) {
            throw new \RuntimeException(sprintf('Profile with token "%s" not found.', $token));
        }

        /** @var MessageDataCollector $mailCollector */
        $mailCollector = $profile->getCollector('swiftmailer');

        $this->assertGreaterThan(0, $mailCollector->getMessageCount());

        $collectedMessages = $mailCollector->getMessages();

        $emails = [];
        /** @var \Pimcore\Mail $message */
        foreach ($collectedMessages as $message) {
            if ($email->getProperty('test_identifier') !== $message->getDocument()->getProperty('test_identifier')) {
                continue;
            }
            $emails[] = $message;
        }

        return $emails;

    }

    /**
     * Actor Function to see if last executed request is in given path
     *
     * @param string $expectedPath
     */
    public function seeLastRequestIsInPath(string $expectedPath)
    {
        $requestUri = $this->pimcoreCore->client->getInternalRequest()->getUri();
        $requestServer = $this->pimcoreCore->client->getInternalRequest()->getServer();

        $expectedUri = sprintf('http://%s%s', $requestServer['HTTP_HOST'], $expectedPath);

        $this->assertEquals($expectedUri, $requestUri);
    }

    /**
     * Actor Function to check if last _fragment request has given properties in request attributes.
     *
     * @param array $properties
     */
    public function seePropertiesInLastFragmentRequest(array $properties = [])
    {
        /** @var Profiler $profiler */
        $profiler = $this->pimcoreCore->_getContainer()->get('profiler');

        $tokens = $profiler->find('', '_fragment', 1, 'GET', '', '');
        if (count($tokens) === 0) {
            throw new \RuntimeException('No profile found. Is the profiler data collector enabled?');
        }

        $token = $tokens[0]['token'];
        /** @var \Symfony\Component\HttpKernel\Profiler\Profile $profile */
        $profile = $profiler->loadProfile($token);

        if (!$profile instanceof Profile) {
            throw new \RuntimeException(sprintf('Profile with token "%s" not found.', $token));
        }

        /** @var RequestDataCollector $requestCollector */
        $requestCollector = $profile->getCollector('request');

        foreach ($properties as $property) {
            $this->assertTrue($requestCollector->getRequestAttributes()->has($property), sprintf('"%s" not found in request collector.', $property));
        }
    }
}
