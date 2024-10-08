<?php

namespace App\Infrastructure\Service\Transacao;

use App\Application\DTO\Transacao\TransacaoDTO;
use App\Domain\Transacao\Entity\Transacao;
use App\Domain\Transacao\Enums\Status;
use App\Domain\Transacao\Enums\TipoTransacao;
use App\Domain\Usuario\Entity\Usuario;
use App\Infrastructure\Repository\Usuario\UsuarioRepository;
use App\Infrastructure\Service\Comum\ClientService;
use App\Infrastructure\Service\Comum\MessageService;
use Doctrine\ORM\EntityManagerInterface;

class TransacaoService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsuarioRepository $usuarioRepository,
        private readonly ClientService $client,
        private readonly MessageService $messageService,
    ) {
    }

    public function depositar(TransacaoDTO $transacaoDTO): Transacao
    {
        $this->entityManager->beginTransaction();

        try {
            /** @var Usuario $remetente */
            $remetente = $this->getUsuario($transacaoDTO->cpfCnpjRemetente);
            $remetente->getCarteira()->adicionarSaldo($transacaoDTO->valor);

            $this->entityManager->persist($remetente);

            $transacao = $this->criarTransacao(
                $remetente,
                null,
                $transacaoDTO->valor,
                Status::Concluido,
                TipoTransacao::Deposito,
            );

            if (! $this->client->checkAuthorizationTransaction()) {
                throw new \DomainException('Transação não autorizada');
            }

            $this->entityManager->commit();

            return $transacao;
        } catch (\Exception $e) {
            $this->entityManager->rollback();

            $this->criarTransacao(
                $remetente,
                null,
                $transacaoDTO->valor,
                Status::Falhou,
                TipoTransacao::Deposito
            );

            throw new \RuntimeException('Erro ao realizar o deposito: ' . $e->getMessage(), 0, $e);
        }
    }

    public function transferencia(TransacaoDTO $transacaoDTO): Transacao
    {
        $this->entityManager->beginTransaction();

        try {
            $remetente = $this->getUsuario($transacaoDTO->cpfCnpjRemetente);
            $destinatario = $this->getUsuario($transacaoDTO->cpfCnpjDestinatario);

            $this->validarTransferencia($remetente, $transacaoDTO->valor);

            $this->executarTransferencia($remetente, $destinatario, $transacaoDTO->valor);
            $this->entityManager->commit();

            return $this->criarTransacao(
                $remetente,
                $destinatario,
                $transacaoDTO->valor,
                Status::Concluido,
                TipoTransacao::Transferencia,
            );
        } catch (\Exception $e) {
            $this->entityManager->rollback();

            $remetente = $this->getUsuario($transacaoDTO->cpfCnpjRemetente);
            $destinatario = $this->getUsuario($transacaoDTO->cpfCnpjDestinatario);

            $this->criarTransacao(
                $remetente,
                $destinatario,
                $transacaoDTO->valor,
                Status::Falhou,
                TipoTransacao::Transferencia
            );

            throw new \RuntimeException('Erro ao realizar a transferência: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getUsuario(?string $cpfCnpj): Usuario
    {
        /** @var Usuario $usuario */
        $usuario = $this->usuarioRepository->findOneBy(['cpfCnpj' => $cpfCnpj]);

        return $usuario;
    }

    private function validarTransferencia(Usuario $remetente, float $valor): void
    {
        if ($remetente->isLogista()) {
            throw new \Exception('Logista não pode efetuar transferências.');
        }

        if ($remetente->getCarteira()->getSaldo() < $valor) {
            throw new \Exception('Saldo insuficiente');
        }

        if (! $this->client->checkAuthorizationTransaction()) {
            throw new \DomainException('Transação não autorizada');
        }
    }

    private function executarTransferencia(Usuario $remetente, Usuario $destinatario, float $valor): void
    {
        $remetente->getCarteira()->subtrairSaldo($valor);
        $destinatario->getCarteira()->adicionarSaldo($valor);

        $this->entityManager->persist($remetente);
        $this->entityManager->persist($destinatario);

        $this->entityManager->flush();
    }

    private function criarTransacao(
        Usuario $remetente,
        ?Usuario $destinatario,
        float $valor,
        Status $status,
        TipoTransacao $tipo,
    ): Transacao {
        $transacao = new Transacao($remetente, $destinatario, $valor, $status, $tipo, new \DateTime());

        $this->entityManager->persist($transacao);
        $this->entityManager->flush();
        $this->notificarStatusEmail($transacao, $status);

        return $transacao;
    }

    public function notificarStatusEmail(Transacao $transacao, Status $status): void
    {
        if ($this->client->shouldSendMensage()) {
            $mensagem = $this->montarMensagemStatusTransacao($transacao, $status);
            $email = $transacao->getTipoTransacao()->value === TipoTransacao::Deposito->value
                ? $transacao->getRemetente()->getEmail()
                : $transacao->getDestinatario()?->getEmail();

            $this->messageService->sendMessage($email?->__toString(), 'Transação', $mensagem);
        }
    }

    private function montarMensagemStatusTransacao(Transacao $transacao, Status $status): string
    {
        if (Status::Concluido->value == $status->value) {
            return "A transação {$transacao->getId()} foi concluída com sucesso.";
        } elseif (Status::Falhou->value == $status->value) {
            return "A transação {$transacao->getId()} falhou.";
        } else {
            return "A transação {$transacao->getId()} está com o status: {$status->value}.";
        }
    }
}
