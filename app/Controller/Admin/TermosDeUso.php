<?php 

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\User as EntityUser;
use \App\Session\User\Login as SessionUser;
use \App\Model\Entity\EstadoCidades;

class TermosDeUso extends Page {

	/** Versão vigente do termo (alterar força novo aceite quando colunas SQL existem). */
	public const VERSAO = '2026-07';
	public const DATA_VERSAO = '27/07/2026';

	public static function index($request){
		$content = View::render('admin/modules/termos_uso/index', [
			'termos' => self::getTermosHtml()
		]);
		return parent::getPanel('Termos de Uso', $content, 'Termos de Uso', $request);
	}

	public static function usuarioAceitouVersaoAtual(?object $userRow = null): bool {
		if ($userRow === null) {
			$session = SessionUser::getUserLogedData();
			$id = (int)($session['usuario']['id'] ?? 0);
			if ($id <= 0) {
				return false;
			}
			$campos = 'termos_uso';
			if (EntityUser::temColunasTermosVersao()) {
				$campos .= ', termos_versao';
			}
			$userRow = EntityUser::getUser('id = '.$id, null, null, $campos)->fetchObject();
		}
		if (!$userRow || (int)($userRow->termos_uso ?? 0) !== 1) {
			return false;
		}
		if (!EntityUser::temColunasTermosVersao()) {
			return true;
		}
		$v = trim((string)($userRow->termos_versao ?? ''));
		// Aceite antigo sem versão: exige reaceitar a versão atual
		return $v === self::VERSAO;
	}

	public static function getTermosHtml(): string {
		$userLogedData = SessionUser::getUserLogedData();
		$dadosEscola = $userLogedData['escola'];
		$id_user = (int)$userLogedData['usuario']['id'];
		$dadosUser = (array) EntityUser::getUserById($id_user);

		$campos = 'termos_uso';
		if (EntityUser::temColunasTermosVersao()) {
			$campos .= ', termos_aceito_em, termos_versao';
		}
		$rowTermos = EntityUser::getUser('id = '.$id_user, null, null, $campos)->fetchObject();
		$aceitouAtual = self::usuarioAceitouVersaoAtual($rowTermos);

		$cidadeId = (int)($dadosUser['cidade'] ?? 0);
		$estadoId = (int)($dadosUser['uf'] ?? 0);
		if ($cidadeId <= 0) {
			$cidadeId = (int)($dadosEscola['cidade'] ?? 0);
		}
		if ($estadoId <= 0) {
			$estadoId = (int)($dadosEscola['estado'] ?? 0);
		}

		$cidadeNome = '';
		$estadoSigla = '';
		if ($cidadeId > 0) {
			$cidade = EstadoCidades::getCidades('id = '.$cidadeId)->fetchObject();
			if (is_object($cidade)) {
				$cidadeNome = (string)($cidade->nome ?? '');
			}
		}
		if ($estadoId > 0) {
			$estado = EstadoCidades::getEstados('id = '.$estadoId)->fetchObject();
			if (is_object($estado)) {
				$estadoSigla = (string)($estado->sigla ?? '');
			}
		}

		$cidadeUf = trim($cidadeNome.($estadoSigla !== '' ? '/'.$estadoSigla : ''));
		$partesEndereco = [];
		foreach ([
			trim((string)($dadosUser['endereco'] ?? '')),
			trim((string)($dadosUser['numero'] ?? '')),
			trim((string)($dadosUser['bairro'] ?? '')),
			$cidadeUf,
		] as $parte) {
			if ($parte !== '') {
				$partesEndereco[] = $parte;
			}
		}
		$enderecoUser = $partesEndereco ? implode(', ', $partesEndereco) : 'endereço não informado';

		$urlBase = rtrim((string)URL, '/');
		$escolaNome = htmlspecialchars((string)($dadosEscola['nome'] ?? ''), ENT_QUOTES, 'UTF-8');

		if ($aceitouAtual) {
			$aceitoEm = !empty($rowTermos->termos_aceito_em)
				? date('d/m/Y H:i', strtotime((string)$rowTermos->termos_aceito_em))
				: '—';
			$versaoAceita = htmlspecialchars((string)($rowTermos->termos_versao ?? self::VERSAO), ENT_QUOTES, 'UTF-8');
			$blocoStatus = '<div class="alert alert-success">Termo versão <strong>'
				.$versaoAceita.'</strong> aceito em <strong>'.$aceitoEm.'</strong>.</div>';
		} else {
			$blocoStatus = '<div class="form-check my-4">'
				.'<input onchange="ativaBtn()" class="form-check-input" type="checkbox" id="termo_uso">'
				.'<label class="form-check-label" for="termo_uso">'
				.'Declaro estar ciente e concordar com este Termo de Uso e Responsabilidade (versão '
				.self::VERSAO.'), assumindo responsabilidade pelo uso adequado dos dados tratados no Painel CTI '
				.'em nome da escola <strong>'.$escolaNome.'</strong>.'
				.'</label></div>'
				.'<button disabled onclick="termos()" id="btn-termo" class="btn btn-primary mb-3">Aceitar e continuar</button>';
		}

		return View::render('admin/modules/termos_uso/conteudo', [
			'versao' => self::VERSAO,
			'data_versao' => self::DATA_VERSAO,
			'nome' => htmlspecialchars((string)($dadosUser['nome'] ?? ''), ENT_QUOTES, 'UTF-8'),
			'cpf' => htmlspecialchars((string)($dadosUser['cpf'] ?? ''), ENT_QUOTES, 'UTF-8'),
			'endereco' => htmlspecialchars($enderecoUser, ENT_QUOTES, 'UTF-8'),
			'escola_nome' => $escolaNome,
			'escola_cnpj' => htmlspecialchars((string)($dadosEscola['cpf_cnpj'] ?? ''), ENT_QUOTES, 'UTF-8'),
			'url_privacidade' => $urlBase.'/privacidade',
			'url_exclusao' => $urlBase.'/exclusao-de-dados',
			'bloco_status' => $blocoStatus,
		]);
	}

	public static function aceitaTermo($request){
		$session = SessionUser::getUserLogedData();
		$id = (int)($session['usuario']['id'] ?? 0);
		if ($id <= 0) {
			return '0';
		}

		$obUsers = new EntityUser;
		$obUsers->id = $id;
		$obUsers->termos_uso = 1;
		$obUsers->termos_versao = self::VERSAO;
		$obUsers->termos_aceito_em = date('Y-m-d H:i:s');
		$ok = $obUsers->termoAceito();

		if ($ok) {
			SessionUser::marcarTermosAceitos(self::VERSAO);
			return '1';
		}
		return '0';
	}
}
