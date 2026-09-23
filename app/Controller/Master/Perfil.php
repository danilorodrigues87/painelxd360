<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Model\Entity\User as EntityUser;
use App\Common\Helpers\EmailValidator;
use App\Common\Helpers\UserFotoHelper;
use App\Common\Helpers\MasterGateHelper;

class Perfil extends Page {

	public static function index($request) {
		$userLoged = SessionUser::getUserLogedData();
		$id = (int)($userLoged['usuario']['id'] ?? 0);
		$dados = EntityUser::getUserById($id);
		if (!$dados instanceof EntityUser || !MasterGateHelper::isMasterEmail($dados->email ?? '')) {
			$request->getRouter()->redirect('/master');
		}

		$alertSql = EntityUser::temColunaFoto()
			? ''
			: '<div class="alert alert-warning small">Execute: <code>ALTER TABLE usuarios ADD COLUMN foto VARCHAR(255) NULL;</code></div>';

		$content = View::render('master/modules/perfil/index', [
			'alert_sql'    => $alertSql,
			'foto_html'    => UserFotoHelper::htmlCampoFormulario($dados->foto ?? null, 'input-foto-master'),
			'nome'         => htmlspecialchars((string)$dados->nome, ENT_QUOTES, 'UTF-8'),
			'email'        => htmlspecialchars((string)$dados->email, ENT_QUOTES, 'UTF-8'),
			'email_antigo' => htmlspecialchars((string)$dados->email, ENT_QUOTES, 'UTF-8'),
			'whatsapp'     => htmlspecialchars((string)($dados->whatsapp ?? ''), ENT_QUOTES, 'UTF-8'),
			'cpf'          => htmlspecialchars((string)($dados->cpf ?? ''), ENT_QUOTES, 'UTF-8'),
			'rg'           => htmlspecialchars((string)($dados->rg ?? ''), ENT_QUOTES, 'UTF-8'),
			'id'           => (int)$dados->id,
		]);

		return parent::getPanel('Meu perfil — Master', $content, 'perfil');
	}

	public static function salvar($request) {
		$userLoged = SessionUser::getUserLogedData();
		$idUser = (int)($userLoged['usuario']['id'] ?? 0);
		$postVars = $request->getPostVars();
		$fileVars = $request->getFileVars();

		if ($idUser <= 0 || (int)($postVars['id'] ?? 0) !== $idUser) {
			return json_encode(['success' => false, 'message' => 'Sessão inválida.']);
		}

		$atual = EntityUser::getUserById($idUser);
		if (!$atual instanceof EntityUser || !MasterGateHelper::isMasterEmail($atual->email ?? '')) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$nome = trim((string)($postVars['nome'] ?? ''));
		$email = EmailValidator::normalizar($postVars['email'] ?? '');
		$whatsapp = preg_replace('/\D+/', '', (string)($postVars['whatsapp'] ?? ''));
		$cpf = preg_replace('/\D+/', '', (string)($postVars['cpf'] ?? ''));
		$rg = preg_replace('/\D+/', '', (string)($postVars['rg'] ?? ''));

		if ($nome === '') {
			return json_encode(['success' => false, 'message' => 'Informe o nome.']);
		}

		$erroEmail = EmailValidator::mensagemErro($email, true);
		if ($erroEmail !== null) {
			return json_encode(['success' => false, 'message' => $erroEmail]);
		}

		// E-mail master precisa continuar na lista MASTER_EMAILS
		if (!MasterGateHelper::isMasterEmail($email)) {
			return json_encode([
				'success' => false,
				'message' => 'Este e-mail não está em MASTER_EMAILS no .env. Inclua-o antes de alterar o e-mail de login.',
			]);
		}

		$emailAntigo = EmailValidator::normalizar($postVars['email_antigo'] ?? '');
		if ($emailAntigo !== '' && $emailAntigo !== $email) {
			$existe = EntityUser::getUserByEmail($email);
			if ($existe instanceof EntityUser && (int)$existe->id !== $idUser) {
				return json_encode(['success' => false, 'message' => 'Esse e-mail já está cadastrado.']);
			}
		}

		$foto = UserFotoHelper::processarUpload(
			$fileVars['foto'] ?? null,
			$atual->foto ?? ($postVars['foto_atual'] ?? null)
		);

		$ob = new EntityUser;
		$ob->id = $idUser;
		$ob->nome = $nome;
		$ob->email = $email;
		$ob->whatsapp = $whatsapp;
		$ob->rg = $rg !== '' ? $rg : ($atual->rg ?? '');
		$ob->cpf = $cpf !== '' ? $cpf : ($atual->cpf ?? '');
		$ob->nascimento = $atual->nascimento ?? '';
		$ob->endereco = $atual->endereco ?? '';
		$ob->numero = $atual->numero ?? '';
		$ob->bairro = $atual->bairro ?? '';
		$ob->uf = $atual->uf ?? '';
		$ob->cidade = $atual->cidade ?? '';
		$ob->foto = $foto;
		$ob->atualizaPerfil();

		$_SESSION['usuario-mvc-1']['nome'] = $nome;
		$_SESSION['usuario-mvc-1']['email'] = $email;

		return json_encode([
			'success'  => true,
			'message'  => 'Perfil atualizado.',
			'foto_url' => UserFotoHelper::urlPublica($foto),
			'nome'     => $nome,
		]);
	}

	public static function alterarSenha($request) {
		$userLoged = SessionUser::getUserLogedData();
		$idUser = (int)($userLoged['usuario']['id'] ?? 0);
		$postVars = $request->getPostVars();

		if ($idUser <= 0) {
			return json_encode(['success' => false, 'message' => 'Sessão inválida.']);
		}

		$atual = EntityUser::getUserById($idUser);
		if (!$atual instanceof EntityUser || !MasterGateHelper::isMasterEmail($atual->email ?? '')) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$senha1 = (string)($postVars['senha1'] ?? '');
		$senha2 = (string)($postVars['senha2'] ?? '');

		if ($senha1 === '' || $senha2 === '') {
			return json_encode(['success' => false, 'message' => 'Preencha as duas senhas.']);
		}
		if ($senha1 !== $senha2) {
			return json_encode(['success' => false, 'message' => 'As senhas não coincidem.']);
		}
		if (strlen($senha1) < 8) {
			return json_encode(['success' => false, 'message' => 'A senha deve ter pelo menos 8 caracteres.']);
		}

		$ob = new EntityUser;
		$ob->id = $idUser;
		$ob->senha = password_hash($senha1, PASSWORD_DEFAULT);
		$ob->resetSenha();

		return json_encode(['success' => true, 'message' => 'Senha atualizada com sucesso.']);
	}
}
