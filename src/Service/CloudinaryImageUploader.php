<?php

namespace App\Service;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CloudinaryImageUploader
{
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $cloudName,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {
    }

    public function uploadFilmPoster(?string $imageUrl): ?string
    {
        if (null === $imageUrl || '' === trim($imageUrl)) {
            return null;
        }

        $parts = parse_url($imageUrl);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? null)) {
            throw new \InvalidArgumentException('L’affiche doit être une URL HTTPS valide.');
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === $this->cloudName.'.res.cloudinary.com') {
            return $imageUrl;
        }

        if ('image.tmdb.org' !== $host
            || !preg_match('~^/t/p/w500/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$~i', $parts['path'] ?? '')) {
            throw new \InvalidArgumentException('L’affiche doit provenir des résultats de recherche TMDB.');
        }

        if ('' === $this->cloudName || '' === $this->apiKey || '' === $this->apiSecret) {
            throw new \LogicException('Configurez CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY et CLOUDINARY_API_SECRET.');
        }

        $sourceResponse = $this->httpClient->request('GET', $imageUrl, ['max_redirects' => 0]);
        $image = $sourceResponse->getContent();
        if (strlen($image) > self::MAX_IMAGE_SIZE) {
            throw new \RuntimeException('L’affiche dépasse la taille maximale de 10 Mo.');
        }

        $contentType = strtolower(trim(explode(';', $sourceResponse->getHeaders(false)['content-type'][0] ?? '')[0]));
        $extension = match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \RuntimeException('TMDB a renvoyé un format d’image non pris en charge.'),
        };

        $timestamp = time();
        $publicId = 'films/'.hash('sha256', $imageUrl);
        $parameters = [
            'overwrite' => 'true',
            'public_id' => $publicId,
            'timestamp' => (string) $timestamp,
        ];
        ksort($parameters);
        $signature = sha1(implode('&', array_map(
            static fn (string $key, string $value): string => $key.'='.$value,
            array_keys($parameters),
            array_values($parameters),
        )).$this->apiSecret);

        $formData = new FormDataPart([
            'file' => new DataPart($image, 'poster.'.$extension, $contentType),
            'api_key' => $this->apiKey,
            'timestamp' => (string) $timestamp,
            'public_id' => $publicId,
            'overwrite' => 'true',
            'signature' => $signature,
        ]);

        $uploadResponse = $this->httpClient->request(
            'POST',
            sprintf('https://api.cloudinary.com/v1_1/%s/image/upload', rawurlencode($this->cloudName)),
            [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ],
        );
        $result = $uploadResponse->toArray();
        $secureUrl = $result['secure_url'] ?? null;

        if (!is_string($secureUrl)
            || !str_starts_with($secureUrl, sprintf('https://res.cloudinary.com/%s/', $this->cloudName))) {
            throw new \RuntimeException('Cloudinary n’a pas renvoyé une URL sécurisée valide.');
        }

        return $secureUrl;
    }
}
