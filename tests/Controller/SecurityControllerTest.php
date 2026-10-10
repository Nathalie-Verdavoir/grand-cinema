<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\DomCrawler\Crawler;

class SecurityControllerTest extends WebTestCase
{
    private const PASSWORD = 'login-test-password';

    public function testLoginPageDisplaysLoginForm(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//form[contains(@class, "login-form")]'));
        self::assertSame('email', $crawler->filterXPath('//*[@id="username"]')->attr('type'));
        self::assertSame('password', $crawler->filterXPath('//*[@id="password"]')->attr('type'));
    }

    public function testInvalidCredentialsReturnToLoginWithAnError(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->submitLogin($client, $crawler, 'regular@example.test', 'wrong-password');

        self::assertResponseRedirects('/login');
        $errorCrawler = $client->followRedirect();
        self::assertCount(1, $errorCrawler->filterXPath('//form[contains(@class, "login-form")]//div[contains(@class, "alert-danger")]'));
    }

    public function testValidCredentialsAuthenticateAndRedirectToHome(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->submitLogin($client, $crawler, 'regular@example.test', self::PASSWORD);

        self::assertResponseRedirects('/');
    }

    public function testLogoutEndsTheAuthenticatedSession(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $this->submitLogin($client, $crawler, 'regular@example.test', self::PASSWORD);
        self::assertResponseRedirects('/');

        $client->request('GET', '/logout');
        self::assertResponseRedirects('/');

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/login');
    }

    private function submitLogin(AbstractBrowser $client, Crawler $crawler, string $email, string $password): void
    {
        $client->request('POST', '/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $crawler->filterXPath('//*[@name="_csrf_token"]')->attr('value'),
        ]);
    }
}
