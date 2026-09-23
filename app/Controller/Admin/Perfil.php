<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Model\Entity\User as EntityUser;
use App\Model\Entity\EstadoCidades;
use App\Common\Helpers\EmailValidator;
use App\Common\Helpers\UserFotoHelper;

class Perfil extends Page {

	public static function index($request) {
		$userLoged = SessionUser::getUserLogedData();
		$id = (int)($userLoged['usuario']['id'] ?? 0);
		$dados = EntityUser::getUserById($id);
		if (!$dados instanceof EntityUser) {
			$request->getRouter()->redirect('/painel');
		}

		$alertSql = EntityUser::temColunaFoto()
			? ''
			: '<div class="alert alert-warning small">Execute no phpMyAdmin: <code>ALTER TABLE usuarios ADD COLUMN foto VARCHAR(255) NULL;</code></div>';

		$results = EstadoCidades::getEstados();
		$optEstado = '';
		while ($ob = $results->fetchObject(EstadoCidades::class)) {
			$sel = ((int)($dados->uf ?? 0) === (int)$ob->id) ? 'selected' : '';
			$optEstado .= '<option '.$sel.' value="'.(int)$ob->id.'">'.htmlspecialchars($ob->nome, ENT_QUOTES, 'UTF-8').'</option>';
		}

		$content = View::render('admin/modules/perfil/index', [
			'alert_sql'   => $alertSql,
			'foto_html'   => UserFotoHelper::htmlCampoFormulario($dados->foto ?? null, 'input-foto-perfil'),
			'nome'        => htmlspecialchars((string)$dados->nome, ENT_QUOTES, 'UTF-8'),
			'email'       => htmlspecialchars((string)$dados->email, ENT_QUOTES, 'UTF-8'),
			'email_antigo'=> htmlspecialchars((string)$dados->email, ENT_QUOTES, 'UTF-8'),
			'whatsapp'    => htmlspecialchars((string)($dados->whatsapp ?? ''), ENT_QUOTES, 'UTF-8'),
			'cpf'         => htmlspecialchars((string)($dados->cpf ?? ''), ENT_QUOTES, 'UTF-8'),
			'rg'          => htmlspecialchars((string)($dados->rg ?? ''), ENT_QUOTES, 'UTF-8'),
			'nascimento'  => htmlspecialchars((string)($dados->nascimento ?? ''), ENT_QUOTES, 'UTF-8'),
			'endereco'    => htmlspecialchars((string)($dados->endereco ?? ''), ENT_QUOTES, 'UTF-8'),
			'numero'      => htmlspecialchars((string)($dados->numero ?? ''), ENT_QUOTES, 'UTF-8'),
			'bairro'      => htmlspecialchars((string)($dados->bairro ?? ''), ENT_QUOTES, 'UTF-8'),
			'opt_estado'  => $optEstado,
			'cidade_id'   => (int)($dados->cidade ?? 0),
			'id'          => (int)$dados->id,
		]);

		return parent::getPanel('Perfil', $content, 'Perfil', $request);
	}

	public static function salvar($request) {
		$userLoged = SessionUser::getUserLogedData();
		$idUser = (int)($userLoged['usuario']['id'] ?? 0);
		$postVars = $request->getPostVars();
		$fileVars = $request->getFileVars();
		$resposta = [];

		if ($idUser <= 0 || (int)($postVars['id'] ?? 0) !== $idUser) {
			return json_encode(['success' => false, 'message' => 'Sessão inválida.']);
		}

		$nome = trim((string)($postVars['nome'] ?? ''));
		$email = EmailValidator::normalizar($postVars['email'] ?? '');
		$whatsapp = preg_replace('/\D+/', '', (string)($postVars['whatsapp'] ?? ''));
		$rg = preg_replace('/\D+/', '', (string)($postVars['rg'] ?? ''));
		$cpf = preg_replace('/\D+/', '', (string)($postVars['cpf'] ?? ''));
		$endereco = trim((string)($postVars['endereco'] ?? ''));
		$numero = trim((string)($postVars['numero'] ?? ''));
		$bairro = trim((string)($postVars['bairro'] ?? ''));
		$estado = (int)($postVars['estado'] ?? 0);
		$cidade = (int)($postVars['cidade'] ?? 0);

		if ($nome === '') {
			return json_encode(['success' => false, 'message' => 'Informe o nome.']);
		}

		$erroEmail = EmailValidator::mensagemErro($email, true);
		if ($erroEmail !== null) {
			return json_encode(['success' => false, 'message' => $erroEmail]);
		}

		$emailAntigo = EmailValidator::normalizar($postVars['email_antigo'] ?? '');
		if ($emailAntigo !== '' && $emailAntigo !== $email) {
			$existe = EntityUser::getUserByEmail($email);
			if ($existe instanceof EntityUser) {
				return json_encode(['success' => false, 'message' => 'Esse e-mail já está cadastrado.']);
			}
		}

		$atual = EntityUser::getUserById($idUser);
		$fotoAtual = ($atual instanceof EntityUser) ? ($atual->foto ?? null) : null;
		$foto = UserFotoHelper::processarUpload($fileVars['foto'] ?? null, $fotoAtual);

		$ob = new EntityUser;
		$ob->id = $idUser;
		$ob->nome = $nome;
		$ob->email = $email;
		$ob->whatsapp = $whatsapp;
		$ob->rg = $rg;
		$ob->cpf = $cpf;
		$ob->nascimento = $postVars['nascimento'] ?? '';
		$ob->endereco = $endereco;
		$ob->numero = $numero;
		$ob->bairro = $bairro;
		$ob->uf = $estado;
		$ob->cidade = $cidade;
		$ob->foto = $foto;
		$ob->atualizaPerfil();

		return json_encode([
			'success'  => true,
			'message'  => 'Perfil atualizado.',
			'foto_url' => UserFotoHelper::urlPublica($foto),
		]);
	}
}
