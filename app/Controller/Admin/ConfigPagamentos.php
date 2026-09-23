<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\MercadoPagoEscolaHelper;
use App\Common\Gateways\MercadoPago\Client;
use App\Model\Entity\EscolaIntegracoes;

class ConfigPagamentos extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		if (!in_array('pagamentos', ModuleGateHelper::getSlugsEscola($idAdmin), true)) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/config/pagamentos', []);
		return parent::getPanel('Pagamentos', $content, 'config');
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'carregar') {
			return self::carregar();
		}
		if ($acao === 'salvar') {
			return self::salvar($post);
		}
		if ($acao === 'testar') {
			return self::testar($post);
		}
		if ($acao === 'regenerar_token') {
			return self::regenerarToken();
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function carregar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$colOk = EscolaIntegracoes::temColunasMercadoPago();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);

		if ($colOk && (!$cfg instanceof EscolaIntegracoes || empty($cfg->mp_webhook_token))) {
			EscolaIntegracoes::garantirRegistroMp($idAdmin);
			$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		}

		$tokenMask = '';
		if ($cfg instanceof EscolaIntegracoes) {
			$plain = $cfg->getMpAccessTokenDescriptografado();
			if ($plain) {
				$tokenMask = self::mascarar($plain);
			}
		}

		return json_encode([
			'success'        => true,
			'coluna_ok'      => $colOk,
			'mp_ativo'       => $cfg instanceof EscolaIntegracoes ? (int)$cfg->mp_ativo : 0,
			'token_salvo'    => $tokenMask !== '',
			'token_mask'     => $tokenMask,
			'webhook_secret_salvo' => $cfg instanceof EscolaIntegracoes && $cfg->getMpWebhookSecretDescriptografado() !== null,
			'webhook_url'    => MercadoPagoEscolaHelper::webhookUrl($idAdmin),
			'pix_pronto'     => MercadoPagoEscolaHelper::escolaTemPixAtivo($idAdmin),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function salvar(array $post): string {
		if (!EscolaIntegracoes::temColunasMercadoPago()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/escola_integracoes_mercadopago.sql no phpMyAdmin.',
			]);
		}

		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = EscolaIntegracoes::garantirRegistroMp($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return json_encode(['success' => false, 'message' => 'Falha ao preparar configuração.']);
		}

		$cfg->mp_ativo = !empty($post['mp_ativo']) ? 1 : 0;
		$tokenNovo = trim((string)($post['mp_access_token'] ?? ''));
		$secretNovo = trim((string)($post['mp_webhook_secret'] ?? ''));

		if (!$cfg->salvarMercadoPago(
			$tokenNovo !== '' ? $tokenNovo : null,
			$secretNovo !== '' ? $secretNovo : null
		)) {
			return json_encode([
				'success' => false,
				'message' => EscolaIntegracoes::getUltimoErro() ?: 'Falha ao salvar.',
			]);
		}

		return json_encode([
			'success' => true,
			'message' => 'Configuração de pagamentos salva.',
			'pix_pronto' => MercadoPagoEscolaHelper::escolaTemPixAtivo($idAdmin),
			'webhook_url' => MercadoPagoEscolaHelper::webhookUrl($idAdmin),
		], JSON_UNESCAPED_SLASHES);
	}

	private static function testar(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$token = trim((string)($post['mp_access_token'] ?? ''));
		if ($token === '') {
			$client = MercadoPagoEscolaHelper::clientDaEscola($idAdmin);
		} else {
			$client = new Client($token);
		}
		if (!$client) {
			return json_encode(['success' => false, 'message' => 'Informe ou salve um Access Token.']);
		}
		$res = $client->testarConexao();
		return json_encode([
			'success' => $res['ok'],
			'message' => $res['message'],
		]);
	}

	private static function regenerarToken(): string {
		if (!EscolaIntegracoes::temColunasMercadoPago()) {
			return json_encode(['success' => false, 'message' => 'SQL do Mercado Pago não executado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = EscolaIntegracoes::garantirRegistroMp($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return json_encode(['success' => false, 'message' => 'Falha.']);
		}
		$cfg->mp_webhook_token = bin2hex(random_bytes(24));
		$cfg->salvarMercadoPago(null, null);
		return json_encode([
			'success' => true,
			'message' => 'Novo token gerado. Atualize a URL no painel do Mercado Pago.',
			'webhook_url' => MercadoPagoEscolaHelper::webhookUrl($idAdmin),
		], JSON_UNESCAPED_SLASHES);
	}

	private static function mascarar(string $token): string {
		$len = strlen($token);
		if ($len <= 10) {
			return str_repeat('*', $len);
		}
		return substr($token, 0, 6).str_repeat('*', max(4, $len - 10)).substr($token, -4);
	}
}
