<?php

namespace App\Infrastructure\Service\Comum;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClientService
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    public function checkAuthorizationTransaction(): bool
    {
        $response = $this->client->request('GET', 'https://run.mocky.io/v3/5794d450-d2e2-4412-8131-73d0293ac1cc', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            return false;
        }

        $data = $response->toArray();

        return 'Autorizado' === $data['message'];
    }

    public function shouldSendMensage(): bool
    {
        $response = $this->client->request('GET', 'https://run.mocky.io/v3/54dc2cf1-3add-45b5-b5a9-6bf7e7f1f4a6');
        $data = $response->toArray();

        return $data['message'] ?? false;
    }

    public function checkEmailInMailHog(string $recipient, string $message): bool
    {
        $response = $this->client->request('GET', 'http://localhost:8025/api/v2/messages');
        $emails = $response->toArray();

        foreach ($emails['items'] as $email) {
            if ($email['Content']['Headers']['To'][0] === $recipient && $email['Content']['Body'] === $message) {
                return true;
            }
        }

        return false;
    }
}
