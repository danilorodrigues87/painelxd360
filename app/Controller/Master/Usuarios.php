<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Common\MasterModules;
use App\Common\Helpers\EmailValidator;
use App\Common\Helpers\MasterGateHelper;
use App\Common\Helpers\MasterStaffHelper;
use App\Common\Helpers\SaasEmpresaXd360Helper;
use App\Model\Entity\User as EntityUser;
use App\Session\User\Login as SessionUser;

class Usuarios extends Page {

	public static function index($request) {
		MasterStaffHelper::bootstrapSuperAdmins();

		$content = View::render('master/modules/usuarios/index', [
			'permissoes_html' => MasterModules::htmlCheckboxes([]),
		]);
		return parent::getPanel('Usuários Master', $content, 'usuarios');
	}

	public static function getInfo($request) {
		MasterStaffHelper::bootstrapSuperAdmins();

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		switch ($acao) {
			case 'listar':
				return self::listar();
			case 'detalhes':
				return self::detalhes($post);
			case 'salvar':
				return self::salvar($post);
			case 'excluir':
				return self::excluir($post);
			case 'reset_senha':
				return self::resetSenha($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function listar(): string {
		$lista = [];
		foreach (MasterStaffHelper::listarParaSelect() as $row) {
			$u = EntityUser::getUserById((int)$row['id']);
			if (!$u instanceof EntityUser) {
				continue;
			}
			$lista[] = self::formatar($u);
		}
		return json_encode(['success' => true, 'usuarios' => $lista], JSON_UNESCAPED_UNICODE);
	}

	private static function detalhes(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$u = EntityUser::getUserById($id);
		if (!$u instanceof EntityUser || !MasterStaffHelper::isStaffMaster($u)) {
			return json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
		}
		return json_encode(['success' => true, 'usuario' => self::formatar($u)], JSON_UNESCAPED_UNICODE);
	}

	private static function salvar(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$nome = trim((string)($post['nome'] ?? ''));
		$email = EmailValidator::normalizar($post['email'] ?? '');
		$whatsapp = preg_replace('/\D+/', '', (string)($post['whatsapp'] ?? ''));
		$cpf = preg_replace('/\D+/', '', (string)($post['cpf'] ?? ''));
		$rg = preg_replace('/\D+/', '', (string)($post['rg'] ?? ''));
		$ativo = !empty($post['ativo']) && (string)$post['ativo'] !== '0' ? 's' : 'n';
		$senha = trim((string)($post['senha'] ?? ''));

		if ($nome === '') {
			return json_encode(['success' => false, 'message' => 'Informe o nome.']);
		}

		$erroEmail = EmailValidator::mensagemErro($email, true);
		if ($erroEmail !== null) {
			return json_encode(['success' => false, 'message' => $erroEmail]);
		}

		if ($cpf !== '' && strlen($cpf) !== 11) {
			return json_encode(['success' => false, 'message' => 'CPF inválido.']);
		}

		$slugs = MasterModules::slugsFromPost($post);
		$acessoJson = json_encode($slugs, JSON_UNESCAPED_UNICODE);

		if ($id > 0) {
			$atual = EntityUser::getUserById($id);
			if (!$atual instanceof EntityUser || !MasterStaffHelper::isStaffMaster($atual)) {
				return json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
			}
			if (MasterGateHelper::isMasterEmail($atual->email ?? '')) {
				return json_encode(['success' => false, 'message' => 'Super-admins são editados em Meu perfil.']);
			}

			$emailAntigo = EmailValidator::normalizar($post['email_antigo'] ?? $atual->email ?? '');
			if ($emailAntigo !== $email) {
				$existe = EntityUser::getUserByEmailNormalized($email);
				if ($existe instanceof EntityUser && (int)$existe->id !== $id) {
					return json_encode(['success' => false, 'message' => 'E-mail já cadastrado.']);
				}
			}

			$ob = new EntityUser();
			$ob->id = $id;
			$ob->nome = $nome;
			$ob->email = $email;
			$ob->nivel = MasterStaffHelper::NIVEL_OPERADOR;
			$ob->whatsapp = $whatsapp;
			$ob->rg = $rg;
			$ob->cpf = $cpf;
			$ob->nascimento = $atual->nascimento ?? null;
			$ob->endereco = $atual->endereco ?? '';
			$ob->numero = $atual->numero ?? '';
			$ob->bairro = $atual->bairro ?? '';
			$ob->uf = $atual->uf ?? 0;
			$ob->cidade = $atual->cidade ?? 0;
			$ob->ativo = $ativo;
			$ob->acesso = $acessoJson;
			$ob->atualizar();

			return json_encode(['success' => true, 'message' => 'Usuário atualizado.', 'usuario' => self::formatar(EntityUser::getUserById($id))], JSON_UNESCAPED_UNICODE);
		}

		$existe = EntityUser::getUserByEmailNormalized($email);
		if ($existe instanceof EntityUser) {
			return json_encode(['success' => false, 'message' => 'E-mail já cadastrado.']);
		}

		if ($senha === '') {
			$senha = '12345678';
		}
		if (strlen($senha) < 8) {
			return json_encode(['success' => false, 'message' => 'Senha deve ter pelo menos 8 caracteres.']);
		}

		$ob = new EntityUser();
		$ob->nome = $nome;
		$ob->email = $email;
		$ob->nivel = MasterStaffHelper::NIVEL_OPERADOR;
		$ob->senha = password_hash($senha, PASSWORD_DEFAULT);
		$ob->whatsapp = $whatsapp;
		$ob->rg = $rg;
		$ob->cpf = $cpf;
		$ob->nascimento = null;
		$ob->endereco = '';
		$ob->numero = '';
		$ob->bairro = '';
		$ob->uf = 0;
		$ob->cidade = 0;
		$ob->ativo = $ativo;
		$ob->acesso = $acessoJson;
		$ob->id_admin = 0;
		$ob->cadastrar();

		return json_encode([
			'success' => true,
			'message' => 'Usuário criado. Senha inicial: informada no formulário (padrão 12345678 se vazio).',
			'usuario' => self::formatar(EntityUser::getUserById((int)$ob->id)),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function excluir(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$userLoged = SessionUser::getUserLogedData();
		$idLogado = (int)($userLoged['usuario']['id'] ?? 0);

		if ($id <= 0 || $id === $idLogado) {
			return json_encode(['success' => false, 'message' => 'Não é possível excluir este usuário.']);
		}

		$atual = EntityUser::getUserById($id);
		if (!$atual instanceof EntityUser || !MasterStaffHelper::isStaffMaster($atual)) {
			return json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
		}
		if (MasterGateHelper::isMasterEmail($atual->email ?? '')) {
			return json_encode(['success' => false, 'message' => 'Super-admins não podem ser excluídos aqui.']);
		}

		$ob = new EntityUser();
		$ob->id = $id;
		$ob->excluir();

		return json_encode(['success' => true, 'message' => 'Usuário excluído.']);
	}

	private static function resetSenha(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$senha = trim((string)($post['senha'] ?? '12345678'));

		if ($id <= 0) {
			return json_encode(['success' => false, 'message' => 'ID inválido.']);
		}
		if (strlen($senha) < 8) {
			return json_encode(['success' => false, 'message' => 'Senha deve ter pelo menos 8 caracteres.']);
		}

		$atual = EntityUser::getUserById($id);
		if (!$atual instanceof EntityUser || !MasterStaffHelper::isStaffMaster($atual)) {
			return json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
		}
		if (MasterGateHelper::isMasterEmail($atual->email ?? '')) {
			return json_encode(['success' => false, 'message' => 'Altere a senha de super-admin em Meu perfil.']);
		}

		$ob = new EntityUser();
		$ob->id = $id;
		$ob->senha = password_hash($senha, PASSWORD_DEFAULT);
		$ob->resetSenha();

		return json_encode(['success' => true, 'message' => 'Senha redefinida.']);
	}

	/** @return array<string,mixed> */
	private static function formatar(EntityUser $u): array {
		$acesso = json_decode((string)($u->acesso ?? '[]'), true);
		if (!is_array($acesso)) {
			$acesso = [];
		}
		$isSuper = MasterGateHelper::isMasterEmail($u->email ?? '');
		$cpf = preg_replace('/\D+/', '', (string)($u->cpf ?? ''));

		return [
			'id'         => (int)$u->id,
			'nome'       => (string)$u->nome,
			'email'      => (string)$u->email,
			'whatsapp'   => (string)($u->whatsapp ?? ''),
			'cpf'        => strlen($cpf) === 11 ? SaasEmpresaXd360Helper::formatCpf($cpf) : '',
			'cpf_raw'    => $cpf,
			'rg'         => (string)($u->rg ?? ''),
			'ativo'      => ($u->ativo ?? 'n') === 's' ? 1 : 0,
			'is_super'   => $isSuper,
			'acesso'     => $acesso,
			'nivel'      => (string)($u->nivel ?? ''),
		];
	}
}
