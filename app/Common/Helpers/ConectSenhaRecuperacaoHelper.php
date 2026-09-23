<?php

namespace App\Common\Helpers;

use App\Common\Communication\Email;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\User;

/**
 * Recuperação de senha do Conecta Jovem — mesmo fluxo do painel CTI (Recovery + recCode).
 */
class ConectSenhaRecuperacaoHelper {

	public static function generateCode(): string {
		return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	}

	/**
	 * @return array{ok:bool,message:string,code?:int}
	 */
	public static function solicitar(string $email, string $contexto = ''): array {
		$email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['ok' => false, 'message' => 'E-mail inválido.', 'code' => 400];
		}
		if (preg_match('/\r|\n/', $email)) {
			return ['ok' => false, 'message' => 'E-mail inválido.', 'code' => 400];
		}
		if ($contexto !== '' && !in_array($contexto, ['candidato', 'empresa'], true)) {
			return ['ok' => false, 'message' => 'Tipo de conta inválido.', 'code' => 400];
		}

		$user = self::resolverUsuario($email);
		if (!$user instanceof User) {
			return ['ok' => false, 'message' => 'E-mail inválido.', 'code' => 400];
		}
		if (!self::elegivelRecuperacao($user)) {
			return ['ok' => false, 'message' => 'Este e-mail não está cadastrado no Conecta Jovem.', 'code' => 400];
		}

		$code = self::generateCode();
		$obRec = new User();
		$obRec->code = $code;
		$obRec->id = (int)$user->id;
		if (!$obRec->setRecCode()) {
			return ['ok' => false, 'message' => 'Erro ao enviar código.', 'code' => 500];
		}

		$envio = self::enviarCodigoPorEmail((string)$user->email, $code);
		if (!$envio['ok']) {
			return ['ok' => false, 'message' => $envio['message'], 'code' => 500];
		}

		return ['ok' => true, 'message' => 'Enviamos um código de 6 dígitos para o seu e-mail.'];
	}

	/**
	 * @return array{ok:bool,message:string,code?:int}
	 */
	public static function redefinir(string $codigo, string $nova, string $confirma, string $contexto = '', int $minLen = 6): array {
		$codigo = trim($codigo);
		if (!preg_match('/^\d{6}$/', $codigo)) {
			return ['ok' => false, 'message' => 'Código inválido.', 'code' => 400];
		}
		if ($nova === '' || $confirma === '') {
			return ['ok' => false, 'message' => 'Informe e confirme a nova senha.', 'code' => 400];
		}
		if (strlen($nova) < $minLen) {
			return [
				'ok'      => false,
				'message' => 'A nova senha deve ter pelo menos '.$minLen.' caracteres.',
				'code'    => 400,
			];
		}
		if ($nova !== $confirma) {
			return ['ok' => false, 'message' => 'As senhas não coincidem.', 'code' => 400];
		}

		$user = User::getUserByCode($codigo);
		if (!$user instanceof User || !self::elegivelRecuperacao($user)) {
			return ['ok' => false, 'message' => 'Código inválido.', 'code' => 400];
		}

		$obUser = new User();
		$obUser->id = (int)$user->id;
		$obUser->senha = password_hash($nova, PASSWORD_DEFAULT);
		if (!$obUser->resetSenha()) {
			return ['ok' => false, 'message' => 'Erro ao resetar senha.', 'code' => 500];
		}
		$obUser->clearRecCode();

		return ['ok' => true, 'message' => 'Senha redefinida com sucesso. Faça login com a nova senha.'];
	}

	private static function resolverUsuario(string $email): ?User {
		$user = User::getUserByEmail($email);
		if ($user instanceof User) {
			return $user;
		}
		return User::getUserByEmailNormalized($email);
	}

	private static function elegivelRecuperacao(User $user): bool {
		$nivel = (string)($user->nivel ?? '');
		if ($nivel === 'Empresa' || in_array($nivel, ['Candidato', 'Cliente'], true)) {
			return true;
		}

		$idUsuario = (int)($user->id ?? 0);
		if ($idUsuario <= 0) {
			return false;
		}
		if (CjEmpresa::tabelaExiste() && CjEmpresa::getByUsuarioId($idUsuario)) {
			return true;
		}
		if (CjCandidato::tabelaExiste() && CjCandidato::getByUsuarioId($idUsuario)) {
			return true;
		}

		return false;
	}

	/**
	 * Igual Recovery::sendCode — Email::sistema(), mesmo assunto e corpo.
	 *
	 * @return array{ok:bool,message:string}
	 */
	private static function enviarCodigoPorEmail(string $address, string $code): array {
		$subject = 'Recuperação de senha';
		$body = '<p>Seu código de recuepração de senha é:<p><br><b>'.$code.'</b>';

		$obEmail = Email::sistema();
		$res = $obEmail->sendEmail($address, $subject, $body);
		if ($res) {
			return ['ok' => true, 'message' => ''];
		}

		return [
			'ok'      => false,
			'message' => (string)($obEmail->getError() ?: 'Erro ao enviar e-mail.'),
		];
	}
}
