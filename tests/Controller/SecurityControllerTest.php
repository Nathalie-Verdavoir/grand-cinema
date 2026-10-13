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
        self::assertCount(1, $crawler->filterXPath('//form[contains(@class, "registration-form")]'));
        self::assertCount(1, $crawler->filterXPath('//button[contains(concat(" ", normalize-space(@class), " "), " navbar-toggler ") and contains(concat(" ", normalize-space(@class), " "), " btn ")]'));
        self::assertSame('email', $crawler->filterXPath('//*[@id="username"]')->attr('type'));
        self::assertSame('password', $crawler->filterXPath('//*[@id="password"]')->attr('type'));
        self::assertSame('/reset-password', $crawler->filterXPath('//a[contains(@class, "forgot-password-link")]')->attr('href'));
        self::assertCount(1, $crawler->filterXPath('//button[@type="submit" and normalize-space()="Se connecter"]'));
    }

    public function testRegistrationPageUsesTheCombinedAuthenticationPage(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//form[contains(@class, "login-form")]'));
        self::assertCount(1, $crawler->filterXPath('//form[contains(@class, "registration-form")]'));
        self::assertSame('/register', $crawler->filterXPath('//form[contains(@class, "registration-form")]')->attr('action'));
    }

    public function testForgotPasswordRequestPageDisplaysEmailForm(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//form[contains(@class, "reset-password-form")]'));
        self::assertSame(
            'email',
            $crawler->filterXPath('//form[contains(@class, "reset-password-form")]//input[@type="email"]')->attr('type')
        );
    }

    public function testCheckEmailPageDoesNotRevealWhetherAnAccountExists(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/reset-password/check-email');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Si un compte correspond à l’adresse indiquée',
            $crawler->filterXPath('//section[@aria-labelledby="check-email-title"]')->text()
        );
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

    public function testLoginAttemptsAreThrottled(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $email = sprintf('throttle-%s@example.test', bin2hex(random_bytes(8)));

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->submitLogin($client, $crawler, $email, 'wrong-password');
            self::assertResponseRedirects('/login');
        }

        $this->submitLogin($client, $crawler, $email, 'wrong-password');
        self::assertResponseRedirects('/login');
        $throttledCrawler = $client->followRedirect();
        self::assertStringContainsString(
            'Too many failed login attempts',
            $throttledCrawler->filterXPath('//form[contains(@class, "login-form")]//div[contains(@class, "alert-danger")]')->text()
        );
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
