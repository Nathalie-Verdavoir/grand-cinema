<?php

namespace App\Controller\Admin\Api\Film;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/admin/api/film', name: 'admin_api_film_')]
class FilmApiController extends AbstractController
{
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request, HttpClientInterface $client): JsonResponse
    {
        $query = $request->query->get('q');
        if (!$query) {
            return new JsonResponse([]);
        }

        $response = $client->request('GET', 'https://api.themoviedb.org/3/search/movie?query=' . urlencode($query) . '&include_adult=false&language=fr-fr&page=1', [
            'headers' => [
                'Authorization' => 'Bearer ' . $_ENV['TMDB_API_KEY'],
                'Accept' => 'application/json',
            ],
        ]);

        return new JsonResponse($response->toArray()['results'] ?? []);
    }
}
