<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page a scanned QR code opens.
 *
 * It serves one static file for every list; the page reads the uuid out of
 * its own address and fetches the contents. Keeping the uuid out of the
 * markup means this response is identical for everybody and the address
 * never ends up somewhere it can leak, such as a Referer header on an
 * outbound asset request.
 */
final class SharedListPageController extends AbstractController
{
    #[Route('/items/user/{uuid}', name: 'list_page', methods: ['GET'], requirements: [
        'uuid' => '[0-9a-fA-F-]{36}',
    ])]
    public function show(string $uuid): Response
    {
        return $this->page();
    }

    /**
     * The same page for a list carried as bare numbers, which is what a
     * code generated offline holds. The page works out which kind of
     * address it is on and asks for the matching data.
     */
    #[Route('/items/by-id/{numbers}', name: 'list_page_numbers', methods: ['GET'], requirements: [
        'numbers' => '\\d+(?:-\\d+)*',
    ])]
    public function showByNumbers(string $numbers): Response
    {
        return $this->page();
    }

    private function page(): Response
    {
        $file = $this->getParameter('kernel.project_dir') . '/public/list.html';

        if (!is_file($file)) {
            throw $this->createNotFoundException('The list viewer is not installed');
        }

        $response = new Response((string) file_get_contents($file));
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
