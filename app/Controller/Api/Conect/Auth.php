<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectCandidatoAuthHelper;
use App\Common\Helpers\ConectIdadeHelper;
use App\Common\Helpers\ConectJovemCrmHelper;
use App\Common\Helpers\ConectJovemLeadRouter;
use App\Common\Helpers\ConectSenhaRecuperacaoHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\User;
use Firebase\JWT\JWT;

class Auth {

	use PerfilCandidatoResponse;

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function login($request): array {
		$post = $request->getPostVars() ?: [];
		$email = trim((string)($post['email'] ?? ''));
		$password = (string)($post['password'] ?? $post['senha'] ?? '');
		if ($email === '' || $password === '') {
			return self::respond(['message' => 'Informe email e senha.'], 400);
		}
		$user = User::getUserByEmail($email);
		if (!$user instanceof User || !password_verify($password, (string)$user->senha)) {
			return self::respond(['message' => 'Credenciais inválidas.'], 401);
		}
		if (!ConectCandidatoAuthHelper::podeAcessarConect($user)) {
			return self::respond([
				'message' => ConectCandidatoAuthHelper::mensagemLoginIncorreto($user, 'candidato'),
			], 403);
		}
		$candidato = ConectCandidatoAuthHelper::resolverPerfil($user);
		if (!$candidato) {
			return self::respond(['message' => 'Perfil de candidato não encontrado. Cadastre-se no portal ou peça à escola parceira.'], 403);
		}

		$expiresIn = 86400;
		$token = JWT::encode([
			'sub'          => (int)$user->id,
			'email'        => $user->email,
			'nivel'        => 'Candidato',
			'id_admin'     => (int)$candidato->id_admin,
			'id_candidato' => (int)$candidato->id,
			'iat'          => time(),
			'exp'          => time() + $expiresIn,
		], getenv('JWT_KEY') ?: 'change-me', 'HS256');

		return self::respond([
			'user'   => ConectApiMapper::userCandidato($user, $candidato),
			'tokens' => ConectApiMapper::tokens($token, $expiresIn),
		]);
	}

	public static function register($request): array {
		if (!CjCandidato::tabelaExiste()) {
			return self::respond(['message' => 'Módulo Conecta Jovem não instalado (SQL).'], 503);
		}
		$post = $request->getPostVars() ?: [];
		$nome = trim((string)($post['nome'] ?? ''));
		$email = strtolower(trim((string)($post['email'] ?? '')));
		$senha = (string)($post['password'] ?? $post['senha'] ?? '');
		$whatsapp = preg_replace('/\D+/', '', (string)($post['whatsapp'] ?? ''));
		$cidadeId = (int)($post['cidadeId'] ?? $post['cidade_id'] ?? 0);
		$bairro = trim((string)($post['bairro'] ?? ''));
		$uf = strtoupper(substr(trim((string)($post['uf'] ?? '')), 0, 2));

		if ($nome === '' || $email === '' || strlen($senha) < 6) {
			return self::respond(['message' => 'Preencha nome, e-mail e senha (mín. 6 caracteres).'], 400);
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return self::respond(['message' => 'E-mail inválido.'], 400);
		}
		if (User::getUserByEmail($email)) {
			return self::respond(['message' => 'E-mail já cadastrado.'], 409);
		}

		$nascVal = ConectIdadeHelper::extrairValidarNascimento($post, true);
		if (!$nascVal['ok']) {
			return self::respond(['message' => $nascVal['erro'] ?? 'Data de nascimento inválida.'], 400);
		}

		$idAdmin = ConectJovemLeadRouter::resolverIdAdmin(['cidade_id' => $cidadeId]);

		$user = new User();
		$user->nome = $nome;
		$user->email = $email;
		$user->senha = password_hash($senha, PASSWORD_DEFAULT);
		$user->nivel = 'Candidato';
		$user->id_admin = $idAdmin;
		$user->whatsapp = $whatsapp;
		$user->ativo = 1;
		$user->cadastrar();
		if ((int)$user->id <= 0) {
			return self::respond(['message' => 'Não foi possível criar usuário.'], 500);
		}

		$leadId = ConectJovemCrmHelper::criarLeadExterno(
			$idAdmin,
			$nome,
			$whatsapp,
			$email,
			(string)($post['cursoInteresse'] ?? 'Empregabilidade'),
			$cidadeId > 0 ? $cidadeId : null,
			$bairro !== '' ? $bairro : null,
			$nascVal['dados']['nascimento'] ?? null,
			$nascVal['dados']['responsavel_nome'] ?? null
		);

		$candidatoInsert = [
			'id_usuario'  => (int)$user->id,
			'id_admin'    => $idAdmin,
			'tipo'        => 'externo',
			'nome'        => $nome,
			'email'       => $email,
			'whatsapp'    => $whatsapp,
			'cidade_id'   => $cidadeId > 0 ? $cidadeId : null,
			'bairro'      => $bairro !== '' ? $bairro : null,
			'uf'          => $uf !== '' ? $uf : null,
			'resumo'      => trim((string)($post['resumo'] ?? '')),
			'crm_lead_id' => $leadId,
			'status'      => 'ativo',
		];
		if (!empty($nascVal['dados'])) {
			$candidatoInsert = array_merge($candidatoInsert, $nascVal['dados']);
		}

		$candidatoId = CjCandidato::inserir($candidatoInsert);

		if (!$candidatoId) {
			return self::respond(['message' => 'Usuário criado, mas perfil de candidato falhou.'], 500);
		}

		$candidato = CjCandidato::getById($candidatoId);
		$expiresIn = 86400;
		$token = JWT::encode([
			'sub'          => (int)$user->id,
			'email'        => $user->email,
			'nivel'        => 'Candidato',
			'id_admin'     => $idAdmin,
			'id_candidato' => $candidatoId,
			'iat'          => time(),
			'exp'          => time() + $expiresIn,
		], getenv('JWT_KEY') ?: 'change-me', 'HS256');

		return self::respond([
			'user'   => ConectApiMapper::userCandidato($user, $candidato),
			'tokens' => ConectApiMapper::tokens($token, $expiresIn),
		], 201);
	}

	public static function me($request): array {
		$user = $request->user ?? null;
		$candidato = $request->candidato ?? null;
		if (!$user || !$candidato) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		return self::respond(self::perfilResponse($user, $candidato));
	}

	public static function forgotPassword($request): array {
		$post = $request->getPostVars() ?: [];
		$email = (string)($post['email'] ?? '');
		$res = ConectSenhaRecuperacaoHelper::solicitar($email, 'candidato');
		return self::respond(['message' => $res['message']], $res['code'] ?? ($res['ok'] ? 200 : 400));
	}

	public static function resetPassword($request): array {
		$post = $request->getPostVars() ?: [];
		$codigo = (string)($post['code'] ?? $post['codigo'] ?? '');
		$nova = (string)($post['newPassword'] ?? $post['senha'] ?? $post['password'] ?? '');
		$confirma = (string)($post['confirmPassword'] ?? $post['senha_confirma'] ?? $nova);
		$res = ConectSenhaRecuperacaoHelper::redefinir($codigo, $nova, $confirma, 'candidato');
		return self::respond(['message' => $res['message']], $res['code'] ?? ($res['ok'] ? 200 : 400));
	}
}
