<?php

namespace App\Controller\Api\Student;

use App\Model\Entity\User;
use App\Model\Entity\ClientesAssinantes;
use App\Common\Helpers\StudentApiMapper;
use App\Common\Communication\Email;
use App\Common\Environment;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {

	private static function json($data, int $code = 200): array {
		return ['_http' => $code, 'body' => $data];
	}

	public static function respond($result) {
		$code = is_array($result) && isset($result['_http']) ? (int)$result['_http'] : 200;
		$body = is_array($result) && isset($result['body']) ? $result['body'] : $result;
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
		];
	}

	public static function login($request) {
		$post = $request->getPostVars() ?: [];
		$email = trim((string)($post['email'] ?? ''));
		$password = (string)($post['password'] ?? $post['senha'] ?? '');
		if ($email === '' || $password === '') {
			return self::respond(self::json(['message' => 'Informe email e senha.'], 400));
		}
		$user = User::getUserByEmail($email);
		if (!$user instanceof User || !password_verify($password, (string)$user->senha)) {
			return self::respond(self::json(['message' => 'Credenciais inválidas.'], 401));
		}
		if (($user->nivel ?? '') !== 'Cliente') {
			return self::respond(self::json(['message' => 'Acesso apenas para alunos.'], 403));
		}
		$escola = ClientesAssinantes::getEscolaById((int)$user->id_admin);
		if (!$escola || !$escola->isAtiva()) {
			return self::respond(self::json(['message' => 'Escola inativa.'], 403));
		}

		$expiresIn = 86400;
		$payload = [
			'sub' => (int)$user->id,
			'email' => $user->email,
			'id_admin' => (int)$user->id_admin,
			'nivel' => 'Cliente',
			'iat' => time(),
			'exp' => time() + $expiresIn,
		];
		$token = JWT::encode($payload, getenv('JWT_KEY') ?: 'change-me', 'HS256');

		return self::respond(self::json([
			'user' => StudentApiMapper::user($user),
			'tokens' => StudentApiMapper::tokens($token, $expiresIn),
		]));
	}

	/**
	 * Sempre retorna ok (anti-enumeration). Se o aluno existir, envia e-mail com link.
	 */
	public static function forgotPassword($request) {
		$post = $request->getPostVars() ?: [];
		$email = strtolower(trim((string)($post['email'] ?? '')));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return self::respond(self::json(['ok' => true]));
		}

		$user = User::getUserByEmail($email);
		if ($user instanceof User && ($user->nivel ?? '') === 'Cliente') {
			self::enviarLinkReset($user);
		}

		return self::respond(self::json(['ok' => true]));
	}

	/**
	 * Redefine senha com token JWT (purpose=pwd_reset).
	 */
	public static function resetPassword($request) {
		$post = $request->getPostVars() ?: [];
		$token = trim((string)($post['token'] ?? ''));
		$password = (string)($post['password'] ?? $post['senha'] ?? '');
		if ($token === '' || $password === '') {
			return self::respond(self::json(['message' => 'Informe o token e a nova senha.'], 400));
		}
		if (strlen($password) < 8) {
			return self::respond(self::json(['message' => 'A senha deve ter pelo menos 8 caracteres.'], 400));
		}

		try {
			$decode = (array)JWT::decode($token, new Key(getenv('JWT_KEY') ?: 'change-me', 'HS256'));
		} catch (\Throwable $e) {
			return self::respond(self::json(['message' => 'Link inválido ou expirado.'], 400));
		}

		if (($decode['purpose'] ?? '') !== 'pwd_reset') {
			return self::respond(self::json(['message' => 'Token inválido.'], 400));
		}
		$id = (int)($decode['sub'] ?? 0);
		$user = $id > 0 ? User::getUserById($id) : null;
		if (!$user instanceof User || ($user->nivel ?? '') !== 'Cliente') {
			return self::respond(self::json(['message' => 'Usuário inválido.'], 400));
		}
		$emailClaim = strtolower((string)($decode['email'] ?? ''));
		if ($emailClaim !== '' && $emailClaim !== strtolower((string)$user->email)) {
			return self::respond(self::json(['message' => 'Token inválido.'], 400));
		}

		$user->senha = password_hash($password, PASSWORD_DEFAULT);
		$user->resetSenha();

		return self::respond(self::json(['ok' => true, 'message' => 'Senha atualizada. Faça login.']));
	}

	private static function enviarLinkReset(User $user): void {
		$portal = rtrim((string)(
			Environment::get('ASCEND_URL')
			?: getenv('ASCEND_URL')
			?: 'http://localhost:8080'
		), '/');

		$expiresIn = 3600;
		$payload = [
			'sub' => (int)$user->id,
			'email' => (string)$user->email,
			'id_admin' => (int)$user->id_admin,
			'purpose' => 'pwd_reset',
			'iat' => time(),
			'exp' => time() + $expiresIn,
		];
		$token = JWT::encode($payload, getenv('JWT_KEY') ?: 'change-me', 'HS256');
		$link = $portal.'/reset-password?token='.rawurlencode($token);

		$nome = htmlspecialchars((string)$user->nome, ENT_QUOTES, 'UTF-8');
		$body = '<p>Olá, <strong>'.$nome.'</strong>.</p>'
			.'<p>Recebemos um pedido para redefinir sua senha no portal do aluno.</p>'
			.'<p><a href="'.htmlspecialchars($link, ENT_QUOTES, 'UTF-8').'">Clique aqui para criar uma nova senha</a></p>'
			.'<p>O link vale por 1 hora. Se você não pediu isso, ignore este e-mail.</p>'
			.'<p style="color:#888;font-size:12px;word-break:break-all;">'.$link.'</p>';

		try {
			$mail = Email::escola((int)$user->id_admin);
			$mail->sendEmail([(string)$user->email], 'Redefinir senha — portal do aluno', $body);
		} catch (\Throwable $e) {
			// silencioso: resposta ao cliente já é ok
		}
	}
}
