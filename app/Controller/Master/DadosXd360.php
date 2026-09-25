<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Common\Helpers\ConectCnpjHelper;
use App\Common\Helpers\EmailValidator;
use App\Common\Helpers\MasterStaffHelper;
use App\Common\Helpers\SaasEmpresaXd360Helper;
use App\Model\Entity\EstadoCidades;
use App\Model\Entity\SaasEmpresaXd360;

class DadosXd360 extends Page {

	public static function index($request) {
		if (!SaasEmpresaXd360::tabelaExiste()) {
			$content = View::render('master/modules/dados_xd360/sql', []);
			return parent::getPanel('Dados jurídicos XD360', $content, 'dados_xd360');
		}

		MasterStaffHelper::bootstrapSuperAdmins();

		$estados = [];
		$results = EstadoCidades::getEstados(null, 'nome ASC');
		while ($e = $results->fetchObject()) {
			$estados[] = [
				'id'    => (int)$e->id,
				'nome'  => (string)$e->nome,
				'sigla' => (string)($e->sigla ?? ''),
			];
		}

		$content = View::render('master/modules/dados_xd360/index', [
			'estados_json' => json_encode($estados, JSON_UNESCAPED_UNICODE),
		]);
		return parent::getPanel('Dados jurídicos XD360', $content, 'dados_xd360');
	}

	public static function getInfo($request) {
		if (!SaasEmpresaXd360::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/saas_empresaxd360.sql no phpMyAdmin.',
			]);
		}

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		switch ($acao) {
			case 'carregar':
				return self::carregar();
			case 'salvar':
				return self::salvar($post);
			case 'cidades':
				return self::cidades($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function carregar(): string {
		$emp = SaasEmpresaXd360Helper::getOuDefaults();
		$check = SaasEmpresaXd360Helper::checarCompleto($emp);

		return json_encode([
			'success'          => true,
			'dados'            => self::formatar($emp),
			'usuarios_master'  => MasterStaffHelper::listarParaSelect(),
			'completo'         => $check['ok'],
			'faltando'         => $check['faltando'],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function salvar(array $post): string {
		$emp = SaasEmpresaXd360Helper::getOuDefaults();

		$emp->razao_social = trim((string)($post['razao_social'] ?? ''));
		$emp->nome_fantasia = trim((string)($post['nome_fantasia'] ?? ''));
		$emp->cnpj = (string)($post['cnpj'] ?? '');
		$emp->endereco = trim((string)($post['endereco'] ?? ''));
		$emp->numero = trim((string)($post['numero'] ?? ''));
		$emp->bairro = trim((string)($post['bairro'] ?? ''));
		$emp->cep = (string)($post['cep'] ?? '');
		$emp->estado = (int)($post['estado'] ?? 0);
		$emp->cidade = (int)($post['cidade'] ?? 0);
		$emp->uf = (string)($post['uf'] ?? '');
		$emp->cidade_nome = (string)($post['cidade_nome'] ?? '');
		$emp->email = EmailValidator::normalizar($post['email'] ?? '');
		$emp->telefone = trim((string)($post['telefone'] ?? ''));
		$emp->site = trim((string)($post['site'] ?? ''));
		$emp->rep_cargo = trim((string)($post['rep_cargo'] ?? '')) ?: 'Administrador';
		$emp->foro_comarca = trim((string)($post['foro_comarca'] ?? ''));

		$repUserId = (int)($post['rep_legal_usuario_id'] ?? 0);
		if (SaasEmpresaXd360::temColunaRepLegalUsuarioId()) {
			if ($repUserId <= 0 || !MasterStaffHelper::pertenceStaffMaster($repUserId)) {
				return json_encode(['success' => false, 'message' => 'Selecione um representante legal válido (usuário Master).']);
			}
			$emp->rep_legal_usuario_id = $repUserId;
		} else {
			$emp->rep_nome = trim((string)($post['rep_nome'] ?? ''));
			$emp->rep_cpf = (string)($post['rep_cpf'] ?? '');
			$emp->rep_rg = (string)($post['rep_rg'] ?? '');
			$cpf = preg_replace('/\D+/', '', (string)$emp->rep_cpf);
			if ($cpf !== '' && strlen($cpf) !== 11) {
				return json_encode(['success' => false, 'message' => 'CPF do representante inválido.']);
			}
		}

		if ($emp->razao_social === '' || $emp->nome_fantasia === '') {
			return json_encode(['success' => false, 'message' => 'Informe razão social e nome fantasia.']);
		}

		$cnpj = preg_replace('/\D+/', '', (string)$emp->cnpj);
		if ($cnpj !== '' && !ConectCnpjHelper::validar($cnpj)) {
			return json_encode(['success' => false, 'message' => 'CNPJ inválido.']);
		}

		try {
			$ok = SaasEmpresaXd360::salvar($emp);
		} catch (\Throwable $e) {
			return json_encode(['success' => false, 'message' => 'Não foi possível gravar os dados jurídicos. Confira CNPJ, CEP e representante.']);
		}
		if (!$ok) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar.']);
		}

		$saved = SaasEmpresaXd360::get();
		$check = SaasEmpresaXd360Helper::checarCompleto($saved);

		return json_encode([
			'success'  => true,
			'message'  => 'Dados jurídicos da XD360 salvos.',
			'dados'    => self::formatar($saved instanceof SaasEmpresaXd360 ? $saved : $emp),
			'completo' => $check['ok'],
			'faltando' => $check['faltando'],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function cidades(array $post): string {
		$idEstado = (int)($post['estado'] ?? 0);
		if ($idEstado <= 0) {
			return json_encode(['success' => true, 'cidades' => []]);
		}
		$out = [];
		$results = EstadoCidades::getCidades('estados_id = '.$idEstado, 'nome ASC');
		while ($c = $results->fetchObject()) {
			$out[] = ['id' => (int)$c->id, 'nome' => (string)$c->nome];
		}
		return json_encode(['success' => true, 'cidades' => $out], JSON_UNESCAPED_UNICODE);
	}

	/** @return array<string,mixed> */
	private static function formatar(SaasEmpresaXd360 $emp): array {
		$rep = SaasEmpresaXd360Helper::resolverRepresentanteLegal($emp);
		return [
			'razao_social'           => (string)$emp->razao_social,
			'nome_fantasia'          => (string)$emp->nome_fantasia,
			'cnpj'                   => SaasEmpresaXd360Helper::formatCnpj($emp->cnpj ?? ''),
			'cnpj_raw'               => preg_replace('/\D+/', '', (string)($emp->cnpj ?? '')),
			'endereco'               => (string)($emp->endereco ?? ''),
			'numero'                 => (string)($emp->numero ?? ''),
			'bairro'                 => (string)($emp->bairro ?? ''),
			'cep'                    => (string)($emp->cep ?? ''),
			'estado'                 => (int)($emp->estado ?? 0),
			'cidade'                 => (int)($emp->cidade ?? 0),
			'uf'                     => (string)($emp->uf ?? ''),
			'cidade_nome'            => (string)($emp->cidade_nome ?? ''),
			'email'                  => (string)($emp->email ?? ''),
			'telefone'               => (string)($emp->telefone ?? ''),
			'site'                   => (string)($emp->site ?? ''),
			'rep_legal_usuario_id'   => (int)($emp->rep_legal_usuario_id ?? 0),
			'rep_nome'               => $rep ? ($rep['nome'] ?? '') : '',
			'rep_cpf'                => ($rep && isset($rep['cpf'])) ? SaasEmpresaXd360Helper::formatCpf($rep['cpf']) : '',
			'rep_rg'                 => $rep ? ($rep['rg'] ?? '') : '',
			'rep_cargo'              => (string)($emp->rep_cargo ?? 'Administrador'),
			'foro_comarca'           => (string)($emp->foro_comarca ?? ''),
			'endereco_completo'      => SaasEmpresaXd360Helper::resolverEndereco($emp),
			'tem_coluna_rep_usuario' => SaasEmpresaXd360::temColunaRepLegalUsuarioId(),
		];
	}
}
