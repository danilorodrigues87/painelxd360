<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Common\ProductModules;
use App\Common\Helpers\ModuleGateHelper;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\EstadoCidades;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\User as EntityUser;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\BrandingHelper;
use App\Common\Helpers\SaasAssinaturaService;
use App\Common\Helpers\SlugHelper;
use App\Common\Helpers\TenantHostHelper;

class Escolas extends Page {

	public static function index($request) {
		$baseDomain = trim((string)\App\Common\Environment::get('XD360_BASE_DOMAIN', 'xd360.com.br'));
		$content = View::render('master/modules/escolas/index', [
			'modulos_json' => json_encode(self::catalogoModulos(), JSON_UNESCAPED_UNICODE),
			'planos_json'  => json_encode(Planos::listarAtivosResumo(), JSON_UNESCAPED_UNICODE),
			'estados_json' => json_encode(self::listarEstados(), JSON_UNESCAPED_UNICODE),
			'tem_plan_id'  => ClientesAssinantes::temColunaPlanId() ? '1' : '0',
			'tem_modelo_cert' => ClientesAssinantes::temColunaModeloCertificado() ? '1' : '0',
			'modelo_cert_padrao_json' => json_encode(BrandingHelper::urlModeloCertPadrao(), JSON_UNESCAPED_SLASHES),
			'base_domain_json' => json_encode($baseDomain, JSON_UNESCAPED_UNICODE),
		]);
		return parent::getPanel('Clientes — XD360', $content, 'escolas');
	}

	public static function getInfo($request) {
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		switch ($acao) {
			case 'listar':
				return self::listar();
			case 'detalhes':
				return self::detalhes($post);
			case 'salvar':
				return self::salvar($post, $request->getFileVars());
			case 'cidades':
				return self::cidades($post);
			case 'toggle_ativo':
				return self::toggleAtivo($post);
			case 'reset_diretor':
				return self::resetDiretor($post);
			case 'impersonar':
				return self::impersonar($request, $post);
			case 'contratos':
				return json_encode([
					'success' => true,
					'contratos' => \App\Common\Helpers\SaasContratoService::listar((int)($post['id'] ?? 0)),
					'planos' => Planos::listarAtivosResumo(),
					'produtos' => \App\Common\ProductModules::listarComModo(),
				], JSON_UNESCAPED_UNICODE);
			case 'salvar_contrato':
				$r = \App\Common\Helpers\SaasContratoService::criar((int)($post['id_admin'] ?? 0), $post);
				return json_encode(['success' => $r['ok'], 'message' => $r['message'], 'contrato' => $r['contrato'] ?? null], JSON_UNESCAPED_UNICODE);
			case 'preparar_renovacao':
				$r = \App\Common\Helpers\SaasContratoService::prepararRenovacao((int)($post['contrato_id'] ?? 0));
				return json_encode(['success' => $r['ok'], 'message' => $r['message']] + $r, JSON_UNESCAPED_UNICODE);
			case 'renovar_contrato':
				$r = \App\Common\Helpers\SaasContratoService::renovar((int)($post['contrato_id'] ?? 0), $post);
				return json_encode(['success' => $r['ok'], 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	/** @return array<int, array{slug:string,label:string}> */
	private static function catalogoModulos(): array {
		$out = [];
		foreach (ProductModules::getCatalog() as $slug => $label) {
			$out[] = ['slug' => $slug, 'label' => $label];
		}
		return $out;
	}

	private static function listar(): string {
		$results = ClientesAssinantes::getEscolas(null, 'nome ASC');
		$lista = [];
		while ($e = $results->fetchObject(ClientesAssinantes::class)) {
			if (ClientesAssinantes::temColunaCatalogoCti() && !empty($e->catalogo_cti)) {
				continue;
			}
			$lista[] = self::formatar($e);
		}
		return json_encode(['success' => true, 'escolas' => $lista]);
	}

	private static function detalhes(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$e = ClientesAssinantes::getEscolaById($id);
		if (!$e instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
		}
		$data = self::formatar($e, true);
		$data['diretores'] = self::listarDiretores((int)$e->id);
		return json_encode(['success' => true, 'escola' => $data]);
	}

	private static function salvar(array $post, array $files = []): string {
		try {
			$id = (int)($post['id'] ?? 0);
			$nome = trim((string)($post['nome'] ?? ''));
			$email = trim((string)($post['email'] ?? ''));
			$telefone = trim((string)($post['telefone'] ?? ''));
			$cpfCnpj = trim((string)($post['cpf_cnpj'] ?? ''));
			$ativo = !empty($post['ativo']) ? 's' : 'n';
			$diretorNome = trim((string)($post['diretor_nome'] ?? ''));
			$diretorEmail = trim((string)($post['diretor_email'] ?? ''));
			$planId = (int)($post['plan_id'] ?? 0);

			if ($nome === '') {
				return json_encode(['success' => false, 'message' => 'Informe o nome da empresa.']);
			}

			[$slugFinal, $erroSlug] = self::resolverSlug($post, $id, $nome);
			if ($erroSlug !== null) {
				return json_encode(['success' => false, 'message' => $erroSlug]);
			}

			if (!empty($post['comercial_por_contrato'])) {
				if ($id > 0) {
					$atual = ClientesAssinantes::getEscolaById($id);
					$modulosJson = $atual instanceof ClientesAssinantes ? $atual->modulos_liberados : '[]';
					$planIdSalvar = $atual instanceof ClientesAssinantes ? ($atual->plan_id ?: null) : null;
				} else {
					$modulosJson = '[]';
					$planIdSalvar = null;
				}
			} else {
				[$modulosJson, $planIdSalvar, $erroMods] = self::resolverModulosEPlano($post, $planId);
				if ($erroMods !== null) {
					return json_encode(['success' => false, 'message' => $erroMods]);
				}
			}

			if ($id > 0) {
				$ob = ClientesAssinantes::getEscolaById($id);
				if (!$ob instanceof ClientesAssinantes) {
					return json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
				}
				self::preencherDadosEscola($ob, $post, $nome, $email, $telefone, $cpfCnpj, $ativo, $modulosJson, $planIdSalvar, $slugFinal);
				$ob->logo = BrandingHelper::processarUploadLogo($files['logo'] ?? null, $ob->logo ?? null) ?: '';
				if (ClientesAssinantes::temColunaModeloCertificado()) {
					$ob->modelo_certificado = BrandingHelper::processarUploadModeloCertificado(
						$files['modelo_certificado'] ?? null,
						$ob->modelo_certificado ?? null
					);
				}
				$ob->id_admin = (int)$ob->id;
				$ob->atualizar();
				self::atualizarResponsavel((int)$ob->id, $diretorNome, $diretorEmail, trim((string)($post['diretor_cpf'] ?? '')));
				ModuleGateHelper::limparCache((int)$ob->id);
				ModuleGateHelper::sincronizarAcessoDiretores((int)$ob->id);
				return json_encode([
					'success' => true,
					'message' => 'Cliente atualizado.',
					'escola'  => self::formatar($ob, true),
				]);
			}

			if ($diretorNome === '' || $diretorEmail === '') {
				return json_encode(['success' => false, 'message' => 'Informe nome e e-mail do administrador.']);
			}
			if (!filter_var($diretorEmail, FILTER_VALIDATE_EMAIL)) {
				return json_encode(['success' => false, 'message' => 'E-mail do administrador inválido.']);
			}
			if (EntityUser::getUserByEmail($diretorEmail) instanceof EntityUser) {
				return json_encode(['success' => false, 'message' => 'Este e-mail já está cadastrado.']);
			}

			$ob = new ClientesAssinantes;
			self::preencherDadosEscola($ob, $post, $nome, $email !== '' ? $email : $diretorEmail, $telefone, $cpfCnpj, $ativo, $modulosJson, $planIdSalvar, $slugFinal);
			// Novo cliente: trial 14 dias por padrão (salvo se Master desmarcar)
			if (empty($post['sem_trial']) && ClientesAssinantes::temColunasAssinatura()) {
				if (empty($post['assinatura_status']) || ($post['assinatura_status'] ?? '') === 'trial') {
					SaasAssinaturaService::aplicarTrialPadrao(
						$ob,
						!empty($post['trial_ate']) ? (string)$post['trial_ate'] : null
					);
				}
			}
			$ob->logo = BrandingHelper::processarUploadLogo($files['logo'] ?? null, null) ?: '';
			if (ClientesAssinantes::temColunaModeloCertificado()) {
				$ob->modelo_certificado = BrandingHelper::processarUploadModeloCertificado(
					$files['modelo_certificado'] ?? null,
					null
				);
			}
			$ob->instagram = null;
			$ob->youtube = null;
			$ob->id_admin = 0;
			$ob->cadastrar();

			if ((int)$ob->id <= 0) {
				return json_encode(['success' => false, 'message' => 'Falha ao criar o cliente.']);
			}

			ModuleGateHelper::limparCache((int)$ob->id);

			$senhaTemp = self::gerarSenhaTemporaria();
			$labelsAcesso = ($modulosJson === null)
				? array_values(ProductModules::getCatalog())
				: ProductModules::slugsParaLabels(json_decode($modulosJson, true) ?: []);

			$diretor = new EntityUser;
			$diretor->nome = $diretorNome;
			$diretor->email = $diretorEmail;
			$diretor->nivel = 'Diretor';
			$diretor->senha = password_hash($senhaTemp, PASSWORD_DEFAULT);
			$diretor->id_responsavel = 0;
			$diretor->whatsapp = $telefone !== '' ? $telefone : '';
			$diretor->rg = '';
			$diretor->cpf = preg_replace('/\D+/', '', (string)($post['diretor_cpf'] ?? ''));
			$diretor->nascimento = null;
			$diretor->endereco = (string)($ob->endereco ?? '');
			$diretor->numero = (string)($ob->numero ?? '');
			$diretor->bairro = (string)($ob->bairro ?? '');
			$diretor->uf = (int)($ob->estado ?: 0);
			$diretor->cidade = (int)($ob->cidade ?: 0);
			$diretor->ativo = 's';
			$diretor->acesso = json_encode(array_values($labelsAcesso), JSON_UNESCAPED_UNICODE);
			$diretor->id_admin = (int)$ob->id;
			$diretor->cadastrar();

			return json_encode([
				'success' => true,
				'message' => 'Cliente criado com sucesso.',
				'escola'  => self::formatar($ob, true),
				'diretor' => [
					'nome'  => $diretorNome,
					'email' => $diretorEmail,
					'senha' => $senhaTemp,
				],
			]);
		} catch (\Throwable $e) {
			return json_encode([
				'success' => false,
				'message' => 'Erro ao salvar cliente: '.$e->getMessage(),
			]);
		}
	}

	/** @return array{0:?string,1:?string} [slug, erro] */
	private static function resolverSlug(array $post, int $id, string $nome): array {
		if (!ClientesAssinantes::temColunaSlug()) {
			return [null, null];
		}
		$raw = trim((string)($post['slug'] ?? ''));
		if ($raw === '') {
			$raw = SlugHelper::fromNome($nome);
		}
		$slug = SlugHelper::sanitize($raw);
		if ($slug === '') {
			return [null, 'Informe um identificador (slug) válido para o subdomínio.'];
		}
		if (!SlugHelper::disponivel($slug, $id > 0 ? $id : null)) {
			return [null, 'Este identificador já está em uso ou é reservado.'];
		}
		return [$slug, null];
	}

	private static function preencherDadosEscola(
		ClientesAssinantes $ob,
		array $post,
		string $nome,
		string $email,
		string $telefone,
		string $cpfCnpj,
		$ativo,
		$modulosJson,
		$planIdSalvar,
		?string $slugFinal = null
	): void {
		$ob->nome = $nome;
		$ob->email = $email;
		$ob->telefone = $telefone;
		$ob->cpf_cnpj = $cpfCnpj;
		$ob->site = trim((string)($post['site'] ?? ''));
		$ob->endereco = trim((string)($post['endereco'] ?? ''));
		$ob->numero = trim((string)($post['numero'] ?? ''));
		$ob->bairro = trim((string)($post['bairro'] ?? ''));
		$ob->cidade = (int)($post['cidade'] ?? 0);
		$ob->estado = (int)($post['estado'] ?? 0);
		$ob->cep = trim((string)($post['cep'] ?? ''));
		$ob->ativo = $ativo;
		$ob->modulos_liberados = $modulosJson;
		$ob->plan_id = $planIdSalvar;
		if (ClientesAssinantes::temColunaSlug() && $slugFinal !== null) {
			$ob->slug = $slugFinal;
		}
		if (ClientesAssinantes::temColunaDominioCustom()) {
			$dom = trim(strtolower((string)($post['dominio_custom'] ?? '')));
			$dom = preg_replace('#^https?://#', '', $dom) ?? $dom;
			$dom = rtrim($dom, '/');
			$ob->dominio_custom = $dom !== '' ? $dom : null;
			$ob->dominio_verificado = !empty($post['dominio_verificado']) ? 1 : 0;
		}
		if (ClientesAssinantes::temColunasAssinatura()) {
			$dia = (int)($post['dia_vencimento_assinatura'] ?? $ob->dia_vencimento_assinatura ?? 10);
			$ob->dia_vencimento_assinatura = max(1, min(28, $dia ?: 10));
		}
		if (ClientesAssinantes::temColunaValorMensalCustom() && array_key_exists('valor_mensal_custom', $post)) {
			$raw = trim(str_replace(',', '.', (string)($post['valor_mensal_custom'] ?? '')));
			$ob->valor_mensal_custom = ($raw !== '' && (float)$raw > 0) ? round((float)$raw, 2) : null;
		}
		if (ClientesAssinantes::temColunasAssinatura()) {
			$status = trim((string)($post['assinatura_status'] ?? $ob->assinatura_status ?? 'ativa'));
			if (!in_array($status, ['ativa', 'suspensa', 'trial'], true)) {
				$status = 'ativa';
			}
			$ob->assinatura_status = $status;
		}
		if (ClientesAssinantes::temColunaTrialAte()) {
			$trialAte = trim((string)($post['trial_ate'] ?? ''));
			if ($trialAte !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $trialAte)) {
				$ob->trial_ate = $trialAte;
			} elseif (!empty($post['iniciar_trial'])) {
				SaasAssinaturaService::aplicarTrialPadrao($ob);
			} elseif ($trialAte === '' && ($ob->assinatura_status ?? '') !== 'trial') {
				$ob->trial_ate = null;
			}
		}
	}

	/** @return array<int, array{id:int,nome:string,sigla:string}> */
	private static function listarEstados(): array {
		$out = [];
		$results = EstadoCidades::getEstados(null, 'nome ASC');
		while ($e = $results->fetchObject()) {
			$out[] = [
				'id'    => (int)$e->id,
				'nome'  => (string)$e->nome,
				'sigla' => (string)($e->sigla ?? ''),
			];
		}
		return $out;
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

	/**
	 * @return array{0:?string,1:?int,2:?string} [modulosJson, planId, erro]
	 */
	private static function resolverModulosEPlano(array $post, int $planId): array {
		if ($planId > 0 && PlanosAssinatura::tabelaExiste()) {
			$plano = PlanosAssinatura::getById($planId);
			if (!$plano instanceof PlanosAssinatura) {
				return [null, null, 'Plano inválido.'];
			}
			if (!(int)$plano->ativo) {
				return [null, null, 'Este plano está inativo.'];
			}
			return [$plano->modulosParaEscola(), $planId, null];
		}

		$todosModulos = !empty($post['todos_modulos']);
		if ($todosModulos) {
			return [null, null, null];
		}
		$slugs = self::parseSlugs($post['modulos_json'] ?? '[]');
		if (empty($slugs)) {
			return [null, null, 'Selecione um plano, módulos, ou marque “Todos os módulos”.'];
		}
		return [json_encode($slugs, JSON_UNESCAPED_UNICODE), null, null];
	}

	private static function toggleAtivo(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$ob = ClientesAssinantes::getEscolaById($id);
		if (!$ob instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
		}
		$ob->ativo = $ob->isAtiva() ? 'n' : 's';
		$ob->atualizar();
		ModuleGateHelper::limparCache($id);

		return json_encode([
			'success' => true,
			'message' => $ob->isAtiva() ? 'Cliente ativado.' : 'Cliente desativado.',
			'escola'  => self::formatar($ob),
		]);
	}

	private static function resetDiretor(array $post): string {
		$escolaId = (int)($post['id'] ?? 0);
		$usuarioId = (int)($post['usuario_id'] ?? 0);
		$escola = ClientesAssinantes::getEscolaById($escolaId);
		if (!$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
		}

		$idAdmin = (int)$escola->id;
		if ($usuarioId > 0) {
			$user = EntityUser::getUser(
				'id = '.$usuarioId.' AND id_admin = '.$idAdmin.' AND nivel = "Diretor"'
			)->fetchObject(EntityUser::class);
		} else {
			$user = EntityUser::getUser(
				'id_admin = '.$idAdmin.' AND nivel = "Diretor" AND ativo = "s"',
				'id ASC',
				'1'
			)->fetchObject(EntityUser::class);
		}

		if (!$user instanceof EntityUser) {
			return json_encode(['success' => false, 'message' => 'Nenhum administrador encontrado neste cliente.']);
		}

		$senhaTemp = self::gerarSenhaTemporaria();
		$user->senha = password_hash($senhaTemp, PASSWORD_DEFAULT);
		$user->resetSenha();

		return json_encode([
			'success' => true,
			'message' => 'Senha do administrador redefinida.',
			'diretor' => [
				'id'    => (int)$user->id,
				'nome'  => $user->nome,
				'email' => $user->email,
				'senha' => $senhaTemp,
			],
		]);
	}

	private static function impersonar($request, array $post): string {
		$escolaId = (int)($post['id'] ?? 0);
		$escola = ClientesAssinantes::getEscolaById($escolaId);
		if (!$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
		}
		if (!$escola->isAtiva()) {
			return json_encode(['success' => false, 'message' => 'Ative o cliente antes de entrar no painel.']);
		}

		$diretor = EntityUser::getUser(
			'id_admin = '.(int)$escola->id.' AND nivel = "Diretor" AND ativo = "s"',
			'id ASC',
			'1'
		)->fetchObject(EntityUser::class);

		if (!$diretor instanceof EntityUser) {
			return json_encode(['success' => false, 'message' => 'Nenhum administrador ativo neste cliente.']);
		}

		if (!SessionUser::iniciarImpersonate($diretor, $escola)) {
			return json_encode(['success' => false, 'message' => 'Não foi possível iniciar o acesso.']);
		}

		return json_encode([
			'success'  => true,
			'message'  => 'Entrando no painel do cliente...',
			'redirect' => rtrim((string)URL, '/').'/painel',
		]);
	}

	private static function atualizarResponsavel(int $idAdmin, string $nome, string $email, string $cpf): void {
		if ($nome === '' && $cpf === '' && $email === '') {
			return;
		}
		$user = EntityUser::getUser(
			'id_admin = '.$idAdmin.' AND nivel = "Diretor"',
			'id ASC',
			'1'
		)->fetchObject(EntityUser::class);
		if (!$user instanceof EntityUser) {
			return;
		}
		if ($nome !== '') {
			$user->nome = $nome;
		}
		$cpfDig = preg_replace('/\D+/', '', $cpf);
		if ($cpfDig !== '') {
			$user->cpf = $cpfDig;
		}
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== (string)$user->email) {
			$outro = EntityUser::getUserByEmail($email);
			if (!$outro instanceof EntityUser || (int)$outro->id === (int)$user->id) {
				$user->email = $email;
			}
		}
		$user->nascimento = $user->nascimento ?: null;
		$user->uf = (int)($user->uf ?: 0);
		$user->cidade = (int)($user->cidade ?: 0);
		$user->atualizaPerfil();
	}

	/** @return array<int, array{id:int,nome:string,email:string,cpf:string,ativo:string}> */
	private static function listarDiretores(int $idAdmin): array {
		$out = [];
		$results = EntityUser::getUser(
			'id_admin = '.$idAdmin.' AND nivel = "Diretor"',
			'nome ASC',
			null,
			'id, nome, email, cpf, ativo'
		);
		while ($u = $results->fetchObject(EntityUser::class)) {
			$out[] = [
				'id'    => (int)$u->id,
				'nome'  => $u->nome,
				'email' => $u->email,
				'cpf'   => (string)($u->cpf ?? ''),
				'ativo' => $u->ativo,
			];
		}
		return $out;
	}

	private static function formatar(ClientesAssinantes $e, bool $completo = false): array {
		$raw = $e->modulos_liberados ?? null;
		$todos = ($raw === null || $raw === '');
		$slugs = [];
		if (!$todos) {
			$decoded = json_decode((string)$raw, true);
			if (is_array($decoded)) {
				$validos = array_flip(ProductModules::getSlugs());
				foreach ($decoded as $s) {
					$s = (string)$s;
					if (isset($validos[$s])) {
						$slugs[] = $s;
					}
				}
			}
		}

		$planId = ClientesAssinantes::temColunaPlanId() ? (int)($e->plan_id ?? 0) : 0;
		$planoNome = null;
		if ($planId > 0) {
			$plano = PlanosAssinatura::getById($planId);
			if ($plano instanceof PlanosAssinatura) {
				$planoNome = $plano->nome;
			}
		}

		$urlSub = TenantHostHelper::urlSubdominioCliente($e);

		$out = [
			'id'            => (int)$e->id,
			'id_admin'      => (int)($e->id_admin ?: $e->id),
			'nome'          => $e->nome,
			'slug'          => ClientesAssinantes::temColunaSlug() ? ($e->slug ?? null) : null,
			'url_subdominio' => $urlSub,
			'dominio_custom' => ClientesAssinantes::temColunaDominioCustom()
				? ($e->dominio_custom ?? null)
				: null,
			'dominio_verificado' => ClientesAssinantes::temColunaDominioCustom()
				? (!empty($e->dominio_verificado) ? 1 : 0)
				: 0,
			'email'         => $e->email,
			'telefone'      => $e->telefone,
			'cpf_cnpj'      => $e->cpf_cnpj,
			'ativo'         => $e->isAtiva() ? 1 : 0,
			'todos_modulos' => $todos,
			'modulos'       => $slugs,
			'modulos_qtd'   => $todos ? count(ProductModules::getSlugs()) : count($slugs),
			'plan_id'       => $planId ?: null,
			'plano_nome'    => $planoNome,
			'dia_vencimento_assinatura' => ClientesAssinantes::temColunasAssinatura()
				? max(1, min(28, (int)($e->dia_vencimento_assinatura ?? 10)))
				: 10,
			'assinatura_status' => ClientesAssinantes::temColunasAssinatura()
				? (string)($e->assinatura_status ?? 'ativa')
				: 'ativa',
			'assinatura_proximo_vencimento' => ClientesAssinantes::temColunasAssinatura()
				? ($e->assinatura_proximo_vencimento ?? null)
				: null,
			'valor_mensal_custom' => ClientesAssinantes::temColunaValorMensalCustom()
				? (($e->valor_mensal_custom !== null && (float)$e->valor_mensal_custom > 0)
					? round((float)$e->valor_mensal_custom, 2)
					: null)
				: null,
			'trial_ate' => ClientesAssinantes::temColunaTrialAte()
				? ($e->trial_ate ?? null)
				: null,
			'em_trial' => SaasAssinaturaService::emTrialAtivo($e) ? 1 : 0,
			'valor_efetivo' => SaasAssinaturaService::resolverValorMensal($e),
		];

		if ($completo) {
			$out['site'] = $e->site;
			$out['endereco'] = $e->endereco;
			$out['numero'] = $e->numero;
			$out['bairro'] = $e->bairro;
			$out['cidade'] = $e->cidade;
			$out['estado'] = $e->estado;
			$out['cep'] = $e->cep;
			$out['logo'] = $e->logo;
			$out['logo_url'] = BrandingHelper::urlLogoEscola($e->logo ?? null);
			$out['modelo_certificado'] = ClientesAssinantes::temColunaModeloCertificado()
				? ($e->modelo_certificado ?? null)
				: null;
			$out['modelo_certificado_url'] = BrandingHelper::urlModeloCertificado(
				ClientesAssinantes::temColunaModeloCertificado() ? ($e->modelo_certificado ?? null) : null
			);
			$out['tem_modelo_certificado'] = ClientesAssinantes::temColunaModeloCertificado() ? 1 : 0;
		}

		return $out;
	}

	/** @return string[] */
	private static function parseSlugs($raw): array {
		$arr = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
		$validos = array_flip(ProductModules::getSlugs());
		$out = [];
		foreach ($arr as $s) {
			$s = (string)$s;
			if (isset($validos[$s])) {
				$out[$s] = true;
			}
		}
		return array_keys($out);
	}

	private static function gerarSenhaTemporaria(): string {
		return 'Xd'.substr(bin2hex(random_bytes(4)), 0, 6).'!';
	}
}
