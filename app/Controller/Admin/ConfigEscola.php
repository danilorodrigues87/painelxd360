<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\BrandingHelper;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\EstadoCidades;
use App\Model\Entity\PlanosAssinatura;

class ConfigEscola extends Page {

	private static function assertDiretor($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertDiretor($request)) {
			return '';
		}

		$estados = [];
		$results = EstadoCidades::getEstados(null, 'nome ASC');
		while ($e = $results->fetchObject()) {
			$estados[] = [
				'id'    => (int)$e->id,
				'nome'  => (string)$e->nome,
				'sigla' => (string)($e->sigla ?? ''),
			];
		}

		$content = View::render('admin/modules/config/escola', [
			'estados_json' => json_encode($estados, JSON_UNESCAPED_UNICODE),
			'tem_modelo_cert' => ClientesAssinantes::temColunaModeloCertificado() ? '1' : '0',
			'modelo_cert_padrao_json' => json_encode(BrandingHelper::urlModeloCertPadrao(), JSON_UNESCAPED_SLASHES),
			'logo_padrao_json' => json_encode(BrandingHelper::urlLogoCti(), JSON_UNESCAPED_SLASHES),
		]);
		return parent::getPanel('Dados da empresa', $content, 'config');
	}

	public static function getInfo($request) {
		if (!self::assertDiretor($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'carregar') {
			return self::carregar();
		}
		if ($acao === 'salvar') {
			return self::salvar($post, $request->getFileVars());
		}
		if ($acao === 'cidades') {
			return self::cidades($post);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function carregar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$e = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$e instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Empresa não encontrada.']);
		}

		$planoNome = null;
		if (ClientesAssinantes::temColunaPlanId() && !empty($e->plan_id)) {
			$plano = PlanosAssinatura::getById((int)$e->plan_id);
			if ($plano instanceof PlanosAssinatura) {
				$planoNome = $plano->nome;
			}
		}

		return json_encode([
			'success' => true,
			'escola'  => [
				'nome'       => (string)$e->nome,
				'cpf_cnpj'   => (string)($e->cpf_cnpj ?? ''),
				'ativo'      => $e->isAtiva() ? 1 : 0,
				'plano_nome' => $planoNome,
				'email'      => (string)($e->email ?? ''),
				'telefone'   => (string)($e->telefone ?? ''),
				'site'       => (string)($e->site ?? ''),
				'instagram'  => (string)($e->instagram ?? ''),
				'youtube'    => (string)($e->youtube ?? ''),
				'cep'        => (string)($e->cep ?? ''),
				'endereco'   => (string)($e->endereco ?? ''),
				'numero'     => (string)($e->numero ?? ''),
				'bairro'     => (string)($e->bairro ?? ''),
				'estado'     => (int)($e->estado ?? 0),
				'cidade'     => (int)($e->cidade ?? 0),
				'logo_url'   => BrandingHelper::urlLogoEscola($e->logo ?? null),
				'tem_logo'   => trim((string)($e->logo ?? '')) !== '',
				'modelo_certificado_url' => BrandingHelper::urlModeloCertificado(
					ClientesAssinantes::temColunaModeloCertificado() ? ($e->modelo_certificado ?? null) : null
				),
				'tem_modelo_cert' => ClientesAssinantes::temColunaModeloCertificado() ? 1 : 0,
			],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function salvar(array $post, array $files): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$e = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$e instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Empresa não encontrada.']);
		}

		$email = trim((string)($post['email'] ?? ''));
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return json_encode(['success' => false, 'message' => 'E-mail da escola inválido.']);
		}

		$e->email = $email;
		$e->telefone = trim((string)($post['telefone'] ?? ''));
		$e->site = trim((string)($post['site'] ?? ''));
		$e->instagram = trim((string)($post['instagram'] ?? '')) ?: null;
		$e->youtube = trim((string)($post['youtube'] ?? '')) ?: null;
		$e->cep = trim((string)($post['cep'] ?? ''));
		$e->endereco = trim((string)($post['endereco'] ?? ''));
		$e->numero = trim((string)($post['numero'] ?? ''));
		$e->bairro = trim((string)($post['bairro'] ?? ''));
		$e->estado = (int)($post['estado'] ?? 0);
		$e->cidade = (int)($post['cidade'] ?? 0);

		$e->logo = BrandingHelper::processarUploadLogo($files['logo'] ?? null, $e->logo ?? null) ?: '';
		if (ClientesAssinantes::temColunaModeloCertificado()) {
			$e->modelo_certificado = BrandingHelper::processarUploadModeloCertificado(
				$files['modelo_certificado'] ?? null,
				$e->modelo_certificado ?? null
			);
		}

		if (!$e->atualizarOperacional()) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar.']);
		}

		return json_encode([
			'success' => true,
			'message' => 'Dados da escola atualizados.',
			'logo_url' => BrandingHelper::urlLogoEscola($e->logo ?? null),
			'modelo_certificado_url' => BrandingHelper::urlModeloCertificado(
				ClientesAssinantes::temColunaModeloCertificado() ? ($e->modelo_certificado ?? null) : null
			),
		], JSON_UNESCAPED_SLASHES);
	}

	private static function cidades(array $post): string {
		$estadoId = (int)($post['estado'] ?? 0);
		$lista = [];
		if ($estadoId > 0) {
			$results = EstadoCidades::getCidades('estados_id = '.$estadoId, 'nome ASC');
			while ($c = $results->fetchObject()) {
				$lista[] = [
					'id'   => (int)$c->id,
					'nome' => (string)$c->nome,
				];
			}
		}
		return json_encode(['success' => true, 'cidades' => $lista], JSON_UNESCAPED_UNICODE);
	}
}
