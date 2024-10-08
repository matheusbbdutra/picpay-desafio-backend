<?php

namespace App\Tests\Domain\Transacao;

use App\Application\DTO\Transacao\TransacaoDTO;
use App\Domain\Transacao\Entity\Carteira;
use App\Domain\Transacao\Entity\Transacao;
use App\Domain\Usuario\Entity\Usuario;
use App\Infrastructure\Repository\Usuario\UsuarioRepository;
use App\Infrastructure\Service\Comum\ClientService;
use App\Infrastructure\Service\Comum\MessageService;
use App\Infrastructure\Service\Transacao\TransacaoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TransacaoServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UsuarioRepository $usuarioRepository;
    private ClientService $clientService;
    private MessageService $messageService;
    private TransacaoService $transacaoService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->usuarioRepository = $this->createMock(UsuarioRepository::class);
        $this->clientService = $this->createMock(ClientService::class);
        $this->messageService = $this->createMock(MessageService::class);

        $this->transacaoService = new TransacaoService(
            $this->entityManager,
            $this->usuarioRepository,
            $this->clientService,
            $this->messageService,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function depositarDeveIncrementarSaldoQuandoValorValido(): void
    {
        $transacao = $this->createMock(Transacao::class);
        $transacao->method('getValor')->willReturn(100);

        $this->transacaoService->depositar($transacao);

        $this->assertEquals(100, $this->transacaoService->getSaldo());
    }

    public function depositarNaoDeveIncrementarSaldoQuandoValorInvalido(): void
    {
        $transacao = $this->createMock(Transacao::class);
        $transacao->method('getValor')->willReturn(-100);

        $this->transacaoService->depositar($transacao);

        $this->assertEquals(0, $this->transacaoService->getSaldo());
    }

    public function depositarDeveLancarExcecaoQuandoTransacaoNula(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->transacaoService->depositar(null);
    }

    public function testTransferencia(): void
    {
        $transacaoDTO = new TransacaoDTO();
        $transacaoDTO->cpfCnpjRemetente = 'cpfCnpjRemetente';
        $transacaoDTO->cpfCnpjDestinatario = 'cpfCnpjDestinatario';
        $transacaoDTO->valor = 100.0;

        $remetente = $this->createMock(Usuario::class);
        $destinatario = $this->createMock(Usuario::class);

        $carteiraRemetente = $this->createMock(Carteira::class);
        $carteiraDestinatario = $this->createMock(Carteira::class);

        $carteiraRemetente->method('setSaldo')->willReturnOnConsecutiveCalls(100.0);
        $carteiraRemetente->method('getSaldo')->willReturnOnConsecutiveCalls(100.0);
        $carteiraDestinatario->method('getSaldo')->willReturnOnConsecutiveCalls(100.0);

        $remetente->method('getCarteira')->willReturn($carteiraRemetente);
        $destinatario->method('getCarteira')->willReturn($carteiraDestinatario);

        $this->usuarioRepository->method('findOneBy')->willReturnOnConsecutiveCalls($remetente, $destinatario);

        $this->clientService->expects($this->any())
            ->method('checkAuthorizationTransaction')
            ->willReturn(true);

        $this->transacaoService->transferencia($transacaoDTO);

        $this->assertEquals(0.0, $remetente->getCarteira()->getSaldo());
        $this->assertEquals(100.0, $destinatario->getCarteira()->getSaldo());
    }
}
